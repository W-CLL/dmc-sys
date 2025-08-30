<?php

namespace app\api\controller;
use app\common\controller\Api;
use app\common\controller\TaskDistributor;
use think\Cache;


class AutoUpdateGlobalObjName extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';
    const GLOBAL_CACHE_KEY = 'global_handler_key';

    private $logFile = null; // 日志文件路径

    /**
     * 初始化日志文件
     */
    private function initLogFile($taskType = 'global')
    {
        $logDir = APP_PATH . '../logs/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $timestamp = date('Y-m-d_H-i-s');
        $this->logFile = $logDir . "task_{$taskType}_{$timestamp}.log";

        // 写入任务开始信息
        $this->writeLog("=== 任务开始 ===");
        $this->writeLog("任务类型: {$taskType}");
        $this->writeLog("开始时间: " . date('Y-m-d H:i:s'));
        $this->writeLog("==================");
    }

    /**
     * 写入日志
     */
    private function writeLog($message)
    {
        if ($this->logFile) {
            $timestamp = date('Y-m-d H:i:s');
            file_put_contents($this->logFile, "[{$timestamp}] {$message}\n", FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * 分割当天全域消耗下的广告计划
     * @param string $user_name
     * @param bool $is_special
     * @return void
     */
    public function chunkGlobalComAdv_old(string $user_name = '', bool $is_special = false)
    {
        $page = Cache::get('chunk_obj_global_page', 1);
        if (!$is_special && $page == 1) {
            checkQueueExecutionOver('autoUpdateGlobalObjName','chunkAutoGlobalObj');
        }
        $redis = Cache::store('redis');
        list($advList, $notWhiteCom) = $this->getAdvList($page, $user_name, $is_special);
        list($start_time, $end_time) = getPersonStartTime($user_name);

        $distributor = new TaskDistributor();
        if (empty($advList)) {
            $taskNum = $distributor->dispatch();
            echo "全部处理完了";
            echo $taskNum;
            Cache::rm('chunk_obj_global_page');
            $redis->rm(self::GLOBAL_CACHE_KEY . '_over');
            Cache::set(self::GLOBAL_CACHE_KEY, strtotime(date('Y-m-d')));
            die;
        }
        $list = sendApiRes(API_BASE_URL."/getGlobalOptCountCollectionApi/", [
            'start_time' => $start_time,
            'end_time' => $end_time,
            'adv_list' => $advList
        ], 'POST')['data'];

        if (empty($list)) {
            $taskNum = $distributor->dispatch();
            echo "全部处理完了";
            echo $taskNum;
            Cache::rm('chunk_obj_global_page');
            $redis->rm(self::GLOBAL_CACHE_KEY . '_over');
            Cache::set(self::GLOBAL_CACHE_KEY, strtotime(date('Y-m-d')));
            die;
        }
//        $queue = new Queue();

        // 先收集所有有效的广告主数据
        $validAdvList = [];
        foreach ($list as $item) {
            // 使用动态计算方法
            $needComNum = $this->calculateNeedComNum($item, $notWhiteCom);

            if ($needComNum <= 0) {
                continue;
            }

            $objList = sendApiRes(API_BASE_URL."/getGlobalObjListApi/", [
                $item['advertiser_id'], $needComNum
            ])['data'];

            if (!$objList) {
                echo "❌ 广告主 {$item['advertiser_id']} 获取计划列表失败或为空\n";
                continue;
            }

            if($this->specialAdvObj($item['advertiser_id'],$user_name)){
                $objList = $this->specialAdvObj($item['advertiser_id'],$user_name);
            }

            $count = count($objList);
            $totalOps = $needComNum;
            $perObjOps = ceil($totalOps / $count);

            // 跳过无效的任务
            if ($perObjOps <= 0) {
                echo "跳过广告主 {$item['advertiser_id']}，操作次数为 {$perObjOps}，需要操作数为 {$needComNum}\n";
                continue;
            }

            $validAdvList[] = [
                'advertiser_id' => $item['advertiser_id'],
                'objList' => $objList,
                'needComNum' => $needComNum,
                'perObjOps' => $perObjOps
            ];
        }

        if (empty($validAdvList)) {
            echo "❌ 没有有效的广告主需要处理\n";
            $taskNum = $distributor->dispatch();
            echo "全部处理完了";
            echo $taskNum;
            Cache::rm('chunk_obj_global_page');
            $redis->rm(self::GLOBAL_CACHE_KEY . '_over');
            Cache::set(self::GLOBAL_CACHE_KEY, strtotime(date('Y-m-d')));
            die;
        }

        // 判断是否只有一个广告主需要操作
        if (count($validAdvList) == 1) {
            echo "🎯 检测到只有一个广告主需要操作，使用直接操作模式\n";
            $this->processGlobalSingleAdvertiser($validAdvList[0]);
        } else {
            echo "🔄 检测到多个广告主需要操作，使用TaskDistributor分发模式\n";
            foreach ($validAdvList as $advData) {
                foreach ($advData['objList'] as $objId) {
                    $distributor->addTask($advData['advertiser_id'], $objId, $advData['perObjOps']);
                }
            }
        }

        // 处理完所有数据后，统一dispatch
        $taskCount = $distributor->dispatch();
        echo "任务分发完成，共生成 {$taskCount} 个任务\n";

        if ($is_special) {
            echo "全部处理完了";
            die;
        }
        $page++;
        Cache::set('chunk_obj_global_page', $page);
        $this->chunkGlobalComAdv($user_name);
    }


    public function chunkGlobalComAdv(string $user_name = '', bool $is_special = false)
    {
        // 初始化日志文件
        $this->initLogFile('global_' . $user_name);

        $redis = Cache::store('redis');
        $page = Cache::get('chunk_obj_global_page', 1);
        if (!$is_special && $page == 1) {
            checkQueueExecutionOver('autoUpdateGlobalObjName', 'chunkAutoGlobalObj');
        }

        list($start_time, $end_time) = getPersonStartTime($user_name);

        // 优化：缓存时间范围和用户配置
        $timeRangeKey = "time_range_{$user_name}";
        $cachedTimeRange = Cache::get($timeRangeKey);
        if (!$cachedTimeRange || $cachedTimeRange['date'] !== date('Y-m-d')) {
            Cache::set($timeRangeKey, [
                'start_time' => $start_time,
                'end_time' => $end_time,
                'date' => date('Y-m-d')
            ], 3600); // 缓存1小时
        } else {
            $start_time = $cachedTimeRange['start_time'];
            $end_time = $cachedTimeRange['end_time'];
        }

        $distributor = new TaskDistributor();
        $distributor->delayMin = 8;
        $distributor->delayMax = 20;
        $distributor->setMaxConsecutiveTasks(4);

        // 优化：批量处理多页数据，但保持单次dispatch
        $batchSize = 5; // 每次处理5页数据，减少API调用但不过度分割
        $totalProcessedPages = 0;

        while (true) {
            $batchData = $this->getBatchAdvData($page, $batchSize, $user_name);

            if (empty($batchData['advLists'])) {
                // 处理完所有数据后，统一dispatch
               $taskCount = $distributor->dispatch();
               $this->writeLog("任务分发完成，共生成 {$taskCount} 个任务");
                $this->finishProcessing($redis);
                break;
            }

            $this->processBatchData($batchData, $start_time, $end_time, $distributor, $user_name);

            $page += $batchSize;
            $totalProcessedPages += $batchSize;
            Cache::set('chunk_obj_global_page', $page);

            // 优化：每处理较多页面后，进行内存清理但不重新创建distributor
            if ($totalProcessedPages % 20 == 0) {
                // 强制垃圾回收，但保持distributor实例
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
                $this->writeLog("已处理 {$totalProcessedPages} 页数据...");
            }
        }
    }

    /**
     * 批量获取广告主数据，减少API调用
     */
    private function getBatchAdvData($startPage, $batchSize, $user_name)
    {
        $advLists = [];
        $notWhiteCom = null;

        for ($i = 0; $i < $batchSize; $i++) {
            $currentPage = $startPage + $i;
            [$advList, $notWhiteComData] = $this->getAdvList($currentPage, $user_name);

            if (empty($advList)) {
                break;
            }

            $advLists[$currentPage] = $advList;
            if ($notWhiteCom === null) {
                $notWhiteCom = $notWhiteComData; // 所有页面使用相同的公司配置
            }
        }

        return [
            'advLists' => $advLists,
            'notWhiteCom' => $notWhiteCom
        ];
    }

    /**
     * 批量处理数据，优化API调用
     */
    private function processBatchData($batchData, $start_time, $end_time, $distributor, $user_name)
    {
        $advLists = $batchData['advLists'];
        $notWhiteCom = $batchData['notWhiteCom'];

        // 优化：合并所有页面的广告主ID，进行批量API调用
        $allAdvIds = [];
        foreach ($advLists as $advList) {
            $allAdvIds = array_merge($allAdvIds, $advList);
        }
        $allAdvIds = array_unique($allAdvIds);

        // 批量获取优化计数数据
        $batchOptCountData = $this->getBatchOptCountData($allAdvIds, $start_time, $end_time);
        if (empty($batchOptCountData)) {
            return;
        }

        // 批量获取对象列表数据
        $batchObjListData = $this->getBatchObjListData($batchOptCountData, $notWhiteCom, $user_name);
        // dump($batchObjListData);
        // 批量添加任务（过滤无效任务）
        foreach ($batchObjListData as $advId => $data) {
            // 跳过无效的任务数据
            if ($data['perObjOps'] <= 0) {
                $this->writeLog("跳过广告主 {$advId}，操作次数为 {$data['perObjOps']}");
                continue;
            }

            foreach ($data['objList'] as $objId) {
                $distributor->addTask($advId, $objId, $data['perObjOps']);
            }
        }
    }

    /**
     * 批量获取优化计数数据
     */
    private function getBatchOptCountData($advIds, $start_time, $end_time)
    {
        if (empty($advIds)) return [];

        // 分批处理，避免单次请求数据量过大
        $chunks = array_chunk($advIds, 500); // 每次最多500个广告主
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
     * 批量获取对象列表数据
     */
    private function getBatchObjListData($optCountData, $notWhiteCom, $user_name)
    {
        $batchObjData = [];
        $apiRequests = []; // 批量API请求数据

        // 批量获取账户级别比例设置，提高性能
        $advIds = array_column($optCountData, 'advertiser_id');
        $accountPercentages = $this->getBatchAccountPercentages($advIds);

        // 准备批量请求数据
        foreach ($optCountData as $item) {
            $needComNum = $this->calculateNeedComNum($item, $notWhiteCom, $accountPercentages);
            $apiRequests[] = [
                'advertiser_id' => $item['advertiser_id'],
                'need_num' => $needComNum
            ];
        }

        // 批量调用API获取对象列表
        if (!empty($apiRequests)) {
            $batchObjLists = $this->callBatchObjListApi($apiRequests);

            // 处理批量结果
            foreach ($optCountData as $index => $item) {
                $advId = $item['advertiser_id'];
                $needComNum = $apiRequests[$index]['need_num'];
                $objList = $batchObjLists[$index] ?? [];

                if (empty($objList)) continue;

                // 检查特殊客户配置
                $specialObjList = $this->specialAdvObj($advId, $user_name);
                if ($specialObjList) {
                    $objList = $specialObjList;
                }

                $count = count($objList);
                $perObjOps = $count > 0 ? ceil($needComNum / $count) : 0;

                $batchObjData[$advId] = [
                    'objList' => $objList,
                    'perObjOps' => $perObjOps
                ];
            }
        }

        return $batchObjData;
    }

    /**
     * 计算需要的操作次数（动态计算，避免比例无限上升）
     */
    private function calculateNeedComNum($item, $notWhiteCom, $accountPercentages = [])
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

        // 获取目标比例：优先使用账户级别设置，其次使用公司级别设置
        $targetPercentage = $this->getTargetPercentage($item['advertiser_id'], $item['company_name'], $notWhiteCom, $accountPercentages);

        // 🎯 新策略分层判断
        if ($currentPercentage > $activityThreshold) {
            // 超过600%：完全不操作
            $this->writeLog("🚫 广告主 {$item['advertiser_id']} 比例超过{$activityThreshold}%（当前{$currentPercentage}%），完全停止操作");
            return 0;
        } elseif ($currentPercentage > $dynamicThreshold) {
            // 400%-600%：只保持每天活跃度
            $activeOps = $this->getMinActiveOperations($globalConfig);
            $this->writeLog("🔄 广告主 {$item['advertiser_id']} 比例{$dynamicThreshold}%-{$activityThreshold}%（当前{$currentPercentage}%），保持活跃度 {$activeOps} 次");
            return $activeOps;
        } elseif ($currentPercentage > $normalThreshold) {
            // 200%-400%：检查操作空间
            if ($operatingSpace <= $minSpaceThreshold) {
                $this->writeLog("⚠️ 广告主 {$item['advertiser_id']} 操作空间不足{$minSpaceThreshold}%（剩余{$operatingSpace}%），停止操作");
                return 0;
            } else {
                // 有足够操作空间，进行动态计算
                return $this->calculateDynamicAddAmount($item, $cusNum, $companyNum, $targetPercentage, $dynamicThreshold, $globalConfig);
            }
        } else {
            // 小于200%：正常追加
            $actualComNum = $cusNum + ($cusNum * $targetPercentage / 100);
            $needComNum = $companyNum > 0 ? $actualComNum - $companyNum : $actualComNum;
            $this->writeLog("✅ 广告主 {$item['advertiser_id']} 比例正常（{$currentPercentage}%），正常追加 " . ceil($needComNum) . " 次");
            return (int)ceil($needComNum);
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

        // 记录详细计算过程到日志
        $this->writeLog("广告主 {$item['advertiser_id']} 动态计算详情:");
        $this->writeLog("- 当前比例: " . round($currentPercentage, 2) . "%");
        $this->writeLog("- 剩余空间: " . round($remainingPercentage, 2) . "%");
        $this->writeLog("- 目标比例: {$targetPercentage}%");
        $this->writeLog("- 客户数: {$cusNum}, 公司数: {$companyNum}");
        $this->writeLog("- 最大可添加: " . round($maxCanAdd, 2));
        $this->writeLog("- 理想添加量: " . round($idealAddAmount, 2));
        $this->writeLog("- 动态每日限制: " . round($dynamicDailyAdd, 2));
        $this->writeLog("- 最终添加: {$finalAddAmount}个");

        // 显示使用的策略类型
        if ($remainingPercentage > 0 && $idealAddAmount > 0) {
            if ($remainingPercentage > 50) {
                $this->writeLog("📊 策略: 正常保底策略（剩余空间充足）");
            } elseif ($remainingPercentage > 20) {
                $this->writeLog("⚡ 策略: 保守活跃度策略（剩余空间中等）");
            } elseif ($remainingPercentage > 0) {
                $this->writeLog("🔥 策略: 最小活跃度策略（剩余空间很少）");
            }
        }

        // 如果最终添加量为0，显示原因
        if ($finalAddAmount == 0) {
            $reason = "未知原因";
            if ($maxCanAdd <= 0) {
                $reason = "已达最大比例限制";
            } elseif ($idealAddAmount <= 0) {
                $reason = "理想添加量为0且无剩余空间";
            } elseif ($dynamicDailyAdd <= 0) {
                $reason = "动态每日限制过小";
            }
            $this->writeLog("⚠️ 添加0个的原因: {$reason}");
        }
        $this->writeLog("---");

        return (int)ceil($finalAddAmount);
    }

    /**
     * 获取目标比例：优先使用账户级别设置，其次使用公司级别设置
     * @param string $advertiserId 广告主ID
     * @param string $companyName 公司名称
     * @param array $notWhiteCom 公司级别比例设置
     * @param array $accountPercentages 批量获取的账户级别比例设置
     * @return int 目标比例
     */
    private function getTargetPercentage($advertiserId, $companyName, $notWhiteCom, $accountPercentages = [])
    {
        // 1. 优先获取账户级别的比例设置
        $accountPercentage = $accountPercentages[$advertiserId] ?? 0;
        if ($accountPercentage > 0) {
            $this->writeLog("✅ 广告主 {$advertiserId} 使用账户级别比例设置: {$accountPercentage}%");
            return $accountPercentage;
        }

        // 2. 其次使用公司级别的比例设置
        $companyPercentage = $notWhiteCom[$companyName] ?? 0;
        if ($companyPercentage > 0) {
            $this->writeLog("📊 广告主 {$advertiserId} 使用公司级别比例设置: {$companyPercentage}%");
            return $companyPercentage;
        }

        // 3. 最后使用默认比例
        $defaultPercentage = 30;
        $this->writeLog("⚠️ 广告主 {$advertiserId} 公司 {$companyName} 未配置任何比例，使用默认{$defaultPercentage}%");
        return $defaultPercentage;
    }

    /**
     * 批量获取账户级别的比例设置
     * @param array $advIds 广告主ID数组
     * @return array 账户比例设置数组，key为广告主ID，value为比例
     */
    private function getBatchAccountPercentages($advIds)
    {
        try {
            if (empty($advIds)) {
                return [];
            }

            // 调用API接口获取账户级别的比例设置
            $response = sendApiRes(API_BASE_URL . "/getAccountPercentagesApi/", [
                'adv_ids' => $advIds
            ], 'POST');

            if (isset($response['status']) && $response['status'] !== 0) {
                $this->writeLog("⚠️ 调用账户比例设置API失败: " . ($response['msg'] ?? '未知错误'));
                return [];
            }

            $accountPercentages = $response['data'] ?? [];
            $this->writeLog("📊 批量获取到 " . count($accountPercentages) . " 个账户级别比例设置");

            return $accountPercentages;

        } catch (\Exception $e) {
            $this->writeLog("⚠️ 批量获取账户比例设置失败: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取账户级别的比例设置（单个查询，保留用于兼容性）
     * @param string $advertiserId 广告主ID
     * @return int 账户比例设置，0表示未设置
     */
    private function getAccountPercentage($advertiserId)
    {
        try {
            // 调用批量接口获取单个账户的比例设置
            $accountPercentages = $this->getBatchAccountPercentages([$advertiserId]);
            return $accountPercentages[$advertiserId] ?? 0;

        } catch (\Exception $e) {
            $this->writeLog("⚠️ 获取广告主 {$advertiserId} 账户比例设置失败: " . $e->getMessage());
            return 0;
        }
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
     * 检查当前是否在饭点时间，返回饭点时间段信息
     */
    private function getCurrentMealPeriod()
    {
        $config = include APP_PATH . 'config/dynamic_ratio_config.php';
        $mealConfig = $config['meal_time_control'] ?? [];

        if (!($mealConfig['enable'] ?? true)) {
            return null;
        }

        $currentHour = (int)date('H');
        $currentMinute = (int)date('i');
        $currentTime = $currentHour * 60 + $currentMinute; // 转换为分钟数便于比较

        $timePeriods = $mealConfig['time_periods'] ?? [];

        foreach ($timePeriods as $periodKey => $period) {
            if (!($period['enabled'] ?? true)) {
                continue; // 跳过未启用的时间段
            }

            $startTime = ($period['start_hour'] ?? 0) * 60 + ($period['start_minute'] ?? 0);
            $endTime = ($period['end_hour'] ?? 0) * 60 + ($period['end_minute'] ?? 0);

            // 检查是否为跨天时间段（结束时间小于开始时间）
            if ($endTime < $startTime) {
                // 跨天时间段：如23:30-01:30
                // 当前时间在开始时间之后（今天晚上）或结束时间之前（明天凌晨）
                if ($currentTime >= $startTime || $currentTime <= $endTime) {
                    return array_merge($period, ['key' => $periodKey]);
                }
            } else {
                // 同一天内的时间段：如12:00-13:30
                if ($currentTime >= $startTime && $currentTime <= $endTime) {
                    return array_merge($period, ['key' => $periodKey]);
                }
            }
        }

        return null; // 不在任何饭点时间内
    }

    /**
     * 批量调用对象列表API
     */
    private function callBatchObjListApi($requests)
    {
        // 这里可以实现真正的批量API调用
        // 目前先用循环模拟，实际应该改为并发请求
        $results = [];

        foreach ($requests as $request) {
            $result = sendApiRes(API_BASE_URL . "/getGlobalObjListApi/", [
                $request['advertiser_id'],
                $request['need_num']
            ])['data'] ?? [];

            $results[] = $result;
        }

        return $results;
    }

    /**
     * 完成处理，清理缓存
     */
    private function finishProcessing($redis)
    {
        Cache::rm('chunk_obj_global_page');
        $redis->rm(self::GLOBAL_CACHE_KEY . '_over');
        Cache::set(self::GLOBAL_CACHE_KEY, strtotime(date('Y-m-d')));

        // 写入任务结束信息到日志
        $this->writeLog("=== 任务结束 ===");
        $this->writeLog("结束时间: " . date('Y-m-d H:i:s'));
        $this->writeLog("==================");

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

        // 优化：缓存广告主列表，按页缓存
        $advListKey = "adv_list_{$user_name}_{$page}";
        $cachedAdvList = Cache::get($advListKey);

        if (!$cachedAdvList) {
            $adv_list = sendApiRes(API_BASE_URL."/getAdvListApi/", [
                "company_name" => $cachedCompanyData['companyNames'],
                "page" => $page,
                "charge_name" => $cachedCompanyData['charge_name'],
                "limit" => 1000,
                "type" => 2
            ], 'POST')['data'];

            $adv_ids = array_column((array)$adv_list, 'adv_id');

            $cachedAdvList = $adv_ids;
            // 缓存10分钟
            Cache::set($advListKey, $cachedAdvList, 600);
        }

        return [$cachedAdvList, $cachedCompanyData['notWhiteCom']];
    }


    // 某些客户指定了一条计划用于修改
    private function specialAdvObj($adv_id,$user_name){
        $special = [
            'mmc' => [
                '1829163931608537' => ["1832152428880217"] // 比如这个户只允许刷1832152428880217这个计划，就放在数组里，如果涉及两条或者以上，直接在数组上继续加即可，返回去方法内顶替掉$list
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
     * 处理单个广告主（全域推广直接操作模式）
     */
    private function processGlobalSingleAdvertiser($advData)
    {
        $queue = new Queue();
        $advertiser_id = $advData['advertiser_id'];
        $objList = $advData['objList'];
        $needComNum = $advData['needComNum'];
        $objCount = count($objList);

        echo "🚀 全域单广告主模式：广告主 {$advertiser_id}，需要操作 {$needComNum} 次，分配给 {$objCount} 个计划\n";

        // 计算每个计划应该操作多少次
        $perObjOps = ceil($needComNum / $objCount);
        echo "📊 每个计划操作 {$perObjOps} 次\n";

        $totalTasks = 0;

        // 为每个计划创建对应次数的任务
        foreach ($objList as $objId) {
            for ($i = 0; $i < $perObjOps; $i++) {
                $delay = rand(2, 8); // 全域推广延时2-8秒
                $taskData = [
                    'adv_id' => $advertiser_id,
                    'obj_id' => $objId,
                    'delay' => $delay,
                    'last_one' => false
                ];

                $queue->addQueue(
                    "【全域单户】{$advertiser_id}_{$objId}",
                    'app\job\AutoUpdateGlobalObjName',
                    'autoUpdateGlobalObjName',
                    $taskData,
                    '',
                    "延迟{$delay}秒执行"
                );

                $totalTasks++;

                // 只记录前10个任务的详细日志，避免日志过多
                if ($totalTasks <= 10) {
                    echo "➕ 全域单户任务：广告主 {$advertiser_id}，计划 {$objId}，延时 {$delay} 秒\n";
                }
            }
        }

        echo "✅ 全域单广告主任务创建完成，共生成 {$totalTasks} 个队列任务\n";
    }

}