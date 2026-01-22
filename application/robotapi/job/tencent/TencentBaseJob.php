<?php

namespace app\robotapi\job\tencent;

use think\Exception;
use think\Db;
use app\robotapi\model\TencentStore;

/**
 * 腾讯相关任务基类
 */
class TencentBaseJob
{
    /**
     * 写日志
     */
    protected function writeLog($type, $message, $context = [])
    {
        $logDir = __DIR__ . '/log';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/transfer_' . date('Y-m-d') . '.log';
        $logData = [
            'time' => date('Y-m-d H:i:s'),
            'type' => $type,
            'message' => $message,
            'context' => $context
        ];
        @file_put_contents($logFile, json_encode($logData, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * 格式化接口错误消息
     */
    protected function formatErrorMessage($transfer)
    {
        $message_cn = $transfer['message_cn'] ?? $transfer['message'] ?? '未知错误';
        if (strpos($message_cn, 'traceId:') !== false) {
            $message_cn = trim(substr($message_cn, 0, strpos($message_cn, 'traceId:')));
        }
        return $message_cn;
    }

    /**
     * 扣除费用（转入场景）
     * @param array $data 转账记录数据
     * @throws Exception
     */
    protected function deductingFees($data)
    {
        if ($data["transfer_direction"] == 1) {
            // 转入
            $store_model = new TencentStore();
            // 添加行锁，防止并发问题
            $sql = $store_model->where(["store_id" => ["=", $data['store_id']]]);
            $prefix = $data["account_type"] == 1 ? "public_" : "private_";

            if ($data["deduction_balance"] > 0) {
                $sql->where(["$prefix"."money_tencent" => [">=", $data["deduction_balance"]]])->dec("$prefix"."money_tencent", $data["deduction_balance"]);
            }
            if ($data["deduction_credit_limit"] > 0) {
                $sql->where(["$prefix"."credit_limit_tencent" => [">=", $data["deduction_credit_limit"]]])->dec("$prefix"."credit_limit_tencent", $data["deduction_credit_limit"]);
                $sql->inc("$prefix"."spending_credit_limit_tencent", $data["deduction_credit_limit"]);
            }

            if (!$sql->update(["update_time" => time()])) {
                throw new Exception("扣款失败");
            }
        }
    }

    /**
     * 恢复扣款（接口失败时的补偿回滚）
     * @param array $data 转账记录数据
     * @throws Exception
     */
    protected function restoreDeduction($data)
    {
        if ($data["transfer_direction"] == 1) {
            // 转入
            $store_model = new TencentStore();
            // 添加行锁，防止并发问题
            $sql = $store_model->where(["store_id" => ["=", $data['store_id']]])->lock(true);
            $prefix = $data["account_type"] == 1 ? "public_" : "private_";

            if ($data["deduction_balance"] > 0) {
                $sql->inc("$prefix"."money_tencent", $data["deduction_balance"]);
            }
            if ($data["deduction_credit_limit"] > 0) {
                $sql->inc("$prefix"."credit_limit_tencent", $data["deduction_credit_limit"]);
                $sql->dec("$prefix"."spending_credit_limit_tencent", $data["deduction_credit_limit"]);
            }

            if (!$sql->update(["update_time" => time()])) {
                throw new Exception("恢复扣款失败");
            }
        }
    }


}
