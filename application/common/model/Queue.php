<?php

namespace app\common\model;

use think\Db;
use think\Model;

/**
 * 队列任务模型
 */
class Queue extends Model
{
    protected $name = 'queue_record';
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';


    /**
     * 添加任务
     * @param string $jobName 任务名
     * @param string $jobHandlerClassName 任务类名
     * @param string $jobQueueName 队列名
     * @param array $jobData 任务数据
     * @param string $remark 备注
     * @return bool
     */
    public static function addQueue(string $jobName ,string $jobHandlerClassName, string $jobQueueName, array $jobData,string $RelationTable='',string $remark = "")
    {
        $isPushed = \think\Queue::push($jobHandlerClassName, $jobData, $jobQueueName);
        // database 驱动时，返回值为 1|false  ;   redis 驱动时，返回值为 随机字符串|false
        if ($isPushed !== false) {
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

    public function rebuildOne($id)
    {
        $jobData = $this->where(['id'=>$id])->find()->toArray();

        $res = $this->addQueue($jobData['job_name'],
            $jobData['class_name'],
            $jobData['queue_name'],
            json_decode($jobData['job_data'],true),
            $jobData['relation_table'],
            $jobData['remark']);
        if($res){
            $this->where(['id'=>$id])->delete();
            return true;
        }
        return false;
    }
}