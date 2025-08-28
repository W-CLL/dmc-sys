<?php

namespace app\index\controller;

use jlqc\FundManagement;
use think\Cache;
use think\Db;

class HandleMaterialTask
{
    /**
     * 处理素材调控任务 - 批量禁用指定前缀的调控任务
     * @param string $user_name 用户名，用于缓存标识
     * @param string $task_name_prefix 任务名称前缀，默认为 '起量_202508'
     * @param int $batch_size 每页处理数量，默认100
     * @return array 返回处理结果
     */
    public function handleMaterialTask(string $user_name, string $task_name_prefix = '起量_2025', int $batch_size = 100): array
    {
        try {
            // 参数验证
            if (empty($user_name)) {
                throw new \Exception('用户名不能为空');
            }

            $startTime = microtime(true);
            $this->logInfo("开始处理素材调控任务", ['user_name' => $user_name, 'prefix' => $task_name_prefix]);

            // 获取分页信息和队列数据
            $pageKey = Cache::get($user_name . '_handle_material_page', 0);
            $processedData = $this->getQueueRecordsData($user_name);

            if (empty($processedData)) {
                $this->logError("没有找到需要处理的队列数据");
                echo "没有找到需要处理的数据";
                return ['success' => false, 'message' => '没有数据需要处理'];
            }

            $totalCount = count($processedData);
            echo "共{$totalCount}条需要处理\n";

            // 分页处理
            $chunks = array_chunk($processedData, $batch_size, true);

            // 检查是否处理完成
            if (empty($chunks[$pageKey])) {
                $this->cleanupCache($user_name);
                $message = "全部处理完了";
                echo $message;
                $this->logInfo($message, ['total_processed' => $totalCount]);
                return ['success' => true, 'message' => $message, 'total_processed' => $totalCount];
            }

            // 处理当前页数据
            $result = $this->processCurrentPageTasks($chunks[$pageKey], $task_name_prefix, $pageKey);

            // 更新页码
            $nextPageKey = $pageKey + 1;
            Cache::set($user_name . '_handle_material_page', $nextPageKey);

            $executionTime = round(microtime(true) - $startTime, 2);
            $message = "第{$pageKey}页处理完成，处理了{$result['processed_count']}条记录，耗时{$executionTime}秒";
            echo $message;

            $this->logInfo("页面处理完成", [
                'page' => $pageKey,
                'processed' => $result['processed_count'],
                'success' => $result['success_count'],
                'failed' => $result['failed_count'],
                'execution_time' => $executionTime
            ]);

            return [
                'success' => true,
                'message' => $message,
                'page' => $pageKey,
                'processed_count' => $result['processed_count'],
                'success_count' => $result['success_count'],
                'failed_count' => $result['failed_count'],
                'execution_time' => $executionTime
            ];

        } catch (\Exception $e) {
            $errorMessage = "处理素材调控任务异常: " . $e->getMessage();
            $this->logError($errorMessage, ['exception' => $e->getTraceAsString()]);
            echo $errorMessage;
            return ['success' => false, 'message' => $errorMessage];
        }
    }

    /**
     * 获取队列记录数据
     * @param string $user_name
     * @return array
     */
    private function getQueueRecordsData(string $user_name): array
    {
        $cacheKey = $user_name . 'job_data_list';
        $queueRecordsCache = Cache::get($cacheKey);

        if ($queueRecordsCache) {
            $queueRecords = unserialize($queueRecordsCache);
        } else {
            $queueRecords = Db::name('queue_record')
                ->where(['queue_name' => 'autoUpdateGlobalObjMaterial'])
                ->where(['create_time'=>['between',strtotime(date('Y-m-d 00:00:00')),time()]])
                ->column('job_data');

            if (empty($queueRecords)) {
                return [];
            }

            Cache::set($cacheKey, serialize($queueRecords), 3600); // 缓存1小时
        }

        // 处理队列数据
        $finallyData = [];
        foreach ($queueRecords as $item) {
            try {
                $data = json_decode($item, true);
                if (!$data || !isset($data['obj_id']) || !isset($data['adv_id'])) {
                    $this->logError("队列数据格式错误", ['data' => $item]);
                    continue;
                }

                // 解析 obj_id|material_id 格式
                $objIdParts = explode('|', $data['obj_id']);
                if (count($objIdParts) >= 2) {
                    $objId = $objIdParts[0];
                    $finallyData[$objId] = $data['adv_id'];
                }
            } catch (\Exception $e) {
                $this->logError("解析队列数据异常", ['data' => $item, 'error' => $e->getMessage()]);
            }
        }

        return $finallyData;
    }

