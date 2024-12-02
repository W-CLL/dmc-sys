<?php

namespace app\job;

use think\Cache;
use think\Db;
use think\queue\Job;
use jlqc\FundManagement;
use app\common\model\Queue;
class UpdateObjStatus
{
    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $redis = Cache::store('redis')->handler();
        Db::startTrans();
        try {
            $canRun = $this->check();
            if (!$canRun) {
                return;
            }
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
            $redis->rpush('queue_status_update', serialize($queue_status_update_array));
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            $queue_status_update_array[$jobId] = ['status' => 2, 'msg' => '队列消费失败[执行异常]：'.mb_convert_encoding($e->getMessage(), 'UTF-8', 'auto')];
            $redis->rpush('queue_status_update', serialize($queue_status_update_array));
        }
    }

    private function Run($data){
        $access_token = Cache::get("qc_access_token");
        foreach ($data as $key => $value){
            $ad_detail_res = FundManagement::get_ad_detail($access_token, $value, $key);
            if($ad_detail_res['code'] != 0){
                $where[] = $value;
                unset($data[$key]);
            }else if ($ad_detail_res['data']['status'] == 'DELETE' || $ad_detail_res['data']['status'] == 'FROZEN' || $ad_detail_res['data']['status'] == 'TIME_DONE') {
                $where[] = $value;
                unset($data[$key]);
            }
        }
        if(!empty($where)){
            $res = Db::name('qc_obj')->where('object_id','in',$where)->update(['status'=>0]);
            if(!$res){
                return false;
            }
        }
        Cache::set("obj_arr",$data);
        return true;
    }

    private function check(){
        $queueModel = new Queue();
        $queueSum = $queueModel->where(['queue_name'=>'createQcOpt','status'=>0])->count();
        if ($queueSum > 0){
            return false;
        }else{
            return true;
        }
    }

}