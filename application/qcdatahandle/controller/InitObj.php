<?php


namespace app\qcdatahandle\controller;

use app\admin\model\Company;
use app\admin\model\QcObj;
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
    public function initInsertObj($marketingGoal, $cacheKeyPrefix, $day = 2)
    {
        set_time_limit(360);
        $redis = Cache::store('redis');
//        dump($redis->rm("{$cacheKeyPrefix}_page_".$day));
//        dump($redis->rm("empty_{$cacheKeyPrefix}_list_".$day));
//        die;
        $page = $redis->get("{$cacheKeyPrefix}_page_".$day,1);; // 动态生成缓存键
        $companyModel = new Company();
        $comFun = new ComFun();
        $where = "NOT EXISTS (SELECT 1 FROM fa_qc_obj f WHERE f.adv_id = c.advertiser_id AND f.marketing_goal ='".$marketingGoal."'";
        if ($day == 1) {
            $newDay = $day + 1; // 不直接修改外部传入的 $day
            list($start_date, $end_date) = $comFun->getSearchDate($newDay);
            $where .= ' AND f.obj_create_time NOT BETWEEN '. strtotime($start_date).' AND '. strtotime($end_date).')';
        }else{
            $where .=" )";
        }

        // 查询公司列表，过滤没有处理过的广告商
        $list = $companyModel->alias('c')
            ->where($where)
            ->order('c.advertiser_id desc')
            ->page($page)
            ->limit(100)
//            ->fetchSql(true)  // 仅用于调试，生产环境可以去掉
//            ->select();
            ->column('advertiser_id');

        // 如果空数据超过15次且分页已超过100，停止处理
        if ( $redis->get("empty_{$cacheKeyPrefix}_list_".$day,0) > 15 && $page > 100) {
            echo "{$marketingGoal} 数据已经全部处理完";
//            $redis->rm("{$cacheKeyPrefix}_page_".$day);
            die;
        }
        // 获取日期范围
        list($start_date, $end_date) = $comFun->getSearchDate($day);
        // 构建请求过滤条件
        $filter = [
            "status"=>"ALL_INCLUDE_DELETED",//全部（包含已删除）
            "marketing_goal" => $marketingGoal,
            "ad_create_start_date" => $start_date,
            "ad_create_end_date" => $end_date,
        ];

        // 构建请求并发送
        $requests = $this->buildGuzzleRequest(count($list), $list, $filter);
        $insertData = $this->sendGuzzleRequest($requests);

        // 处理返回数据
        if ($insertData) {
            $objModel = new QcObj();
            $res = $objModel->saveAll($insertData);
            if ($res) {
                $redis->set("{$cacheKeyPrefix}_page_".$day,$page + 1);
                echo "成功插入 " . count($insertData) . " 条数据， 第 " . $page . " 页";
            } else {
                echo "插入失败";
            }
        } else {
            echo '这一批为空，转到下一页';
            $redis->set("empty_{$cacheKeyPrefix}_list_".$day,$redis->get("empty_{$cacheKeyPrefix}_list_".$day) + 1);
            $redis->set("{$cacheKeyPrefix}_page_".$day,$page+1);
            die;
        }
    }

    /**
     * 推商品
     * @param int $day
     * @return void
     * @throws Exception
     */
    public function initInsertVideoObj(int $day = 2)
    {
        $this->initInsertObj("VIDEO_PROM_GOODS", "qc_com_adv_video", $day);
    }

    /**
     * 推直播间
     * @param int $day
     * @return void
     * @throws Exception
     */
    public function initInsertLiveObj(int $day = 2)
    {
        $this->initInsertObj("LIVE_PROM_GOODS", "qc_com_adv_live", $day);
    }

    /**
     * 构建请求
     * @param $count
     * @param $advIds
     * @param $filter
     * @return array
     */
    protected function buildGuzzleRequest($count, $advIds, $filter): array
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

        // 并发请求
        $pool = new Pool($guzzleClient, array_column($requests, 'request'), [
            'concurrency' => 50,  // 控制并发数
            'fulfilled' => function ($response, $index) use (&$insertData, $requests) {
                $resData = json_decode($response->getBody()->getContents(), true);
                $requestInfo = $requests[$index]['params'];
                $requestAdvId = $requestInfo['advertiser_id'];
//                dump($resData);
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
