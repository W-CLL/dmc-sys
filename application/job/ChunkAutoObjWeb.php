<?php

namespace app\job;

use app\common\model\Queue;
use think\Exception;
use think\queue\Job;


class ChunkAutoObjWeb
{

    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new Queue();
        $queueData = $queueModel->where('job_id', $jobId)->find();
        if (!$queueData) {
            $job->delete();
            return '';
        }
        try {
            $isJobDone = $this->doJob($data, $queueData);
            if ($isJobDone) {
                $queueData->save(['id' => $queueData['id'], 'status' => 1, 'msg' => "处理完成"]);
                $job->delete();
            } else {
                if ($job->attempts() > 3) {
                    $job->delete();
                }
            }
        } catch (Exception $e) {
            $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => $e->getMessage()]);
            $job->delete();
        }
    }

    /**
     *
     */
    protected function doJob($data)
    {
        $queue = new Queue();
        //平均分开到每个计划需要更新多少次
        $needNum = $data['need_opt_num'];
        $accountNum = count($data['obj_list']);
        $singleAccountNeedNum = round($needNum / $accountNum);
        for ($i = 0; $i < $singleAccountNeedNum; $i++) {
            foreach ($data['obj_list'] as $item) {
                $seconds = rand(2, 7);
                $upData = [
                    'adv_id' => $data['adv_id'],
                    'obj_id' => $item,
                    'delay' =>($accountNum>4) ?0: $seconds,
                    'last_one' => false,
                ];
                if ($i == $singleAccountNeedNum - 1) {
                    $upData['last_one'] = true;
                }
             $queue->addQueue('web修改' . $item . '计划名称', 'app\job\AutoUpdateObjNameWeb', 'autoUpdateObjNameWeb', $upData, '', '延迟' . $seconds . '秒执行');
            }
        }
        return true;
    }
}