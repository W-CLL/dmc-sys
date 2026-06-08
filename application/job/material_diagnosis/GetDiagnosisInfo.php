<?php

namespace app\job\material_diagnosis;

use jlqc\FundManagement;
use think\Db;
use think\Env;
use think\Exception;
use think\queue\Job;

class GetDiagnosisInfo
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
                    Db::name('material_diagnosis')->where(['task_id' => ['in',$data['task_ids']]])->update([
                        'is_get' => 0
                    ]);
                }
            }
        } catch (Exception $e) {
            $insert_data = [
                'job_name' => '素材前测结果查询',
                'job_id' => $jobId,
                'class_name' => 'app\job\material_diagnosis\GetDiagnosisInfo',
                'queue_name' => 'getDiagnosisInfo',
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
        $res = FundManagement::get_diagnosis_task([
            'agent_id' => (int)Env::get('dmc_ad_config.advertiser_id'),
            'task_ids' => json_encode($data['task_ids']),
        ]);
        if ($res['code'] != 0){
            return false;
        }
        foreach ($res['data']['task_list'] as $item){
            if ($item['status'] == "SUCCESS" || $item['status'] == "FAILED"){
                Db::name('material_diagnosis')->where(['task_id' => $item['task_id']])->update([
                    "material_id" => $item['material_id'],
                    "video_id" => $item['video_id'],
                    "status" => $item['status'] == "SUCCESS" ? 1 : 2,
                    "is_ecp_high_quality_material" => $item['is_ecp_high_quality_material'] == "YES" ? 1 : 2,
                    "is_inefficient_material" => $item['is_inefficient_material'] == "YES" ? 1 : 2,
                    "is_first_publish_material" => $item['is_first_publish_material'] == "YES" ? 1 : 2,
                    "not_ecp_high_quality_reason" => json_encode($item['not_ecp_high_quality_reason'],JSON_UNESCAPED_UNICODE),
                    "update_time" => time(),
                ]);
            }else{
                Db::name('material_diagnosis')->where(['task_id' => $item['task_id']])->update([
                    'is_get' => 0
                ]);
            }
        }
        return true;
    }



}