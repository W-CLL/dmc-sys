<?php

namespace app\job\prequalification;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use think\Cache;
use think\Db;
use think\Exception;
use jlqc\FundManagement;
use think\queue\Job;


/**
 * 千川素材状态推送队列处理
 * 类型：status.material.qianchuan.realtime
 */
class Prequalification
{
    // 优化配置 - 从配置文件加载，针对抢占请求优化
    protected $maxRetries;
    protected $chunkSize;
    protected $maxConcurrency;
    protected $requestTimeout;




    public function __construct()
    {
        $configPath = __DIR__ . '/config/job_config.php';
        if (file_exists($configPath)) {
            $this->config = include $configPath;
        } else {
            // 默认配置
            $this->config = [
                'database' => ['chunk_size' => 30],
                'http' => ['max_concurrency' => 3, 'request_timeout' => 15],
                'retry' => ['max_retries' => 3],
            ];
        }

        // 设置属性 - 针对抢占请求优化
        $this->chunkSize = $this->config['database']['chunk_size'] ?? 30;
        $this->maxConcurrency = $this->config['http']['max_concurrency'] ?? 2; // 提高并发数
        $this->requestTimeout = $this->config['http']['request_timeout'] ?? 10; // 降低超时时间
        $this->maxRetries = $this->config['retry']['max_retries'] ?? 5; // 增加重试次数
    }


    /**
     * fire方法是消息队列默认调用的方法
     * @param Job $job 当前的任务对象
     * @param array|mixed $data 发布任务时自定义的数据
     */
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
            }
        } catch (Exception $e) {
            $delay = 60;
            if ($job->attempts() <= 3) {
                $delay = $delay * $job->attempts();
                $job->release($delay);
            } else {
                $insert_data = [
                    'job_name' => '预审提交',
                    'job_id' => $jobId,
                    'class_name' => 'app\job\prequalification\Prequalification',
                    'queue_name' => 'prequalification',
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
    }


    public function doJob($data){
        $res = FundManagement::get_adv_material_info([
            'advertiser_id' => (int)$data['advertiser_id'],
            'filtering' => json_encode([
                'material_ids' => $data['material_ids']
            ]),
            'page_size' => 50
        ]);
        if ($res['code'] != 0){
            throw new Exception("查询接口异常，状态码：". $res['code']);
        }
        if (empty($res['data']['list'])){
            return true;
        }
        $info = $res['data']['list'];
        $array = [];
        foreach ($info as $item) {
            $array[$item['id']]['filename'] = $item['filename'];
            $array[$item['id']]['material_id'] = $item['material_id'];
        }
        $requests = $this->buildGuzzleRequest($array,$data['advertiser_id']);
        list($res_data,$error_data,$msg_data) = $this->sendGuzzleRequest($requests);
        if (!empty($msg_data)){
            var_dump($msg_data);
        }
        if (!empty($error_data)){
            // 失败的重新扔进队列
            $material_ids = [];
            foreach ($error_data as $item){
                $material_ids[] = $array[$item]['material_id'];
            }
            if (!empty($material_ids)){
                \think\Queue::push('app\job\prequalification\Prequalification', ['advertiser_id' => $data['advertiser_id'], 'material_ids' => $material_ids], 'prequalification');
            }
            sleep(10);
        }

        foreach ($res_data as $key => $value) {
            Db::name('material_prequalification')->where(['material_id' => $array[$key]['material_id']])->update([
                'filename' => $array[$key]['filename'],
                'video_id' => $key,
                'object_id' => $value['object_id']
            ]);
        }

        return true;

    }


    /**
     * 构建请求
     * @param $data
     * @param $advertiser_id
     * @return array
     */
    protected function buildGuzzleRequest($data,$advertiser_id): array
    {

        $access_token = Cache::get("qc_access_token");
        $url = "https://api.oceanengine.com/open_api/v3.0/security/open_material_audit/";
        $headers = [
            'Access-Token' => $access_token,
            'Content-Type' => 'application/json'
        ];
        $requests = [];
        foreach ($data as $key => $item) {
            $params = [
                'account_id' => (int)$advertiser_id,
                'business_type' => 'QIAN_CHUAN',
                'type' => 'VIDEO',
                'data' => $key,
                'msg_type' => 'SEND'
            ];

            // 将参数编码为JSON格式
            $body = json_encode($params);
            $request = new Request('POST', $url, $headers, $body);
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
        $info = [];
        $error = [];
        if (empty($this->connectionPool)) {
            $this->connectionPool = new Client([
                'timeout' => $this->requestTimeout,
                'connect_timeout' => 2, // 降低连接超时
                'read_timeout' => $this->requestTimeout,
                'verify' => false,
                'curl' => [
                    CURLOPT_FORBID_REUSE => false, // 允许连接复用
                    CURLOPT_FRESH_CONNECT => false, // 不强制新连接
                    CURLOPT_TCP_KEEPALIVE => 1,
                    CURLOPT_TCP_KEEPIDLE => 10, // 降低保活时间
                    CURLOPT_TCP_KEEPINTVL => 5,
                    CURLOPT_NOSIGNAL => 1, // 避免信号中断
                ],
                'pool' => ['max_connections' => 50, 'idle_timeout' => 30] // 增加连接池
            ]);
        }
        $pool = new Pool($this->connectionPool, array_column($requests, 'request'), [
            'concurrency' => $this->maxConcurrency,  // 提高并发数抢占请求
            'fulfilled' => function ($response, $index) use (&$info, $requests, &$error, &$msg) {
                $resData = json_decode($response->getBody()->getContents(), true);
                $request_info = $requests[$index]['params'];
                $video_id = $request_info['data'];

                if ($resData['code'] == 0){
                    if ($resData['data']['result']){
                        $info[$video_id]['object_id'] = $resData['data']['object_id'];
                    }
                }else if ($resData['code'] == 40100 || $resData['code'] == 40110){
                    $error[] = $video_id;
                }else{
                    $msg[$video_id]['msg'] = $resData["message"];
                    $msg[$video_id]['code'] = $resData["code"];
                }
            },
//            'rejected' => function ($reason, $index) use (&$info, $requests) {
//                // 记录失败请求日志
//                \think\Log::error("预审请求失败: " . $reason->getMessage());
//            },
        ]);

        $promise = $pool->promise();
        $promise->wait();
        return [$info,$error,$msg];
    }
}
