<?php

namespace app\job;

use think\Db;
use think\Cache;
use think\queue\Job;
use jlqc\FundManagement;
use app\common\model\Queue;

class DivideObj
{
    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $redis = Cache::store('redis_db2')->handler();
        Db::startTrans();
        try {
            $isJobDone = $this->Run($data);
            if ($isJobDone) {
                $queue_status_update_array[$jobId] = ['status' => 1, 'msg' => '队列消费成功'];
                $job->delete();
            } else {
                $attempts = $job->attempts();
                if($attempts >= 3){
                    $queue_status_update_array[$jobId] = ['status' => 2, 'msg' => '队列消费失败[失败次数过多]'];
                    $job->delete();
                }
                //如果任务执行失败了，重新发布这个任务，3s后
                $job->release(3);
            }
            if(isset($queue_status_update_array)){
                $redis->rpush('queue_status_update', serialize($queue_status_update_array));
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            $queue_status_update_array[$jobId] = ['status' => 2, 'msg' => '队列消费失败[执行异常]：'.mb_convert_encoding($e->getMessage(), 'UTF-8', 'auto')];
            $redis->rpush('queue_status_update', serialize($queue_status_update_array));
        }
    }

    private function Run($data)
    {
        $queueModel = new Queue();
        $access_token = Cache::get("qc_access_token");
        $total_page = $data['response']['data']['page_info']['total_page'];
        for ($x = 1; $x <= $total_page; $x++) {
            $data['request_condition']['page'] = $x;
            $res = FundManagement::get_ad_list($access_token, $data['request_condition']);
            if (is_array($res['data']['list'])) {
                $queue_data['company_id'] = $data['company_id'];
                $queue_data['advertiser_id'] = $data['advertiser_id'];
                $queue_data['marketing_goal'] = $data['marketing_goal'];
                $queue_data['res'] = $res;
                $queueModel->addQueue('广告计划数据插入', 'app\job\InsObj', 'createInsObj', $queue_data, '');
            }
        }
        return true;
    }

}