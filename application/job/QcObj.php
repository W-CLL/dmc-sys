<?php

namespace app\job;

use think\Db;
use think\Cache;
use think\queue\Job;
use jlqc\FundManagement;
use app\common\model\Queue;
class QcObj
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


    /**
     * 具体执行的逻辑
     * @param $data
     * @return boolean
     */
    private function Run($data)
    {
        $queueModel = new Queue();
        $access_token = Cache::get("qc_access_token");
        foreach ($data['data'] as $key => $value){
            $params = [
                'advertiser_id' => $value['advertiser_id'],
                'page' => '1',
                'page_size' => '200',
                'start_date' => date('Y-m-d', strtotime(date('Y-m-d') . $data['time_describe'])),
                'end_date' => date('Y-m-d'),
            ];
            $res = FundManagement::get_ad_report($access_token, $params);
            if($res['code'] == 0){
                $queue_data['request_condition'] = $params;
                $queue_data['response'] = $res;
                $queue_data['company_id'] = $value['id'];
                $queueModel->addQueue('广告计划数据分割','app\job\DivideObj','createDivideObj',$queue_data,'');
            }else{
                return false;
            }
        }
        return true;
    }
}