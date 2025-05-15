<?php

namespace app\common\model;

use think\Db;
use think\Model;

/**
 * 队列平均任务模型
 */
class QueueAvg extends Model
{
    protected $name = 'queue_record_avg';
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';


    /**
     * 添加任务
     * @param string $jobName 任务名
     * @param string $jobHandlerClassName 任务类名(大小写必须明确)
     * @param string $jobQueueName 队列名
     * @param array $jobData 任务数据
     * @param string $remark 备注
     * @return bool
     */
    public function addQueue(string $jobName, string $jobHandlerClassName, string $jobQueueName, array $jobData, string $RelationTable = '', string $remark = "")
    {
        return $this->addToQueue($jobName, $jobHandlerClassName, $jobQueueName, $jobData, $RelationTable, $remark, false);
    }



    /**
     * 重启任务
     * @param string $jobHandlerClassName 任务类名(大小写必须明确)
     * @param string $jobQueueName 队列名
     * @param array  $jobData 任务数据
     * @param int    $queueId 队列表对应id
     * @return bool
     */
    public function rebootQueue(string $jobHandlerClassName, string $jobQueueName, array $jobData, int $queueId)
    {
        return $this->reboot($jobHandlerClassName, $jobQueueName, $jobData, $queueId);
    }


    /**
     * 添加延时任务
     * @param string $jobName 任务名
     * @param string $jobHandlerClassName 任务类名(大小写必须明确)
     * @param string $jobQueueName 队列名
     * @param array $jobData 任务数据
     * @param int $delaySecond 延迟多少秒
     * @param string $RelationTable
     * @param string $remark 备注
     * @return bool
     */
    public function addDelayQueue(string $jobName, string $jobHandlerClassName, string $jobQueueName, array $jobData, int $delaySecond, string $RelationTable = '', string $remark = ""): bool
    {
        return $this->addToQueue($jobName, $jobHandlerClassName, $jobQueueName, $jobData, $RelationTable, $remark ?: '延迟' . $delaySecond . '秒执行', true, $delaySecond);
    }

    /**
     * 任务添加公共函数
     * @param string $jobName
     * @param string $jobHandlerClassName
     * @param string $jobQueueName
     * @param array $jobData
     * @param string $RelationTable
     * @param string $remark
     * @param bool $isDelay
     * @param int $delaySecond
     * @return bool
     */
    private function addToQueue(
        string $jobName,
        string $jobHandlerClassName,
        string $jobQueueName,
        array  $jobData,
        string $RelationTable = '',
        string $remark = '',
        bool   $isDelay = false,
        int    $delaySecond = 0
    ): bool
    {
        try {
            // 根据是否是延迟队列，选择对应的推送方法
            if ($isDelay) {
                $isPushed = \think\Queue::later($delaySecond, $jobHandlerClassName, $jobData, $jobQueueName);
            } else {
                $isPushed = \think\Queue::push($jobHandlerClassName, $jobData, $jobQueueName);
            }
        } catch (\Exception $e) {
            \think\Log::error('add_queue_error：' . $e->getMessage());
            return false;
        }
        // database 驱动时，返回值为 1|false  ; redis 驱动时，返回值为 随机字符串|false
        if ($isPushed) {
            $queueModel = new static();
            $queueModel->job_name = $jobName;
            $queueModel->job_id = $isPushed;
            $queueModel->class_name = $jobHandlerClassName;
            $queueModel->queue_name = $jobQueueName;
            $queueModel->relation_table = $RelationTable;
            $queueModel->job_data = json_encode($jobData);
            $queueModel->remark = $remark;
            $queueModel->save();
            return true;
        } else {
            return false;
        }
    }



    /**
     * 重新插入redis队列
     * @param string $jobHandlerClassName
     * @param string $jobQueueName
     * @param array $jobData
     * @param int $queueId
     * @return bool
     */
    private function reboot(string $jobHandlerClassName, string $jobQueueName, array $jobData, int $queueId):  bool{
        try {
            $isPushed = \think\Queue::push($jobHandlerClassName, $jobData, $jobQueueName);
        } catch (\Exception $e) {
            \think\Log::error('reboot_error：' . $e->getMessage());
            return false;
        }
        if ($isPushed) {
            $queueModel = $this->get($queueId);
            $queueModel->job_id = $isPushed;
            $queueModel->status = 0;
            $queueModel->msg = NULL;
            $queueModel->update_time = time();
            $queueModel->save();
            return true;
        }else{
            return false;
        }
    }


    public function rebuildOne($id)
    {
        $jobData = $this->where(['id' => $id])->find()->toArray();

        $res = $this->addQueue($jobData['job_name'],
            $jobData['class_name'],
            $jobData['queue_name'],
            json_decode($jobData['job_data'], true),
            $jobData['relation_table'],
            $jobData['remark']);
        if ($res) {
            $this->where(['id' => $id])->delete();
            return true;
        }
        return false;
    }


    public function rebootOne($id){
        $jobData = $this->where(['id' => $id])->find()->toArray();
        $res = $this->rebootQueue($jobData['class_name'],
            $jobData['queue_name'],
            json_decode($jobData['job_data'], true),
            $id);
        if ($res) {
            return true;
        }
        return false;
    }
}