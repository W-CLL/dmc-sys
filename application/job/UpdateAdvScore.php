<?php

namespace app\job;


use app\common\model\AdvScore;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use think\Cache;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\Exception;
use think\exception\DbException;
use think\queue\Job;

class UpdateAdvScore
{

    /**
     * 完成的直接删，失败了的才记录进数据库
     * @param Job $job
     * @param $data
     * @return void
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws DbException
     */
    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        $queueData = $queueModel->where('job_id', $jobId)->find();
        try {
            if ($this->doJob($data)) {
                if ($queueData) {
                    $queueData->save(['id' => $queueData['id'], 'status' => 1, 'msg' => "处理完成"]);
                }
                $job->delete();
            }
            if ($job->attempts() > 3) {
                $job->delete();
            }
            return ;
        } catch (Exception $e) {
            $insert_data = [
                'job_name' => '更新广告主积分',
                'job_id' => $jobId,
                'class_name' => 'app\job\UpdateAdvScore',
                'queue_name' => 'upAdvScore',
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
    protected function doJob($data): bool
    {

        $requests = $this->buildGuzzleRequest($data);

        list($insertData, $need_rebuild) = $this->sendGuzzleRequest($requests);
        if ($need_rebuild) {
            $job_data = [
                'adv_list' => $need_rebuild,
                'params' => $data['params']
            ];
            \think\Queue::later(2, 'app\job\UpdateAdvScore', $job_data, "upAdvScore");
        }
//        dump($insertData);
        if ($insertData) {
            return $this->saveAdvScore($insertData);
        }
        return true;
    }

    /**
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws Exception
     */
    public function saveAdvScore($insertData): bool
    {
        $adv_score = new AdvScore();
        $save_data = [];
        foreach ($insertData as $list) {
            $score_info = $adv_score->where(['adv_id' => $list['adv_id'], 'year' => $list['year']])->find();
            if ($score_info) {
                $list['id'] = $score_info['id'];
            }
            $save_data[] = $list;
        }
        if ($save_data) {
            try {
                $adv_score->saveAll($save_data);
            } catch (\Exception $e) {
                echo $e->getMessage();
                throw new Exception($e->getMessage());
            }
            return true;
        }
        echo "都是空的";
        return true;
    }


    /**
     * 构建请求
     * @param $data
     * @return array
     */
    protected function buildGuzzleRequest($data): array
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://api.oceanengine.com/open_api/v3.0/security/score_total/get/";
        $headers = [
            'Access-Token' => $access_token,
            'Content-Type' => 'application/json'
        ];

        $requests = [];
        $params['filtering'] = json_encode($data['params']['filtering']);
        $params['business_line'] = $data['params']['business_line'];
        foreach ($data['adv_list'] as $advId) {
            $params["advertiser_id"] = (int)$advId;
            $query = http_build_query($params);
            $urlWithQuery = $url . '?' . $query;
            $request = new Request('GET', $urlWithQuery, $headers);
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
        $need_rebuild = [];
        $guzzleClient = new Client();
        // 并发请求
        $pool = new Pool($guzzleClient, array_column($requests, 'request'), [
            'concurrency' => 1,  // 控制并发数
            'fulfilled' => function ($response, $index) use (&$insertData, $requests, &$need_rebuild) {
                $resData = json_decode($response->getBody()->getContents(), true);
                $requestInfo = $requests[$index]['params'];
                $requestAdvId = $requestInfo['advertiser_id'];
//                dump($resData);
                if ($resData['code'] == 0 && !empty($resData['data']['score_info_list'])) {
//                    echo "123";
                    $one_score = 0;
                    $two_score = 0;
                    foreach ($resData['data']['score_info_list'] as $item) {
                        if($item['illegal_type'] == "ONECLASS" && $one_score ==0 ){
                            $one_score = $item['score'];
                        }
                        if($item['illegal_type'] == "TWOTHREECLASS" && $two_score ==0){
                            $two_score = $item['score'];
                        }
                    }
                    $insertData[] = [
                        "one_class_score" => $one_score,
                        "two_three_class_score" => $two_score,
                        "year" => date('Y'),
                        'adv_id' => $requestAdvId,
                        'request_id' => $resData['request_id']
                    ];
                } elseif ($resData['code'] != 0) {
//                    echo "1234";
                    if (!skipIfContainsError($resData['message'])) {
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
        return [$insertData, $need_rebuild];
    }

}
