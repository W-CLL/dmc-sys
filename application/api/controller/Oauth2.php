<?php

namespace app\api\controller;


use app\admin\model\Company;
use app\common\controller\Api;
use app\common\model\Queue;
use app\store\model\TransferRecords;
use fast\Random;
use jlqc\AccountRelationship;
use jlqc\FundManagement;
use jlqc\UserInfo;
use Requests;
use think\Cache;
use think\Db;
use think\Env;


class Oauth2 extends Api
{

    public function aa()
    {

        \qywx\Api::media_upload(ROOT_PATH . "public/receipt/20240624/d65cad0a209eccbce02c0f39d24868ae.jpg");
    }

    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';

    //获取千川授权
    public function auth_code_callback()
    {
        $auth_code = input("auth_code");
        $state = input("state");
        if (!empty($auth_code)) {
//            $data = Db::name("qc_config")->where("id", 1)->find();
            $app_id = Env::get('dmc_ad_config.app_id');
            $secret = Env::get('dmc_ad_config.secret');
//            $data = Env::get('dmc_ad_config');
            $data = \jlqc\AccessToken::get_access_token($app_id, $secret, $auth_code);
            if ($data["code"] == 0 && $data["message"] === "OK") {
                Cache::set("qc_access_token", $data['data']['access_token'], 0);
                Cache::set("qc_refresh_token", $data['data']['refresh_token'], 0);
                return "授权成功";
            }
            return "授权失败,err:" . json_encode($data, JSON_UNESCAPED_UNICODE);
        }
    }

