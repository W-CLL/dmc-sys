<?php

namespace app\job;

use think\Cache;
use think\Db;
use think\queue\Job;
use jlqc\FundManagement;
use app\common\model\Queue;

class QcOpt
{
    /**
     * fire方法是消息队列默认调用的方法
     * @param Job $job 当前的任务对象
     * @param array|mixed $data 发布任务时自定义的数据
     */
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
            $redis->rpush('queue_status_update', serialize($queue_status_update_array));
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            $queue_status_update_array[$jobId] = ['status' => 2, 'msg' => '队列消费失败[执行异常]：'.mb_convert_encoding($e->getMessage(), 'UTF-8', 'auto')];
            $redis->rpush('queue_status_update', serialize($queue_status_update_array));
        }
    }
    private function doJob($data)
    {
        $access_token = Cache::get("qc_access_token");
        $queueModel = new Queue();
        foreach ($data['data'] as $key => $value) {
            $split_array = array_chunk($value, 20); // 广告id分割，每次请求20条【最大值为20】
            foreach ($split_array as $k => $v) {
                $params = [
                    'advertiser_id' => $key,
                    'object_id' => $v,
                    'page' => '1',
                    'page_size' => '20',
                    'start_date' => $data['start_time'],
                    'end_date' => $data['end_time'],
                ];
                $res = FundManagement::get_opt_log($access_token, $params);
                if ($res['code'] == 0) {
                    $queue_data['total_page'] = $res['data']['page_info']['total_page'];
                    $queue_data['params'] = $params;
                    $queueModel->addQueue('广告计划操作数据分割处理','app\job\DivideOpt','createDivideOpt',$queue_data,'');
                }else{
                    return false;
                }
            }
        }
        return true;
    }
}