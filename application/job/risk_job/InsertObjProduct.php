<?php

namespace app\job\risk_job;

use app\admin\model\QcGlobalObj;
use app\common\model\ObjProduct;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use think\Cache;
use think\Exception;
use think\queue\Job;


class InsertObjProduct
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
                return '';
            } else {
                if ($job->attempts() > 3) {
                    $job->delete();
                    return '';
                }
            }
        } catch (Exception|\Exception $e) {
            $insert_data = [
                'job_name' => '插入计划商品信息',
                'job_id' => $jobId,
                'class_name' => 'app\job\risk_job\InsertObjProduct',
                'queue_name' => 'insertObjProduct',
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
//        sleep(1);
        $requests = $this->buildGuzzleRequest($data);
       $insertData = $this->sendGuzzleRequest($requests);
        if (empty($insertData)) {
            echo "空的";
            return true;
        } else {
            try {
                $this->saveObjProduct($insertData);
                return true;
            } catch (Exception $exception) {
                dump($exception->getMessage());
                throw  new Exception($exception->getMessage());
            }
        }
    }

    /**
     *
     * @param $list
     * @return void
     * @throws \Exception
     */
    protected function saveObjProduct($list)
    {
        $product_model = new ObjProduct();
        $obj_model = new QcGlobalObj();
        $result = array_values(array_reduce($list, function ($carry, $item) {
            $key = $item['obj_id'] . '-' . $item['product_id'];
            if (!isset($carry[$key])) {
                $carry[$key] = $item;
            }
            return $carry;
        }, []));
        $goodsIds = array_column($result, 'product_id');
        $advIds = array_column($result, 'obj_id');
        $existingRecords = $product_model->where([
            'obj_id'=> ['in',$advIds],
            'product_id'=>['in', $goodsIds],
        ])->select();

        $update_count = count($existingRecords);
        $total_count = count($result);
        echo "更新".$update_count.'条';
        echo "插入".($total_count-$update_count).'条';
        $existingMap = [];
        foreach ($existingRecords as $record) {
            $existingMap[$record['obj_id'] . '-' . $record['product_id']] = $record;
        }
        $need_update_obj = [];
        foreach ($result as &$item) {
            $key = $item['obj_id'] . '-' . $item['product_id'];
            if(in_array($item['obj_status'],['DELETE',"TIME_DONE","FROZEN"])){
                $need_update_obj[] = $item['obj_id'];
            }
            unset($item['obj_status']);
            if (isset($existingMap[$key])) {
                $item['id'] = $existingMap[$key]['id'];
            }
        }
        $obj_model->where(['obj_id'=>['in',$need_update_obj]])->update(['is_handle'=>1]);
        $product_model->saveAll($result);
    }


    /**
     * 构建请求
     * @param $data
     * @return array
     */
    protected function buildGuzzleRequest($data): array
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/uni_promotion/ad/product/get/";
        $headers = [
            'Access-Token' => $access_token,
            'Content-Type' => 'application/json'
        ];
        $requests = [];
        $fields =  json_encode(['product_show_count_for_roi2']);
        foreach ($data['obj_ids'] as $ad_id=>$status) {
            $params['advertiser_id'] = (int)$data['adv_id'];
            $params['ad_id'] = (int)$ad_id;
            $params['start_date'] = $data['start_time'];
            $params['end_date'] = $data['end_time'];
            $params['fields'] = $data['fields']??$fields;
            $params['page'] = $data['page']??1;
            $params['page_size'] = $data['page_size']??100;

            $query = http_build_query($params);
            $params['obj_status'] = $status;
            $urlWithQuery = $url . '?' . $query;
            $request = new Request('GET', $urlWithQuery, $headers);
            $requests[] = ['request' => $request, 'params' =>$params];
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
        if (empty($this->connectionPool)) {
            $this->connectionPool = new Client([
                'timeout' => 10,
                'verify' => false,
                'curl' => [
                    CURLOPT_FORBID_REUSE => false,  // 允许连接复用
                    CURLOPT_FRESH_CONNECT => false
                ],
                'pool' => [
                    'max_connections' => 20, // 最大连接数
                    'idle_timeout' => 30     // 空闲超时(秒)
                ]
            ]);
        }
        $pool = new Pool($this->connectionPool, array_column($requests, 'request'), [
            'concurrency' => 2,  // 控制并发数
            'fulfilled' => function ($response, $index) use (&$insertData, $requests) {
                $resData = json_decode($response->getBody()->getContents(), true);
                $requestInfo = $requests[$index]['params'];
                $requestAdvId = $requestInfo['advertiser_id'];
                $objId = $requestInfo['ad_id'];
                if (!empty($resData) && !empty($resData['data']['page_info'])) {
                    foreach ($resData['data']['product_list'] as $item) {
                        $product_info = $item['product_info'];
                        $insertData[] = [
                            'adv_id' => $requestAdvId,
                            'obj_id' => $objId,
                            "obj_status"=>$requestInfo['obj_status'],
                            'product_id' => $product_info['product_id'],
                            "name" => $product_info['product_name'],
                            "product_image" =>$product_info['product_image'],
                            "tag" => json_encode($product_info['product_tag']),
                            "is_del" => $item['is_del'],
                            "audit_status" => strtotime($product_info['audit_status']),
                        ];
                    }
                    if ($resData['data']['page_info']['total_page'] > $requestInfo['page']) {//处理商家分页
                        echo "t" . $resData['data']['page_info']['total_page'] . "页,c".$requestInfo['page']."页  ";
                        $rebuild_data = [
                            'adv_id' => $requestAdvId,
                            'obj_ids' => [$objId=>$requestInfo['obj_status']],
                            'start_time' =>$requestInfo['start_date'],
                            'end_time' => $requestInfo['start_date'],
                            'page' => $requestInfo['page'] + 1,
                        ];
                        \think\Queue::later(5, 'app\job\risk_job\InsertObjProduct',$rebuild_data,"insertObjProduct");
                    }
                } elseif ($resData['code'] != 0) {
                    echo $resData['message'];
                    if (!$this->skipIfContainsError($resData['message'])) {
                        $rebuild_data = [
                            'adv_id' => $requestAdvId,
                            'obj_ids' => [$objId=>$requestInfo['obj_status']],
                            'start_time' =>$requestInfo['start_date'],
                            'end_time' => $requestInfo['end_date'],
                            'page' => $requestInfo['page'],
                        ];
                        \think\Queue::later(5, 'app\job\risk_job\InsertObjProduct',$rebuild_data,"insertObjProduct");
                    }
                }
            },
            'rejected' => function ($reason, $index) use ($requests) {
                $adv_id = $requests[$index]['params']['advertiser_id'];
                $obj_id = $requests[$index]['params']['ad_id'];
                $rebuild_data = [
                    'adv_id' => $adv_id,
                    'obj_ids' =>[$obj_id=> $requests[$index]['params']['obj_status']],
                    'start_time' =>$requests[$index]['params']['start_date'],
                    'end_time' => $requests[$index]['params']['end_date'],
                    'page' => $requests[$index]['params']['page'],
                ];
                \think\Queue::later(5, 'app\job\risk_job\InsertObjProduct',$rebuild_data,"insertObjProduct");
                echo "失败请求，重启\n".$reason;
            },
        ]);
        // 等待所有请求完成
        $promise = $pool->promise();
        $promise->wait();
        return $insertData;
    }


    public function skipIfContainsError($message): bool
    {
        $keywords = [
            '/已禁用/iu',
            '/广告主状态异常/iu',
            '/服务内部错误/iu',
            '/No permission to operate account/iu',
            '/当前计划不支持查询计划下商品列表/iu',
            '/无法识别的user类型/iu',
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
