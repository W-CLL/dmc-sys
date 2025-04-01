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
     */
    protected function doJob($data)
    {
        foreach ($data['adv_list'] as $item) {
            $params = [
                'advertiser_id' => intval($item),
                'start_date' => $data['date'] . ' 00:00:00',
                'end_date' => $data['date'] . ' 23:59:59',
            ];
            \think\Queue::push('app\job\UpdateSingleAdvGlobalCost', $params, 'upAdvSingleGlobalCost');
        }
        return true;
    }
}
