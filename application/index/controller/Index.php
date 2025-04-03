<?php

namespace app\index\controller;

use app\admin\model\Company as CompanyModel;
use app\admin\model\Operator as OperatorModel;
use app\admin\model\QcObjOptLog as PlanOptLogModel;
use app\admin\model\QcObj as ObjModel;
use app\common\controller\Frontend;
use app\common\model\Queue;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use jlqc\FundManagement;
use MongoDB\BSON\Timestamp;
use think\Cache;
use think\cache\driver\Redis;
use think\Db;

class Index extends Frontend
{

    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';
    protected $layout = '';

    public function index()
    {
        return $this->view->fetch();
    }


    public function get_qc_money($advertiser_id = '', $store_id = 8)
    {
        // 1795937699753995
        if (empty($advertiser_id)) {
            $advertiser_id = input("advertiser_id");
        }
        if (!$advertiser_id) {
            $this->error('请输入正确的ID');
        }
        $company = Db::name("company")->where(['advertiser_id' => $advertiser_id, "store_id" => $store_id])->find();
        if ($company) {
            $access_token = Cache::get("qc_access_token");
            $qc_money = FundManagement::account_balance_wallet($access_token, $advertiser_id);
//            $qc_money1 = FundManagement::account_balance($access_token, $advertiser_id);
            $return_code = FundManagement::$auth_return_code;
            dump($qc_money);
//            dump($qc_money1);
//            die;
            if (in_array($qc_money['code'], $return_code)) {
                return json(["code" => 0, "msg" => "千川授权已失效，请联系管理员"]);
//                $this->error('千川授权已失效，请联系管理员');
            }
            $total_money = $qc_money['data']['total_balance_abs'];
            $grant_balance = $qc_money['data']['grant_balance'];
            $actual_money = $total_money - $grant_balance;
            $data = [
                "money" => $actual_money / 100000,
                "total_money" => $actual_money / 100000,
                "grant_balance" => $actual_money / 100000,
                "account_type" => $company['account_type']
            ];
            return json(["code" => 1,
                "data" => $data,
                "msg" => "请求成功"]);
        }
        return json(["code" => 0, "msg" => "请求失败，请刷新后重新请求"]);
    }

    public function testGetAdplanList()
    {
        $sum = 0;
        $access_token = Cache::get("qc_access_token");
//        $res = FundManagement::get_ad_detail($access_token, 1785969793391627, 1810069220637712);
//        var_dump($res);
//        die;
//        $res = FundManagement::get_agent_statement($access_token,1739518270441480, "2024-11-01", "2024-11-15",1,100,1811064042732587);
//        foreach ($res['data']['list'] as $v){
//            $sum += $v['no_grant_cost'] / 100000;
//        }
//        var_dump($sum);
//        $res1 = FundManagement::get_ad_info($access_token,json_encode([1744560849630222],JSON_UNESCAPED_UNICODE));
//        var_dump($res1['data']['account_detail_list'][0]['optimizer_name']);
//        die;
////        $sum = $sum / 1000;
////        var_dump($sum);
//        die;
        $list = Db::name('company')
            ->where('adv_status', 1)
            ->order('advertiser_id', 'desc')
            ->column('advertiser_id');
        $chunks = array_chunk($list, 200);
        $i = 0;
        foreach ($chunks as $chunk) {
            $job_data = [
                'filtering' => [
                    'marketing_goal' => "VIDEO_PROM_GOODS",
                    'ad_create_start_date' => '2025-02-01',
                    'ad_create_end_date' => '2025-04-03',
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
                ],
                'adv_list' => $chunk
            ];
            \think\Queue::push('app\job\InsertDayObj', $job_data, "insertDayObj");
            echo $i++;
        }
        die;
        $params = [
            'advertiser_id' => '1759237117991943',
            'filtering' => [
                'marketing_goal' => 'VIDEO_PROM_GOODS',
                'ad_create_start_date' => '2025-02-01',
                'ad_create_end_date' => '2025-04-02',
                'status' => 'ALL_INCLUDE_DELETED',

            ],
            'page' => '1',
            'page_size' => '200',
        ];

        $res = FundManagement::get_ad_list($access_token, $params);

        var_dump($res);
        die;
        $total_page = $res['data']['page_info']['total_page'];
        for ($i = 1; $i <= $total_page; $i++) {
            $params['page'] = $i;
            $res = FundManagement::get_ad_report($access_token, $params);
            $this->testGetAdplanOptList(array_column($res['data']['list'], "ad_id"));
        }
//        dump($object_ids);
//        die;
        return true;
    }

