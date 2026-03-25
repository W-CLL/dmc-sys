<?php
namespace app\api\controller;


use app\admin\model\Company;
use app\admin\model\QcGlobalObj;
use app\common\controller\Api;
use app\common\model\Queue;
use app\common\model\viral_fission\FissionDeriveMaterial;
use app\store\model\TransferRecords;
use app\store\model\ShareWalletTransferLog;
use fast\Random;
use jlqc\AccountRelationship;
use jlqc\FundManagement;
use jlqc\UserInfo;
use Requests;
use think\Cache;
use think\Db;
use think\Env;
use think\Exception;
use think\exception\PDOException;


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
        $max_retries = 3;  // 最大重试次数
        $retry_delay = 1;  // 重试间隔（秒）
        
//        dump(Cache::get("qc_access_token"));
//        $data = Db::name("qc_config")->where("id", 1)->find();
        $app_id = Env::get('dmc_ad_config.app_id');
        $secret = Env::get('dmc_ad_config.secret');
//        $data = Env::get('dmc_ad_config');
        
        for ($i = 1; $i <= $max_retries; $i++) {
            $data = \jlqc\AccessToken::refresh_token_save($app_id, $secret);
            
            if ($data['code'] == 0 && $data['message'] == "OK") {
                Cache::set("qc_access_token", $data['data']['access_token'], 0);
                Cache::set("qc_refresh_token", $data['data']['refresh_token'], 0);
                return "刷新授权状态成功";
            }
            
            // 如果不是最后一次尝试，则等待后重试
            if ($i < $max_retries) {
                sleep($retry_delay);
            }
        }

        return "刷新失败，已重试{$max_retries}次，错误信息:" . json_encode($data, JSON_UNESCAPED_UNICODE);
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
                    $company_add_data[] = [
                        'id' => $info['id'],
                        "name" => $item["name"],
                        "company_name" => $item["company"]
                    ];
