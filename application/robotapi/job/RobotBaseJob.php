<?php

namespace app\robotapi\job;

use think\Exception;
use think\queue\Job;
use app\robotapi\model\QueueRobot;
use think\Cache;

class RobotBaseJob
{
    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new QueueRobot();
        $queueData = $queueModel->where('job_id', $jobId)->find();
        try {
            if (!$data['job_class']){
                throw new Exception('任务实际执行类必传');
            }
            $class = new $data['job_class'];
            $isJobDone = $class->doJob($data);
            if ($isJobDone) {
                $queueData->save(['id' => $queueData['id'], 'status' => 1, 'msg' => "处理完成"]);
                $job->delete();
            }else{
                throw new Exception('失败');
            }
            return true;
        }catch (Exception|\Exception $e){
            // 获取当前尝试次数
            $maxAttempts = 5; // 最大重试次数
            $currentAttempts = $job->attempts();
            if($currentAttempts == $maxAttempts && ($queueData['job_name'] == '共享钱包【查询转账信息】' || $queueData['job_name'] == '千川账户【查询转账信息】')){
                $field = $queueData['job_name'] == '千川账户【查询转账信息】' ? 'transfer_records_id' : 'swtl_id';
                $id = json_decode($queueData['job_data'], true)[$field];
                cache::set($field.$id, 1, 1800);
            }
            if ($currentAttempts <= $maxAttempts) {
                // 延迟重试
                $delay = $currentAttempts * 10 * $currentAttempts;
                $job->release($delay);
                exit();
            } else {
                // 超过最大重试次数，标记为失败并删除任务
                $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => $e->getMessage()]);
                $job->delete();
                return false;
            }
        }
    }
}