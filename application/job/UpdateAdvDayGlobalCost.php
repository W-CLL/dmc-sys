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
                'job_name' => '更新账户全域消耗',
                'job_id' => $jobId,
                'class_name' => 'app\job\UpdateAdvDayGlobalCost',
                'queue_name' => 'updateAdvDayGlobalCost',
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
     */
    protected function doJob($data)
    {
        $costModel = new QcAdvDayCost();
        list($req_live,$req_video) = $this->buildGuzzleRequest($data);
        if(!empty($req_live)){
            list($data_live,$need_rebuild_live) = $this->sendGuzzleRequest($req_live);
        }
        if (!empty($req_video)) {
            list($data_video, $need_rebuild_video) = $this->sendGuzzleRequest($req_video);
        }
        if (!empty($data_live) || !empty($data_video)) {
            $merge_data = $this->mergeData($data_live, $data_video);
            foreach ($merge_data as $advId => $cost){
                $info = $costModel->where(['adv_id' => $advId, 'type' => 2, 'cost_date' => strtotime($data['date'])])->find();
                if ($info) {
                    $save_data[] = [
                        'cost' => $cost,
                        'cost_date' => strtotime($data['date']),
                        'type' => 2,
                        'adv_id' => $advId,
                        'id' => $info['id']
                    ];
                } else {
                    $save_data[] = [
                        'cost' => $cost,
                        'cost_date' => strtotime($data['date']),
                        'type' => 2,
                        'adv_id' => $advId
                    ];
                }
            }
            if(!empty($save_data)){
                try {
                    $costModel->saveAll($save_data);
                }catch (Exception $e){
                    throw new Exception($e->getMessage());
                }
            }
        }
        if (!empty($need_rebuild_live)){
            $data_l = [
                'adv_list' => $need_rebuild_live,
                'date' => $data['date'],
                'run_type' => 1 // 0:全执行 1:仅构造直播 2：仅构造商品
            ];
            \think\Queue::later(3,'app\job\UpdateAdvDayGlobalCost', $data_l, 'upAdvDayGlobalCost');
        }
        if (!empty($need_rebuild_video)){
            $data_v = [
                'adv_list' => $need_rebuild_video,
                'date' => $data['date'],
                'run_type' => 2 // 0:全执行 1:仅构造直播 2：仅构造商品
            ];
            \think\Queue::later(3,'app\job\UpdateAdvDayGlobalCost', $data_v, 'upAdvDayGlobalCost');
        }
        return true;
    }



    /**
     * 构建请求
     * @param $advIds
     * @param $filter
     * @return array
     */
    protected function buildGuzzleRequest($data): array
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/report/uni_promotion/get/";
        $headers = [
            'Access-Token' => $access_token,
        ];
        $requests1 = [];
        $requests2 = [];
        foreach ($data['adv_list'] as $advId) {
            $params = [
                "advertiser_id" => (int)$advId,
                'start_date' => $data['date'] . ' 00:00:00',
                'end_date' => $data['date'] . ' 23:59:59',
                'fields' => json_encode([
                    'stat_cost'
                ])
            ];
            if($data['run_type'] === 0 || $data['run_type'] === 1){
                $params['marketing_goal'] = "LIVE_PROM_GOODS";
                $query = http_build_query($params);
                $urlWithQuery = $url . '?' . $query;
                $request = new Request('GET', $urlWithQuery, $headers);
                $requests1[] = ['request' => $request, 'params' => $params];
            }
            if ($data['run_type'] === 0 || $data['run_type'] === 2){
                $params['marketing_goal'] = "VIDEO_PROM_GOODS";
                $query = http_build_query($params);
                $urlWithQuery = $url . '?' . $query;
                $request = new Request('GET', $urlWithQuery, $headers);
                $requests2[] = ['request' => $request, 'params' => $params];
            }
        }
        return [$requests1,$requests2];
    }

    /**
     * 发送请求
     * @param $requests
     * @return array
     */
    protected function sendGuzzleRequest($requests)
    {
        $data = [];
        $need_rebuild = [];
        $guzzleClient = new Client();
        // 并发请求
        $pool = new Pool($guzzleClient, array_column($requests, 'request'), [
            'concurrency' => 10,  // 控制并发数
            'fulfilled' => function ($response, $index) use (&$data, $requests, &$need_rebuild) {
                $resData = json_decode($response->getBody()->getContents(), true);
                $requestInfo = $requests[$index]['params'];
                $requestAdvId = $requestInfo['advertiser_id'];

                if (!empty($resData) && $resData['code'] == 0 && $resData['message'] == "OK") {
                    $data[$requestAdvId] = $resData['data']['stat_cost'];
                }elseif($resData['code'] != 0){
                    if(!$this->skipIfContainsError($resData['message'])){
                        $need_rebuild[] = $requestAdvId;
                    }
                }
            },
            'rejected' => function ($reason, $index) {
                echo "Request {$index} failed: " . $reason->getMessage() . "\n";
            },
        ]);
        // 等待所有请求完成
        $promise = $pool->promise();
        $promise->wait();
        return [$data,$need_rebuild];
    }


    public  function skipIfContainsError($message): bool
    {
        // 定义需要匹配的关键词列表（支持中英文）
        $keywords = [
            '/广告主账号已禁用/iu',  // 中文关键词（忽略大小写）
            '/No permission to operate account/iu',  // 英文关键词（忽略大小写）
        ];
        // 检查是否匹配其中一个关键词
        foreach ($keywords as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }
        return false;
    }

    public  function mergeData($data_live,$data_video){
        $merge_data = [];
        foreach ($data_live as $advId => $cost){
            if(isset($data_video[$advId])){
                $merge_data[$advId] = $cost + $data_video[$advId];
            }else{
                $merge_data[$advId] = $cost;
            }
        }
        return $merge_data;
    }
}
