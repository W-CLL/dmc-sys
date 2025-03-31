<?php

namespace app\job;

use app\admin\model\QcGlobalObj;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use think\Cache;
use think\Db;
use think\Exception;
use think\queue\Job;


class InsertDayGlobalObj
{

    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        $queueData = $queueModel->where('job_id', $jobId)->find();
        try {
            $isJobDone = $this->doJob($data);
            if ($isJobDone) {

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
            $insert_data = [
                'job_name' => '插入当天新增全域计划',
                'job_id' => $jobId,
                'class_name' => 'app\job\InsertDayGlobalObj',
                'queue_name' => 'insertDayGlobalObj',
                'relation_table' => '',
                'job_data' =>json_encode( $data),
                'remark' => '',
                'msg' => $e->getMessage(),
                'status' => 2,
            ];
            if($queueData){
                $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' =>$e->getMessage()]);
                $job->delete();
                return '';
            }
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
//        sleep(10);
        $requests = $this->buildGuzzleRequest($data['adv_list'],$data['params']);
        list($insertData,$error,$need_rebuild) = $this->sendGuzzleRequest($requests);
        echo date('m-d H:i:s');
        if($need_rebuild){
            echo "部分需要重新处理";
            $job_data = [
                'adv_list'=>$need_rebuild,
                'params'=>$data['params']
            ];
            \think\Queue::push('app\job\InsertDayGlobalObj', $job_data, "insertDayGlobalObj");
        }
//        if($error){
//            throw new Exception(json_encode($error));
//        }
        if(empty($insertData)){
            echo "都是空的";
            return true;
        }else{
            return $this->saveNewObj($insertData);
        }

    }

    /**
     * 插入当天新增全域计划
     * @param $list
     * @return bool
     * @throws \Exception
     */
    protected function saveNewObj($list)
    {
        $objModel = new QcGlobalObj();
        $adv = [];
        foreach ($list as $key => $item) {
            if ($item['obj_id']) {
                if (!isset($adv[$item['adv_id']])) {
                    $adv[$item['adv_id']] = [];
                }
                $adv[$item['adv_id']][] = $item['obj_id'];
            }
        }
        $exitedIds =[];
        foreach ($adv as $key=> $item){
            $exitedIds[] = $objModel->where(['adv_id'=>$key,'obj_id' => ['in', $item]])->column('obj_id');
        }
        $exitedIds = array_merge(...$exitedIds);

//        $keys = array_keys($adv);
//        $values = array_values($adv);
//        $flattenedValues = array_merge(...$values);
//        $exitedIds = $objModel->where(['obj_id' => ['in', $flattenedValues]])->column('obj_id');
        $afterData = array_filter($list, function ($item) use ($exitedIds) {
            return !in_array($item['obj_id'], $exitedIds);
        });
        echo '后'.count($afterData);
        if ($afterData) {
            echo "写进了";
            $res = $objModel->saveAll($afterData);
            if ($res) {
                return true;
            } else {
                return false;
            }
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
    protected function buildGuzzleRequest($advIds, $params): array
    {

        $access_token = Cache::get("qc_access_token");
        $url ="https://api.oceanengine.com/open_api/v1.0/qianchuan/uni_promotion/list/";
        $headers = [
            'Access-Token' => $access_token,
            'Content-Type' => 'application/json'
        ];
        $requests = [];
        $params['filtering'] =  json_encode($params['filtering']);
        $params['fields'] =  json_encode($params['fields']);
        foreach ($advIds as $advId) {
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
        $error = [];
        $need_rebuild = [];
        $guzzleClient = new Client();
        // 并发请求
        $pool = new Pool($guzzleClient, array_column($requests, 'request'), [
            'concurrency' => 10,  // 控制并发数
            'fulfilled' => function ($response, $index) use (&$insertData, $requests, &$error,&$need_rebuild) {
                $resData = json_decode($response->getBody()->getContents(), true);
                $requestInfo = $requests[$index]['params'];
                $requestAdvId = $requestInfo['advertiser_id'];
                if (!empty($resData) && $resData['code'] == 0 && !empty($resData['data']['ad_list'])) {
                    foreach ($resData['data']['ad_list'] as $item) {
                        $insertData[] = [
                            'adv_id' => $requestAdvId,
                            'obj_id' => $item['ad_info']['id'],
                            'name' => $item['ad_info']['name'],
                            'obj_status' => $item['ad_info']['status'],
                            'opt_status' => $item['ad_info']['opt_status'],
                            'marketing_goal' => $item['ad_info']['marketing_goal'],
                            'smart_bid_type' => $item['ad_info']['smart_bid_type'],
                            'obj_create_time' => strtotime($item['ad_info']['create_time']),
                            'obj_modify_time' => strtotime($item['ad_info']['modify_time']),
                            'start_time' => strtotime($item['ad_info']['start_time']),
                            'end_time' => strtotime($item['ad_info']['end_time']),
                            'product_info' => json_encode($item['product_info']),
                            'room_info' => json_encode($item['room_info']),
                            'stats_info' => json_encode($item['stats_info']),
                        ];
                    }
                    if ($resData['data']['page_info']['total_page'] > 1) {
                        $requestInfo['page'] = 2;
                        \think\Queue::push('app\job\InitGlobalObj', $requestInfo, "initGlobalObj");
                    }
                }elseif($resData['code'] !=0){
                    if(!$this->skipIfContainsError($resData['message'])){
//                        echo $resData['message'];
//                        $error[] = $resData['message'];
                        $need_rebuild[] = $requestAdvId;
                    };
                }

            },
            'rejected' => function ($reason, $index) {
                echo "Request {$index} failed: " . $reason->getMessage() . "\n";
            },
        ]);
        // 等待所有请求完成
        $promise = $pool->promise();
        $promise->wait();
        return [$insertData,$error,$need_rebuild];
    }


  public  function skipIfContainsError($message): bool
  {
        // 定义需要匹配的关键词列表（支持中英文）
        $keywords = [
            '/广告主账号已禁用/iu',  // 中文关键词（忽略大小写）
            '/No permission to operate account/iu',  // 英文关键词（忽略大小写）
        ];
        // 检查是否匹配其中一个关键词
        foreach ($keywords as $pattern) {

            if (preg_match($pattern, $message)) {

                return true;
            }
        }
        return false;
    }

}
