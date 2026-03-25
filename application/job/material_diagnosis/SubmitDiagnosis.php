<?php

namespace app\job\material_diagnosis;

use jlqc\FundManagement;
use think\Db;
use think\Env;
use think\Exception;
use think\queue\Job;

class SubmitDiagnosis
{
    /**
     * fire方法是消息队列默认调用的方法
     * @param Job $job 当前的任务对象
     * @param array|mixed $data 发布任务时自定义的数据
     */
    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        $queueData = $queueModel->where('job_id', $jobId)->find();
        try {
            $isJobDone = $this->doJob($data);
            if ($isJobDone) {
                if ($queueData) {
                    $queueData->save(['id' => $queueData['id'], 'status' => 1, 'msg' => "处理完成"]);
                }
                $job->delete();
            } else {
                if ($job->attempts() > 3) {
                    $job->delete();
                    throw new Exception("重试未果");
                }
            }
        } catch (Exception $e) {
            $insert_data = [
                'job_name' => '素材前测提交',
                'job_id' => $jobId,
                'class_name' => 'app\job\material_diagnosis\SubmitDiagnosis',
                'queue_name' => 'submitDiagnosis',
                'relation_table' => '',
                'job_data' => json_encode($data),
                'remark' => '',
                'msg' => $e->getMessage(),
                'status' => 2,
            ];
            if ($queueData) {
                $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => $e->getMessage()]);
                $job->delete();
                return ;
            }
            $queueModel->save($insert_data);
            $job->delete();
            return ;
        }
    }


    public function doJob($data){
        $res = FundManagement::create_diagnosis_task([
            'agent_id' => (int)Env::get('dmc_ad_config.advertiser_id'),
            'advertiser_id' => (int)$data['advertiser_id'],
            'video_ids' => $data['video_ids'],
        ]);
//        var_dump($res);
        if ($res['code'] == 40100 || $res['code'] == 40110){
            return false;
        }
        $data = $res['data'];
        $ins = [];
        foreach ($data['task_ids'] as $task_id){
            $ins[] = [
                'task_id' => $task_id,
                'create_time' => time()
            ];
        }
        if (!empty($ins)){
            Db::name('material_diagnosis')->insertAll($ins);
        }
        return true;
    }

}