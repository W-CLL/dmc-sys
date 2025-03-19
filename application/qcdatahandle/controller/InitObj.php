<?php


namespace app\qcdatahandle\controller;

use app\admin\model\Company;
use app\admin\model\QcObj;
use app\common\model\Queue;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use think\Cache;
use think\Controller;

class InitObj extends Controller
{
    /**
     * 推商品/直播间 数据插入的通用函数
     * @param $marketingGoal
     * @param $cacheKeyPrefix
     * @param $day
     * @return void
     * @throws Exception
     */
    public function initInsertObj($marketingGoal, $cacheKeyPrefix, $day)
    {
        dump('初始化完了，禁止访问!');
        die;
        set_time_limit(360);
        $redis = Cache::store('redis');
//        dump($redis->rm("{$cacheKeyPrefix}_page_".$day));
//        dump($redis->rm("empty_{$cacheKeyPrefix}_list_".$day));
//        die;
        $page = $redis->get("{$cacheKeyPrefix}_page_" . $day, 1);; // 动态生成缓存键
        $companyModel = new Company();
        $comFun = new ComFun();

        // 查询公司列表，过滤没有处理过的广告商
        $list = $companyModel
//            ->alias('c')
//            ->where($where)
            ->where(['adv_status' => 1])
            ->order('advertiser_id desc')
            ->page($page)
            ->limit(50)
//            ->fetchSql(true)  // 仅用于调试，生产环境可以去掉
//            ->select();
            ->column('advertiser_id');
        if ($page == 1 && empty($list)) {
            echo "没有数据";
            die;
        }
        // 如果空数据超过15次且分页已超过100，停止处理
        if ($redis->get("empty_{$cacheKeyPrefix}_list_" . $day, 0) > 150 && $page > 300) {
            echo "{$marketingGoal} 数据已经全部处理完";
            die;
        }
        // 获取日期范围
        list($start_date, $end_date) = $comFun->getSearchDate($day);
        echo $start_date . "--" . $end_date . '</br>';
        // 构建请求过滤条件
        $filter = [
            "marketing_goal" => $marketingGoal,
            "ad_create_start_date" => $start_date,
            "ad_create_end_date" => $end_date,
            'marketing_scene' => 'ALL',
            "status" => "ALL_INCLUDE_DELETED",
            "campaign_scene" => [
                'DAILY_SALE',
                'NEW_CUSTOMER_TRANSFORMATION',
                'LIVE_HEAT',
                'PLANT_GRASS',
                'PRODUCT_HEAT',
                'NEW_PRODUCT_BOOST',
            ],
        ];

        // 构建请求并发送
        $requests = $this->buildGuzzleRequest($list, $filter);
        $insertData = $this->sendGuzzleRequest($requests);
        // 处理返回数据
        if ($insertData) {
            list($res, $count) = $this->saveNewObj($insertData);
            if ($res) {
                $redis->set("{$cacheKeyPrefix}_page_" . $day, $page + 1);
                echo "成功插入 " . $count . " 条数据， 第 " . $page . " 页";
            } else {
                echo "插入失败";
            }
        } else {
            echo '这一批为空，转到下一页';
            echo " 第 " . $page . " 页";
            $redis->set("empty_{$cacheKeyPrefix}_list_" . $day, $redis->get("empty_{$cacheKeyPrefix}_list_" . $day) + 1);
            $redis->set("{$cacheKeyPrefix}_page_" . $day, $page + 1);
            die;
        }
    }


    protected function saveNewObj($list)
    {
        $objModel = new QcObj();
        $adv = [];
        foreach ($list as $key => $item) {
            if ($item['obj_id']) {
                if (!isset($adv[$item['adv_id']])) {
                    $adv[$item['adv_id']] = [];
                }
                $adv[$item['adv_id']][] = $item['obj_id'];
            }
        }
        $keys = array_keys($adv);
        $values = array_values($adv);
        $flattenedValues = array_merge(...$values);
        $exitedIds = $objModel->where(['adv_id' => ['in', $keys], 'obj_id' => ['in', $flattenedValues]])->column('obj_id');
        $afterData = array_filter($list, function ($item) use ($exitedIds) {
            return !in_array($item['obj_id'], $exitedIds);
        });
        if ($afterData) {
            $res = $objModel->saveAll($afterData);
            if ($res) {
                return [true, count($afterData)];
            } else {
                return [false, ''];
            }
        }
        return [true, count($afterData)];
    }

    /**
     * 推商品
     * @param int $day
     * @return void
     * @throws Exception
     */
    public function initInsertVideoObj(int $day = 1)
    {
        set_time_limit(300);
        $this->initInsertObj("VIDEO_PROM_GOODS", "qc_com_adv_video", $day);
    }

    /**
     * 推直播间
     * @param int $day
     * @return void
     * @throws Exception
     */
    public function initInsertLiveObj(int $day = 1)
    {
        set_time_limit(300);
        $this->initInsertObj("LIVE_PROM_GOODS", "qc_com_adv_live", $day);
    }

    /**
     * 构建请求
     * @param $count
     * @param $advIds
     * @param $filter
     * @return array
     */
    protected function buildGuzzleRequest($advIds, $filter): array
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/ad/get/";
        $headers = [
            'Access-Token' => $access_token,
            'Content-Type' => 'application/json'
        ];
        $requests = [];
        foreach ($advIds as $advId) {
            $params = [
                "advertiser_id" => (int)$advId,
                "page" => 1,
                "page_size" => 200,
                'filtering' => json_encode($filter),
            ];
            $request = new Request('GET', $url, $headers, json_encode($params));
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
        $guzzleClient = new Client();
        $queue = new Queue();
        // 并发请求
        $pool = new Pool($guzzleClient, array_column($requests, 'request'), [
            'concurrency' => 10,  // 控制并发数
            'fulfilled' => function ($response, $index) use (&$insertData, $requests, $queue) {
                $resData = json_decode($response->getBody()->getContents(), true);
                $requestInfo = $requests[$index]['params'];
                $requestAdvId = $requestInfo['advertiser_id'];
                if (!empty($resData) && $resData['code'] == 0 && !empty($resData['data']['list'])) {
                    foreach ($resData['data']['list'] as $item) {
                        $insertData[] = [
                            'adv_id' => $requestAdvId,
                            'obj_id' => $item['ad_id'],
                            'name' => $item['name'],
                            'obj_status' => $item['status'],
                            'opt_status' => $item['opt_status'],
                            'marketing_goal' => $item['marketing_goal'],
                            'marketing_scene' => $item['marketing_scene'],
                            'campaign_scene' => $item['campaign_scene'],
                            'campaign_id' => $item['campaign_id'],
                            'lab_ad_type' => $item['lab_ad_type'],
                            'obj_create_time' => strtotime($item['ad_create_time']),
                            'obj_modify_time' => strtotime($item['ad_modify_time']),
                            'product_info' => json_encode($item['product_info']),
                            'aweme_info' => json_encode($item['product_info']),
                            'delivery_setting' => json_encode($item['product_info']),
                        ];
                    }
                    echo "计划总页数是:" . $resData['data']['page_info']['total_page'];
                    echo "总数是:" . $resData['data']['page_info']['total_number'];
                    //从第二页开始用队列进行执行
                    if ($resData['data']['page_info']['total_page'] > 1) {
                        $requestInfo['page'] = 2;
                        \think\Queue::push('app\job\InitObj', $requestInfo, "initObj");
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
