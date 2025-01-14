<?php

namespace app\job;

use app\admin\model\QcObj;
use app\common\model\Queue;
use jlqc\FundManagement;
use think\Cache;
use think\Exception;
use think\queue\Job;

/**
 * 每天早上八点，下午三点自动跑刷名称
 */

class AutoUpdateObjName
{

    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        $queueData = $queueModel->where('job_id', $jobId)->find();
        if (!$queueData) {
            $job->delete();
            die;
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

    protected function doJon($data,$queueData)
    {

    }

}