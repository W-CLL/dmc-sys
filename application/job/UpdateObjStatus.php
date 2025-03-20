<?php

namespace app\job;

use app\admin\model\QcObj;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use think\Cache;
use think\Db;
use think\Exception;
use think\Log;
use think\queue\Job;
use jlqc\FundManagement;
use app\common\model\Queue;

class UpdateObjStatus
{
    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        try {
            $isJobDone = $this->doJob($data);
            if ($isJobDone) {
                $queueData = $queueModel->where('job_id', $jobId)->find();
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
            $queueData = $queueModel->where('job_id', $jobId)->find();
            if($queueData){
                $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => $e->getMessage()]);
                $job->delete();
                return '';
            }
            $insert_data = [
                'job_name' => '更新计划状态',
                'job_id' => $jobId,
                'class_name' => 'app\job\UpdateObjStatus',
                'queue_name' => 'updateObjStatus',
                'relation_table' => '',
                'job_data' => json_encode($data),
                'remark' => '',
                'msg' => $e->getMessage(),
                'status' => 2,
            ];

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
        if(empty($data['obj_list'])){
            echo "没有列表";
            return true;
        }
        $requests = $this->buildGuzzleRequest($data);

        list($updateData, $error) = $this->sendGuzzleRequest($requests);

        if ($error) {
            throw new Exception(json_encode($error));
        }
        // 处理返回数据
        if ($updateData) {
            return $this->updateObjStatus($updateData);
        }

        return true;
    }

    /**
     * 更新计划状态
     * @param $list
     * @return bool
     * @throws \Exception
     */
    protected function updateObjStatus($list)
    {
        $obj_model = new QcObj();
        foreach ($list as $item) {
            $item['update']['update_time'] = time();
            $obj_model->where($item['where'])->update($item['update']);
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
    protected function buildGuzzleRequest($data): array
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/ad/detail/get/";
        $headers = [
            'Access-Token' => $access_token,
            'Content-Type' => 'application/json'
        ];
        $requests = [];
        $objList = $data['obj_list'];
        $advId = $data['adv_id'];

        foreach ($objList as $obj_id) {
            $params = [
                "advertiser_id" => (int)$advId,
                "ad_id" => $obj_id,
                "request_material_url" => false,
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
        $updateData = [];
        $guzzleClient = new Client();
        $error = [];
        // 并发请求
        $pool = new Pool($guzzleClient, array_column($requests, 'request'), [
            'concurrency' => 10,  // 控制并发数
            'fulfilled' => function ($response, $index) use (&$updateData, $requests, &$error) {
                $resData = json_decode($response->getBody()->getContents(), true);

                if ($resData['code'] != 0) {
                    $error[] = $resData['message'];
                }
                $requestInfo = $requests[$index]['params'];
                $requestAdvId = $requestInfo['advertiser_id'];
                $requestObjId = $requestInfo['ad_id'];
                if (!empty($resData) && $resData['code'] == 0 && !empty($resData['data'])) {
                    $updateData[] = [
                        'where' => [
                            'adv_id' => $requestAdvId,
                            'obj_id' => $requestObjId,
                        ],
                        'update' => [
                            'opt_status' => $resData['data']['opt_status'],
                            'obj_status' => $resData['data']['status']
                        ],
                    ];
                }
            },
            'rejected' => function ($reason, $index) {
                echo "Request {$index} failed: " . $reason->getMessage() . "\n";
            },
        ]);
        // 等待所有请求完成
        $promise = $pool->promise();
        $promise->wait();
        return [$updateData, $error];
    }


}