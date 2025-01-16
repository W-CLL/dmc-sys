<?php

namespace app\qcdatahandle\controller;


use app\common\controller\Frontend;
use app\common\model\QcAdvDayCost;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use think\Cache;
use think\Db;


class InitQcObjCost extends Frontend
{

    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';
    protected $layout = '';

    public function moreExcute()
    {
      for($i=0;$i<5;$i++){
        $this->initQcAdvConstWithMon();
      }
      die;
    }

    /**
     * 初始化广告计划近一个月的消耗
     */
    public function initQcAdvConstWithMon()
    {
        dump('初始化完了，禁止访问!');
        die;
        set_time_limit(360);
        $qcObjDayCostModel = new QcAdvDayCost();
//        $existedIds = $qcObjDayCostModel->group('obj_id')->column('obj_id');
        $existedIds =  trim(Cache::get('qc_handle_obj_ids',0),',');
        $existedIds =  array_unique(explode(',',$existedIds));



        $result = Db::name('qc_obj')
            ->field('advertiser_id, count(object_id) as obj_num')
            ->where(['object_id'=> ['not in', $existedIds]])
            ->group('advertiser_id')
            ->order('advertiser_id desc')
            ->limit(5)
            ->select();



        dump($result);
dump(count($existedIds));
die;
        if(empty($result)){
            dump('已经全部初始化完毕');
            die;
        }
        $data1 = [];
        $emptyCostObjIds = '';
        foreach ($result as $item) {
            $page = ceil($item['obj_num'] / 100);
            if ($item['obj_num'] >= 100) {
                for ($i = 1; $i <= $page; $i++) {
                    $list = Db::name('qc_obj')
                        ->where(['object_id' => ['not in', $existedIds], 'advertiser_id' => $item['advertiser_id']])
                        ->page($i)
                        ->limit(100)
                        ->column('object_id');
                    $objIds = array_map('intval', $list);
                    $requests = $this->buildGuzzleRequest($item['advertiser_id'], $objIds);
                    $emptyCostObjIds .= ','.implode(',',$list).',';
                    if(count($list) <= 100){
                        break;
                    }
                }
                $insertDatas = $this->sendGuzzleRequest($requests);

                $data1[] = $insertDatas;
            }
            $list = Db::name('qc_obj')
                ->where(['object_id' => ['not in', $existedIds], 'advertiser_id' => $item['advertiser_id']])
                ->column('object_id');
            $emptyCostObjIds .= ','.implode(',',$list).',';
            $objIds = array_map('intval', $list);
            $requests = $this->buildGuzzleRequest($item['advertiser_id'], $objIds);
            $insertData = $this->sendGuzzleRequest($requests);
//            if(!empty($insertData) || !empty($data1)){
                array_push($data1, $insertData);
//            }

//            dump($insertData);
//            $fanllyData[] = array_merge($insertData, $insertDatas);
        }

        foreach ($data1 as $item) {
            if (empty($item)) {
                continue;
            } else {
                foreach ($item as &$value) {
                    $value['cost_date'] = strtotime($value['cost_date']);
                }
                $res = $qcObjDayCostModel->saveAll($item);
                if (!$res) {
                    dump($res);
                    echo "插入失败" . PHP_EOL;
                    die;
                }
            }
        }
        Cache::set('qc_handle_obj_ids', implode(',', $existedIds)  . $emptyCostObjIds);
        echo "插入成功" . PHP_EOL;
//        dump($fanllyData);

    }

    protected function sendGuzzleRequest($requests)
    {
        $insertData = [];
        $is_empty = false;
        $guzzleClient = new Client();
        $pool = new Pool($guzzleClient, $requests(10), [
            'concurrency' => 20, // 并发请求数量
            'fulfilled' => function ($response, $index) use (&$insertData, &$is_empty) {
                $resData = json_decode($response->getBody()->getContents(), true);
                if (!empty($resData)) {
                    if ($resData['code'] == 0 && !empty($resData['data']['list'])) {
                        foreach ($resData['data']['list'] as $item) {
                            $insertData[] = [
                                'adv_id' => $item['advertiser_id'],
                                'obj_id' => $item['ad_id'],
                                'cost' => $item['stat_cost'],
                                'cost_date' => $item['stat_datetime'],
                            ];
                        }
                    } else {
                        $is_empty = true;
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

        return $insertData;

    }

    protected function buildGuzzleRequest($advId, $objIds)
    {
        $access_token = Cache::get("qc_access_token");
        return function ($total_page) use ($access_token, $advId, $objIds) {
            $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/report/ad/get/";
            $headers = [
                'Access-Token' => $access_token,
                'Content-Type' => 'application/json'
            ];
            $params = array(
                "advertiser_id" => (int)$advId,
                "start_date" => date('Y-m-d', strtotime('-29 days')),
                "end_date" => date('Y-m-d', time()),
                "page" => 1,
                "fields" => ['stat_cost'],
                "page_size" => 100,
                "order_type" => "DESC",
                "filtering" => array(
                    "ad_ids" => $objIds,
                    "marketing_goal" => 'ALL'
                ),
                'time_granularity' => 'TIME_GRANULARITY_DAILY'
            );
            yield new Request('GET', $url, $headers, json_encode($params));
        };
    }

    /**
     * 1h更新一次当天计划消耗
     * @return void
     */
    public function updateCurrDayObjCost()
    {

    }

}