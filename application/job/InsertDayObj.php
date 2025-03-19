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
        try {
            $isJobDone = $this->doJob($data);
            if ($isJobDone) {
                $job->delete();
            } else {
                if ($job->attempts() > 3) {
                    $job->delete();
                }
            }
        } catch (Exception|\Exception $e) {
            $insert_data = [
                'job_name' => '插入当天新增计划',
                'job_id' => $jobId,
                'class_name' => 'app\job\InsertDayObj',
                'queue_name' => 'insertDayObj',
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
     * @throws Exception
     * @throws \Exception
     */
    protected function doJob($data): bool
    {

        $requests = $this->buildGuzzleRequest($data['adv_list'],$data['filtering']);
        list($insertData,$error) = $this->sendGuzzleRequest($requests);
        if($error){
            throw new Exception($error);
        }
        // 处理返回数据
        if ($insertData) {
            return $this->saveNewObj($insertData);
        }

        return true;
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
        $keys = array_keys($adv);
        $values = array_values($adv);
        $flattenedValues = array_merge(...$values);
        $exitedIds = $objModel->where(['adv_id' => ['in', $keys], 'obj_id' => ['in', $flattenedValues]])->column('obj_id');
        $afterData = array_filter($list, function ($item) use ($exitedIds) {
            return !in_array($item['obj_id'], $exitedIds);
        });
        if ($afterData) {
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
     * @param $count
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
        $guzzleClient = new Client();
        // 并发请求
        $pool = new Pool($guzzleClient, array_column($requests, 'request'), [
            'concurrency' => 10,  // 控制并发数
            'fulfilled' => function ($response, $index) use (&$insertData, $requests, &$error) {
                $resData = json_decode($response->getBody()->getContents(), true);

                $requestInfo = $requests[$index]['params'];
                $requestAdvId = $requestInfo['advertiser_id'];
                $error = '';
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
                    $error = $resData['message'];
                }
            },
            'rejected' => function ($reason, $index) {
                echo "Request {$index} failed: " . $reason->getMessage() . "\n";
            },
        ]);
        // 等待所有请求完成
        $promise = $pool->promise();
        $promise->wait();
        return [$insertData,$error];
    }





}
