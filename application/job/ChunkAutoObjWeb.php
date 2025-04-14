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
        $queueModel = new \app\common\model\Queue();
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
    protected function doJob($data, $queueData)
    {
        $queue = new Queue();
        //平均分开到每个计划需要更新多少次
        $needNum = $data['need_opt_num'];
        $accountNum = count($data['obj_list']);
        $singleAccountNeedNum = round($needNum / $accountNum);
        for ($i = 0; $i < $singleAccountNeedNum; $i++) {
            foreach ($data['obj_list'] as $item) {
//                if (isset($data['is_abnormal']) && $data['is_abnormal']) {
//                    $seconds = 0;
//                } else {
                    $seconds = rand(2, 7);
//                }
//                $seconds = 3;
                $upData = [
                    'adv_id' => $data['adv_id'],
                    'obj_id' => $item,
                    'delay' => $seconds,
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