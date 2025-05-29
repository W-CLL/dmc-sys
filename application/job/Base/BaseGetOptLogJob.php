<?php

namespace app\job\Base;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use think\Cache;
use think\Exception;
use think\queue\Job;

abstract class BaseGetOptLogJob
{
    /**
     * 获取对应的日志模型类名（子类必须实现）
     */
    abstract protected function getLogModelClass();

    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        $queueData = $queueModel->where('job_id', $jobId)->find();

        if (!$queueData) {
            return $this->deleteJobAndReturn($job);
        }

        try {
            $isJobDone = $this->doJob($data, $queueData);

            if ($isJobDone) {
                $queueData->save(['id' => $queueData['id'], 'status' => 1, 'msg' => "处理完成"]);
            } else if ($job->attempts() > 3) {
                $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => '超过最大重试次数']);
            }

            return $this->deleteJobAndReturn($job);

        } catch (Exception $e) {
            $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => $e->getMessage()]);
            return $this->deleteJobAndReturn($job, $e->getMessage());
        }
    }

    protected function doJob($data, $queueData)
    {
        $requests = $this->buildGuzzleRequest($data);
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
            for ($i = $data['from_page']; $i <= $data['total_page']; $i++) {
                $params = [
                    "advertiser_id" => $data['adv_id'],
                    "object_id" => $data['obj_ids'],
                    "start_time" => $data['start_time'],
                    "end_time" => $data['end_time'],
                    "page" => $i,
                    "page_size" => 20,
                ];
                $request = new Request('GET', $url, $headers, json_encode($params));
                $requests[] = [
                    'request' => $request,
                    'params' => $params,
                ];
            }
            yield from $requests;
        };
    }

    protected function sendGuzzleRequest($requests)
    {
        $requestsArray = iterator_to_array($requests(20));
        $guzzleClient = new Client();
        $pool = new Pool($guzzleClient, array_column($requestsArray, 'request'), [
            'concurrency' => 10,
            'fulfilled' => function ($response, $index) use (&$requestsArray) {
                $resData = json_decode($response->getBody()->getContents(), true);
                $requestInfo = $requestsArray[$index]['params'];
                $requestAdvId = $requestInfo['advertiser_id'];

                if (!empty($resData) && $resData['code'] == 0 && !empty($resData['data']['logs'])) {
                    $this->handleInsertData($resData['data']['logs'], $requestAdvId);
                }
            },
            'rejected' => function ($reason, $index) {
                echo "Request {$index} failed: " . $reason->getMessage() . "\n";
            },
        ]);

        $promise = $pool->promise();
        $promise->wait();
        return true;
    }

    /**
     * 处理插入数据（统一逻辑）
     * @param array $data
     * @param int $advId
     * @return bool
     * @throws Exception
     */
    protected function handleInsertData(array $data, int $advId): bool
    {
        $insertData = [];
        $model_class = $this->getLogModelClass();
        $model = new $model_class();

        foreach ($data as $item) {
            // 检查是否已存在该记录
            $exists = $model
                ->where([
                    'adv_id' => $advId,
                    'obj_id' => $item['object_id'],
                    'opt_ip' => $item['opt_ip'],
                    'opt_time' => strtotime($item['create_time']),
                    'object_name' => $item['object_name'],
                    'content_title' => $item['content_title']
                ])
                ->count();

            if ($exists > 0) continue;

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
            $res =  $model->saveAll($insertData);
            if(!$res){
                throw  new Exception($res);
            }
        }
        return true;

    }

    /**
     * 删除任务并返回结果
     */
    private function deleteJobAndReturn(Job $job, ?string $logMessage = null): string
    {
        $job->delete();
        return '';
    }
}
