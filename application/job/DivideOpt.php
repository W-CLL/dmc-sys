<?php

namespace app\job;

use app\common\model\Queue;
use jlqc\FundManagement;
use think\queue\Job;
use think\Cache;
use think\Db;

class DivideOpt
{
    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $redis = Cache::store('redis')->handler();
        Db::startTrans();
        try {
            $isJobDone = $this->doJob($data);
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

    private function doJob($data){
        $access_token = Cache::get("qc_access_token");
        $res_total_page = $data['total_page'];
        for ($i = 1; $i <= $res_total_page; $i++) {
            $data['params']['page'] = $i;
            $res = FundManagement::get_opt_log($access_token, $data['params']);
            if (!empty($res['data']['logs'])) {
                foreach ($res['data']['logs'] as $k1 => $v1) {
                    $data_insert[] = [
                        'advertiser_id' => $data['params']['advertiser_id'],
                        'obj_id' => $v1['object_id'],
                        'content_log' => serialize($v1['content_log']),
                        'content_title' => $v1['content_title'],
                        'object_name' => $v1['object_name'],
                        'object_type' => $v1['object_type'],
                        'operator' => $v1['operator'],
                        'opt_ip' => $v1['opt_ip'],
                        'opt_time' => strtotime($v1['create_time']),
                        'create_time' => time()
                    ];
                }
            }
        }
        if (!empty($data_insert)) {
            $split_array = array_chunk($data_insert, 500);
            foreach ($split_array as $k => $v) {
                if(!Db::name('plan_opt_log')->insertAll($v)){
                    return false;
                }
            }
        }
        return true;
    }
}