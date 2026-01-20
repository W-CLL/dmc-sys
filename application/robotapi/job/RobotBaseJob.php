<?php

namespace app\robotapi\job;

use think\Exception;
use think\queue\Job;
use app\robotapi\model\QueueRobot;
use think\Cache;
use think\Log;

class RobotBaseJob
{
    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'] ?? null;
        $queueModel = new QueueRobot();
        $queueData = $queueModel->where('job_id', $jobId)->find();

        // 防护：队列记录不存在时直接删除任务
        if (!$queueData) {
            Log::error("RobotBaseJob: 队列记录不存在, job_id={$jobId}");
            $job->delete();
            return '';
        }

        try {
            if (empty($data['job_class'])) {
                throw new Exception('任务实际执行类必传');
            }

            if (!class_exists($data['job_class'])) {
                throw new Exception('任务类不存在: ' . $data['job_class']);
            }

            $class = new $data['job_class']();
            $isJobDone = $class->doJob($data);

            if ($isJobDone) {
                $queueData->save(['id' => $queueData['id'], 'status' => 1, 'msg' => "处理完成"]);
                $job->delete();
                return '';
            } else {
                throw new Exception('任务执行返回失败');
            }
        } catch (Exception|\Exception $e) {
            $maxAttempts = 5;
            $currentAttempts = $job->attempts();
            $jobName = $queueData['job_name'] ?? '';

            Log::error("RobotBaseJob异常: job_id={$jobId}, attempts={$currentAttempts}, error=" . $e->getMessage());

            // 特定任务的缓存标记
            if ($currentAttempts == $maxAttempts && in_array($jobName, ['共享钱包【查询转账信息】', '千川账户【查询转账信息】'])) {
                $field = $jobName == '千川账户【查询转账信息】' ? 'transfer_records_id' : 'swtl_id';
                $jobData = json_decode($queueData['job_data'], true);
                $id = $jobData[$field] ?? null;
                if ($id) {
                    Cache::set($field . $id, 1, 1800);
                }
            }

            if ($currentAttempts < $maxAttempts) {
                // 延迟重试 - 使用 return 替代 exit，防止杀死 worker 进程
                $delay = $currentAttempts * 10 * $currentAttempts;
                $job->release($delay);
                return '';
            } else {
                // 超过最大重试次数
                $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => $e->getMessage()]);
                $job->delete();
                if (!empty($data['callback_data'])) {
                    $this->callback($data['callback_data'], $e->getMessage());
                }
                return '';
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