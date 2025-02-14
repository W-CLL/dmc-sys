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


    public function initGetGlobalCost($marketing_goal = "LIVE_PROM_GOODS")
    {
        $redis = Cache::store('redis');
        $date = $redis->get('global_cost_date', '2025-02-01');
        $page = Cache::get('global_cost_page_' . $date, 1);
        $model = new Company();
        $adv_list = $model->page($page)->limit(20)->order('id desc')->column('advertiser_id');
        if ($date == '2025-02-15' || $date == '2025-2-15') {
            echo "2月1到2月14的数据已经全部获取完了";
            die;
        }
        if (empty($adv_list)) {
            $start_date = new \DateTime($date);
            $next_day = $start_date->modify('+1 day')->format('Y-m-d');
            $redis->set('global_cost_date', $next_day, 3600);
            Cache::rm('global_cost_page_' . $date);
            echo $date . $marketing_goal . "数据全部获取完";
            die;
        }
        $requests = $this->buildGlobalGuzzleRequest($adv_list, $marketing_goal, $date);
        $insertData = $this->sendGlobalGuzzleRequest($requests);
        $count = count($insertData);
        try {
            if ($insertData) {
                $costModel = new QcAdvDayCost();
                $res = $costModel->saveAll($insertData);
                if ($res) {
                    echo "第{$page}页成功写进{$count}条数据";
                    Cache::set('global_cost_page_' . $date, $page + 1, 3600);
                }
            } else {
                echo "第{$page}页成功写进{$count}条数据";
                Cache::set('global_cost_page_' . $date, $page + 1, 3600);
            }
        } catch (\think\Exception $e) {
            throw new Exception($e->getMessage());
        }

    }


    public function initGetGlobalCostVideo($marketing_goal = "VIDEO_PROM_GOODS")
    {
        $redis = Cache::store('redis');
        $date = $redis->get('global_cost_date_' . $marketing_goal, '2025-02-01');
        $page = Cache::get('global_cost_page_' . $marketing_goal . '_' . $date, 1);
        $model = new Company();
        $adv_list = $model->page($page)->limit(20)->order('id desc')->column('advertiser_id');
        if ($date == '2025-02-15' || $date == '2025-2-15') {
            echo "2月1到2月14的数据已经全部获取完了";
            die;
        }
        if (empty($adv_list)) {
            $start_date = new \DateTime($date);
            $next_day = $start_date->modify('+1 day')->format('Y-m-d');
            $redis->set('global_cost_date_' . $marketing_goal, $next_day, 3600);
            Cache::rm('global_cost_page_' . $marketing_goal . '_' . $date);
            echo $date . $marketing_goal . "数据全部获取完";
            die;
        }
        $requests = $this->buildGlobalGuzzleRequest($adv_list, $marketing_goal, $date);
        $insertData = $this->sendGlobalGuzzleRequest($requests);
        $count = count($insertData);
        try {
            if ($insertData) {
                $costModel = new QcAdvDayCost();
                $res = $this->updateCost($insertData, $costModel);
                if ($res) {
                    echo "第{$page}页成功写进{$count}条数据";
                    Cache::set('global_cost_page_' . $marketing_goal . '_' . $date, $page + 1, 3600);
                }
            } else {
                echo "第{$page}页成功写进{$count}条数据";
                Cache::set('global_cost_page_' . $marketing_goal . '_' . $date, $page + 1, 3600);
            }
        } catch (\think\Exception $e) {
            throw new Exception($e->getMessage());
        }

    }

    public function updateCost($insertData, $costModel)
    {
        foreach ($insertData as $data) {
            $cost = $data['cost'];
            unset($data['cost']);
            $res = $costModel->where($data)->find();
            if ($res) {
                $final_cost = $cost + $res['cost'];
                $costModel->where(['id' => $res['id']])->update(['cost' => $final_cost]);
                echo "更新" . $res['id'];
            } else {
                $data['cost'] = $cost;
                $costModel->save($data);
                echo "插入";
            }
        }
        return true;
    }


    protected function buildGlobalGuzzleRequest($advIds, $marketing_goal, $date): array
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/report/uni_promotion/get";
        $headers = [
            'Access-Token' => $access_token,
            'Content-Type' => 'application/json'
        ];
        $start_date = $date . ' 00:00:00';
        $end_date = $date . ' 23:59:59';
        $requests = [];

        foreach ($advIds as $advId) {
            $params = [
                'advertiser_id' => intval($advId),
                'lab_ad_type' => 'LAB_AD',
                'fields' => [
                    'stat_cost'
                ],
                'start_date' => $start_date,
                'end_date' => $end_date,
                'marketing_goal' => $marketing_goal
            ];
            $res_url = buildUrlWithParams($url, $params);
            $request = new Request('GET', $res_url, $headers);
            $requests[] = ['request' => $request, 'params' => $params];
        }
        return $requests;
    }


    protected function sendGlobalGuzzleRequest($requests)
    {
        $insertData = [];
        $guzzleClient = new Client();
        // 并发请求
        $pool = new Pool($guzzleClient, array_column($requests, 'request'), [
            'concurrency' => 1,  // 控制并发数
            'fulfilled' => function ($response, $index) use (&$insertData, $requests) {
                $resData = json_decode($response->getBody()->getContents(), true);
                $requestInfo = $requests[$index]['params'];
                $requestAdvId = $requestInfo['advertiser_id'];
                if (!empty($resData) && $resData['code'] == 0 && $resData['message'] == "OK") {
                    if ($resData['data']['stat_cost'] != 0) {
                        $insertData[] = [
                            'adv_id' => $requestAdvId,
                            'cost' => $resData['data']['stat_cost'],
                            'type' => 2,
                            'cost_date' => strtotime($requestInfo['start_date'])
                        ];
                    }
                }
            },
            'rejected' => function ($reason, $index) {
                echo "Request {$index} failed: " . $reason->getMessage() . "\n";
            },
        ]);
        // 等待所有请求完成
        $promise = $pool->promise();
        $promise->wait();

        return $insertData;
    }

}