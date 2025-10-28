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
                $this->callback($data["callback_data"], $e->getMessage());
                return false;
            }
        }
    }


    private function callback($data, $msg){
        $url = $data["url"];
        $msg = "您于" . date("Y-m-d H:i:s", $data["time"])."发起的请求结果如下：\n" . $msg;
        $msg_data = [
            "msg" => $msg,
        ];
        $params = [
            "group_wxid" => $data["group_id"],
            "sender_name" => $data["sender_name"],
            "message" => $msg_data,
            "msg_wxid" => $data["msg_uuid"],
        ];
        $queue = new QueueRobot();
        $queue->addQueue('回调请求', 'app\robotapi\job\RobotBaseJob', 'robotBaseJob',[
            "job_class" => '\app\robotapi\job\sendMsg\Send',
            "url" => $url,
            "params" => $params,
        ]);
    }
}