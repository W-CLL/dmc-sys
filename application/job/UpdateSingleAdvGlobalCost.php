<?php

namespace app\job;

use app\admin\model\QcObj;
use app\common\model\QcAdvDayCost;
use app\qcdatahandle\controller\ComFun;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use jlqc\FundManagement;
use think\Cache;
use think\Exception;
use think\queue\Job;


class UpdateSingleAdvGlobalCost
{

    /**
     * 完成的直接删，失败了的才记录进数据库
     * @param Job $job
     * @param $data
     * @return string
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
                }
            }
        } catch (Exception $e) {
            $insert_data = [
                'job_name' => '更新账户当天全域消耗',
                'job_id' => $jobId,
                'class_name' => 'app\job\UpdateSingleAdvGlobalCost',
                'queue_name' => 'upAdvSingleGlobalCost',
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

    /**
     * @throws Exception
     */
    protected function doJob($data)
    {

        $access_token = Cache::get("qc_access_token");
        $costModel = new QcAdvDayCost();
        $data['marketing_goal'] = "LIVE_PROM_GOODS";
//        $data['advertiser_id'] = (int)$data['adv_id'];
        $params = $data;

        $live_cost = FundManagement::get_global_adv_cost($access_token, $params);

        $l_cost = 0;
        if ($live_cost['code'] == 0 && $live_cost['message'] == "OK") {
            $l_cost = $live_cost['data']['stat_cost'];
        }else{
            throw  new Exception($l_cost['message']);
        }
        $params['marketing_goal'] = "VIDEO_PROM_GOODS";
        $video_cost = FundManagement::get_global_adv_cost($access_token, $params);

        $v_cost = 0;
        if ($video_cost['code'] == 0 && $video_cost['message'] == "OK") {
            $v_cost = $video_cost['data']['stat_cost'];
        }else{
            throw  new Exception($l_cost['message']);
        }
        $final_cost = $l_cost + $v_cost;
        $date_time = strtotime($data['start_date']);
        $info = $costModel->where(['adv_id' => $data['advertiser_id'], 'type' => 2, 'cost_date' => $date_time])->find();
        if ($final_cost) {
            if ($info) {
                echo "更新";
                return $info->save(['cost' => $final_cost]);
            } else {
                $insert_data = [
                    'cost' => $final_cost,
                    'cost_date' => $date_time,
                    'type' => 2,
                    'adv_id' => $data['advertiser_id']
                ];
                echo "插入";
                return $costModel->save($insert_data);
            }
        }

        return true;
    }
}
