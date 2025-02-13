<?php

namespace app\qcdatahandle\controller;

use app\admin\model\Company;
use app\common\model\QcAdvDayCost;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use jlqc\FundManagement;
use think\Cache;
use think\cache\driver\Redis;
use think\Controller;
use think\Db;


class InitQcAdvCost extends Controller
{

    public function moreExcute($day = 2)
    {
        // 直接循环5次处理任务
        for ($i = 0; $i < 5; $i++) {
            $this->initQcAdvConstWithMon($day);
        }
        die;
    }

    /**
     * 初始化广告账户近一个月的消耗
     * @param int $day 2 前30天的前30天，1 前30天
     * @return void
     * @throws Exception
     */
    public function initQcAdvConstWithMon(int $day = 2)
    {
        dump('初始化完了，禁止访问!');
        die;
        set_time_limit(360); // 延长执行时间
        $redis = Cache::store('redis');
        // 初始化模型
        $qcAdvDayCostModel = new QcAdvDayCost();
        // 缓存已处理广告账户ID列表
        $existedIds = array_unique(explode(',', trim($redis->get('qc_handle_adv_ids_' . $day, ''), ',')));
        // 查询未处理的广告账户
        $advIds = Db::name('company')
            ->where(['advertiser_id' => ['not in', $existedIds]])
            ->order('create_time desc')
            ->limit(100)
            ->column('advertiser_id');

        if (empty($advIds)) {
            echo "所有广告账户已处理完毕\n";
//            dump($redis->rm('qc_handle_adv_ids_'.$day));
            return;
        }

        // 构建并发请求
        $requests = $this->buildGuzzleRequest($advIds, $day);
        $insertData = $this->sendGuzzleRequest($requests);
        if ($insertData) {
            $result = $qcAdvDayCostModel->saveAll($insertData);
            if (!$result) {
                echo "插入失败\n";
                die;
            }
            echo "插入成功\n";
        }
        // 更新已处理的广告账户ID
        $redis->set('qc_handle_adv_ids_' . $day, implode(',', array_merge($existedIds, $advIds)));
        echo "处理了 " . (count($existedIds) + count($advIds)) . " 个广告账户\n";
    }

    protected function buildGuzzleRequest($advIds, $day)
    {
        // 获取当前的时间区间
        $comFun = new ComFun();
        list($start_date, $end_date) = $comFun->getSearchDate($day);
        echo $start_date . "--" . $end_date . '</n>';
        $access_token = Cache::get("qc_access_token");
        $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/report/advertiser/get/";
        $headers = [
            'Access-Token' => $access_token,
            'Content-Type' => 'application/json'
        ];
        $requests = [];
        foreach ($advIds as $advId) {
            $params = [
                "advertiser_id" => $advId,
                "start_date" => $start_date,
                "end_date" => $end_date,
                "page" => 1,
                "fields" => ['stat_cost'],
                "page_size" => 100,
                "order_type" => "DESC",
                "filtering" => [
                    "marketing_goal" => 'ALL'
                ],
                'time_granularity' => 'TIME_GRANULARITY_DAILY'
            ];

            $request = new Request('GET', $url, $headers, json_encode($params));
            $requests[] = [
                'request' => $request, // 请求对象
                'params' => $params,   // 请求参数
            ];
        }

        return $requests;
    }

    protected function sendGuzzleRequest($requests)
    {
        $insertData = [];
        $guzzleClient = new Client();
        $pool = new Pool($guzzleClient, array_column($requests, 'request'), [
            'concurrency' => 50, // 并发请求数量
            'fulfilled' => function ($response, $index) use (&$insertData) {
                $resData = json_decode($response->getBody()->getContents(), true);
                if (!empty($resData) && $resData['code'] == 0 && !empty($resData['data']['list'])) {
                    foreach ($resData['data']['list'] as $item) {
                        $insertData[] = [
                            'adv_id' => $item['advertiser_id'],
                            'cost' => $item['stat_cost'],
                            'cost_date' => strtotime($item['stat_datetime']),
                        ];
                    }
                }
            },
            'rejected' => function ($reason, $index) {
                echo "请求 {$index} 失败: " . $reason->getMessage() . "\n";
            },
        ]);

        // 发送请求并等待所有请求完成
        $promise = $pool->promise();
        $promise->wait();

        return $insertData;
    }

    /**
     * @throws Exception
     */
    public function initGlobalAdvCost($date)
    {

        set_time_limit(360); // 延长执行时间
        $model = new Company();
        $page = Cache::get('global_cost_page',1);

        $adv_list = $model->page($page)->limit(80)->order('id desc')->column('advertiser_id');

        $token = Cache::get("qc_access_token");
        $start_date = $date . ' 00:00:00';
        $end_date = $date . ' 23:59:59';
        $insert_data = [];

        if(empty($adv_list)){
            echo "已经全部处理完{$date}的数据";
            echo "记得清理页数缓存";
            die;
        }
        //八千多条
        foreach ($adv_list as $item){
            $params = [
                'advertiser_id' => $item,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'marketing_goal' => 'LIVE_PROM_GOODS'//直播间
            ];

            $live_cost = FundManagement::get_global_adv_cost($token, $params);
            $params['marketing_goal'] = 'VIDEO_PROM_GOODS';//商品
            $goods_cost = FundManagement::get_global_adv_cost($token, $params);
            $l_cost = 0;
            $g_cost = 0;
            if ($live_cost['code'] == 0 && $live_cost['message'] == "OK") {
                $l_cost = $live_cost['data']['stat_cost'];
            }
            if ($goods_cost['code'] == 0 && $goods_cost['message'] == "OK") {
                $g_cost = $goods_cost['data']['stat_cost'];
            }
            if($l_cost>0 || $g_cost>0) {
                $total_cost = $l_cost + $g_cost;
                $insert_data[] = [
                    'adv_id' => $item,
                    'cost' => $total_cost,
                    'cost_date' => strtotime($date),
                    'type' => 2,
                ];
            }
        }
        $count = count($insert_data);
        if($insert_data) {
            $costModel = new QcAdvDayCost();
            $res = $costModel->saveAll($insert_data);
            if ($res) {
                echo "第{$page}页成功写进{$count}条数据";
                Cache::set('global_cost_page', $page + 1);
            }
        }else {
            echo "第{$page}页成功写进{$count}条数据";
            Cache::set('global_cost_page', $page + 1);
        }
    }

    public function rmGlobalCostPageCache()
    {
        dump(Cache::rm('global_cost_page'));
        die;
    }

}