    public function testGetAdplanOptList($object_ids = [])
    {
        $access_token = Cache::get("qc_access_token");
        $object_ids = ["1816571139155068", "1816571119288555", "1816571107958810", "1813879845232724", "1813879883040809", "1814666911762522"];

        $params = [
            'advertiser_id' => '1811064042732587',
            'object_id' => $object_ids,
            'page' => '1',
            'page_size' => '20',
            'start_date' => date('Y-m-d 00:00:00', strtotime("first day of this month")),
            'end_date' => date('Y-m-d 23:59:59', strtotime("last day of this month")),
        ];
        $res = FundManagement::get_opt_log($access_token, $params);
        var_dump($res);
        die;
//        die;
//        $taotal_page = $res['data']['page_info']['total_page'];
////        $taotal_page = $res['data']['page_info']['total_page'];
//        for ($i = 1; $i <= $taotal_page; $i++) {
//            if ($i > 1) {
//                $params['page'] = $i;
//                $res = FundManagement::get_opt_log($access_token, $params);
//            }
//            foreach ($res['data']['logs'] as $item) {
//                if ($item['operator'] == "BMWYC") {
//                    $new_data[] = $item;
//                } else {
//                    $other_data[] = $item;
//                }
//            }
//        }
////        dump($res);
//        dump($new_data);
//        dump($other_data);
//        die;
    }

    public function testAddQueue()
    {
        echo "禁止访问!";
        die;
        $queueModel = new \app\common\model\Queue();
        $res = $queueModel->addQueue("app\job\Test", "test", ["name" => "test1"]);
        dump($res);
        die;
    }

    public function genExternalAccount()
    {
        $secret = bin2hex(random_bytes(16));
        dump($secret);
        die;
    }

    public function testSyncCrm()
    {
        echo "禁止访问!";
        die;
        $account = Db::name('external_accounts')->where('status', 1)->find();
        $url = "http://crm1688.cn.com";
        $method = "post";
        $a = '{"customer_name":"xiaogege","adduser":"陈秀玉",
        "sales_price":"950","customer_back":"1.035","account_type":"1","account":"136844","note":"","addtime":"1727059203","from":1}';

        $enData = openssl_encrypt($a, 'AES-128-ECB', $account['secret'], 0);

        $params = [
            'app' => 'charge_controller_dmcapi',
            'data' => $enData,
            'account' => '20240919001',
            'act' => 'post',
        ];

        $client = new Client();
        $response = $client->request($method, $url, [
            'form_params' => $params
        ]);

