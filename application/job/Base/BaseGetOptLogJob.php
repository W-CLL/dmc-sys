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


    /**
     * 获取本次任务名称（子类必须实现）
     */
    abstract protected function getThisJobName();

    /**
     * 获取本次任务类（子类必须实现）
     */
    abstract protected function getThisJobClass();
    /**
     * 获取本次队列名（子类必须实现）
     */
    abstract protected function getThisQueueName();
    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        $queueData = $queueModel->where('job_id', $jobId)->find();
        try {
            $isJobDone = $this->doJob($data, $queueData);
            if ($isJobDone) {
                if ($queueData) {
                    $queueData->save(['id' => $queueData['id'], 'status' => 1, 'msg' => "处理完成"]);
                }
                $job->delete();
            } else if ($job->attempts() > 3) {
                $job->delete();
            }
            return $this->deleteJobAndReturn($job);
        } catch (Exception $e) {
            $insert_data = [
                'job_name' => $this->getThisJobName(),
                'job_id' => $jobId,
                'class_name' => $this->getThisJobClass(),
                'queue_name' => $this->getThisQueueName(),
                'relation_table' => '',
                'job_data' => json_encode($data),
                'remark' => '',
                'msg' => $e->getMessage(),
                'status' => 2,
            ];
            if ($queueData) {
                $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => $e->getMessage()]);
                $job->delete();
                return '';
            }
            $queueModel->save($insert_data);
            return $this->deleteJobAndReturn($job, $e->getMessage());
        }
    }

    protected function doJob($data, $queueData)
    {
        $requests = $this->buildGuzzleRequest($data);
        list($insArr, $needRetry) = $this->sendGuzzleRequest($requests);
        !empty($insArr)?$this->handleInsertData($insArr):0;
        !empty($needRetry)?$this->retry($needRetry):0;
        return true;
    }

    protected function buildGuzzleRequest($data)
    {
        return function ($total_page) use ($data) {
            $access_token = Cache::get("qc_access_token");
            $url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/tools/log_search/";
            $headers = [
                'Access-Token' => $access_token,
                'Content-Type' => 'application/json'
            ];
            $requests = [];
            if(isset($data['retry'])){
                $params = [
                    "advertiser_id" => $data['adv_id'],
                    "object_type" => "AD",
                    "object_id" => $data['obj_id'],
                    "start_time" => $data['start_time'],
                    "end_time" => $data['end_time'],
                    "page" => $data['page'],
                    "page_size" => 20,
                ];
                $query = http_build_query($params);
                $urlWithQuery = $url . '?' . $query;
                $request = new Request('GET', $urlWithQuery, $headers);
                $requests[] = ['request' => $request, 'params' => $params];
            }else{
                for ($i = $data['from_page']; $i <= $data['total_page']; $i++) {
                    $params = [
                        "advertiser_id" => $data['adv_id'],
                        "object_type" => "AD",
                        "object_id" => $data['obj_id'],
                        "start_time" => $data['start_time'],
                        "end_time" => $data['end_time'],
                        "page" => $i,
                        "page_size" => 20,
                    ];
                    $query = http_build_query($params);
                    $urlWithQuery = $url . '?' . $query;
                    $request = new Request('GET', $urlWithQuery, $headers);
                    $requests[] = ['request' => $request, 'params' => $params];
                }
            }
            yield from $requests;
        };
    }

    protected function sendGuzzleRequest($requests)
    {
        $insArr = [];
        $needRetry = [];
        $requestsArray = iterator_to_array($requests(20));
        $guzzleClient = new Client();
        $pool = new Pool($guzzleClient, array_column($requestsArray, 'request'), [
            'concurrency' => 2,
            'fulfilled' => function ($response, $index) use (&$requestsArray, &$insArr,  &$needRetry) {
                $resData = json_decode($response->getBody()->getContents(), true);
                $requestInfo = $requestsArray[$index]['params'];
                $requestAdvId = $requestInfo['advertiser_id'];
                if (!empty($resData) && $resData['code'] == 0 && !empty($resData['data']['logs'])) {
                    $insArr[$requestAdvId][] = $resData['data']['logs'];
                }elseif ($resData['code'] === 40110){ // 请求过多
                    $needRetry[$requestAdvId][] = [
                        'obj_id' => $requestInfo['object_id'],
                        'start_time' => $requestInfo['start_time'],
                        'end_time' => $requestInfo['end_time'],
                        'page' => $requestInfo['page'],
                    ];
                }
            },
            'rejected' => function ($reason, $index) {
                echo "Request {$index} failed: " . $reason->getMessage() . "\n";
            },
        ]);
        $promise = $pool->promise();
        $promise->wait();
        return [$insArr, $needRetry];
    }

    /**
     * 处理插入数据（统一逻辑）
     * @param array $data
     * @return bool
     * @throws Exception
     */
    protected function handleInsertData(array $data): bool
    {
        $insertData = [];
        $model_class = $this->getLogModelClass();
        $model = new $model_class();

        foreach ($data as $key => $value) {
            foreach ($value as $log){
                foreach ($log as $item){
                    // 检查是否已存在该记录
                    $exists = $model
                        ->where([
                            'adv_id' => $key,
                            'obj_id' => $item['object_id'],
                            'opt_ip' => $item['opt_ip'],
                            'opt_time' => strtotime($item['create_time']),
                            'content_title' => $item['content_title']
                        ])
                        ->find();

                    if ($exists) continue;

                    $insertData[] = [
                        'adv_id' => $key,
                        'obj_id' => $item['object_id'],
                        'content_log' => json_encode($item['content_log']),
                        'content_title' => $item['content_title'],
                        'object_name' => $item['object_name'],
                        'object_type' => $item['object_type'],
//                    'operator' => $item['operator_name'],
                        'operator' => $item['operator_id'],    // 傻逼接口同主体下返回的operator_name都一致，不得已用id字段存储
                        'opt_ip' => $item['opt_ip'],
                        'opt_time' => strtotime($item['create_time']),
                    ];
                }
            }
        }
        if($insertData){
            $res = $model->saveAll($insertData);
            if(!$res){
                throw new Exception($res);
            }
        }
        return true;

    }


    protected function retry($data){
        foreach ($data as $key => $item) {
            $params = [
                "advertiser_id" => (int)$key,
                'object_id' => $item['obj_id'],
                'start_time' => $item['start_time'],
                'end_time' => $item['end_time'],
                "page" => $item['page'],
                'retry' => true,
            ];
            \think\Queue::later(10, $this->getThisJobClass(), $params, $this->getThisQueueName());
        }
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
