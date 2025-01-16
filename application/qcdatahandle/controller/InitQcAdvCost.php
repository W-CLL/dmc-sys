<?php

namespace app\qcdatahandle\controller;

use app\common\model\QcAdvDayCost;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use think\Cache;
use think\cache\driver\Redis;
use think\Controller;
use think\Db;


class InitQcAdvCost extends Controller
{

    public function moreExcute($day=2)
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
    public function initQcAdvConstWithMon(int $day=2)
    {
        dump('初始化完了，禁止访问!');
        die;
        set_time_limit(360); // 延长执行时间
        $redis = Cache::store('redis');
        // 初始化模型
        $qcAdvDayCostModel = new QcAdvDayCost();
        // 缓存已处理广告账户ID列表
        $existedIds = array_unique(explode(',', trim($redis->get('qc_handle_adv_ids_'.$day,''), ',')));
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
        $redis->set('qc_handle_adv_ids_'.$day,implode(',', array_merge($existedIds, $advIds)));
        echo "处理了 " . (count($existedIds) + count($advIds)) . " 个广告账户\n";
    }

    protected function buildGuzzleRequest($advIds, $day)
    {
        // 获取当前的时间区间
        $comFun = new ComFun();
        list($start_date, $end_date) = $comFun->getSearchDate($day);
        echo $start_date."--".$end_date.'</n>';
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

}