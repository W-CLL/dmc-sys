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
        $redis = Cache::store('redis')->handler();
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
        $access_token = Cache::get("qc_access_token");
        $redis = Cache::store('redis')->handler();
        foreach ($data['res']['data']['list'] as $item) {
            if (!$redis->SISMEMBER('obj_id',$item['ad_id'])) {
                $arr['company_id'] = $data['company_id'];
                $arr['advertiser_id'] = $item['advertiser_id'];
                $arr['object_id'] = $item['ad_id'];
                $arr['create_time'] = time();
                $ad_detail_res = FundManagement::get_ad_detail($access_token, $item['advertiser_id'], $item['ad_id']);
                if ($ad_detail_res['code'] == 0) {
                    if ($ad_detail_res['data']['status'] == 'DELETE' || $ad_detail_res['data']['status'] == 'FROZEN' || $ad_detail_res['data']['status'] == 'TIME_DONE') {
                        $arr['status'] = 0;
                    } else {
                        $arr['status'] = 1;
                        $redis->SADD('obj_id',$item['ad_id']);
                        $redis->SADD('obj_arr',serialize(['advertiser_id' => $item['advertiser_id'], 'object_id' => $item['ad_id']]));
                    }
                    $arr['object_name'] = $ad_detail_res['data']['name'];
                } else {
                    $arr['status'] = 0;
                }
                $ins_data[] = $arr;
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