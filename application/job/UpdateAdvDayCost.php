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


class UpdateAdvDayCost
{

    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        $queueData = $queueModel->where('job_id', $jobId)->find();
        if (!$queueData) {
            $job->delete();
            die;
        }
        try {
            $isJobDone = $this->doJob($data, $queueData);
            if ($isJobDone) {
                $queueData->save(['id' => $queueData['id'], 'status' => 1, 'msg' => "处理完成"]);
                $job->delete();
            } else {
                if ($job->attempts() > 3) {
                    $job->delete();
                }
            }
        } catch (Exception $e) {
            $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => $e->getMessage()]);
            $job->delete();
        }
    }

    /**
     * @throws Exception
     */
    protected function doJob($data, $queueData)
    {
        $requests = $this->buildGuzzleRequest($data);
        $allData = $this->sendGuzzleRequest($requests);
        if(empty($allData)){
            return true;
        }
//     dump($allData);
//        die;
        $costModel = new QcAdvDayCost();
        foreach ($allData as $data) {
            $dayCost = $costModel->where(['adv_id' => $data['adv_id'], 'cost_date' => $data['cost_date']])->find();
            if ($dayCost) {
                echo "更新";
                $upData['id']=$dayCost['id'];
                $upData['cost'] = $data['cost'];
                $res = $dayCost->save($upData);
            } else {
                echo "插入";
                $res = $costModel->save($data);
            }
            if (!$res) {
                throw  new Exception($res);
            }
        }
        return true;

    }

    protected function buildGuzzleRequest($advIds)
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/report/advertiser/get/";
        $headers = [
            'Access-Token' => $access_token,
            'Content-Type' => 'application/json'
        ];
        $requests = [];
        foreach ($advIds as $advId) {
            $params = [
                "advertiser_id" => $advId,
                "start_date" => date('Y-m-d',time()),
                "end_date" => date('Y-m-d',time()),
//                "start_date" => "2025-01-08",
//                "end_date" => "2025-01-08",
                "page" => 1,
                "fields" => ['stat_cost'],
                "page_size" => 100,
                "order_type" => "DESC",
                "filtering" => [
                    "marketing_goal" => 'ALL'
                ],
                'time_granularity' => 'TIME_GRANULARITY_DAILY'
            ];
            $request = new Request('GET', $url, $headers, json_encode($params));
            $requests[] = [
                'request' => $request, // 请求对象
                'params' => $params,   // 请求参数
            ];
        }
        return $requests;
    }

    protected function sendGuzzleRequest($requests)
    {
        sleep(3);
        $insertData = [];
        $guzzleClient = new Client();
        $pool = new Pool($guzzleClient, array_column($requests, 'request'), [
            'concurrency' => 50, // 并发请求数量
            'fulfilled' => function ($response, $index) use (&$insertData) {
                $resData = json_decode($response->getBody()->getContents(), true);
//                dump($resData['code'] . '1');
                if ($resData['code'] != 0) {
                    dump($resData['message']);
                }
                if (!empty($resData) && $resData['code'] == 0 && !empty($resData['data']['list'])) {
                    foreach ($resData['data']['list'] as $item) {
                        $insertData[] = [
                            'adv_id' => $item['advertiser_id'],
                            'cost' => $item['stat_cost'],
                            'cost_date' => strtotime($item['stat_datetime']),
                        ];
                    }
                }
            },
            'rejected' => function ($reason, $index) {
                echo "请求 {$index} 失败: " . $reason->getMessage() . "\n";
            },
        ]);
        // 发送请求并等待所有请求完成
        $promise = $pool->promise();
        $promise->wait();
        return $insertData;
    }

}
