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


class UpdateAdvDayGlobalCost
{

    /**
     * 完成的直接删，失败了的才记录进数据库
     * @param Job $job
     * @param $data
     * @return void
     */
    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();

        try {
            $isJobDone = $this->doJob($data);
            if ($isJobDone) {
                $job->delete();
            } else {
                if ($job->attempts() > 3) {
                    $job->delete();
                }
            }
        } catch (Exception $e) {
            $insert_data = [
                'job_name' => '2h更新账户当天全域消耗',
                'job_id' => $jobId,
                'class_name' => 'app\job\UpdateAdvDayGlobalCost',
                'queue_name' => 'upAdvDayGlobalCost',
                'relation_table' => '',
                'job_data' => $data,
                'remark' => '',
                'msg' => $e->getMessage(),
                'status' => 2,
            ];
            $queueModel->save($insert_data);
            $job->delete();
        }
    }

    /**
     * @throws Exception
     */
    protected function doJob($data)
    {
        $access_token = Cache::get("qc_access_token");
        $costModel = new QcAdvDayCost();
        foreach ($data['adv_list'] as $item) {
            $params = [
                'advertiser_id' => intval($item),
                'start_date' => $data['date'] . ' 00:00:00',
                'end_date' => $data['date'] . ' 23:59:59',
                'marketing_goal' => 'LIVE_PROM_GOODS'
            ];
            $live_cost = FundManagement::get_global_adv_cost($access_token, $params);
            $l_cost = 0;
            if ($live_cost['code'] == 0 && $live_cost['message'] == "OK") {
                $l_cost = $live_cost['data']['stat_cost'];
            }
            $params['marketing_goal'] = "VIDEO_PROM_GOODS";
            $video_cost = FundManagement::get_global_adv_cost($access_token, $params);
            $v_cost = 0;
            if ($video_cost['code'] == 0 && $video_cost['message'] == "OK") {
                $v_cost = $video_cost['data']['stat_cost'];
            }
            $final_cost = $l_cost + $v_cost;
            $date_time = strtotime($data['date']);
            $info = $costModel->where(['adv_id' => $item, 'type' => 2, 'cost_date' => $date_time])->find();
            if ($final_cost) {
                if ($info) {
                    $res = $info->save(['cost' => $final_cost]);
                    echo "更新了";
                } else {
                    $insert_data = [
                        'cost' => $final_cost,
                        'cost_date' => $date_time,
                        'type' => 2,
                        'adv_id' => $item
                    ];

                    $res = $costModel->save($insert_data);
                    echo "插入了";

                }
                if (!$res && $res != 0) {
                    throw  new Exception($res);
                }
            }
        }
        return true;
    }
}
