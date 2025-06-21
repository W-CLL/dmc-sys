<?php

namespace app\job\Base;

use think\Cache;
use think\Exception;
use think\queue\Job;
use app\common\model\Queue;

abstract class BaseUpdateObjStatusJob
{
    /**
     * 获取模型类名（子类必须实现）
     */
    abstract protected function getModelClass();

    /**
     * 获取任务名称（用于日志或队列展示）
     */
    abstract public function getJobName(): string;

    /**
     * 获取队列名称（子类必须实现）
     */
    abstract public function getQueueName(): string;

    /**
     * 获取请求地址（子类必须实现）
     */
    abstract protected function getRequestUrl(): string;

    /**
     * 传入额外的参数
     */
    abstract protected function extraParams(): array;

    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        try {
            $isJobDone = $this->doJob($data);
            if ($isJobDone) {
                $queueData = $queueModel->where('job_id', $jobId)->find();
                if ($queueData) {
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
            if ($queueData) {
                $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => $e->getMessage()]);
                $job->delete();
                return '';
            }

            $insert_data = [
                'job_name' => $this->getJobName(),
                'job_id' => $jobId,
                'class_name' => static::class,
                'queue_name' => $this->getQueueName(),
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
     */
    protected function doJob($data): bool
    {
        if (empty($data['obj_list'])) {
            echo "没有列表\n";
            return true;
        }
        $requests = $this->buildGuzzleRequest($data);
        list($updateData, $error) = $this->sendGuzzleRequest($requests);
//        dump($error);
        if ($error) {
            echo "类名";
            echo static::class;
            \think\Queue::later(20,static::class, $error,$this->getQueueName());
        }
        if ($updateData) {
            return $this->updateObjStatus($updateData);
        }

        return true;
    }

    /**
     * 构建 Guzzle 请求
     */
    protected function buildGuzzleRequest($data): array
    {
        $access_token = Cache::get("qc_access_token");
        $url = $this->getRequestUrl();
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
                "ad_id" => (int)$obj_id,
            ];
            $params = array_merge($params, $this->extraParams() ?? []);


            $query = http_build_query($params);
            $urlWithQuery = $url . '?' . $query;
            $request = new \GuzzleHttp\Psr7\Request('GET', $urlWithQuery, $headers);

            $requests[] = ['request' => $request, 'params' => $params];
        }

        return $requests;
    }

    /**
     * 发送 Guzzle 请求
     */
    protected function sendGuzzleRequest($requests): array
    {
        $updateData = [];
        $guzzleClient = new \GuzzleHttp\Client();
        $error = [];

        $pool = new \GuzzleHttp\Pool($guzzleClient, array_column($requests, 'request'), [
            'concurrency' => 10,
            'fulfilled' => function ($response, $index) use (&$updateData, $requests, &$error) {
                $resData = json_decode($response->getBody()->getContents(), true);

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

                if ($resData['code'] != 0) {
//                    echo $resData['message'];
                    if(!skipIfContainsError($resData['message'],['当前广告主状态已禁用'])){
                        dump($resData['message']);
//                        die;
                        $error['adv_id'] = $requestAdvId;
                        $error['obj_list'][] = $requestObjId;
                    };
                }
            },
            'rejected' => function ($reason, $index) use (&$error) {
                $error[] = "Request {$index} failed: " . $reason->getMessage();
            },
        ]);

        $promise = $pool->promise();
        $promise->wait();

        return [$updateData, $error];
    }

    /**
     * 更新计划状态
     */
    protected function updateObjStatus(array $list): bool
    {
        $model_name = $this->getModelClass();
        $obj_model = new $model_name();

        foreach ($list as $item) {
            $item['update']['update_time'] = time();
            $obj_model->where($item['where'])->update($item['update']);
        }

        return true;
    }
}
