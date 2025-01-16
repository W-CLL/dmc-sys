<?php


namespace app\qcdatahandle\controller;

use app\admin\model\QcObjOptLog;
use app\admin\model\QcObj;
use app\common\library\Log;
use app\common\model\Queue;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use think\Cache;
use think\cache\driver\Redis;
use think\Controller;

class InitObjOptLog extends Controller
{

//    public function index($day = 2)
//    {
//        for ($i = 0; $i < 20; $i++) {
//            $this->intObjOptLog($day);
//        }
//        $objModel = new QcObj();
//        $exist = QcObjOptLog::group('adv_id')->column('adv_id');
//        $emptyAdvId = Cache::get('empty_adv_id', 0);
//        $exist = array_merge($exist, explode(',', $emptyAdvId));
//        $exist = array_unique($exist);
//        echo "处理了" . count($exist) . '个,还剩下' . $objModel->distinct('adv_id')->where(['adv_id' => ['not in', $exist]])->count();
//        echo '</br>';
//    }

    public function intObjOptLog($day)
    {
        dump('初始化完了，禁止访问!');
        die;
        // 设置超时时间
        set_time_limit(360);
        $redis = Cache::store('redis');
//        dump($redis->rm('empty_adv_id_'.$day));
//        dump($redis->get('empty_adv_id_'.$day));
////        die;
        $where = '';
        if ($day == 1) {
            $comFun = new ComFun();
            //筛选已经处理了时间区间（前60天-前30天的）
            list($start_date, $end_date) = $comFun->getSearchDate(2);
            $where = 'opt_time not between ' . strtotime($start_date . " 00:00:00") . " and " . strtotime($end_date . " 23:59:59");
        }
        //检查已处理的广告账户
        $objModel = new QcObj();
        $emptyAdvId = $redis->get('empty_adv_id_'.$day, ''); // 获取空广告账户缓存
        $processedAdvIds = QcObjOptLog::group('adv_id')
            ->where($where)
            ->column('adv_id'); // 获取已处理广告账户
        // 合并并去重已处理的广告账户
        $allProcessedAdvIds = array_unique(array_merge($processedAdvIds, explode(',', $emptyAdvId)));
        // 通过计划列表里面的广告账户，获取下一个需要处理的广告账户
        //（不从总的（company表）去获取）
        $advId = $objModel
            ->distinct('adv_id')
            ->where(['adv_id' => ['not in', $allProcessedAdvIds]])
            ->order('obj_create_time', 'asc')
            ->limit(1)
            ->value('adv_id');
        // 如果没有待处理的广告账户
        if (!$advId) {
            echo "已经全部处理完了";
            return;
        }
        // 获取广告账户下的计划列表
        $list = $objModel
            ->where(['adv_id' => $advId])
            ->order('obj_create_time', 'asc')
            ->limit(1000)
            ->column('obj_id');
        if (empty($list)) {
            echo "该广告账户没有可处理的计划";
            return;
        }
        $list = array_map('intval', $list); // 将计划id转为整型
        try {
            $requests = $this->buildGuzzleRequest(count($list), $list, $advId, $day);
            $res = $this->sendGuzzleRequest($requests);
            // 如果一批请求没有数据，更新缓存
            if ($res) {
                $redis->set('empty_adv_id_'.$day, $advId . ',' . $emptyAdvId);
            }
        } catch (\Exception $e) {
            // 记录异常
            Log::error("Error in processing AdvId: {$advId}. Message: {$e->getMessage()}");
        }
        echo "处理了" . count($allProcessedAdvIds) . '个,还剩下' . $objModel->where(['adv_id' => ['not in', $allProcessedAdvIds]])->group('adv_id')->count();
        echo '</br>';
    }

    /**
     * 构建请求
     * @param int $count
     * @param array $objIds
     * @param $advId
     * @param $day
     * @return Closure
     */
    protected function buildGuzzleRequest($count = 1, $objIds = [], $advId, $day)
    {
        $access_token = Cache::get("qc_access_token");
        // 获取需要处理的区间
        $comFun = new ComFun();
        list($start_date, $end_date) = $comFun->getSearchDate($day);
        echo $start_date."--".$end_date.'</br>';
        $requests = [];
        $count = ceil($count / 20); // 计算分页数
        // 分页处理
        for ($i = 0; $i < $count; $i++) {
            $start = $i * 20;
            $object_ids = array_slice($objIds, $start, 20);
            $params = [
                "advertiser_id" => (int)$advId,
                'object_id' => $object_ids,
                'start_time' => $start_date . ' 00:00:00',
                'end_time' => $end_date . ' 23:59:59',
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
            'concurrency' => 50, // 并发请求数量
            'fulfilled' => function ($response, $index) use (&$insertData, &$is_empty, &$requestsArray, $queue, &$is_error) {
                $resData = json_decode($response->getBody()->getContents(), true);
                //可以在这里获取到每一个请求的请求参数吗
                $requestInfo = $requestsArray[$index]['params'];
                $requestAdvId = $requestInfo['advertiser_id'];
                if (!empty($resData)) {
                    if ($resData['code'] == 0 && !empty($resData['data']['logs'])) {
                        $totalNumber = $resData['data']['page_info']['total_number'];
                        $totalPage = $resData['data']['page_info']['total_page'];
                        if ($totalNumber <= 20 && $totalNumber > 0) {
                            $res = $this->handleInsertData($resData['data']['logs'], $requestAdvId);
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
//                            dump($res);
                            if (!$res) {
                                dump($res);
                            }
                            //从第二页开始用队列进行写入
                            $queue->addQueue('插入计划操作日志', 'app\job\InsertObjOptLog', 'insertObjOptLog', $queueData);
                        }
                    } elseif ($resData['code'] == 0 && empty($resData['data']['logs'])) {
                        $is_empty = true;
                    } else {
                        $is_error = true;
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
        return $is_empty ?: $is_error;
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