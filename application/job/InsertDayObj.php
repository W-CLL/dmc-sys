<?php

namespace app\job;

use app\admin\model\QcObj;
use app\common\model\Queue;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use jlqc\FundManagement;
use think\Cache;
use think\Exception;
use think\Log;
use think\queue\Job;


class InsertDayObj
{

    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        $queueData = $queueModel->where('job_id', $jobId)->find();
        try {
            $isJobDone = $this->doJob($data);
            if ($isJobDone) {

                if($queueData){
                    $queueData->save(['id' => $queueData['id'], 'status' => 1, 'msg' => "处理完成"]);
                }
                $job->delete();
                return '';
            } else {
                if ($job->attempts() > 3) {
                    $job->delete();
                    return '';
                }
            }
        } catch (Exception|\Exception $e) {
            $insert_data = [
                'job_name' => '插入当天新增计划',
                'job_id' => $jobId,
                'class_name' => 'app\job\InsertDayObj',
                'queue_name' => 'insertDayObj',
                'relation_table' => '',
                'job_data' => json_encode($data),
                'remark' => '',
                'msg' => $e->getMessage(),
                'status' => 2,
            ];
            if($queueData){
                $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' =>$e->getMessage()]);
                $job->delete();
                return '';
            }
            $queueModel->save($insert_data);
            $job->delete();
            return '';
        }
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    protected function doJob($data): bool
    {
        sleep(5);
        if(isset($data['advertiser_id'])){
            $data['adv_list'] = [$data['advertiser_id']];
            $data['filtering'] = json_decode($data['filtering'],true);
        }
        if(isset($data['params'])){
            $data['filtering'] = $data['params'];
        }
        $requests = $this->buildGuzzleRequest($data['adv_list'],$data['filtering']);
        list($insertData,$error,$need_rebuild) = $this->sendGuzzleRequest($requests);
        echo date('m-d H:i:s');
        if($need_rebuild){
            echo "部分需要重新处理";
            $job_data = [
                'adv_list'=>$need_rebuild,
                'filtering'=>$data['filtering']
            ];
            \think\Queue::push('app\job\InsertDayObj', $job_data, "insertDayObj");
        }
//        if($error){
//            throw new Exception(json_encode($error));
//        }
        if(empty($insertData)){
            echo "都是空的";
            return true;
        }else{

            return $this->saveNewObj($insertData);
        }
    }

    /**
     * 插入当天新增计划
     * @param $list
     * @return bool
     * @throws \Exception
     */
    protected function saveNewObj($list)
    {
        $objModel = new QcObj();
        $adv = [];
        foreach ($list as $key => $item) {
            if ($item['obj_id']) {
                if (!isset($adv[$item['adv_id']])) {
                    $adv[$item['adv_id']] = [];
                }
                $adv[$item['adv_id']][] = $item['obj_id'];
            }
        }

        $exitedIds =[];
        foreach ($adv as $key=> $item){
            $exitedIds[] = $objModel->where(['adv_id'=>$key,'obj_id' => ['in', $item]])->column('obj_id');
        }
        $exitedIds = array_merge(...$exitedIds);

//        $keys = array_keys($adv);
//        $values = array_values($adv);
//        $flattenedValues = array_merge(...$values);
//        $exitedIds = $objModel->where([ 'obj_id' => ['in', $flattenedValues]])->column('obj_id');
        $afterData = array_filter($list, function ($item) use ($exitedIds) {
            return !in_array($item['obj_id'], $exitedIds);
        });
        if ($afterData) {
            echo "写进了";
            $res = $objModel->saveAll($afterData);
            if ($res) {
                return true;
            } else {
                return false;
            }
        }
        return true;
    }


    /**
     * 构建请求
     * @param $advIds
     * @param $filter
     * @return array
     */
    protected function buildGuzzleRequest($advIds, $filter): array
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/ad/get/";
        $headers = [
            'Access-Token' => $access_token,
            'Content-Type' => 'application/json'
        ];
        $requests = [];
        foreach ($advIds as $advId) {
            $params = [
                "advertiser_id" => (int)$advId,
                "page" => 1,
                "page_size" => 200,
                'filtering' => json_encode($filter),
            ];
            $request = new Request('GET', $url, $headers, json_encode($params));
            $requests[] = ['request' => $request, 'params' => $params];
        }
        return $requests;
    }
    /**
     * 发送请求
     * @param $requests
     * @return array
     */
    protected function sendGuzzleRequest($requests)
    {
        $insertData = [];
        $error = [];
        $need_rebuild = [];
        $guzzleClient = new Client();
        // 并发请求
        $pool = new Pool($guzzleClient, array_column($requests, 'request'), [
            'concurrency' => 10,  // 控制并发数
            'fulfilled' => function ($response, $index) use (&$insertData, $requests, &$error,&$need_rebuild) {
                $resData = json_decode($response->getBody()->getContents(), true);

                $requestInfo = $requests[$index]['params'];
                $requestAdvId = $requestInfo['advertiser_id'];

//                $insertData = [];    // ？
                if (!empty($resData) && $resData['code'] == 0 && !empty($resData['data']['list'])) {
                    foreach ($resData['data']['list'] as $item) {
                        $insertData[] = [
                            'adv_id' => $requestAdvId,
                            'obj_id' => $item['ad_id'],
                            'name' => $item['name'],
                            'obj_status' => $item['status'],
                            'opt_status' => $item['opt_status'],
                            'marketing_goal' => $item['marketing_goal'],
                            'marketing_scene' => $item['marketing_scene'],
                            'campaign_scene' => $item['campaign_scene'],
                            'campaign_id' => $item['campaign_id'],
                            'lab_ad_type' => $item['lab_ad_type'],
                            'obj_create_time' => strtotime($item['ad_create_time']),
                            'obj_modify_time' => strtotime($item['ad_modify_time']),
                            'product_info' => json_encode($item['product_info']),
                            'aweme_info' => json_encode($item['product_info']),
                            'delivery_setting' => json_encode($item['product_info']),
                        ];
                    }
                    if ($resData['data']['page_info']['total_page'] > 1) {
                        $requestInfo['page'] = 2;
                        \think\Queue::push('app\job\InitObj', $requestInfo, "initObj");
                    }
                }elseif($resData['code'] !=0){
                    if(!$this->skipIfContainsError($resData['message'])){
//                        $error[] = $resData['message'];
                        $need_rebuild[] = $requestAdvId;
                    };
                }
            },
            'rejected' => function ($reason, $index) {
                echo "Request {$index} failed: " . $reason->getMessage() . "\n";
            },
        ]);
        // 等待所有请求完成
        $promise = $pool->promise();
        $promise->wait();
        return [$insertData,$error,$need_rebuild];
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


}