    /**
     * 处理当前页的任务
     * @param array $pageData
     * @param string $taskNamePrefix
     * @param int $pageKey
     * @return array
     */
    private function processCurrentPageTasks(array $pageData, string $taskNamePrefix, int $pageKey): array
    {
        $processedCount = 0;
        $successCount = 0;
        $failedCount = 0;

        foreach ($pageData as $objId => $advId) {
            try {
                $result = $this->processControlTask($advId, $objId, $taskNamePrefix);
                $processedCount += $result['processed'];
                $successCount += $result['success'];
                $failedCount += $result['failed'];

            } catch (\Exception $e) {
                $failedCount++;
                $this->logError("处理单个任务异常", [
                    'obj_id' => $objId,
                    'adv_id' => $advId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return [
            'processed_count' => $processedCount,
            'success_count' => $successCount,
            'failed_count' => $failedCount
        ];
    }

    /**
     * 处理单个广告主的调控任务
     * @param int $advId 广告主ID
     * @param int $objId 计划ID
     * @param string $taskNamePrefix 任务名称前缀
     * @return array
     */
    private function processControlTask(int $advId, int $objId, string $taskNamePrefix): array
    {
        $processed = 0;
        $success = 0;
        $failed = 0;

        try {
            // 构建查询参数
            $params = [
                'advertiser_id' => (int)$advId,
                'marketing_goal' => "VIDEO_PROM_GOODS",
                'ad_id' => (int)$objId,
                'start_time' => date('Y-m-d 00:00:00'),
                'end_time' => date('Y-m-d 23:59:59'),
                'scene' => "MATERIAL_ADD_BUDGET",
                'filtering' => json_encode([
                    'search_keyword' => "起量_",
                    'task_status' => 'PROCESSING',
                ]),
                'page_size' => 100
            ];

            // 获取调控任务列表
            $res = FundManagement::get_global_control_task_list($params);

            if (!$res || !isset($res['data'])) {
                $this->logError("获取调控任务列表失败：API返回数据格式错误", ['params' => $params]);
                return ['processed' => 0, 'success' => 0, 'failed' => 1];
            }

            if ($res['data']['code'] != 0) {
                $this->logError("获取调控任务列表失败", [
                    'params' => $params,
                    'response' => $res
                ]);
                return ['processed' => 0, 'success' => 0, 'failed' => 1];
            }

            $taskList = $res['data']['data']['task_list'] ?? [];

            if (empty($taskList)) {
                return ['processed' => 0, 'success' => 0, 'failed' => 0];
            }

            // 处理匹配的任务
            foreach ($taskList as $task) {
                if (strpos($task['name'], $taskNamePrefix) === 0) {
                    $processed++;

                    $updateParams = [
                        'advertiser_id' => (int)$advId,
                        'task_ids' => [$task['id']],
                        'opt_type' => 'DISABLE'
                    ];

                    $updateRes = FundManagement::update_global_control_task($updateParams);

                    if ($this->isUpdateSuccess($updateRes)) {
                        $success++;
                        $this->logInfo("成功禁用调控任务", [
                            'task_id' => $task['id'],
                            'task_name' => $task['name'],
                            'adv_id' => $advId,
                            'obj_id' => $objId
                        ]);
                    } else {
                        $failed++;
                        $this->logError("禁用调控任务失败", [
                            'task_id' => $task['id'],
                            'task_name' => $task['name'],
                            'adv_id' => $advId,
                            'obj_id' => $objId,
                            'response' => $updateRes
                        ]);
                    }
                }
            }

        } catch (\Exception $e) {
            $failed++;
            $this->logError("处理调控任务异常", [
                'adv_id' => $advId,
                'obj_id' => $objId,
                'error' => $e->getMessage()
            ]);
        }

        return ['processed' => $processed, 'success' => $success, 'failed' => $failed];
    }

    /**
     * 判断更新任务是否成功
     * @param array $response
     * @return bool
     */
    private function isUpdateSuccess(array $response): bool
    {
        return isset($response['data']['code']) && $response['data']['code'] == 0;
    }

    /**
     * 清理缓存
     * @param string $user_name
     */
    private function cleanupCache(string $user_name)
    {
        Cache::rm($user_name . '_handle_material_page');
        Cache::rm($user_name . 'job_data_list');
    }

    /**
     * 记录信息日志
     * @param string $message
     * @param array $context
     */
    private function logInfo(string $message, array $context = [])
    {
        $logData = [
            'level' => 'INFO',
            'message' => $message,
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s'),
            'method' => __METHOD__
        ];

        // 这里可以根据项目的日志系统进行调整
        error_log("MaterialTask INFO: " . json_encode($logData, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 记录错误日志
     * @param string $message
     * @param array $context
     */
    private function logError(string $message, array $context = [])
    {
        $logData = [
            'level' => 'ERROR',
            'message' => $message,
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s'),
            'method' => __METHOD__
        ];

        // 这里可以根据项目的日志系统进行调整
        error_log("MaterialTask ERROR: " . json_encode($logData, JSON_UNESCAPED_UNICODE));
    }
}