    //刷新千川授权，12小时一次
    public function access_token_save()
    {
//        dump(Cache::get("qc_access_token"));
//        $data = Db::name("qc_config")->where("id", 1)->find();
        $app_id = Env::get('dmc_ad_config.app_id');
        $secret = Env::get('dmc_ad_config.secret');
//        $data = Env::get('dmc_ad_config');
        $data = \jlqc\AccessToken::refresh_token_save($app_id, $secret);
        if ($data['code'] == 0 && $data['message'] == "OK") {
            Cache::set("qc_access_token", $data['data']['access_token'], 0);
            Cache::set("qc_refresh_token", $data['data']['refresh_token'], 0);
            return "刷新授权状态成功";
        }

        return "刷新失败,err:" . json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    //获取百度ai AccessToken 30天获取一次
    public function baidu_get_access_token()
    {
        $data = Db::name("qc_config")->where("id", 2)->find();
        $data = \baidu\AccessToken::get_access_token($data['api_key'], $data['secret']);
        if (isset($data['access_token'])) {
            Cache::set("baidu_access_token", $data['access_token'], 0);
            return "获取百度授权成功";
        }
        return "获取百度授权失败";
    }


    //获取千川所有绑定账户并写入
    public function synchronous1()
    {
//        $config_data = Db::name("qc_config")->where("id", 1)->find();
        $advertiser_id = Env::get('dmc_ad_config.advertiser_id');
        $access_token = Cache::get("qc_access_token");

        $data = [];
        $advertiser_data = AccountRelationship::advertiser_select($access_token, $advertiser_id, 1, 100);
//        var_dump($advertiser_data['data']['advertiser_ids']);die();
        $total_page = $advertiser_data['data']['page_info']['total_page'];
        $public_info_data = UserInfo::public_info($access_token, json_encode($advertiser_data['data']['advertiser_ids']));

        for ($i = 1; $i <= $total_page; $i++) {
            if ($i > 1) {
                $advertiser_data = AccountRelationship::advertiser_select($access_token, $advertiser_id, $i, 100);
                $public_info_data = UserInfo::public_info($access_token, json_encode($advertiser_data['data']['advertiser_ids']));
            }
            foreach ($public_info_data['data'] as $k => $v) {
                $salt = Random::alnum();
                $data[] = [
                    "advertiser_id" => $v['id'],
                    "company_name" => $v['company'],
                    "name" => $v['name'],
                    "first_industry_name" => $v['first_industry_name'],
                    "second_industry_name" => $v['second_industry_name'],
                    "salt" => $salt,
                    "password" => $this->auth->getEncryptPassword("123456", $salt),
                    "create_time" => time(),
                    "update_time" => time(),
                ];
            }
        }

        if (Db::name("company")->insertAll($data)) {
            return "执行成功,新增数据" . count($data) . "条";
        }
    }

    //检测千川是否有新增绑定账户并更新
    public function synchronous()
    {
        $companyModel = new Company();
        $time = time();
//        $config_data = Db::name("qc_config")->where("id", 1)->find();
        $advertiser_id = Env::get('dmc_ad_config.advertiser_id');
        $access_token = Cache::get("qc_access_token");
        $count = 100;
        $advertiser_data = AccountRelationship::advertiser_select($access_token, $advertiser_id, '', $count);
        $public_info_data = UserInfo::public_info($access_token, json_encode($advertiser_data['data']['advertiser_ids']));
        $company_add_data = [];
        foreach ($public_info_data['data'] as $item) {
            $info = $companyModel->where('advertiser_id', $item['id'])->find();
            if ($info) {
                if ($item['name'] != $info['name'] || $item['company'] != $info['company_name']) {
                    $companyModel->where(["advertiser_id" => $item["id"]])->update([
                        "name" => $item["name"],
                        "company_name" => $item["company"],
                        "update_time" => time()
                    ]);
                }else{
                    continue;
                }
            } else {
                $salt = Random::alnum();
                $company_add_data[] = [
                    "advertiser_id" => $item["id"],
                    "company_name" => $item["company"],
                    "name" => $item["name"],
                    "first_industry_name" => $item["first_industry_name"],
                    "second_industry_name" => $item["second_industry_name"],
                    "salt" => $salt,
                    "password" => $this->auth->getEncryptPassword("123456", $salt),
                    "create_time" => time(),
                    "update_time" => time(),
                ];
            }
        }
        try {
            $queue = new Queue();
            if ($advertiser_data['data']['cursor_page_info']['has_more']) {
                $queue_data = [
                    'advertiser_id' => $advertiser_id,
                    'count' => $count,
                    'cursor' => $advertiser_data['data']['cursor_page_info']['cursor'],
                ];
                $queue->addQueue('检查更新广告账户', 'app\job\SyncAdv', 'syncAdv', $queue_data);
            }
            if (!empty($company_add_data)) {
                $companyModel->saveAll($company_add_data);
            }
        } catch (\Exception $e) {
            $time = time() - $time;
            return "更新失败:" . $e->getMessage() . "花费时间：" . $time . "秒";
        }
        $time = time() - $time;
        return "更新成功," . "花费时间：" . $time . "秒";
    }

    //获取方舟登录cookie
    public function get_fz_cookie()
    {
        $url = "http://159.75.167.202:3000/jlfz/get_cookie";
        $data = [
            "email" => "apiapi@zebranumber.cn",
            "password" => "Yx147258",
        ];
        Requests::post($url, $data);
        return "执行成功";
    }

    //获取图片
    public function get_fz_transfer_image()
    {
        $data = Db::name("transfer_records")
            ->where(["status" => 1])
            ->whereNull("image")
            ->where(['transfer_serial' => ['>', 0]])
            ->where(["create_time" => [">", strtotime('today midnight')]])
            ->where(["create_time" => ["<", time() - 300]])
            ->limit(0,20)  // 做个分页，防止Google无头开启过多导致js脚本罢工【PS：因为下面更新url写入，所以每次获取的第0页都是不一样的，故写死】
            ->select();
        foreach ($data as $k => $v) {
            $url = "http://127.0.0.1:3000/jlfz/get_transfer_image";
            $data = [
                "cookie" => Cache::store("redis")->get("jlfz_cookie"),
                "transfer_serial" => $v['transfer_serial'],
            ];
            Requests::post($url, $data);
        }
        return "执行成功";
    }

    //获取图片url写入数据库
    public function get_transfer_image_url()
    {
        $data = Db::name("transfer_records")
            ->where(["status" => 1])
            ->whereNull("image")
            ->where(["create_time" => [">", strtotime('today midnight')]])
            ->where(["create_time" => ["<", time() - 300]])
            ->select();
        foreach ($data as $k => $v) {
            $url = Cache::store("redis")->get($v['transfer_serial']);
            if ($url) {
                Db::name("transfer_records")->where(["id" => $v['id']])->update(["image" => $url]);
                Cache::store("redis")->rm($v['transfer_serial']);
            }
        }
        return "执行成功";
    }

    public function genTransferImageUrl()
    {
        $list = Db::name("transfer_records")
            ->where(["status" => 1])
            ->whereNull("image")
//            ->where(["create_time" => [">", strtotime('today midnight')]])
//            ->where(["create_time" => ["<", time() - 300]])
            ->limit(30)
            ->order('create_time desc')
            ->select();
        if(!$list){
            return "没有需要处理的数据";
        }
        $token = Cache::get("qc_access_token");
        $account_id = Env::get('dmc_ad_config.advertiser_id');
        $biz_request_no = generate_random_string(10, true);
        $company_model = new Company();
        $transfer_model = new TransferRecords();
        foreach ($list as $k => $v) {
            $data = FundManagement::transfer_detail($token,  $biz_request_no,(int)$account_id, $v['transfer_serial']);

            if($data['code'] == 0 && $data['data']) {
                $transfer_info = $data['data']['transfer_target_record_list'][0];
                $target_account_info = $company_model->where(['advertiser_id' => $transfer_info['target_account_id']])->find();
                $account_info = $company_model->where(['advertiser_id' => $transfer_info['account_id']])->find();
                if($transfer_info['account_id'] == "1739518270441480"){
                    $account_info['name'] = "广州斑马数字科技有限公司";
                    $account_info['advertiser_id'] = $transfer_info['account_id'];
                    $account_info['company_name'] = "广州斑马数字科技有限公司";
                }
                $money = number_format($v['money'], 2);
                if ($v['transfer_direction'] == 1) {
                    $transfer_type = "加款";
                    $transfer_in = $target_account_info['name'] . "\n转入方ID：" . $target_account_info['advertiser_id'];
                    $transfer_out = $account_info['name'] . "\n转出方ID：" . $account_info['advertiser_id'];
                } else if ($v['transfer_direction'] == 2) {
                    $transfer_type = "退款";
                    $money = '-'.$money;
                    $transfer_in = $account_info['name'] . "\n转入方ID：" . $account_info['advertiser_id'];
                    $transfer_out = $target_account_info['name'] . "\n转出方ID：" . $target_account_info['advertiser_id'];
                }
                if ($account_info['company_name'] == $target_account_info['company_name']) {
                    $transfer_type = "同级账户转账";
                }
                $img_data = [
                    $data['data']['transfer_finish_time'],
                    $transfer_out,
                    $transfer_in,
                    $transfer_type,
                    '通用',
                    $money,
                    '账户余额',
                    'OPENAPI'];
                $day = date('Ymd');
                $path = ROOT_PATH . 'public/transfer_images/' . $day . '/';
                $file_name = (int)round(microtime(true) * 1000) . '.png';
                if (!file_exists($path)) {
                    $created = mkdir($path, 0755, true);
                    if (!$created) {
                        // 错误处理
                        dump("目录创建失败: {$path}");
                        die;
                    }
                }
                $res = generateTransferImg($img_data, [], $path, $file_name);
                if ($res) {
                    $update[] = [
                        'id'=>$v['id'],
                        'image'=>'transfer_images/' . $day . '/' . $file_name
                    ];
                } else {
                    dump($res);
                }
            }
        }
       $res =  $transfer_model->saveAll($update);
        if($res){
            return "执行成功";
        }
        return $res;

    }

    public function updateKahuna()
    {
        if (Cache::get('kahuna_run_status') == 1) {
            echo "今日已经更新完毕";
            return;
        }
        $i = 0;
        $access_token = Cache::get("qc_access_token");
        $advertiser_ids = Cache::get("ad_ids");
        $advertiser_ids_info = Cache::get("ad_ids_info");
        if (!$advertiser_ids) {
            $advertiser_ids = Db::name("company")->column("advertiser_id");
        }
        if (!$advertiser_ids_info) {
            $advertiser_ids_info = Db::name("company")->column("kahuna", "advertiser_id");
            Cache::set('ad_ids_info', $advertiser_ids_info);
        }
        $advertiser_ids = array_map(function ($item) {
            return (int)$item;
        }, $advertiser_ids);
        foreach ($advertiser_ids as $key => $split) {
            if ($i == 50) {
                break;
            }
            $res1 = FundManagement::get_ad_info($access_token, json_encode([$split], JSON_UNESCAPED_UNICODE));
            if ($res1['code'] == 0 && $res1['data']['account_detail_list'][0]['optimizer_name'] != $advertiser_ids_info[$split]) {
                $arr['kahuna'] = $res1['data']['account_detail_list'][0]['optimizer_name'];
                if (!Db::name('company')->where(['advertiser_id' => $split])->update($arr)) {
                    throw new \Exception('出错');
                }
            }
            unset($advertiser_ids[$key]);
            $i++;
        }
        if ($i < 50) {
            $expiryDate = new \DateTime();
            $expiryDate->setTime(0, 0, 0);
            $expiryDate->modify('+1 day');
            Cache::rm('ad_ids');
            Cache::rm('ad_ids_info');
            Cache::set('kahuna_run_status', 1, $expiryDate);
            echo "全部完成";
            return;
        }
        Cache::set('ad_ids', $advertiser_ids);
        echo "本次更新完成";
    }

    // 创建获取广告计划队列 [每天凌晨执行]
    public function createQcObjQueue()
    {
        $this->createVPGObjQueue();
        $this->createLPGObjQueue();
        echo "OK";
    }

    public function createVPGObjQueue()
    {
        $queueModel = new Queue();
        $company_info = Db::name('company')->where("id", '>', 0)->field('id,advertiser_id')->select();
        $split_array = array_chunk($company_info, 100);
        foreach ($split_array as $k => $v) {
            $data['marketing_goal'] = 'VIDEO_PROM_GOODS';  // 推商品
            $data['time_describe'] = ' -1 days';
            $data['data'] = $v;
            $queueModel->addQueue('获取广告计划【推商品】', 'app\job\QcObj', 'createQcObj', $data, '');
        }
    }

    public function createLPGObjQueue()
    {
        $queueModel = new Queue();
        $company_info = Db::name('company')->where("id", '>', 0)->field('id,advertiser_id')->select();
        $split_array = array_chunk($company_info, 100);
        foreach ($split_array as $k => $v) {
            $data['marketing_goal'] = 'LIVE_PROM_GOODS';  // 推直播间
            $data['time_describe'] = ' -1 days';
            $data['data'] = $v;
            $queueModel->addQueue('获取广告计划【推直播间】', 'app\job\QcObj', 'createQcObj', $data, '');
        }
    }


    // 创建获取广告计划操作日志队列 [每天凌晨执行]  2
    public function createQcOptQueue()
    {
        $queueModel = new Queue();
        $data = [];
        $redis = Cache::store('redis_db2')->handler();
        $array = $redis->SMEMBERS('obj_arr');
        foreach ($array as $v) {
            $v = unserialize($v);
            if (!isset($data[$v['advertiser_id']])) {
                $data[$v['advertiser_id']] = [];
            }
            $data[$v['advertiser_id']][] = $v['object_id'];
        }
        $split_array = array_chunk($data, 1, true);
        foreach ($split_array as $v) {
            $queue_data['start_time'] = date('Y-m-d H:i:s', strtotime(date('Y-m-d') . ' -1 days'));
            $queue_data['end_time'] = date('Y-m-d H:i:s', strtotime(date('Y-m-d')));
            $queue_data['data'] = $v;
            $queueModel->addQueue('获取广告计划操作日志', 'app\job\QcOpt', 'createQcOpt', $queue_data, '');
        }
    }


    // 创建更新广告计划状态队列 [每天凌晨执行]  3
    public function updateObjStatusQueue()
    {
        $queueModel = new Queue();
        $obj_info = [];
        $redis = Cache::store('redis_db2')->handler();
        $array = $redis->SMEMBERS('obj_arr');
        foreach ($array as $v) {
            $v = unserialize($v);
            $obj_info[$v['object_id']] = $v['advertiser_id'];
        }
        $split_array = array_chunk($obj_info, 100, true);
        foreach ($split_array as $v) {
            $queueModel->addQueue('更新广告计划状态', 'app\job\UpdateObjStatus', 'updateObjStatus', $v, '');
        }
    }


    // 消费缓存数据更新队列状态
    public function consumptionCache()
    {
        $queueModel = new Queue();
        $redis = Cache::store('redis_db2')->handler();
        for ($i = 0; $i <= 500; $i++) {
            $data = $redis->lpop('queue_status_update');
            if (empty($data)) {
                break;
            }
            if ($data == "Array") {
                continue;
            }
            $data = unserialize($data);
            $job_id = array_keys($data)[0];
            if (!$queueModel->where('job_id', $job_id)->update($data[$job_id])) {
                $redis->rpush('queue_status_update', serialize($data));
            }
        }
        echo 'success';
    }


    // 获取广告主负责人名称
    public function getKahuna()
    {
        // 防止并发导致数据表锁死
        $queueSum = Db::name("queue_record")->where(['queue_name' => 'updateObjStatus', 'status' => 0])->count();
        if ($queueSum > 0) {
            echo "每日更新队列尚未消费完成";
            return;
        }

        $advertiser_ids = Db::name("company")->where('kahuna', null)->limit(100)->column("advertiser_id");
        if (empty($advertiser_ids)) {
            echo '无更新';
            return;
        }
        $access_token = Cache::get("qc_access_token");
        $advertiser_ids = array_map(function ($item) {
            return (int)$item;
        }, $advertiser_ids);
        foreach ($advertiser_ids as $key => $split) {
            $res1 = FundManagement::get_ad_info($access_token, json_encode([$split], JSON_UNESCAPED_UNICODE));
            if ($res1['code'] == 0) {
                $arr['kahuna'] = $res1['data']['account_detail_list'][0]['optimizer_name'];
                $arr['update_time'] = time();
                $res =  Db::name('company')->where(['advertiser_id' => $split])->update($arr);
            }
        }
        echo '完成';
    }


    // 每日更新本月操作次数[测试]
    public function updateThisMonthOptSum()
    {
        $bm_username = Db::name('ad_operator')->field('name')->select();
        $bm_username = array_column($bm_username, 'name');
        $a_count = Db::name('plan_opt_log')
            ->field('advertiser_id,count(*) as this_month_opt_sum')
            ->group('advertiser_id')
            ->where([
                'opt_time' => ['between', [strtotime("first day of this month 00:00:00"), strtotime("last day of this month 23:59:59")]]
            ])->select();

        $b_count = Db::name('plan_opt_log')
            ->field('advertiser_id,count(*) as this_month_bmopt_sum')
            ->group('advertiser_id')
            ->where([
                'operator' => ['in', $bm_username],
                'opt_time' => ['between', [strtotime("first day of this month 00:00:00"), strtotime("last day of this month 23:59:59")]]
            ])->select();
        // 初始化结果数组
        $result = [];

        // 处理 this_month_opt_sum
        foreach ($a_count as $item) {
            $advertiser_id = $item['advertiser_id'];
            if (!isset($result[$advertiser_id])) {
                $result[$advertiser_id] = [];
            }
            $result[$advertiser_id]['this_month_opt_sum'] = $item['this_month_opt_sum'];
            $result[$advertiser_id]['this_month_bmopt_sum'] = 0;
        }

        // 处理 this_month_bmopt_sum
        foreach ($b_count as $item) {
            $advertiser_id = $item['advertiser_id'];
            if (!isset($result[$advertiser_id])) {
                $result[$advertiser_id] = [];
            }
            $result[$advertiser_id]['this_month_bmopt_sum'] = $item['this_month_bmopt_sum'];
        }


        foreach ($result as $advertiser_id => $item) {
            Db::name('company')->where('advertiser_id', $advertiser_id)->update($item);
        }
        echo "更新完成";
    }


    public function btNotice(){
        return 1;
    }


}