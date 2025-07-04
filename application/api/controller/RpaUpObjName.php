<?php

namespace app\api\controller;


use app\common\controller\Api;
use app\common\controller\TaskDistributor;
use app\common\model\Queue;
use think\Cache;
use think\Db;


/**
 * 根据元素操作计划
 */
class RpaUpObjName extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';

    const CACHE_TYPE_NOT_LAB = "not_lab";
    const CACHE_TYPE_LAB = "lab";

    const WEB_GLOBAL_KEY = 'web_global_key';

    private $logFile = null; // 日志文件路径

    const WEB_STRAND_KEY = 'web_strand_key';

    /**
     * 初始化日志文件
     */
    private function initLogFile($taskType = 'rpa')
    {
        $logDir = APP_PATH . '../logs/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $timestamp = date('Y-m-d_H-i-s');
        $this->logFile = $logDir . "task_{$taskType}_{$timestamp}.log";

        // 写入任务开始信息
        $this->writeLog("=== RPA任务开始 ===");
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

    private function processObjList($user_name, $cacheKey, $apiUrl, $listApiUrl, $type, $modelName, $main_queue)
    {
        // 初始化日志文件
        $this->initLogFile($type . '_' . $user_name);

        $page = Cache::get($cacheKey . '_page', 1);
        if ($page == 1) {
            checkQueueExecutionOver($main_queue, "chunkAutoObjWeb");
        }

        // 缓存时间范围和用户配置
        list($start_time, $end_time) = getPersonStartTime($user_name);
        $timeRangeKey = "time_range_{$user_name}_{$type}";
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

        // 初始化任务分发器
        $distributor = new TaskDistributor();
        $this->configureDistributor($distributor, $type);

        // 批量处理多页数据
        $batchSize = 5; // 每次处理5页数据
        $totalProcessedPages = 0;

        while (true) {
            $batchData = $this->getBatchAdvData($page, $batchSize, $user_name, $modelName);
            if (empty($batchData['advLists'])) {
                // 处理完所有数据后，统一dispatch
                $taskCount = $distributor->dispatch();
                echo "处理完成，共生成 {$taskCount} 个任务";
                $this->finishProcessing($cacheKey);
                break;
            }

            $this->processBatchData($batchData, $start_time, $end_time, $distributor, $apiUrl, $listApiUrl, $type);

            $page += $batchSize;
            $totalProcessedPages += $batchSize;
            Cache::set($cacheKey . '_page', $page);

            // 每处理较多页面后，进行内存清理
            if ($totalProcessedPages % 20 == 0) {
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
                $this->writeLog("已处理 {$totalProcessedPages} 页数据...");
            }
        }
    }

    private function getCallingFunctionName($cacheKey): string
    {
        return $cacheKey == self::CACHE_TYPE_NOT_LAB ? 'getNotLabObjList' : 'getGlobalObjList';
    }

    /**
     * 配置任务分发器
     */
    private function configureDistributor($distributor, $type)
    {
        if ($type === 'global') {
            $distributor->delayMin = 8;
            $distributor->delayMax = 20;
            $distributor->setJob('【全域】RPA_计划', 'app\job\AutoUpdateObjNameGlobalWeb', 'autoUpdateObjNameGlobalWeb');
        } else {
            $distributor->delayMin = 5;
            $distributor->delayMax = 15;
            $distributor->setJob('【标准】RPA_计划', 'app\job\AutoUpdateObjNameWeb', 'autoUpdateObjNameWeb');
        }
        $distributor->setMaxConsecutiveTasks(4);
    }

    /**
     * 批量获取广告主数据
     */
    private function getBatchAdvData($startPage, $batchSize, $user_name, $modelName)
    {
        $advLists = [];
        $notWhiteCom = null;

        for ($i = 0; $i < $batchSize; $i++) {
            $currentPage = $startPage + $i;
            $autoClass = new $modelName();
            [$advList, $notWhiteComData] = $autoClass->getAdvList($currentPage, $user_name, false);

            if (empty($advList)) {
                break;
            }

            $advLists[$currentPage] = $advList;
            if ($notWhiteCom === null) {
                $notWhiteCom = $notWhiteComData;
            }
        }

        return [
            'advLists' => $advLists,
            'notWhiteCom' => $notWhiteCom
        ];
    }

    /**
     * 批量处理数据
     */
    private function processBatchData($batchData, $start_time, $end_time, $distributor, $apiUrl, $listApiUrl, $type)
    {
        $advLists = $batchData['advLists'];
        $notWhiteCom = $batchData['notWhiteCom'];

        // 合并所有页面的广告主ID，进行批量API调用
        $allAdvIds = [];
        foreach ($advLists as $advList) {
            $allAdvIds = array_merge($allAdvIds, $advList);
        }
        $allAdvIds = array_unique($allAdvIds);

        // 批量获取优化计数数据
        $batchOptCountData = $this->getBatchOptCountData($allAdvIds, $start_time, $end_time, $apiUrl);
        if (empty($batchOptCountData)) {
            return;
        }

        // 批量获取对象列表数据
        $batchObjListData = $this->getBatchObjListData($batchOptCountData, $notWhiteCom, $listApiUrl, $type);

        // 批量添加任务（过滤无效任务）
        foreach ($batchObjListData as $advId => $data) {
            // 跳过无效的任务数据
            if ($data['perObjOps'] <= 0) {
                echo "跳过广告主 {$advId}，操作次数为 {$data['perObjOps']}\n";
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
    private function getBatchOptCountData($advIds, $start_time, $end_time, $apiUrl)
    {
        if (empty($advIds)) return [];

        // 分批处理，避免单次请求数据量过大
        $chunks = array_chunk($advIds, 500);
        $allData = [];

        foreach ($chunks as $chunk) {
            $rep = sendApiRes($apiUrl, [
                'start_time' => $start_time,
                'end_time' => $end_time,
                'adv_list' => $chunk
            ], 'POST');

            if (isset($rep['msg'])) {
                echo $rep['msg'];
                continue;
            }

            $data = $rep['data'] ?? [];
            $allData = array_merge($allData, $data);
        }

        return $allData;
    }

    /**
     * 批量获取对象列表数据
     */
    private function getBatchObjListData($optCountData, $notWhiteCom, $listApiUrl, $type)
    {
        $batchObjData = [];
        $apiRequests = [];

        // 准备批量请求数据
        foreach ($optCountData as $item) {
            $needComNum = $this->calculateNeedComNum($item, $notWhiteCom);
            if ($needComNum <= 0) {
                continue; // 跳过不需要操作的广告主
            }

            $apiRequests[] = [
                'advertiser_id' => $item['advertiser_id'],
                'need_num' => $needComNum
            ];
        }

        // 批量调用API获取对象列表
        if (!empty($apiRequests)) {
            $batchObjLists = $this->callBatchObjListApi($apiRequests, $listApiUrl);

            // 处理批量结果
            $requestIndex = 0;
            foreach ($optCountData as $item) {
                $advId = $item['advertiser_id'];
                $needComNum = $this->calculateNeedComNum($item, $notWhiteCom);

                if ($needComNum <= 0) {
                    continue; // 跳过但不增加索引
                }

                $objList = $batchObjLists[$requestIndex] ?? [];
                $requestIndex++; // 只有处理了的请求才增加索引

                if (empty($objList)) continue;

                $count = count($objList);
                $perObjOps = $count > 0 ? ceil($needComNum / $count) : 0;

                $batchObjData[$advId] = [
                    'objList' => $objList,
                    'perObjOps' => $perObjOps,
                    'type' => $type
                ];
            }
        }

        return $batchObjData;
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
        $config = include APP_PATH . 'config/dynamic_ratio_config.php';
        $rpaConfig = $config['rpa'];
        $strategyConfig = $config['new_strategy'] ?? [];

        // 新策略参数（RPA更保守）
        $normalThreshold = $strategyConfig['normal_threshold'] ?? 200;           // 正常追加阈值
        $dynamicThreshold = $strategyConfig['dynamic_threshold'] ?? 400;         // 动态计算阈值
        $activityThreshold = $strategyConfig['activity_threshold'] ?? 600;       // 活跃度阈值
        $minSpaceThreshold = ($strategyConfig['min_space_threshold'] ?? 10) + 5; // RPA更保守，+5%

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
            $this->writeLog("⚠️ RPA广告主 {$item['advertiser_id']} 公司 {$item['company_name']} 未配置目标比例，使用默认30%");
        }

        // 🎯 RPA新策略分层判断（更保守）
        if ($currentPercentage > $activityThreshold) {
            // 超过600%：完全不操作
            echo "🚫 RPA广告主 {$item['advertiser_id']} 比例超过{$activityThreshold}%（当前{$currentPercentage}%），完全停止操作</br>";
            return 0;
        } elseif ($currentPercentage > $dynamicThreshold) {
            // 400%-600%：只保持每天活跃度（RPA更保守）
            $activeOps = $this->getRpaMinActiveOperations($rpaConfig);
            echo "🔄 RPA广告主 {$item['advertiser_id']} 比例{$dynamicThreshold}%-{$activityThreshold}%（当前{$currentPercentage}%），保持活跃度 {$activeOps} 次</br>";
            return $activeOps;
        } elseif ($currentPercentage > $normalThreshold) {
            // 200%-400%：检查操作空间（RPA更严格）
            if ($operatingSpace <= $minSpaceThreshold) {
                echo "⚠️ RPA广告主 {$item['advertiser_id']} 操作空间不足{$minSpaceThreshold}%（剩余{$operatingSpace}%），停止操作</br>";
                return 0;
            } else {
                // 有足够操作空间，进行动态计算
                return $this->calculateRpaDynamicAddAmount($item, $cusNum, $companyNum, $targetPercentage, $dynamicThreshold, $rpaConfig);
            }
        } else {
            // 小于200%：正常追加
            $actualComNum = $cusNum + $cusNum * $targetPercentage / 100;
            $needComNum = $companyNum > 0 ? $actualComNum - $companyNum : $actualComNum;
            echo "✅ RPA广告主 {$item['advertiser_id']} 比例正常（{$currentPercentage}%），正常追加 " . ceil($needComNum) . " 次</br>";
            return (int)ceil($needComNum);
        }
    }

    /**
     * RPA任务的动态计算添加量（新策略版本）
     */
    private function calculateRpaDynamicAddAmount($item, $cusNum, $companyNum, $targetPercentage, $maxPercentage, $rpaConfig)
    {
        // 从配置中获取参数
        $minDailyAdd = $rpaConfig['min_daily_add'];
        $maxDailyAdd = $rpaConfig['max_daily_add'];

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

        // 🔧 RPA保底策略：当理想添加量为0时，使用保底策略保持活跃度
        if ($idealAddAmount <= 0 && $remainingPercentage > 0) {
            if ($remainingPercentage > 50) {
                // 剩余空间充足时，RPA正常保底策略
                $fallbackAmount = min(
                    $maxCanAdd,
                    ceil($remainingPercentage / 60) * $minDailyAdd  // RPA更保守，每60%空间
                );
            } elseif ($remainingPercentage > 20) {
                // 剩余空间中等时，RPA保守活跃度策略
                $fallbackAmount = min(
                    $maxCanAdd,
                    ceil($remainingPercentage / 25) * 1  // 每25%空间给1个操作
                );
            } else {
                // 剩余空间很少时，RPA最小活跃度策略
                $fallbackAmount = min(
                    $maxCanAdd,
                    max(1, ceil($remainingPercentage / 15))  // 至少1个，每15%空间给1个操作
                );
            }
            $idealAddAmount = max($idealAddAmount, $fallbackAmount);
        }

        // RPA任务更保守的动态调整策略
        $triggerRange = $maxPercentage - $targetPercentage * 2;
        if ($triggerRange <= 0) {
            $triggerRange = 100;
        }
        $percentageToMax = $remainingPercentage / $triggerRange;
        $dynamicDailyAdd = $minDailyAdd + ($maxDailyAdd - $minDailyAdd) * $percentageToMax * 0.8; // 比API任务更保守
        $dynamicDailyAdd = max($dynamicDailyAdd, $minDailyAdd * 0.3); // RPA更保守的保底

        // 选择最小值，确保不超过任何限制
        $finalAddAmount = min(
            $maxCanAdd,           // 不超过最大比例限制
            $idealAddAmount,      // 不超过理想添加量（已修复）
            $dynamicDailyAdd      // 不超过动态每日限制
        );

        $finalAddAmount = max(0, $finalAddAmount); // 确保不为负数

        // 记录RPA详细计算过程（便于调试）
        echo "RPA广告主 {$item['advertiser_id']} 动态计算详情:</br>";
        echo "- 当前比例: " . round($currentPercentage, 2) . "%</br>";
        echo "- 剩余空间: " . round($remainingPercentage, 2) . "%</br>";
        echo "- 目标比例: {$targetPercentage}%</br>";
        echo "- 客户数: {$cusNum}, 公司数: {$companyNum}</br>";
        echo "- 最大可添加: " . round($maxCanAdd, 2) . "</br>";
        echo "- 理想添加量: " . round($idealAddAmount, 2) . "</br>";
        echo "- 动态每日限制: " . round($dynamicDailyAdd, 2) . "</br>";
        echo "- 最终添加: {$finalAddAmount}个</br>";

        // 显示RPA使用的策略类型
        if ($remainingPercentage > 0 && $idealAddAmount > 0) {
            if ($remainingPercentage > 50) {
                echo "📊 RPA策略: 正常保底策略（剩余空间充足）</br>";
            } elseif ($remainingPercentage > 20) {
                echo "⚡ RPA策略: 保守活跃度策略（剩余空间中等）</br>";
            } elseif ($remainingPercentage > 0) {
                echo "🔥 RPA策略: 最小活跃度策略（剩余空间很少）</br>";
            }
        }
        echo "---</br>";

        return (int)ceil($finalAddAmount);
    }

    /**
     * 获取RPA任务的最小活跃度操作次数
     * 即使达到最大比例，也要保持账户活跃度
     */
    private function getRpaMinActiveOperations($config)
    {
        // 从配置中获取活跃度参数
        $minActive = $config['min_active_operations'] ?? 1;
        $maxActive = $config['max_active_operations'] ?? 15;

        // 智能活跃度策略（RPA任务更保守）
        $activityConfig = include APP_PATH . 'config/dynamic_ratio_config.php';
        $activityStrategy = $activityConfig['activity_strategy'];

        if (!$activityStrategy['enable_min_activity']) {
            return 0; // 如果禁用活跃度策略，返回0
        }

        // RPA任务的基础操作次数（比API任务保守）
        $baseOperations = rand($minActive, $maxActive);

        // RPA任务在工作时间也保持相对保守
        $currentHour = (int)date('H');
        if ($currentHour >= 9 && $currentHour <= 18) {
            // 工作时间，轻微增加
            $baseOperations = min($maxActive, $baseOperations + rand(0, 2));
        } elseif ($currentHour >= 22 || $currentHour <= 6) {
            // 深夜时间，更加保守
            $baseOperations = max($minActive, $baseOperations - rand(1, 3));
        }

        return $baseOperations;
    }

    /**
     * 批量调用对象列表API
     */
    private function callBatchObjListApi($requests, $listApiUrl)
    {
        $results = [];

        foreach ($requests as $request) {
            $rep = sendApiRes($listApiUrl, [
                $request['advertiser_id'],
                $request['need_num']
            ]);

            if (isset($rep['msg'])) {
                echo $rep['msg'];
                $results[] = [];
                continue;
            }

            $results[] = $rep['data'] ?? [];
        }

        return $results;
    }

    /**
     * 完成处理，清理缓存
     */
    private function finishProcessing($cacheKey)
    {
        Cache::rm($cacheKey . '_page');
        echo "全部处理完了";
    }

    public function getNotLabObjList($user_name)
    {
        $this->processObjList(
            $user_name,
            self::CACHE_TYPE_NOT_LAB,
            API_BASE_URL."/getOptCountCollectionApi/",
            API_BASE_URL."/getRpaObjListApi/",
            'stand',
            "app\api\controller\AutoUpdateObjName",
            "autoUpdateObjNameWeb"
        );
    }

    public function getGlobalObjList($user_name)
    {
        $this->processObjList(
            $user_name,
            self::WEB_GLOBAL_KEY,
            API_BASE_URL."/getGlobalOptCountCollectionApi/",
            API_BASE_URL."/getGlobalObjListApi/",
            'global',
            "app\api\controller\AutoUpdateGlobalObjName",
            "autoUpdateObjNameGlobalWeb"
        );
    }



}