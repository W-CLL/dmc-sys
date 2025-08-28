<?php

namespace app\api\controller;
use app\common\controller\Api;
use app\common\controller\TaskDistributor;
use app\common\model\QcAdvDayCost;
use app\common\model\MaterialWhitelist;
use think\Cache;
use think\Db;


class AutoUpdateGlobalObjMaterialTask extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';
    const GLOBAL_CACHE_KEY = 'global_material_handler_key';





    public function chunkGlobalComAdv(string $user_name = '', bool $is_special = false)
    {
        // 设置更长的执行时间限制
        set_time_limit(1200); // 🔧 增加到20分钟
        ini_set('memory_limit', '1024M'); // 🔧 增加内存限制到1GB

        try {
            $redis = Cache::store('redis');
            $page = Cache::get('chunk_obj_material_global_page', 1);

            if (!$is_special && $page == 1) {
                //检查还有没有在进行的任务
                checkQueueExecutionOver('autoUpdateGlobalObjMaterial', 'chunkAutoGlobalObjMaterial');
            }

            list($start_time, $end_time) = getPersonStartTime($user_name);//查询一个月信息

            // 缓存时间范围和用户配置
            $timeRangeKey = "material_time_range_{$user_name}";
            $cachedTimeRange = Cache::get($timeRangeKey);
            if (!$cachedTimeRange || $cachedTimeRange['date'] !== date('Y-m-d')) {
                Cache::set($timeRangeKey, [
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                    'date' => date('Y-m-d')
                ], 10); // 缓存1小时
            } else {
                $start_time = $cachedTimeRange['start_time'];
                $end_time = $cachedTimeRange['end_time'];
            }

            $distributor = new TaskDistributor();
            $distributor->delayMin = 8;
            $distributor->delayMax = 20;
            $distributor->setMaxConsecutiveTasks(4);//同一个 adv 连续最多分配 N 个任务
            // 设置素材任务的队列配置
            $distributor->setJob('【全域素材】账户_计划_素材', 'app\job\AutoUpdateGlobalObjMaterial', 'autoUpdateGlobalObjMaterial');

            // 批量处理多页数据
            $batchSize = 20; // 🔧 进一步增加到每次处理20页数据，大幅减少循环次数
            $totalProcessedPages = 0;
            $loopCount = 0;
            $maxLoops = 1000; // 🔧 添加最大循环次数限制，防止无限循环
            $startTime = time(); // 🔧 记录开始时间
            $maxExecutionTime = 1080; // 🔧 最大执行时间18分钟（留2分钟缓冲）

            while (true) {
                $loopCount++;
                $loopStartTime = microtime(true); // 🔧 记录每次循环开始时间

                // 🔧 安全机制：防止无限循环和超时
                if ($loopCount > $maxLoops) {
                    \think\Log::error("AutoUpdateGlobalObjMaterialTask: 达到最大循环次数限制 ($maxLoops)，强制退出");
                    $distributor->dispatch(); // 确保已添加的任务被处理
                    $this->finishProcessing($redis);
                    break;
                }

                // 🔧 时间检查：防止超时
                if ((time() - $startTime) > $maxExecutionTime) {
                    \think\Log::error("AutoUpdateGlobalObjMaterialTask: 达到最大执行时间限制 ($maxExecutionTime 秒)，强制退出");
                    $distributor->dispatch(); // 确保已添加的任务被处理
                    $this->finishProcessing($redis);
                    break;
                }

                //批量获取广告主数据
                $step1Start = microtime(true);
                $batchData = $this->getBatchAdvData($page, $batchSize, $user_name);
                $step1Time = microtime(true) - $step1Start;

                // 统计获取到的数据量
                $totalAdvCount = 0;
                if (!empty($batchData['advLists'])) {
                    foreach ($batchData['advLists'] as $pageNum => $advList) {
                        $totalAdvCount += count($advList);
                    }
                }

                // 检查是否有数据需要处理
                if (!empty($batchData['advLists'])) {
                    $step2Start = microtime(true);
                    $this->processBatchData($batchData, $start_time, $end_time, $distributor, $user_name);
                    $step2Time = microtime(true) - $step2Start;
                } else {
                    $step2Time = 0;
                }

                // 检查是否还有更多数据
                if (empty($batchData['advLists']) && !$batchData['hasMoreData']) {
                    // 处理完所有数据后，统一dispatch
                    $taskCount = $distributor->dispatch();
                    $this->finishProcessing($redis);
                    break;
                }

                $page += $batchSize;
                $totalProcessedPages += $batchSize;
                Cache::set('chunk_obj_material_global_page', $page);

                // 每处理较多页面后，进行内存清理并分批dispatch任务
                if ($totalProcessedPages % 50 == 0) { // 🔧 调整为每50页dispatch一次，减少频率
                    // 分批dispatch任务，避免内存积累过多
                    $distributor->dispatch();

                    // 强制垃圾回收，但保持distributor实例
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }

                // 🔧 性能监控：输出每次循环的耗时分析
                $loopTotalTime = microtime(true) - $loopStartTime;
                if ($loopCount <= 10 || $loopCount % 50 == 0) { // 前10次每次都记录，之后每50次记录一次
                    \think\Log::info("AutoUpdateGlobalObjMaterialTask: 循环 $loopCount - 总耗时: " . number_format($loopTotalTime, 3) . "s, 获取数据: " . number_format($step1Time, 3) . "s, 处理数据: " . number_format($step2Time, 3) . "s, 页码: $page");
                }
            }

        } catch (\Exception $e) {
            // 返回错误响应而不是抛出异常
            return json(['status' => 'error', 'message' => $e->getMessage(), 'step' => 'STEP_ERROR']);
        }

        // 返回成功响应
        return json(['status' => 'success', 'message' => '任务处理完成', 'step' => 'STEP_SUCCESS']);
    }

    /**
     * 批量获取广告主数据，减少API调用
     */
    private function getBatchAdvData($startPage, $batchSize, $user_name)
    {
        $advLists = [];
        $notWhiteCom = null;
        $consecutiveEmptyPages = 0; // 连续空页面计数
        $maxConsecutiveEmpty = 3; // 最大连续空页面数，超过则认为没有更多数据
        $maxPageLimit = 10000; // 🔧 添加页码上限保护

        for ($i = 0; $i < $batchSize; $i++) {
            $currentPage = $startPage + $i;

            // 🔧 安全机制：防止页码过大
            if ($currentPage > $maxPageLimit) {
                error_log("AutoUpdateGlobalObjMaterialTask: getBatchAdvData 页码超过限制 ($maxPageLimit)，停止获取");
                break;
            }

            [$advList, $notWhiteComData] = $this->getAdvList($currentPage, $user_name);

            if (empty($advList)) {
                $consecutiveEmptyPages++;

                // 如果连续多页都没有数据，可能真的没有更多数据了
                if ($consecutiveEmptyPages >= $maxConsecutiveEmpty) {
                    break;
                }

                // 继续检查下一页，不要立即break
                continue;
            }

            // 有数据时重置连续空页面计数
            $consecutiveEmptyPages = 0;
            $advLists[$currentPage] = $advList;

            if ($notWhiteCom === null) {
                $notWhiteCom = $notWhiteComData; // 所有页面使用相同的公司配置
            }
        }

        return [
            'advLists' => $advLists,
            'notWhiteCom' => $notWhiteCom,
            'hasMoreData' => $consecutiveEmptyPages < $maxConsecutiveEmpty // 是否还有更多数据
        ];
    }

    /**
     * 批量处理数据，优化API调用
     */
    private function processBatchData($batchData, $start_time, $end_time, $distributor, $user_name)
    {
        $advLists = $batchData['advLists'];
        $notWhiteCom = $batchData['notWhiteCom'];

        // 合并所有页面的广告主ID，进行批量API调用
        $allAdvIds = [];
        foreach ($advLists as $pageNum => $advList) {
            $allAdvIds = array_merge($allAdvIds, $advList);
        }
        $allAdvIds = array_unique($allAdvIds);

        // 批量获取优化计数数据
        $batchOptCountData = $this->getBatchOptCountData($allAdvIds, $start_time, $end_time);

        if (empty($batchOptCountData)) {
            return;
        }

        // 批量获取素材列表数据
        $batchMaterialListData = $this->getBatchObjListData($batchOptCountData, $notWhiteCom, $user_name);

        // 跨广告主全局乱序处理
        $globalMaterialList = $this->globalShuffleMaterialsAcrossAdvs($batchMaterialListData);

        // 批量添加素材任务（使用全局乱序后的素材列表）

        $taskCount = 0;
        foreach ($globalMaterialList as $material) {
            // 为每个素材添加任务，将material_id编码到obj_id中
            // 格式：obj_id|material_id，这样可以在任务执行时解析出素材ID
            $encodedObjId = $material['obj_id'] . '|' . $material['material_id'];
            $distributor->addTask(
                $material['adv_id'],
                $encodedObjId,
                1 // 每个素材操作1次
            );
            $taskCount++;
        }
    }

    /**
     * 批量获取优化计数数据
     */
    private function getBatchOptCountData($advIds, $start_time, $end_time)
    {
        if (empty($advIds)) return [];

        // 分批处理，避免单次请求数据量过大
        $chunks = array_chunk($advIds, 1000); // 🔧 增加到每次最多1000个广告主，减少API调用次数
        $allData = [];

        foreach ($chunks as $chunk) {
            $data = sendApiRes(API_BASE_URL . "/getGlobalOptCountCollectionApi/", [
                'start_time' => $start_time,
                'end_time' => $end_time,
                'adv_list' => $chunk
            ], 'POST')['data'] ?? [];

            $allData = array_merge($allData, $data);
        }

        return $allData;
    }

    /**
     * 批量获取素材列表数据（新版本：操作素材而非计划）
     */
    private function getBatchObjListData($optCountData, $notWhiteCom, $user_name)
    {
        $batchMaterialData = [];

        // 获取素材追投白名单数据（通过API接口）
        $whitelistData = $this->getMaterialWhitelistData();

        // 处理每个广告主的数据
        foreach ($optCountData as $item) {
            $advId = $item['advertiser_id'];
            $companyName = $item['company_name'] ?? '';

            // 检查是否在素材追投白名单中（优先级：公司级别 > 广告主级别）
            if ($this->isInWhitelist($advId, $companyName, $whitelistData)) {
                // 记录跳过的白名单
                \think\Log::info("AutoUpdateGlobalObjMaterialTask: 跳过素材追投白名单 - 公司: {$companyName}, 广告主ID: {$advId}");
                continue;
            }

            $needComNum = $this->calculateNeedComNum($item, $notWhiteCom);

            if ($needComNum <= 0) {
                continue;
            }

            // 获取该广告主下的计划列表
            $objList = $this->getAdvObjList($advId, $user_name);

            if (empty($objList)) {
                continue;
            }

            // 获取这些计划下的可操作素材
            $materialList = $this->getBatchMaterialList($advId, $objList);

            if (empty($materialList)) {
                continue;
            }

            // 乱序处理素材列表，确保不连续操作同一个计划
            $shuffledMaterials = $this->shuffleMaterialsAcrossObjs($materialList);

            // 限制素材数量
            $limitedMaterials = array_slice($shuffledMaterials, 0, $needComNum);

            $batchMaterialData[$advId] = [
                'materialList' => $limitedMaterials,
                'perMaterialOps' => 1 // 每个素材操作1次
            ];
        }

        return $batchMaterialData;
    }

    /**
     * 计算需要的操作次数（动态计算，避免比例无限上升）
     */
    private function calculateNeedComNum($item, $notWhiteCom)
    {
        $totalNum = (int)$item['total_num'];
        $companyNum = (int)$item['company_num'];
        $cusNum = $totalNum - $companyNum;

        // 从配置文件获取参数
        $config = include(APP_PATH . 'config/dynamic_ratio_config.php');
        $globalConfig = $config['global'];
        $strategyConfig = $config['new_strategy'] ?? [];

        // 新策略参数
        $normalThreshold = $strategyConfig['normal_threshold'] ?? 200;           // 正常追加阈值
        $dynamicThreshold = $strategyConfig['dynamic_threshold'] ?? 400;         // 动态计算阈值
        $activityThreshold = $strategyConfig['activity_threshold'] ?? 600;       // 活跃度阈值
        $minSpaceThreshold = $strategyConfig['min_space_threshold'] ?? 10;       // 最小操作空间阈值

        if ($cusNum <= 0) {
            return 0; // 没有客户数据，不需要操作
        }

        // 计算当前比例和操作空间
        $currentPercentage = $companyNum > 0 ? ($companyNum / $cusNum) * 100 : 0;
        $operatingSpace = $activityThreshold - $currentPercentage; // 到600%的操作空间

        // 获取公司配置的目标比例
        $targetPercentage = $notWhiteCom[$item['company_name']] ?? 0;
        if ($targetPercentage <= 0) {
            $targetPercentage = 30; // 默认30%的目标比例
        }

        // 新策略分层判断
        if ($currentPercentage > $activityThreshold) {
            // 超过600%：完全不操作
            // error_log("calculateNeedComNum - 广告主: {$item['advertiser_id']}, 超过600%阈值，不操作");
            return 0;
        } elseif ($currentPercentage > $dynamicThreshold) {
            // 400%-600%：只保持每天活跃度
            $activeOps = $this->getMinActiveOperations($globalConfig);
            // error_log("calculateNeedComNum - 广告主: {$item['advertiser_id']}, 400%-600%区间，活跃度操作: $activeOps");
            return $activeOps;
        } elseif ($currentPercentage > $normalThreshold) {
            // 200%-400%：检查操作空间
            if ($operatingSpace <= $minSpaceThreshold) {
                // error_log("calculateNeedComNum - 广告主: {$item['advertiser_id']}, 200%-400%区间，操作空间不足，不操作");
                return 0;
            } else {
                // 有足够操作空间，进行动态计算
                $dynamicAmount = $this->calculateDynamicAddAmount($item, $cusNum, $companyNum, $targetPercentage, $dynamicThreshold, $globalConfig);
                // error_log("calculateNeedComNum - 广告主: {$item['advertiser_id']}, 200%-400%区间，动态计算: $dynamicAmount");
                return $dynamicAmount;
            }
        } else {
            // 小于200%：正常追加
            $actualComNum = $cusNum * $targetPercentage / 100; // 🔧 修复：应该是客户数的百分比，不是相加
            $needComNum = $companyNum > 0 ? $actualComNum - $companyNum : $actualComNum;
            $finalNeedComNum = max(0, (int)ceil($needComNum)); // 🔧 确保不为负数

            // 🔧 调试日志（暂时注释以提高性能）
            // error_log("calculateNeedComNum - 广告主: {$item['advertiser_id']}, 公司: {$item['company_name']}, 客户数: $cusNum, 公司数: $companyNum, 当前比例: " . number_format($currentPercentage, 2) . "%, 目标比例: $targetPercentage%, 需要操作: $finalNeedComNum");

            return $finalNeedComNum;
        }
    }

    /**
     * 动态计算添加量，确保不超过最大比例（新策略版本）
     */
    private function calculateDynamicAddAmount($item, $cusNum, $companyNum, $targetPercentage, $maxPercentage, $globalConfig)
    {
        // 从配置中获取参数
        $minDailyAdd = $globalConfig['min_daily_add'];
        $maxDailyAdd = $globalConfig['max_daily_add'];

        // 计算当前比例
        $currentPercentage = ($companyNum / $cusNum) * 100;

        // 计算距离最大比例还有多少空间
        $remainingPercentage = $maxPercentage - $currentPercentage;

        if ($remainingPercentage <= 0) {
            return 0; // 已经达到上限
        }

        // 计算可以添加的最大数量（基于最大比例限制）
        $maxAllowedCompanyNum = ($cusNum * $maxPercentage) / 100;
        $maxCanAdd = $maxAllowedCompanyNum - $companyNum;

        // 计算基于目标比例的理想添加量
        $idealCompanyNum = ($cusNum * $targetPercentage) / 100;
        $idealAddAmount = max(0, $idealCompanyNum - $companyNum);

        // 动态调整每日添加量
        // 如果距离上限很近，减少每日添加量
        $triggerRange = $maxPercentage - $targetPercentage * 2;
        if ($triggerRange <= 0) {
            $triggerRange = 100; // 防止除零错误
        }
        $percentageToMax = $remainingPercentage / $triggerRange;
        $dynamicDailyAdd = $minDailyAdd + ($maxDailyAdd - $minDailyAdd) * $percentageToMax;

        // 🔧 修复：确保动态每日限制不会过小
        $dynamicDailyAdd = max($dynamicDailyAdd, $minDailyAdd * 0.5); // 至少是最小值的一半

        // 🔧 修复：当理想添加量为0时，使用保底策略保持活跃度
        if ($idealAddAmount <= 0 && $remainingPercentage > 0) {
            if ($remainingPercentage > 50) {
                // 剩余空间充足时，正常保底策略
                $fallbackAmount = min(
                    $maxCanAdd,
                    ceil($remainingPercentage / 50) * $minDailyAdd
                );
            } elseif ($remainingPercentage > 20) {
                // 剩余空间中等时，保守的活跃度策略
                $fallbackAmount = min(
                    $maxCanAdd,
                    ceil($remainingPercentage / 20) * 2  // 每20%空间给2个操作
                );
            } else {
                // 剩余空间很少时，最小活跃度策略
                $fallbackAmount = min(
                    $maxCanAdd,
                    max(1, ceil($remainingPercentage / 10))  // 至少1个，每10%空间给1个操作
                );
            }
            $idealAddAmount = max($idealAddAmount, $fallbackAmount);
        }

        // 选择最小值，确保不超过任何限制
        $finalAddAmount = min(
            $maxCanAdd,           // 不超过最大比例限制
            $idealAddAmount,      // 不超过理想添加量（已修复）
            $dynamicDailyAdd      // 不超过动态每日限制
        );

        $finalAddAmount = max(0, $finalAddAmount); // 确保不为负数

        return (int)ceil($finalAddAmount);
    }

    /**
     * 获取最小活跃度操作次数
     * 即使达到最大比例，也要保持账户活跃度
     */
    private function getMinActiveOperations($config)
    {
        // 从配置中获取活跃度参数
        $minActive = $config['min_active_operations'] ?? 1;
        $maxActive = $config['max_active_operations'] ?? 20;

        // 智能活跃度策略
        $activityConfig = include APP_PATH . 'config/dynamic_ratio_config.php';
        $activityStrategy = $activityConfig['activity_strategy'];

        if (!$activityStrategy['enable_min_activity']) {
            return 0; // 如果禁用活跃度策略，返回0
        }

        // 基于时间的智能调整（可选）
        $baseOperations = rand($minActive, $maxActive);

        // 根据当前时间调整活跃度（工作时间稍微多一些）
        $currentHour = (int)date('H');
        if ($currentHour >= 9 && $currentHour <= 18) {
            // 工作时间，稍微增加活跃度
            $baseOperations = min($maxActive, $baseOperations + rand(1, 3));
        } elseif ($currentHour >= 22 || $currentHour <= 6) {
            // 深夜时间，减少活跃度
            $baseOperations = max($minActive, $baseOperations - rand(1, 2));
        }

        return $baseOperations;
    }



    /**
     * 获取广告主下的计划列表
     */
    private function getAdvObjList($advId, $user_name)
    {
        // 获取该广告主下的有效计划
        $objList = sendApiRes(API_BASE_URL . "/getGlobalObjListApi/", [
            $advId,
            1000 // 获取足够多的计划
        ])['data'] ?? [];

        // 检查特殊客户配置
        $specialObjList = $this->specialAdvObj($advId, $user_name);
        if ($specialObjList) {
            $objList = $specialObjList;
        }

        return $objList;
    }

    /**
     * 批量获取计划下的可操作素材（使用新API接口）
     */
    private function getBatchMaterialList($advId, $objList)
    {
        if (empty($objList)) {
            return [];
        }

        try {
            // 调用新的批量素材API接口
            //在这个目录下 /api/online_api/v1/api
            $response = sendApiRes(API_BASE_URL . "/getBatchGlobalObjMaterialListApi/", [
                'adv_id' => $advId,
                'obj_ids' => $objList,
                'needComNum' => 50, // 每个计划最多获取50个素材
                'yearMonth' => date('Ym') // 当前年月
            ], 'POST');

            // 验证API响应格式
            if (!is_array($response)) {
                return [];
            }

            // 检查API是否返回成功状态
            if (isset($response['status']) && $response['status'] !== 0) {
                return [];
            }

            $materialList = $response['data']['data'] ?? [];

            // 验证返回的数据格式
            if (!is_array($materialList)) {
                return [];
            }

            return $materialList;

        } catch (\Exception $e) {
            return [];
        }
    }



    /**
     * 乱序处理素材列表，确保不连续操作同一个计划
     */
    private function shuffleMaterialsAcrossObjs($materialList)
    {

        if (empty($materialList)) {
            return [];
        }

        // 数据验证：确保 $materialList 是数组且包含正确格式的数据
        if (!is_array($materialList)) {
            return [];
        }

        // 按计划ID分组
        $groupedByObj = [];
        foreach ($materialList as $material) {
            // 验证每个素材项是否为数组
            if (!is_array($material)) {
                continue;
            }

            // 验证是否包含必要的字段
            if (!isset($material['obj_id'])) {
                continue;
            }

            $objId = $material['obj_id'];
            if (!isset($groupedByObj[$objId])) {
                $groupedByObj[$objId] = [];
            }
            $groupedByObj[$objId][] = $material;
        }

        // 检查是否有有效的分组数据
        if (empty($groupedByObj)) {
            return [];
        }

        // 乱序处理：轮流从不同计划中取素材
        $shuffledMaterials = [];
        $objIds = array_keys($groupedByObj);
        shuffle($objIds); // 随机打乱计划顺序

        $maxMaterialsPerObj = max(array_map('count', $groupedByObj));

        // 轮流从每个计划中取素材
        for ($i = 0; $i < $maxMaterialsPerObj; $i++) {
            foreach ($objIds as $objId) {
                if (isset($groupedByObj[$objId][$i])) {
                    $shuffledMaterials[] = $groupedByObj[$objId][$i];
                }
            }
        }

        return $shuffledMaterials;
    }

    /**
     * 🎯 全局乱序处理：跨广告主的素材乱序分布
     * 实现效果：账户A计划A素材1 → 账户B计划C素材2 → 账户C计划B素材1...
     */
    private function globalShuffleMaterialsAcrossAdvs($batchMaterialListData)
    {
        if (empty($batchMaterialListData)) {
            return [];
        }

        // 第一步：收集所有素材并按广告主和计划分组
        $globalMaterialGroups = [];
        $totalMaterialCount = 0;

        foreach ($batchMaterialListData as $data) {
            if (empty($data['materialList'])) {
                continue;
            }

            // 按广告主-计划组合进行分组
            foreach ($data['materialList'] as $material) {
                $groupKey = $material['adv_id'] . '_' . $material['obj_id'];

                if (!isset($globalMaterialGroups[$groupKey])) {
                    $globalMaterialGroups[$groupKey] = [
                        'adv_id' => $material['adv_id'],
                        'obj_id' => $material['obj_id'],
                        'materials' => []
                    ];
                }

                $globalMaterialGroups[$groupKey]['materials'][] = $material;
                $totalMaterialCount++;
            }
        }

        if (empty($globalMaterialGroups)) {
            return [];
        }

        // 第二步：实现跨广告主的轮转乱序算法
        $shuffledMaterials = [];
        $groupKeys = array_keys($globalMaterialGroups);

        // 随机打乱组合顺序，确保不同批次有不同的起始顺序
        shuffle($groupKeys);

        // 计算最大轮转次数
        $materialCounts = array_map(function($group) {
            return count($group['materials']);
        }, $globalMaterialGroups);

        $maxMaterialsInGroup = empty($materialCounts) ? 0 : max($materialCounts);

        // 轮转算法：每轮从每个组合中取一个素材
        for ($round = 0; $round < $maxMaterialsInGroup; $round++) {
            // 每轮都重新打乱组合顺序，增加随机性
            if ($round > 0) {
                shuffle($groupKeys);
            }

            foreach ($groupKeys as $groupKey) {
                $group = $globalMaterialGroups[$groupKey];

                // 如果该组合还有素材，取出一个
                if (isset($group['materials'][$round])) {
                    $shuffledMaterials[] = $group['materials'][$round];
                }
            }
        }

        // 第三步：最终随机微调，打破可能的规律性
        $finalShuffled = $this->applyFinalRandomAdjustment($shuffledMaterials);

        return $finalShuffled;
    }

    /**
     * 应用最终随机微调，打破规律性
     */
    private function applyFinalRandomAdjustment($materials)
    {
        if (count($materials) <= 10) {
            return $materials; // 素材太少，不需要微调
        }

        $adjusted = $materials;
        $adjustmentCount = min(10, intval(count($materials) * 0.1)); // 调整10%的素材位置

        for ($i = 0; $i < $adjustmentCount; $i++) {
            $pos1 = rand(0, count($adjusted) - 1);
            $pos2 = rand(0, count($adjusted) - 1);

            // 确保不是相邻位置，避免破坏太多
            if (abs($pos1 - $pos2) > 2) {
                $temp = $adjusted[$pos1];
                $adjusted[$pos1] = $adjusted[$pos2];
                $adjusted[$pos2] = $temp;
            }
        }

        return $adjusted;
    }

    /**
     * 完成处理，清理缓存
     */
    private function finishProcessing($redis)
    {
        Cache::rm('chunk_obj_material_global_page');
        $redis->rm(self::GLOBAL_CACHE_KEY . '_over');
        Cache::set(self::GLOBAL_CACHE_KEY, strtotime(date('Y-m-d')));

        // 只在页面显示简单的完成信息
        echo "处理完成了";
    }

    /**
     * 获取公司设置（优化版本，添加缓存机制）
     * @param $page
     * @param  $user_name
     * @return array
     */
    public function getAdvList($page, $user_name): array
    {
        // 优化：缓存操作员映射
        static $operatorCache = null;
        if ($operatorCache === null) {
            $operatorCache = [
                'zqp' => "张秋萍",
                'mmc' => "莫美春",
                'cxy' => "陈秀玉",
                'tyx' => "罗文静",
                'wyc' => "谭玉霞",
            ];
        }

        $charge_name = '';
        if ($user_name) {
            if (!isset($operatorCache[$user_name])) {
                echo "名字不存在";
                die;
            } else {
                $charge_name = $operatorCache[$user_name];
            }
        }

        // 优化：缓存公司数据，减少API调用
        $companyDataKey = "company_data_{$user_name}";
        $cachedCompanyData = Cache::get($companyDataKey);

        if (!$cachedCompanyData) {
            $name_where = ['is_white' => 0];

            //获取非白名单公司
            if ($charge_name) {
                $ownerCompanyNames = sendApiRes(API_BASE_URL."/ownerCompanyNamesApi/", [
                    $charge_name
                ])['data'];
                $name_where['company_name'] = ['in', $ownerCompanyNames];
            }

            $notWhiteCom = sendApiRes(API_BASE_URL."/notWhiteComApi/", $name_where, 'POST')['data'];
            $companyNames = array_keys($notWhiteCom);

            $cachedCompanyData = [
                'notWhiteCom' => $notWhiteCom,
                'companyNames' => $companyNames,
                'charge_name' => $charge_name
            ];

            // 缓存30分钟
            Cache::set($companyDataKey, $cachedCompanyData, 1800);
        }

        // 优化：缓存完整广告主列表，然后按页分割
        $allAdvListKey = "all_adv_list_{$user_name}";
        $cachedAllAdvList = Cache::get($allAdvListKey);

        if (!$cachedAllAdvList) {
            $allAdvIds = [];
            $currentPage = 1;
            $limit = 1000;
            $maxPages = 500; // 🔧 添加最大页数限制，防止无限循环

            // 循环获取所有页面的数据，直到没有数据为止
            do {
                // 🔧 安全机制：防止无限循环
                if ($currentPage > $maxPages) {
                    error_log("AutoUpdateGlobalObjMaterialTask: getAdvList 达到最大页数限制 ($maxPages)，强制退出");
                    break;
                }

                $adv_list = sendApiRes(API_BASE_URL."/getAdvListApi/", [
                    "company_name" => $cachedCompanyData['companyNames'],
                    "page" => $currentPage,
                    "charge_name" => $cachedCompanyData['charge_name'],
                    "limit" => $limit,
                    "type" => 2
                ], 'POST')['data'];

                if (empty($adv_list)) {
                    break;
                }

                // 提取当前页的广告主ID
                $currentPageAdvIds = array_column((array)$adv_list, 'adv_id');
                $allAdvIds = array_merge($allAdvIds, $currentPageAdvIds);
                $currentPage++;

                // 如果当前页数据少于limit，说明已经是最后一页
                if (count($adv_list) < $limit) {
                    break;
                }

                // 🔧 每50页输出一次进度日志
                if ($currentPage % 50 == 0) {
                    error_log("AutoUpdateGlobalObjMaterialTask: getAdvList 已处理 $currentPage 页，获取到 " . count($allAdvIds) . " 个广告主");
                }

            } while (true);

            $cachedAllAdvList = array_unique($allAdvIds); // 去重

            // 缓存10分钟
            Cache::set($allAdvListKey, $cachedAllAdvList, 600);
        }

        // 按页分割数据，每页500个广告主（🔧 增加批量大小，减少循环次数）
        $pageSize = 1000; // 🔧 进一步增加到每页1000个广告主
        $startIndex = ($page - 1) * $pageSize;
        $pageAdvList = array_slice($cachedAllAdvList, $startIndex, $pageSize);

        return [$pageAdvList, $cachedCompanyData['notWhiteCom']];
    }

    // 某些客户指定了特定计划用于素材操作
    private function specialAdvObj($adv_id,$user_name){
        $special = [
            'mmc' => [
                '1829163931608537' => ["1832152428880217"] // 比如这个户只允许操作1832152428880217这个计划下的素材，就放在数组里，如果涉及两条或者以上，直接在数组上继续加即可
            ]
        ];
        if(!isset($special[$user_name])){
            return false;
        }
        if(isset($special[$user_name][$adv_id])){
            return $special[$user_name][$adv_id];
        }
        return false;
    }
    /**
     * 获取素材追投白名单数据（通过API接口）
     * @return array
     */
    private function getMaterialWhitelistData()
    {
        try {
            // 调用白名单API接口
            $response = sendApiRes(API_BASE_URL . "/getMaterialWhitelistApi/", [], 'GET');

            if (isset($response['code']) && $response['code'] == 0 && isset($response['data'])) {
                return $response['data'];
            } else {
                // API调用失败时记录日志并返回空数组
                \think\Log::error("AutoUpdateGlobalObjMaterialTask: 获取素材追投白名单失败 - " . json_encode($response));
                return ['companies' => [], 'adv_ids' => []];
            }
        } catch (\Exception $e) {
            // 异常时记录日志并返回空数组，确保任务能继续执行
            \think\Log::error("AutoUpdateGlobalObjMaterialTask: 获取素材追投白名单异常 - " . $e->getMessage());
            return ['companies' => [], 'adv_ids' => []];
        }
    }

    /**
     * 检查广告主是否在白名单中
     * @param string $advId 广告主ID
     * @param string $companyName 公司名称（从任务数据中获取）
     * @param array $whitelistData 白名单数据
     * @return bool
     */
    private function isInWhitelist($advId, $companyName, $whitelistData)
    {
        // 1. 优先检查公司级别白名单（优先级更高）
        if (!empty($companyName) && in_array($companyName, $whitelistData['companies'] ?? [])) {
            return true;
        }

        // 2. 检查广告主级别白名单
        if (!empty($advId) && in_array($advId, $whitelistData['adv_ids'] ?? [])) {
            return true;
        }

        // 3. 如果任务数据中的公司名称为空或不准确，通过fa_company表再次确认
        if (empty($companyName) || !$this->isCompanyNameAccurate($advId, $companyName)) {
            $realCompanyName = $this->getCompanyNameByAdvId($advId);
            if (!empty($realCompanyName) && in_array($realCompanyName, $whitelistData['companies'] ?? [])) {
                \think\Log::info("AutoUpdateGlobalObjMaterialTask: 通过fa_company表发现公司白名单 - 广告主ID: {$advId}, 实际公司: {$realCompanyName}");
                return true;
            }
        }

        return false;
    }

    /**
     * 通过广告主ID从fa_company表获取公司名称
     * @param string $advId
     * @return string
     */
    private function getCompanyNameByAdvId($advId)
    {
        try {
            $companyInfo = \think\Db::name('company')
                ->where('advertiser_id', $advId)
                ->field('company_name')
                ->find();

            return $companyInfo['company_name'] ?? '';
        } catch (\Exception $e) {
            \think\Log::error("AutoUpdateGlobalObjMaterialTask: 获取公司名称失败 - 广告主ID: {$advId}, 错误: " . $e->getMessage());
            return '';
        }
    }

    /**
     * 检查任务数据中的公司名称是否准确
     * @param string $advId
     * @param string $taskCompanyName
     * @return bool
     */
    private function isCompanyNameAccurate($advId, $taskCompanyName)
    {
        if (empty($taskCompanyName)) {
            return false;
        }

        $realCompanyName = $this->getCompanyNameByAdvId($advId);
        return !empty($realCompanyName) && $realCompanyName === $taskCompanyName;
    }

}