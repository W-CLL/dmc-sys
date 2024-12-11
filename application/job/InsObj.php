<?php

namespace app\job;

use think\Db;
use think\Cache;
use think\queue\Job;
use jlqc\FundManagement;
use app\common\model\Queue;

class InsObj
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
        $redis = Cache::store('redis_db2')->handler();
        foreach ($data['res']['data']['list'] as $item) {
            if (!$redis->SISMEMBER('obj_id',$item['ad_id'])) {
                $arr['company_id'] = $data['company_id'];
                $arr['advertiser_id'] = $data['advertiser_id'];
                $arr['object_id'] = $item['ad_id'];
                $arr['ad_create_time'] = strtotime($item['ad_create_time']);
                $arr['create_time'] = time();
                if ($item['status'] == 'DELETE' || $item['status'] == 'FROZEN') {
                    $arr['status'] = 0;
                } else {
                    $arr['status'] = 1;
                    $redis->SADD('obj_arr',serialize(['advertiser_id' => $data['advertiser_id'], 'object_id' => $item['ad_id']]));
                }
                $arr['object_name'] = $item['name'];
                $arr['marketing_goal'] = $data['marketing_goal'];
                $ins_data[] = $arr;
                $redis->SADD('obj_id',$item['ad_id']);
            }
        }
        if (!empty($ins_data)) {
            $split_array = array_chunk($ins_data, 500);
            foreach ($split_array as $k => $v) {
                if (!Db::name('qc_obj')->insertAll($v)) {
                    return false;
                }
            }
        }
        return true;
    }
}