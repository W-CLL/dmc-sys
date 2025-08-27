<?php

namespace app\job;

use app\api\controller\Oauth2;
use app\common\model\Queue;
use app\common\model\MaterialControlTaskRecord;
use jlqc\FundManagement;
use think\Exception;
use think\queue\Job;

/**
 * 全域计划素材自动更新任务
 */
class AutoUpdateGlobalObjMaterial
{

    public function fire(Job $job, $data)
    {
        $queueModel = new Queue();
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueData = $queueModel->where('job_id', $jobId)->lock(true)->find();
        if (!$queueData) {
            $job->delete();
            return '';
        }
        if ($queueData['status'] != 0) {
            $job->delete();
            return '';
        }
        try {
            list($isJobDone, $msg) = $this->doJob($data, $jobId);
            if ($isJobDone) {
                // 任务成功，更新状态为1
                $queueData->save(['id' => $queueData['id'], 'status' => 1, 'msg' => $msg]);
            } else {
                // 🔧 任务失败，直接更新状态为2，不进行重试
                $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => $msg]);
            }
            // 无论成功还是失败，都删除队列任务
            $job->delete();
            return '';
        } catch (Exception $e) {
            // 🔧 修复异常处理逻辑
            if ($e->getMessage() == "access_token已过期") {
                // access_token过期，尝试更新token并重启任务
                $update_token = new Oauth2();
                $update_token->access_token_save();
                $queueModel->rebootOne($queueData['id']);
            } else {
                // 其他异常，标记任务失败
                $errorMsg = $e->getMessage() ?: '未知异常';
                $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => "异常失败: " . $errorMsg]);
            }
            $job->delete();
            return '';
        }
    }

    /**
     * 执行素材任务
     * @param array $data 任务数据
     * @throws Exception
     */
    public function doJob($data, $queueJobId)
    {
        $taskRecord = null;

        // 处理延迟参数
        if (isset($data['delay']) && $data['delay'] > 0) {
            sleep($data['delay']);
        }
        try {
            $advId = $data['adv_id'];
            $objIdWithMaterial = $data['obj_id']; // 格式：obj_id|material_id

            // 解析obj_id和material_id
            $parts = explode('|', $objIdWithMaterial);
            if (count($parts) !== 2) {
                throw new Exception("无效的任务数据格式: {$objIdWithMaterial}");
            }

            $objId = $parts[0];
            $materialId = $parts[1];

            // 检查是否已有正在进行的任务
            if (MaterialControlTaskRecord::hasRunningTask($advId, $objId, $materialId)) {
                return [true, "该素材已有正在进行的调控任务，跳过执行"];
            }

            // 1. 创建任务记录
            $taskRecord = MaterialControlTaskRecord::createRecord($advId, $objId, $materialId, $queueJobId);
            // 2. 创建调控任务
            $taskResult = $this->createMaterialControlTask($advId, $objId, $materialId);
            if ($taskResult && isset($taskResult['success']) && $taskResult['success']) {
                // 创建成功，更新记录
                $taskRecord->updateCreateResult(
                    true,
                    $taskResult['task_id'] ?? null,
                    $taskResult['task_name'] ?? null,
                    $taskResult
                );
                // 3. 等待1秒后停止任务
                sleep(1);
                // 4. 停止调控任务
                $stopResult = $this->stopMaterialControlTask($advId, $taskResult['task_id']);
                if ($stopResult && isset($stopResult['success']) && $stopResult['success']) {
                    // 停止成功，任务完全成功
                    $taskRecord->updateStopResult(true, $stopResult);
                    return [true, "素材调控任务完全成功"];
                } else {
                    // 停止失败
                    $taskRecord->updateStopResult(false, $stopResult, "停止任务失败");
                    return [false, "素材调控任务停止失败"];
                }
            } else {
                // 创建失败，更新记录
                $errorMsg = $taskResult['message'] ?? '创建任务失败';
                $taskRecord->updateCreateResult(false, null, null, $taskResult, $errorMsg);
                return [false, "素材调控任务创建失败: " . $errorMsg];
            }

        } catch (Exception $e) {
            // 如果有任务记录，更新错误信息
            if ($taskRecord) {
                $taskRecord->error_message = $e->getMessage();
                $taskRecord->save();
            }
            return [false, "任务执行异常: " . $e->getMessage()];
        }
    }

    /**
     * 创建素材调控任务
     */
    private function createMaterialControlTask($advId, $objId, $materialId)
    {
        $params = [
            'advertiser_id' => (int)$advId,
            'ad_id' => (int)$objId,
            'name' => '起量_' . date("YmdHis"),
            'scene' => "MATERIAL_ADD_BUDGET",
            'budget' => 100,//价格
            'duration' => 24,//时间
            'material_ids' => [(int)$materialId],
        ];

        // 模拟API调用
        return $this->callQianchuanApi('create_control_task', $params);
    }

    /**
     * 停止素材调控任务
     */
    private function stopMaterialControlTask($advId, $taskId)
    {
        // 这里调用千川API停止素材调控任务
        $params = [
            'advertiser_id' => (int)$advId,
            'task_ids' => [$taskId],
            'opt_type' => 'DISABLE'
        ];

        // 模拟API调用
        return $this->callQianchuanApi('stop_control_task', $params);
    }

    /**
     * 调用千川API
     */
    private function callQianchuanApi($endpoint, $params): array
    {
        try {
            if ($endpoint === 'create_control_task') {
                // 调用真实的千川API
                $res = FundManagement::create_global_control_task($params);
                echo "创建\n";
                dump($res);
                // 处理API返回结果
                // code=0 且 message="ok" 表示成功，其他都是失败
                if ($res && isset($res['code']) && $res['code'] == 0 &&
                    isset($res['message']) && strtolower($res['message']) == 'ok') {

                    return [
                        'success' => true,
                        'task_id' => $res['data']['id'] ?? ('task_' . time() . '_' . rand(1000, 9999)),
                        'task_name' => $params['name'] ?? null,
                        'message' => '调控任务创建成功',
                        'request_id' => $res['request_id'] ?? null,
                        'api_response' => $res
                    ];
                } else {
                    // 失败情况：code不为0或者message不为ok
                    $errorMsg = '调控任务创建失败';
                    if (isset($res['code']) && $res['code'] != 0) {
                        $errorMsg .= " (错误码: {$res['code']})";
                    }
                    if (isset($res['message']) && $res['message'] != 'ok') {
                        $errorMsg .= " - {$res['message']}";
                    }

                    return [
                        'success' => false,
                        'message' => $errorMsg,
                        'error_code' => $res['code'] ?? null,
                        'api_response' => $res
                    ];
                }

            } elseif ($endpoint === 'stop_control_task') {
                // 调用真实的千川API停止任务
                $res = FundManagement::update_global_control_task($params);
                echo "更新\n";
                dump($res);

                // 处理API返回结果
                // code=0 且 message="ok" 表示成功
                if ($res && isset($res['code']) && $res['code'] == 0 &&
                    isset($res['message']) && strtolower($res['message']) == 'ok') {

                    // 检查具体的任务更新结果
                    $data = $res['data'] ?? [];
                    $taskIds = $data['task_ids'] ?? [];
                    $errors = $data['errors'] ?? [];

                    if (!empty($taskIds) && empty($errors)) {
                        // 完全成功：有成功的任务ID且没有错误
                        return [
                            'success' => true,
                            'message' => '调控任务停止成功',
                            'task_ids' => $taskIds,
                            'request_id' => $res['request_id'] ?? null,
                            'api_response' => $res
                        ];
                    } elseif (!empty($errors)) {
                        // 部分失败或完全失败：有错误信息
                        $errorMessages = [];
                        foreach ($errors as $error) {
                            $taskId = $error['task_id'] ?? 'unknown';
                            $errorMsg = $error['error_message'] ?? 'unknown error';
                            $errorMessages[] = "任务{$taskId}: {$errorMsg}";
                        }

                        return [
                            'success' => false,
                            'message' => '调控任务停止失败: ' . implode('; ', $errorMessages),
                            'task_ids' => $taskIds,
                            'errors' => $errors,
                            'request_id' => $res['request_id'] ?? null,
                            'api_response' => $res
                        ];
                    } else {
                        // 异常情况：API返回成功但没有具体结果
                        return [
                            'success' => false,
                            'message' => '调控任务停止失败: API返回成功但无具体结果',
                            'request_id' => $res['request_id'] ?? null,
                            'api_response' => $res
                        ];
                    }
                } else {
                    // API调用失败
                    $errorMsg = '调控任务停止失败';
                    if (isset($res['code']) && $res['code'] != 0) {
                        $errorMsg .= " (错误码: {$res['code']})";
                    }
                    if (isset($res['message']) && $res['message'] != 'ok') {
                        $errorMsg .= " - {$res['message']}";
                    }

                    return [
                        'success' => false,
                        'message' => $errorMsg,
                        'error_code' => $res['code'] ?? null,
                        'api_response' => $res
                    ];
                }
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'API调用异常: ' . $e->getMessage(),
                'exception' => $e->getMessage()
            ];
        }

        return [
            'success' => false,
            'message' => '未知的API端点: ' . $endpoint
        ];
    }


}
