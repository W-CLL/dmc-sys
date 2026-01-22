<?php

namespace app\robotapi\job\tencent;

use app\robotapi\model\QueueRobot;
use think\Exception;
use think\Db;
use app\robotapi\model\TransferFailure;
use app\robotapi\model\TencentTransferLog;
use app\robotapi\model\TencentWalletTransferLog;

/**
 * 处理转账失败记录的定时任务
 */
class RetryTransferFailure
{
    /**
     * 处理失败的转账记录
     */
    public function doJob($data)
    {
        $this->writeLog('INFO', '开始处理失败的转账记录');

        // 查询待重试的记录
        $failures = TransferFailure::where([
            'status' => TransferFailure::STATUS_PENDING,
            'retry_count' => ['<', TransferFailure::MAX_RETRY]
        ])
        ->order('create_time asc')
        ->limit(100)  // 每次最多处理100条
        ->select();

        if (empty($failures)) {
            $this->writeLog('INFO', '没有需要处理的失败记录');
            return true;
        }

        $successCount = 0;
        $failedCount = 0;

        foreach ($failures as $failure) {
            $this->writeLog('INFO', '处理失败记录', [
                'id' => $failure['id'],
                'type' => $failure['type'],
                'transfer_records_id' => $failure['transfer_records_id'],
                'retry_count' => $failure['retry_count']
            ]);

            try {
                // 增加重试次数
                TransferFailure::incrementRetry($failure['id']);

                // 重试更新
                $result = $this->retryUpdate($failure);

                if ($result) {
                    // 标记为成功
                    TransferFailure::markSuccess($failure['id']);
                    $this->writeLog('INFO', '重试成功', ['id' => $failure['id']]);
                    $successCount++;
                } else {
                    // 检查是否超过重试次数
                    if ($failure['retry_count'] >= TransferFailure::MAX_RETRY - 1) {
                        TransferFailure::markFailed($failure['id']);
                        $this->writeLog('ERROR', '重试次数超过限制，标记为失败', ['id' => $failure['id']]);
                        $failedCount++;
                        
                        // 发送告警通知
                        $this->sendAlert($failure);
                    }
                }
            } catch (Exception $e) {
                $this->writeLog('ERROR', '重试失败', [
                    'id' => $failure['id'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->writeLog('INFO', '处理完成', [
            'total' => count($failures),
            'success' => $successCount,
            'failed' => $failedCount
        ]);

        return true;
    }

    /**
     * 重试更新订单号
     */
    private function retryUpdate($failure)
    {
        Db::startTrans();
        try {
            if ($failure['type'] == TransferFailure::TYPE_UPDATE_ORDER_UID) {
                // 判断是哪个表的记录
                $record = TencentTransferLog::where('id', $failure['transfer_records_id'])->find();
                if ($record) {
                    $model = new TencentTransferLog();
                } else {
                    $model = new TencentWalletTransferLog();
                }

                $result = $model->where('id', $failure['transfer_records_id'])->update([
                    'order_uid' => $failure['order_uid'],
                    'record' => $failure['record'],
                    'update_time' => time()
                ]);

                if (!$result) {
                    throw new Exception('更新订单号失败');
                }

                // 添加后续任务
                if ($record) {
                    $queue = new QueueRobot();
                    $data = json_decode($failure['data'], true);
                    $queue->addQueue('腾讯广告【转账后续操作】', 'app\robotapi\job\RobotBaseJob', 'robotBaseJob',
                        [
                            "job_class" => '\app\robotapi\job\tencent\SubsequentOperations',
                            "transfer_records_id" => $failure['transfer_records_id'],
                            "handle" => isset($data['sub_wallet_id']) ? "TencentWalletTransfer" : "TencentTransfer",
                            "callback_data" => isset($data['callback_data']) ? $data['callback_data'] : [],
                        ]
                    );
                }

                Db::commit();
                return true;
            }
        } catch (Exception $e) {
            Db::rollback();
            throw $e;
        }

        return false;
    }

    /**
     * 发送告警通知
     */
    private function sendAlert($failure)
    {
        // 这里可以集成钉钉、企业微信等通知方式
        $message = "转账记录更新失败，已超过最大重试次数：\n";
        $message .= "记录ID: {$failure['id']}\n";
        $message .= "转账记录ID: {$failure['transfer_records_id']}\n";
        $message .= "订单号: {$failure['order_uid']}\n";
        $message .= "错误信息: {$failure['error']}\n";
        $message .= "重试次数: {$failure['retry_count']}\n";
        $message .= "创建时间: " . date('Y-m-d H:i:s', $failure['create_time']) . "\n";
        $message .= "请人工介入处理！";

        $this->writeLog('ALERT', $message);
        // TODO: 调用告警接口发送通知
    }

    /**
     * 写日志
     */
    private function writeLog($type, $message, $context = [])
    {
        $logDir = __DIR__ . '/log';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/retry_failure_' . date('Y-m-d') . '.log';
        $logData = [
            'time' => date('Y-m-d H:i:s'),
            'type' => $type,
            'message' => $message,
            'context' => $context
        ];
        file_put_contents($logFile, json_encode($logData, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
