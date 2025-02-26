<?php

namespace app\job;

use app\admin\model\QcObjOptLog;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use think\Cache;
use think\Exception;
use think\queue\Job;
use app\common\model\Queue;

class InsertObjOptLog
{

    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        $queueData = $queueModel->where('job_id', $jobId)->find();
        if(!$queueData){
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

    protected function doJob($data, $queueData)
    {
       $requests =  $this->buildGuzzleRequest($data);
       return $this->sendGuzzleRequest($requests);
    }

    protected function buildGuzzleRequest($data)
    {
        return function ($total_page) use ($data) {
            $access_token = Cache::get("qc_access_token");
            $url = "https://ad.oceanengine.com/open_api/2/tools/log_search/";
            $headers = [
                'Access-Token' => $access_token,
                'Content-Type' => 'application/json'
            ];
            $requests = [];
            $paramsArray = [];
            for ($i = $data['from_page']; $i <= $data['total_page']; $i++) {
                $params = array(
                    "advertiser_id" => $data['adv_id'],
                    'object_id' => $data['obj_ids'],
                    'start_time' => $data['start_time'],
                    'end_time' => $data['end_time'],
                    "page" => $i,
                    "page_size" => 20,
                );
                $paramsArray[] = $params;
                $request = new Request('GET', $url, $headers, json_encode($params));
                $requests[] = [
                    'request' => $request, // 请求对象
                    'params' => $params,   // 请求参数
                ];
            }
            yield from $requests;
            return $paramsArray;

        };
    }

    /**
     * 发送请求
     * @param $requests
     * @return true
     */
    protected function sendGuzzleRequest($requests)
    {
        sleep(1);
        $requestsArray = iterator_to_array($requests(10));
        $guzzleClient = new Client();
        $pool = new Pool($guzzleClient, array_column($requestsArray, 'request'), [
            'concurrency' => 10, // 并发请求数量
            'fulfilled' => function ($response, $index) use ( &$requestsArray) {
                $resData = json_decode($response->getBody()->getContents(), true);
//                dump($resData['code']);
                //可以在这里获取到每一个请求的请求参数吗
                $requestInfo = $requestsArray[$index]['params'];
                $requestAdvId = $requestInfo['advertiser_id'];
                if (!empty($resData)) {
                    if ($resData['code'] == 0 && !empty($resData['data']['logs'])) {
                        $this->handleInsertData($resData['data']['logs'], $requestAdvId);
                    }
                }
            },
            'rejected' => function ($reason, $index) {
                // 请求失败时的回调
                echo "Request {$index} failed: " . $reason->getMessage() . "\n";
            },
        ]);
// 发送请求并等待所有请求完成
        $promise = $pool->promise();
        $promise->wait();
        return true;
    }

    /**
     * @throws \Exception
     */
    protected function handleInsertData($data, $advId)
    {
        $insertData = [];

        foreach ($data as $item) {
            $insertData[] = [
                'adv_id' => $advId,
                'obj_id' => $item['object_id'],
                'content_log' => json_encode($item['content_log']),
                'content_title' => $item['content_title'],
                'object_name' => $item['object_name'],
                'object_type' => $item['object_type'],
                'operator' => $item['operator'],
                'opt_ip' => $item['opt_ip'],
                'opt_time' => strtotime($item['create_time']),
            ];
        }
        if($insertData){
            $objOptLogModel = new QcObjOptLog();
            return $objOptLogModel->saveAll($insertData);
        }
       return true;
    }
}