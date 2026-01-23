<?php

namespace app\robotapi\model;

use think\Model;

/**
 * 转账失败记录表
 * 用于记录接口成功但数据库更新失败的情况
 */
class TransferFailure extends Model
{
    protected $name = 'transfer_failure';
    
    // 失败类型
    const TYPE_UPDATE_ORDER_UID = 1;  // 更新订单号失败
    const TYPE_INSERT_RECORD = 2;      // 插入记录失败
    
    // 重试状态
    const STATUS_PENDING = 0;   // 待重试
    const STATUS_SUCCESS = 1;    // 成功
    const STATUS_FAILED = 2;     // 失败（超过重试次数）
    
    // 最大重试次数
    const MAX_RETRY = 5;
    
    /**
     * 记录失败
     */
    public static function recordFailure($type, $transferRecordsId, $orderUid, $record, $error, $data = [])
    {
        try {
            return self::create([
                'type' => $type,
                'transfer_records_id' => $transferRecordsId,
                'order_uid' => $orderUid,
                'record' => json_encode($record, JSON_UNESCAPED_UNICODE),
                'error' => $error,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'retry_count' => 0,
                'status' => self::STATUS_PENDING,
                'create_time' => time(),
            ]);
        } catch (\Exception $e) {
            // 记录失败时，写入日志避免程序中断
            error_log("TransferFailure::recordFailure 失败: " . $e->getMessage() . 
                      ", Params: type={$type}, transferRecordsId={$transferRecordsId}, orderUid={$orderUid}");
            return false;
        }
    }
    
    /**
     * 标记为成功
     */
    public static function markSuccess($id)
    {
        return self::where('id', $id)->update([
            'status' => self::STATUS_SUCCESS,
            'update_time' => time()
        ]);
    }
    
    /**
     * 增加重试次数
     */
    public static function incrementRetry($id)
    {
        return self::where('id', $id)->inc('retry_count')->update([
            'update_time' => time()
        ]);
    }
    
    /**
     * 标记为失败（超过重试次数）
     */
    public static function markFailed($id)
    {
        return self::where('id', $id)->update([
            'status' => self::STATUS_FAILED,
            'update_time' => time()
        ]);
    }
}
