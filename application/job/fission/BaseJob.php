<?php

namespace app\job\fission;

use app\common\model\Queue;
use think\queue\Job;

abstract class BaseJob
{

    /**
     * 队列记录表
     * @var string
     */
    public $queueRecordModelName = "\app\common\model\Queue";
    /**
     * 模板方法，定义了任务执行的流程骨架
     */
    final public function fire(Job $job, $data)
    {
        $job_id = json_decode($job->getRawBody(), true)['id'];
        $queue_model = new $this->queueRecordModelName();
        $queue_data = $queue_model->where('job_id', $job_id)->find();

        try {
            $is_job_done = $this->doJob($data);

            if ($is_job_done) {
                if ($queue_data) {
                    $queue_data->save(['id' => $queue_data['id'], 'status' => 1, 'msg' => "处理完成"]);
                }
                $job->delete();
                return '';
            } else {
                if ($job->attempts() > 3) {
                    $job->delete();
                    return '';
                }
            }
        } catch (\Exception $e) {

            $insert_data = [
                'job_name' => $this->getJobName(),
                'job_id' => $job_id,
                'class_name' => static::class,
                'queue_name' => $this->getQueueName(),
                'relation_table' => '',
                'job_data' => json_encode($data),
                'remark' => '',
                'msg' => $e->getMessage(),
                'status' => 2,
            ];

            if ($queue_data) {
                $queue_data->save(['id' => $queue_data['id'], 'status' => 2, 'msg' => $e->getMessage()]);
                $job->delete();
                return '';
            }

            $queue_model->save($insert_data);
            $job->delete();
            return '';
        }
    }

    /**
     * 子类必须实现的具体任务逻辑
     */
    abstract protected function doJob($data);

    /**
     * 可选：子类可以重写此方法提供不同的作业名称
     */
    protected function getJobName(): string
    {
        return '未知任务';
    }

    /**
     * 可选：子类可以重写此方法提供不同的队列名称
     */
    protected function getQueueName(): string
    {
        return 'default';
    }
}
