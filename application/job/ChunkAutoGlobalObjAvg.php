<?php

namespace app\job;

use app\common\model\QueueAvg;
use think\Exception;
use think\queue\Job;


class ChunkAutoGlobalObjAvg
{

    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\QueueAvg();
        $queueData = $queueModel->where('job_id', $jobId)->lock(true)->find();
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
        $queue = new QueueAvg();
        //平均分开到每个计划需要更新多少次
        $needNum = $data['need_opt_num'];
        $accountNum = count($data['obj_list']);
        $singleAccountNeedNum = round($needNum / $accountNum);
//        $list_count = count($data['obj_list']);
        for ($i = 0; $i < $singleAccountNeedNum; $i++) {
            foreach ($data['obj_list'] as $item) {
                if(isset($data['is_abnormal']) && $data['is_abnormal']){
                    $seconds = 0;
                }else{
                    $seconds = rand(2, 6);
                }
                $upData = [
                    'adv_id' => $data['adv_id'],
                    'obj_id' => $item,
                    'delay' => $seconds,
                    'last_one' => false
                ];
                if ($i == $singleAccountNeedNum - 1) {
                    $upData['last_one'] = true;
                }
                $queue->addQueue('修改' . $item . '计划名称【全域】', 'app\job\AutoUpdateGlobalObjNameAvg', 'autoUpdateGlobalObjNameAvg', $upData, '', '延迟' . $seconds . '秒执行');
            }
        }
        return true;
    }
}