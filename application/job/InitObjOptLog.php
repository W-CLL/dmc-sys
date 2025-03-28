<?php

namespace app\job;

use app\admin\model\QcObj;
use app\admin\model\QcObjOptLog;
use app\common\model\Queue;
use app\qcdatahandle\controller\ComFun;
use Closure;
use fast\Date;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use jlqc\FundManagement;
use think\Cache;
use think\Exception;
use think\Log;
use think\queue\Job;


class InitObjOptLog
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
        } catch (Exception $e) {
            $insert_data = [
                'job_name' => '初始化计划操作日志',
                'job_id' => $jobId,
                'class_name' => 'app\job\InitObjOptLog',
                'queue_name' => 'initObjOptLog',
                'relation_table' => '',
                'job_data' => $data,
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
    protected function doJob($job_data)
    {

        try {
            $requests =  $this->buildGuzzleRequest($job_data);
            list($res,$msg)= $this->sendGuzzleRequest($requests);
            echo "返回内容1：".$res.$msg;
            if($msg){
                throw new Exception($msg);
            }
            return $res;
        } catch (\Exception $e) {
            // 记录异常
           throw new Exception($e->getMessage());
        }
    }

    /**
     * 构建请求
     * @param $data
     * @return Closure
     */
    protected function buildGuzzleRequest($data)
    {
        $access_token = Cache::get("qc_access_token");
        // 获取需要处理的区间
        $requests = [];
        $count = ceil(count($data['object_ids']) / 20); // 计算分页数
        // 分页处理

        for ($i = 0; $i < $count; $i++) {
            $start = $i * 20;
            $object_ids = array_slice($data['object_ids'], $start, 20);
            $params = [
                "advertiser_id" => (int)$data['advertiser_id'],
                'object_id' => $object_ids,
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                "page" => 1,
                "page_size" => 20,
            ];
            // 构建请求
            $request = new Request('GET', 'https://ad.oceanengine.com/open_api/2/tools/log_search/', [
                'Access-Token' => $access_token,
                'Content-Type' => 'application/json'
            ], json_encode($params));
            $requests[] = ['request' => $request, 'params' => $params];
        }
        return function () use ($requests) {
            yield from $requests; // 返回请求数组
        };
    }

    /**
     * 发送请求
     * @param $requests
     * @return array
     */
    protected function sendGuzzleRequest($requests)
    {
        $queue = new Queue();
        $requestsArray = iterator_to_array($requests(10));
        $guzzleClient = new Client();
        $pool = new Pool($guzzleClient, array_column($requestsArray, 'request'), [
            'concurrency' => 10, // 并发请求数量
            'fulfilled' => function ($response, $index) use (&$insertData, &$is_empty, &$requestsArray, $queue, &$is_error,&$msg,&$is_done) {
                $resData = json_decode($response->getBody()->getContents(), true);
//                dump($resData);
                //可以在这里获取到每一个请求的请求参数吗
                $requestInfo = $requestsArray[$index]['params'];
                $requestAdvId = $requestInfo['advertiser_id'];
                if (!empty($resData)) {
                    if ($resData['code'] == 0 && !empty($resData['data']['logs'])) {
                        $totalNumber = $resData['data']['page_info']['total_number'];
                        $totalPage = $resData['data']['page_info']['total_page'];
                        if ($totalNumber <= 20 && $totalNumber > 0) {
                           $res =  $this->handleInsertData($resData['data']['logs'], $requestAdvId);
                            if (!$res) {
                                $is_done = false;
                                $msg = $res;

                            }
                            $is_done = true;
                        } elseif ($totalNumber > 20) {
                            $queueData = [
                                'adv_id' => $requestAdvId,
                                'obj_ids' => $requestInfo['object_id'],
                                'start_time' => $requestInfo['start_time'],
                                'end_time' => $requestInfo['end_time'],
                                'total_page' => $totalPage,
                                'total_number' => $totalNumber,
                                'from_page' => 2,
                            ];
                            //先把第一页写进去
                            $res = $this->handleInsertData($resData['data']['logs'], $requestAdvId);
                            if (!$res) {
                                $is_done = false;
                                $msg = $res;

                            }
                            $is_done = true;
                            //从第二页开始用队列进行写入
                            $queue->addQueue('插入计划操作日志', 'app\job\InsertObjOptLog', 'insertObjOptLog', $queueData);
                        }
                    } elseif ($resData['code'] == 0 && empty($resData['data']['logs'])) {
                        $is_done = true;
                    } else {
                        $is_done = true;
                       $msg = $resData['message'];
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
        return [$is_done,$msg=''];
    }

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

        $objOptLogModel = new QcObjOptLog();
        return $objOptLogModel->saveAll($insertData);
    }
}
