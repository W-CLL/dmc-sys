<?php

namespace app\common\model;

use think\Model;

/**
 * 素材调控任务记录模型
 */
class MaterialControlTaskRecord extends Model
{
    protected $name = 'material_control_task_record';
    protected $autoWriteTimestamp = 'int';
    
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    // 任务状态常量
    const STATUS_STARTED = 0;        // 开始
    const STATUS_CREATE_SUCCESS = 1; // 创建成功
    const STATUS_CREATE_FAILED = 2;  // 创建失败
    const STATUS_COMPLETE_SUCCESS = 3; // 完全成功（创建+停止都成功）
    const STATUS_STOP_FAILED = 4;    // 停止失败

    /**
     * 状态文本映射
     */
    public static function getStatusText($status)
    {
        $statusMap = [
            self::STATUS_STARTED => '开始',
            self::STATUS_CREATE_SUCCESS => '创建成功',
            self::STATUS_CREATE_FAILED => '创建失败',
            self::STATUS_COMPLETE_SUCCESS => '完全成功',
            self::STATUS_STOP_FAILED => '停止失败',
        ];
        
        return $statusMap[$status] ?? '未知状态';
    }

    /**
     * 创建新的任务记录
     */
    public static function createRecord($advId, $objId, $materialId, $queueJobId = null)
    {
        $record = new static();
        $record->adv_id = $advId;
        $record->obj_id = $objId;
        $record->material_id = $materialId;
        $record->queue_job_id = $queueJobId;
        $record->status = self::STATUS_STARTED;
        $record->start_time = time();
        $record->save();
        
        return $record;
    }

    /**
     * 更新创建任务结果
     */
    public function updateCreateResult($success, $taskId = null, $taskName = null, $result = null, $errorMessage = null)
    {
        $this->status = $success ? self::STATUS_CREATE_SUCCESS : self::STATUS_CREATE_FAILED;
        $this->task_id = $taskId;
        $this->task_name = $taskName;
        $this->create_result = is_array($result) ? json_encode($result) : $result;
        $this->task_create_time = time();
        
        if (!$success && $errorMessage) {
            $this->error_message = $errorMessage;
        }
        
        $this->save();
        return $this;
    }

    /**
     * 更新停止任务结果
     */
    public function updateStopResult($success, $result = null, $errorMessage = null)
    {
        // 只有创建成功的任务才能更新停止结果
        if ($this->status !== self::STATUS_CREATE_SUCCESS) {
            return false;
        }
        
        $this->status = $success ? self::STATUS_COMPLETE_SUCCESS : self::STATUS_STOP_FAILED;
        $this->stop_result = is_array($result) ? json_encode($result) : $result;
        $this->stop_time = time();
        
        // 计算总耗时
        if ($this->start_time) {
            $this->total_duration = time() - $this->start_time;
        }
        
        if (!$success && $errorMessage) {
            $this->error_message = $errorMessage;
        }
        
        $this->save();
        return $this;
    }

    /**
     * 获取任务统计信息
     */
    public static function getTaskStats($advId = null, $startDate = null, $endDate = null)
    {
        $query = static::field([
            'status',
            'COUNT(*) as count',
            'AVG(total_duration) as avg_duration'
        ]);
        
        if ($advId) {
            $query->where('adv_id', $advId);
        }
        
        if ($startDate) {
            $query->where('start_time', '>=', strtotime($startDate));
        }
        
        if ($endDate) {
            $query->where('start_time', '<=', strtotime($endDate . ' 23:59:59'));
        }
        
        return $query->group('status')->select();
    }

    /**
     * 获取失败的任务列表
     */
    public static function getFailedTasks($limit = 100)
    {
        return static::where('status', 'in', [self::STATUS_CREATE_FAILED, self::STATUS_STOP_FAILED])
            ->order('create_time desc')
            ->limit($limit)
            ->select();
    }

    /**
     * 检查是否存在正在进行的任务
     */
    public static function hasRunningTask($advId, $objId, $materialId)
    {
        return static::where([
            'adv_id' => $advId,
            'obj_id' => $objId,
            'material_id' => $materialId,
            'status' => self::STATUS_STARTED
        ])->find() ? true : false;
    }

    /**
     * 获取需要监控处理的任务
     * 条件：状态为创建成功，且创建时间超过指定分钟数
     */
    public static function getPendingTasks($minutesAgo = 1)
    {
        $timeThreshold = time() - ($minutesAgo * 60);

        return static::where([
            'status' => self::STATUS_CREATE_SUCCESS,
            'task_create_time' => ['<', $timeThreshold]
        ])
        ->whereNotNull('task_id')
        ->order('task_create_time asc')
        ->select();
    }

    /**
     * 添加监控处理记录到错误信息中
     */
    public function addMonitorRecord($action, $message)
    {
        $monitorInfo = "[监控处理-" . date('Y-m-d H:i:s') . "] {$action}: {$message}";

        // 如果已有错误信息，追加；否则直接设置
        if ($this->error_message) {
            $this->error_message .= "\n" . $monitorInfo;
        } else {
            $this->error_message = $monitorInfo;
        }

        $this->save();
        return $this;
    }
}
