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
                'job_name' => '更新账户标准消耗',
                'job_id' => $jobId,
                'class_name' => 'app\job\UpdateAdvDayCost',
                'queue_name' => 'upAdvDayCost',
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
     * @throws Exception
     */
    protected function doJob($data)
    {
        $requests = $this->buildGuzzleRequest($data);
        $allData = $this->sendGuzzleRequest($requests);
        if(empty($allData)){
            return true;
        }
        $costModel = new QcAdvDayCost();
        $saveData = [];
        foreach ($allData as $item) {
            $dayCost = $costModel->where(['adv_id' => $item['adv_id'], 'cost_date' => $item['cost_date'],'type'=>1])->find();
            if ($dayCost) {
                $upData['id']=$dayCost['id'];
                $upData['cost'] = $item['cost'];
                $saveData[]=$upData;
//                $res = $dayCost->save($upData);
            } else {
                $saveData[] = $item;
//                $res = $costModel->save($data);
            }

        }
        if($saveData){
            $res = $costModel->saveAll($saveData);
            if (!$res) {
                throw  new Exception($res);
            }
        }
        return true;

    }

    protected function buildGuzzleRequest($job_data)
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/report/advertiser/get/";
        $headers = [
            'Access-Token' => $access_token,
            'Content-Type' => 'application/json'
        ];
        $requests = [];
        foreach ($job_data['adv_list'] as $advId) {
            $params = [
                "advertiser_id" => $advId,
                "start_date" => $job_data['date'],
                "end_date" => $job_data['date'],
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
        sleep(2);
        $insertData = [];
        $guzzleClient = new Client();
        $pool = new Pool($guzzleClient, array_column($requests, 'request'), [
            'concurrency' => 20, // 并发请求数量
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