//                    $companyModel->where(["advertiser_id" => $item["id"]])->update([
//                        "name" => $item["name"],
//                        "company_name" => $item["company"],
//                        "update_time" => time()
//                    ]);
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
//            $queue = new Queue();
            if ($advertiser_data['data']['cursor_page_info']['has_more']) {
                $queue_data = [
                    'advertiser_id' => $advertiser_id,
                    'count' => $count,
                    'cursor' => $advertiser_data['data']['cursor_page_info']['cursor'],
                ];
                \think\Queue::push('app\job\SyncAdv', $queue_data, 'syncAdv');
//                $queue->addQueue('检查更新广告账户', 'app\job\SyncAdv', 'syncAdv', $queue_data);
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
            ->limit(0, 20)  // 做个分页，防止Google无头开启过多导致js脚本罢工【PS：因为下面更新url写入，所以每次获取的第0页都是不一样的，故写死】
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
            ->where(["status" => 1, "from" => 1]) // 仅处理dmc充值的
            ->whereNull("image")
            ->whereNotNull('transfer_serial')
//            ->where(["create_time" => [">", strtotime('today midnight')]])
//            ->where(["create_time" => ["<", time() - 300]])
            ->limit(30)
            ->order('create_time desc')
            ->select();
        if (!$list) {
            return "没有需要处理的数据";
        }
        $token = Cache::get("qc_access_token");
        $account_id = Env::get('dmc_ad_config.advertiser_id');
        $biz_request_no = generate_random_string(10, true);
        $company_model = new Company();
        $transfer_model = new TransferRecords();
        $update = [];
        $error = [];
        foreach ($list as $k => $v) {
            $data = FundManagement::transfer_detail($token, $biz_request_no, (int)$account_id, $v['transfer_serial']);
            if ($data['code'] == 0 && $data['data']) {
                $transfer_info = $data['data']['transfer_target_record_list'][0];
                $target_account_info = $company_model->where(['advertiser_id' => $transfer_info['target_account_id']])->find();
                $account_info = $company_model->where(['advertiser_id' => $transfer_info['account_id']])->find();
                if ($transfer_info['account_id'] == "1739518270441480") {
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
                    $money = '-' . $money;
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
                        'id' => $v['id'],
                        'image' => 'transfer_images/' . $day . '/' . $file_name
                    ];
                } else {
                    dump($res);
                }
            } else {
                $error[] = $data['message'] . $v['id'];
                $update[] = [
                    'id' => $v['id'],
                    'explain' => $data['message']
                ];
            }
        }
        if ($error) {
            echo json_encode($error);
        }
        if ($update) {
            $res = $transfer_model->saveAll($update);
            if ($res) {
                return "执行成功";
            } else {
                return "执行失败";
            }
        }


        return '执行成功2';


    }


    public function shareWalletTransferImageUrl()
    {
        $swtl_model = new ShareWalletTransferLog();
        $list = $swtl_model
            ->where(["status" => 1, "from" => 1]) // 仅处理dmc充值的
            ->whereNull("image")
            ->whereNotNull('transfer_serial')
//            ->where(["create_time" => [">", strtotime('today midnight')]])
//            ->where(["create_time" => ["<", time() - 300]])
            ->limit(30)
            ->order('create_time desc')
            ->select();
        if (!$list) {
            return "没有需要处理的数据";
        }
        $update = [];
        $error = [];
        foreach ($list as $k => $v) {
            $transfer_detail = FundManagement::check_transfer_detail(
                Cache::get("qc_access_token"),
                Env::get('dmc_ad_config.advertiser_id'),
                'AGENT',
                generate_random_string(10, true),
                $v['transfer_serial']
            );
            if ($transfer_detail['code'] != 0) {
                $error[] = $transfer_detail['message'] . $v['id'];
                $update[] = [
                    'id' => $v['id'],
                    'image' => $transfer_detail['message']
                ];
                continue;
            }
            $main_wallet_info = [
                'name' => "广州斑马数字科技有限公司共享钱包",
                'wallet_id' => $v['main_wallet_id']
            ];
            $res = FundManagement::get_wallet_info_list(
                Cache::get("qc_access_token"),
                Env::get('dmc_ad_config.advertiser_id'),
                json_encode([(int)$v['sub_wallet_id']]),
                'AGENT');
            if ($res['code'] != 0) {
                $error[] = $res['message'] . $v['id'];
                $update[] = [
                    'id' => $v['id'],
                    'fail_reason' => $res['message']
                ];
                continue;
            }
            $sub_wallet_info = [
                'name' => $res['data']['wallet_info'][0]['common_wallet_info']['wallet_name'],
                'wallet_id' => $v['sub_wallet_id']
            ];
            $money = number_format($v['money'], 2);
            if ($v['transfer_direction'] == 1) {
                $transfer_type = "加款";
                $transfer_in = $sub_wallet_info['name'] . "\n钱包ID：" . $sub_wallet_info['wallet_id'];
                $transfer_out = $main_wallet_info['name'] . "\n钱包ID：" . $main_wallet_info['wallet_id'];
            } else if ($v['transfer_direction'] == 2) {
                $transfer_type = "退款";
                $money = '-' . $money;
                $transfer_in = $main_wallet_info['name'] . "\n钱包ID：" . $main_wallet_info['wallet_id'];
                $transfer_out = $sub_wallet_info['name'] . "\n钱包ID：" . $sub_wallet_info['wallet_id'];
            }
            $img_data = [
                $transfer_detail['data']['transfer_finish_time'],
                $transfer_out,
                $transfer_in,
                $transfer_type,
                '巨量广告/千川/本地推',
                $money,
                'OPENAPI'];
            $day = date('Ymd', $v['create_time']);
            $path = ROOT_PATH . 'public/share_wallet_images/' . $day . '/';
            $file_name = (int)round(microtime(true) * 1000) . '.png';
            if (!file_exists($path)) {
                $created = mkdir($path, 0755, true);
                if (!$created) {
                    // 错误处理
                    throw new Exception("目录创建失败: {$path}");
                }
            }
            $headerTexts = ['转账时间', '转出方', '转入方', '转账类型', '业务平台', '转账总金额', '操作人'];
            $result = generateTransferImg($img_data, $headerTexts, $path, $file_name);
            if ($result) {
                $update[] = [
                    'id' => $v['id'],
                    'image' => 'share_wallet_images/' . $day . '/' . $file_name
                ];
            } else {
                dump($result);
            }
        }
        if ($error) {
            echo json_encode($error);
        }
        if ($update) {
            $update_res = $swtl_model->saveAll($update);
            if ($update_res) {
                return "执行成功";
            } else {
                return "执行失败";
            }
        }

        return '执行成功2';


    }

    /**
     * 更新负责人
     * @return void
     * @throws Exception
     * @throws PDOException
     */
    public function updateKahuna()
    {


        $access_token = Cache::get("qc_access_token");
        $page = Cache::get('update_company_info_page', 1);
        $com_model = new Company();
        $advertiser_info = $com_model
            ->field('id,advertiser_id,kahuna,agent_id,collaborators')
            ->where('adv_status', 1)
            ->order('id desc')
            ->limit(200)
            ->page($page)
            ->select();
        if (empty($advertiser_info)) {
            Cache::rm('update_company_info_page');
            echo "处理完成";
            die;
        }
        $chunks = array_chunk((array)$advertiser_info, '50');
        $update = [];
        foreach ($chunks as $chunk) {
            $advertiser_ids = array_column((array)$chunk, 'advertiser_id');
            $advertiser_ids = array_map(function ($item) {
                return (int)$item;
            }, $advertiser_ids);
            $res = FundManagement::get_ad_info($access_token, json_encode($advertiser_ids, JSON_UNESCAPED_UNICODE));

            if ($res['code'] == 0 && !empty($res['data']['account_detail_list'])) {
                foreach ($chunk as $info) {
                    foreach ($res['data']['account_detail_list'] as $list) {
                        if ($info['advertiser_id'] == $list['advertiser_id']) {
                            $collaborators = implode(',', array_column($list['collaborators'], 'employee_name'));
                            if (
                                ($info['kahuna'] != $list['optimizer_name']) ||
                                ($info['collaborators'] != $collaborators) ||
                                ($info['agent_id'] != $list['first_agent_id'])

                            ) {
                                $update[] = [
                                    'id' => $info['id'],
                                    'kahuna' => $list['optimizer_name'],
                                    'collaborators' => $collaborators,
                                    'agent_id' => $list['first_agent_id'],
                                ];
                            }

                        }
                    }

                }
            }
        }

        try {
            $com_model->saveAll($update);
            echo "处理完了第" . $page . ("页，准备处理下一页,一页处理200条");
            $page = $page + 1;
            Cache::set('update_company_info_page', $page);

        } catch (Exception $e) {
            throw new \Exception($e->getMessage());
        }

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


    // 获取广告主负责人名称
    public function getKahuna()
    {
        // 防止并发导致数据表锁死
        $queueSum = Db::name("queue_record")->where(['queue_name' => 'updateObjStatus', 'status' => 0])->count();
        if ($queueSum > 0) {
            echo "每日更新队列尚未消费完成";
            return;
        }

        $advertiser_ids = Cache::store('redis')->handler()->lpop('advertiser_ids');
        if (empty($advertiser_ids)) {
            $advertiser_ids = Db::name("company")->where('kahuna', null)->whereOr('collaborators', null)->column("advertiser_id");
            if (empty($advertiser_ids)) {
                echo '无更新';
                return;
            }
            $split_array = array_chunk($advertiser_ids, 100);
            foreach ($split_array as $split) {
                Cache::store('redis')->handler()->rpush('advertiser_ids', serialize($split));
            }
            echo '重排序';
            return;
        }
        $advertiser_ids = unserialize($advertiser_ids);
        $access_token = Cache::get("qc_access_token");
        $advertiser_ids = array_map(function ($item) {
            return (int)$item;
        }, $advertiser_ids);
        foreach ($advertiser_ids as $key => $split) {
            $res1 = FundManagement::get_ad_info($access_token, json_encode([$split], JSON_UNESCAPED_UNICODE));
            if ($res1['code'] == 0) {
                $arr['collaborators'] = json_encode($res1['data']['account_detail_list'][0]['collaborators']);
                $arr['kahuna'] = $res1['data']['account_detail_list'][0]['optimizer_name'];
                $arr['agent_id'] = $res1['data']['account_detail_list'][0]['first_agent_id'];
                $arr['update_time'] = time();
                Db::name('company')->where(['advertiser_id' => $split])->update($arr);
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


    public function btNotice()
    {
        return 1;
    }


    public function updateGlobalObjStatus()
    {
        for ($i = 0; $i <= 1000; $i++) {
            $data = Cache::store('redis')->handler()->lpop('updateGlobalObjStatus');
            if (empty($data)) {
                break;
            }
            $data = json_decode($data, true);
            $save[] = $data;
        }
        if (empty($save)) {
            echo "无数据不更新";
            die;
        }
        $qcGlobalObjModel = new QcGlobalObj();
        $res = $qcGlobalObjModel->saveAll($save);
        if ($res) {
            echo "更新成功，总更新条数：" . count($save);
        } else {
            echo "更新失败";
        }
    }


    public function uploadImage()
    {
        $time = time();
        $model = new FissionDeriveMaterial();
        $list = $model
            ->whereNull('adopt_cover_id')  // 使用专门的 whereNull 方法
            ->whereNull('cover_msg')
            ->whereNotNull('material_info')
            ->where('create_time', '>', strtotime("-7 day"))
            ->field('id, adv_id, adopt_cover_id, material_info')
            ->limit(50)
            ->order('id desc')
            ->select();
        if (empty($list)) {
            echo "空列表";
            die;
        }
        $update = [];
        foreach ($list as $item) {
            $res = FundManagement::upload_image([
                'advertiser_id' => (int)$item['adv_id'],
                'upload_type' => 'UPLOAD_BY_URL',
                'image_url' => json_decode($item['material_info'], true)['cover_url']
            ]);
            if ($res['message'] == "OK" && $res['code'] == 0) {
                $update[] = [
                    'id' => $item['id'],
                    'adopt_cover_id' => $res['data']['id']
                ];
            } else {
                $update[] = [
                    'id' => $item['id'],
                    'cover_msg' => $res['message']
                ];
            }
        }
        if (empty($update)) {
            echo "无更新";
            die;
        }
        try {
            $model->saveAll($update);
            echo "更新成功，总更新条数：" . count($update) . "。总花费时间:" . (time() - $time);
        } catch (\Exception $e) {
            echo "更新失败";
        }
    }


    public function getAdFund($type = 1){
        // 设置免扰时间段，如果处于每天1-6点直接，则跳过
        $time = time();
        if ($time >= strtotime("today 01:00:00") && $time < strtotime("today 06:00:00")){
            echo "免扰时间段";
            die;
        }
        $queueModel = new Queue();
        $array = [];
        $user_list = Db::name('wechat_group')->where(['power' => ['like', '%3%']])->field('bind_store_id,power,group_id')->select();
        if (empty($user_list)){
            echo "无操作用户";
            die;
        }
        switch ($type){
            case 1:
                foreach ($user_list as $item){
                    $company_list = Db::name('company')->where(['store_id' => $item['bind_store_id']])->field('advertiser_id')->column('advertiser_id');
                    $array[$item['group_id']] = $company_list;
                }
                foreach ($array as $group_id => $advertiser_id_list){
                    $chunk = array_chunk($advertiser_id_list, 20);
                    foreach ($chunk as $item){
                        $data = [
                            'advertiser_id_list' => $item,
                            'group_id' => $group_id,
                            'type' => $type
                        ];
                        \think\Queue::push('app\job\checkAdFund', $data, "checkAdFund");
                    }
                }
                break;
            case 2:
                foreach ($user_list as $item){
                    $wallet_list = Db::name('qc_share_wallet')->where(['bind_store_id' => $item['bind_store_id']])->field('sub_wallet_id')->column('sub_wallet_id');
                    $array[$item['group_id']] = $wallet_list;
                }
                foreach ($array as $group_id => $sub_wallet_id_list){
                    $chunk = array_chunk($sub_wallet_id_list, 200);
                    foreach ($chunk as $item){
                        $data = [
                            'sub_wallet_id_list' => $item,
                            'group_id' => $group_id,
                            'type' => $type
                        ];
                        \think\Queue::push('app\job\checkAdFund', $data, "checkAdFund");
                    }
                }
        }
        echo "添加任务成功";
    }




    // 推送素材预审
    public function prequalification(){
        $fiveMinutesAgo = time() - 300;
        $info = Db::name('material_prequalification')->where([
            "status" => 0,
            "create_time" => ["<=", $fiveMinutesAgo],// 取五分钟之前的数据
            "object_id" => NULL,
        ])->field("material_id,advertiser_id,id")->limit(1000)->select();
        $result = [];
        $ids = [];
        // 按material_id去重，保留第一条记录
        $materialIdMap = [];
        foreach ($info as $value) {
            if (isset($materialIdMap[$value['material_id']])) {
                continue; // 已存在，跳过
            }
            $materialIdMap[$value['material_id']] = true;
            $result[$value['advertiser_id']][] = $value['material_id'];
            $ids[] = $value['id'];
        }
        foreach ($result as $key => $value){
            $chunk = array_chunk($value,20);
            foreach ($chunk as $v){
                // 确保material_ids中的值为int类型
                $v = array_map('intval', $v);
                \think\Queue::push('app\job\prequalification\Prequalification', ['advertiser_id' => $key, 'material_ids' => $v], 'prequalification');
            }
        }
        // 更新为已推送状态，防止重复推送
        if (!empty($ids)) {
            Db::name('material_prequalification')->where(['id' => ['in', $ids]])->update(['status' => 1]);
        }
        echo "ok";
    }




    // 更新素材预审结果
    public function updatePrequalificationStatus(){
        $key = 'material_precheck_result';
        for ($i = 0 ; $i < 50 ; $i++){
            $content = \think\Cache::store('redis')->handler()->lpop($key);
            if (empty($content)){
                return "implement:". $i ."    no more";
            }
            $content = json_decode($content,true);
            try {
                if ($content['status'] == 'APPROVE'){
                    $update = [
                        'status' => 2
                    ];
                }else{
                    $update = [
                        'status' => 3,
                        'reason_text' => $content['reason_text']
                    ];
                }
                Db::name('material_prequalification')->where(['object_id' => $content['object_id']])->update($update);
            }catch (Exception $e){
                \think\Cache::store('redis')->handler()->rpush($key, json_encode($content));  // 重新放回缓存
            }
        }
        return "all";
    }


    // 推送前测
    public function submitDiagnosis(){
        $info = Db::name('material_prequalification')
            ->where(['status' => 2,'to_diagnosis' => 0])
            ->field('id,advertiser_id,video_id')
            ->limit(1000)->select();
        if (!$info){
            echo "no more";
            die;
        }
        foreach ($info  as $item){
            $arr[$item['advertiser_id']][] = $item['video_id'];
            $id[] = $item['id'];
        }
        foreach ($arr as $key => $value){
            $chunk = array_chunk($value,100);
            foreach ($chunk as $v){
                \think\Queue::push('app\job\material_diagnosis\SubmitDiagnosis', ['advertiser_id' => $key, 'video_ids' => $v], 'submitDiagnosis');
            }
        }
        Db::name('material_prequalification')->where(['id' => ['in',$id]])->update(['to_diagnosis' => 1]);
        echo "ok";
    }


    // 获取前测结果
    public function getDiagnosisInfo(){
        $task_ids = Db::name('material_diagnosis')->where(['status' => 0])->column('task_id');
        $chunk = array_chunk($task_ids,100);
        foreach ($chunk as $item){
            $item = array_map('intval', $item);
            \think\Queue::push('app\job\material_diagnosis\GetDiagnosisInfo', ['task_ids' => $item], 'getDiagnosisInfo');
        }
        Db::name('material_diagnosis')->where(['task_id' => ['in',$task_ids]])->update(['is_get' => 1]);
        echo "ook";
    }
}