        $res = $response->getBody()->getContents();
        dump(json_decode($res, true));
        die;

    }

    public function testGetCrmData()
    {
        echo "禁止访问!";
        die;
        $params = [
            'app' => 'charge_controller_dmcapi',
            'act' => 'get',
            'log_id' => '306',
            'account' => '20240919002'
        ];
        dump(buildCrmRequest($params));
        die;
    }

    public function testUpdateCrmData()
    {
        echo "禁止访问!";
        exit;
        $queueModel = new Queue();
        $list = $queueModel->select();

        foreach ($list as $item) {
            $pattern = '/log_id:(\d+),crm_id:(\d+)/';
            if (preg_match($pattern, $item['msg'], $matches)) {

                $logId = $matches[1];
                $crmId = $matches[2];
                $logData = Db::name($item['relation_table'])->where('id', $logId)->find();

                if ($logData) {
                    $params = [
                        'app' => 'charge_controller_dmcapi',
                        'act' => 'put',
                        'crm_id' => $crmId,
                        'account' => '20240919001',
                        'add_time' => $logData['create_time']
                    ];
                    $res = buildCrmRequest($params);
                    dump($res);
                }
            }
        }
    }


    public function testTransfer()
    {
//        echo "禁止访问!";
//        die;
        $token = Cache::get("qc_access_token");
        $a = $transfer_detail_data = FundManagement::transfer_detail(
            $token,
            'dfsdfdf',
            '1739518270441480',
            "ZZO7426391592618984211");
        dump($a);
        die;
//        $target_account_detail_list[] = [
//            'account_id' => (int)$company_advertiser_id,
//            'transfer_capital_detail_list' => [[
//                'capital_type' => 'PREPAY_GENERAL',
//                'transfer_amount' => (int)($money * 100),
//            ]]
//        ];
//        $data = FundManagement::create_transfer($token, 288, 1739518270441480, 1739518270441480, $target_account_detail_list, $transfer_direction, $remark);

//        $qc_money = FundManagement::account_balance_wallet($token, "1791676091878467");//获取钱包详细信息
        $qc_money = FundManagement::account_balance($token, 1805332345397339);//获取不到赠送余额

//        $qc_money = FundManagement::account_balance_wallet($token, "1805332345397339");//获取钱包详细信息
        $return_code = FundManagement::$auth_return_code;
        dump($qc_money);
        dump($a);
        die;
    }

    // 初始化obj表
    public function firstRunGetVPGObj()
    {
        $queueModel = new Queue();
        $company_info = Db::name('company')->field('id,advertiser_id')->select();

        $split_array = array_chunk($company_info, 20);
        foreach ($split_array as $item) {
            $data['marketing_goal'] = 'VIDEO_PROM_GOODS';  // 推商品
            $data['time_describe'] = ' -30 days';
            $data['data'] = $item;
            $queueModel->addQueue('获取广告计划【推商品】', 'app\job\QcObj', 'createQcObj', $data, '');
        }
        echo "初始化队列已创建";
    }

    public function firstRunGetLPGObj()
    {
        $queueModel = new Queue();
        $company_info = Db::name('company')->field('id,advertiser_id')->select();
        $split_array = array_chunk($company_info, 20);
        foreach ($split_array as $item) {
            $data['marketing_goal'] = 'LIVE_PROM_GOODS';  // 推直播间
            $data['time_describe'] = ' -30 days';
            $data['data'] = $item;
            $queueModel->addQueue('获取广告计划【推直播间】', 'app\job\QcObj', 'createQcObj', $data, '');
        }
        echo "初始化队列已创建";
    }


    // 初始化负责人，定时任务
    public function updateKahuna()
    {
        if (Cache::get('kahuna_run_status') == 1) {
            echo "已经初始化完毕，请删除定时任务";
            return;
        }
        $i = 0;
        $access_token = Cache::get("qc_access_token");
        $advertiser_ids = Cache::get("ad_ids");
        if (!$advertiser_ids) {
            $advertiser_ids = Db::name("company")->where('kahuna', 'Null')->column("advertiser_id");
        }
        $advertiser_ids = array_map(function ($item) {
            return (int)$item;
        }, $advertiser_ids);
        foreach ($advertiser_ids as $key => $split) {
            if ($i == 50) {
                break;
            }
            $res1 = FundManagement::get_ad_info($access_token, json_encode([$split], JSON_UNESCAPED_UNICODE));
            if ($res1['code'] == 0) {
                $arr['kahuna'] = $res1['data']['account_detail_list'][0]['optimizer_name'];
                if (!Db::name('company')->where(['advertiser_id' => $split])->update($arr)) {
                    throw new \Exception('出错');
                }
            }
            unset($advertiser_ids[$key]);
            $i++;
        }
        if ($i < 50) {
            Cache::rm('start_num');
            Cache::rm('ad_ids');
            Cache::set('kahuna_run_status', 1, 3600 * 24);
            echo "全部完成";
            return;
        }
        Cache::set('ad_ids', $advertiser_ids);
        echo "本次更新完成";
    }


    public function firstRunGetOpt()
    {
        $queueModel = new Queue();
        $obj_info = Db::name('qc_obj')->column('advertiser_id', 'object_id');  // 52000
        foreach ($obj_info as $k => $v) {
            if (!isset($data[$v])) {
                $data[$v] = [];
            }
            $data[$v][] = $k;
        }
        $split_array = array_chunk($data, 1, true);
        foreach ($split_array as $item) {
            $queue_data['start_time'] = date('Y-m-d 00:00:00', strtotime("first day of this month"));
            $queue_data['end_time'] = date('Y-m-d 23:59:59', strtotime("yesterday"));
            $queue_data['data'] = $item;
            $queueModel->addQueue('获取计划操作', 'app\job\QcOpt', 'createQcOpt', $queue_data, '');
        }
        echo "初始化队列已创建";
    }


    public function checkRedisSet()
    {
        $redis = Cache::store('redis_db2')->handler();
//        Cache::store('redis_db2')->handler()->SADD('testasdgf',1);
        $set_info = $redis->SMEMBERS('obj_id');
        var_dump($set_info);
    }

    public function updateObjStatus()
    {
        $start_time = time();
        $access_token = Cache::get("qc_access_token");
        $list = Db::name('qc_obj')->where('status', 1)->limit(1800)->select();
        $guzzleClient = new Client();
        $requests = function () use ($guzzleClient,$access_token,$list) {
            foreach ($list as   $item){
                $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/ad/detail/get/?advertiser_id=".$item['advertiser_id']."&ad_id=".$item['object_id']."&request_material_url=false";
                yield new Request('GET', $url, ['Access-Token'=>$access_token]);
            }
        };
        $updateData = [];
        $pool = new Pool($guzzleClient, $requests(), [
            'concurrency' => 20, // 并发请求数量
            'fulfilled' => function ($response, $index) use (&$adIds0,&$adIds2) {
                // 请求成功时的回调
//                echo "Request {$index} completed with status code: " . $response->getStatusCode() . "\n";
//                echo "Response body: " . $response->getBody()->getContents() . "\n";
                $resData = json_decode($response->getBody()->getContents(),true);
                if(!empty($resData)) {
                    if ($resData['code'] != 0 || in_array($resData['data']['status'], ['DELETE', 'FROZEN'])) {
                        if(isset($resData['data'])){
                            $adIds0[] = $resData['data']['ad_id'];
//                            Db::name('qc_obj')->where('object_id', $resData['data']['ad_id'])->update(['status' => 0]);
                        }
                    } else {
                        $adIds2[] = $resData['data']['ad_id'];
//                        Db::name('qc_obj')->where('object_id', $resData['data']['ad_id'])->update(['status' => 2]);
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
        Db::name('qc_obj')->where(['object_id'=>['in',$adIds0]])->update(['status' => 0]);
        Db::name('qc_obj')->where(['object_id'=>['in',$adIds2]])->update(['status' => 2]);
        $count = Db::name('qc_obj')->where('status',1)->count();
        $time = time() - $start_time;
        echo $count;
        echo '执行成功，执行时间：' . $time . '秒';
    }




    public function updatePolicy(){
        $date = date('Y-m-d');
        if($date != '2025-01-01'){
            echo "非2025-01-01禁止访问";
            return;
        }
        $info = Db::name('store_group')->select();
        foreach ($info as $k=>$v){
            if($v['group_type'] == 1){
                if(!empty($v['discount_percentage'])){
                    Db::name('store')->where('group_id',$v['id'])->update(['public_discount_percentage' => $v['discount_percentage']]);
                }
            }elseif ($v['group_type'] == 2){
                if(!empty($v['discount_percentage'])){
                    Db::name('store')->where('group_id',$v['id'])->update(['private_discount_percentage' => $v['discount_percentage']]);
                }
            }
        }
        echo "更新完成";
    }



}
