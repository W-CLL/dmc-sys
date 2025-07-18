<?php

namespace app\index\controller;

use app\common\controller\Frontend;
use app\robotapi\model\QueueRobot;
use qywx\Api;
use think\Cache;
use think\Db;
use txy\TextRecognition;

class Reserve extends Frontend
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';
    protected $layout = 'default';
    protected static $payee = [
        "广州斑马数字科技有限公司",
        "罗文静",
        "*文静",
        "罗*静",
        "吴忠杰",
        "*忠杰",
        "吴*杰",
        "黄娜",
        "*娜",
    ];
    protected static $unique_identifier = [
        "日志号",
        "交易序号",
        "凭证号",
        "交易流水号",
        "电子回单号",
        "网银交易流水号",
        "指令序号",
        "汇款编号",
        "回单号",
        "受理单号",
        "回单流水号",
        "柜员交易号",
        "转账流水号",
        "流水号",
        "凭证号",
        "回单验证码"
    ];

    protected static $money_name = [
        "金额",
        "金额小写",
        "汇款金额",
        "小写金额",
        "转账金额",
        "金额(小写)",
        "收款金额",
        "转账汇款金额",
        "币种及金额(大小写)",
        "金额/币种",
        "交易金额",
    ];

    protected static $payee_key = [
        "收款人",
        "收款人名称",
        "收款人全称",
        "收款单位",
        "收款方户名",
        "收款户名",
        "收款人姓名",
        "收款人户名",
        "户名",
        "姓名", // ocr识别失败?返回一个"姓名"键
    ];


    public function index()
    {
        $token = input('token','');
        if (empty($token) || !Cache::store('redis')->handler()->exists("token_to_group:" . $token)) {
            $this->error("链接已失效，请重新获取", '');
        }
        if ($this->request->isAjax()) {
            $time = time();
            $group_id = Cache::store('redis')->handler()->get("token_to_group:" . $token);
            $callback_data = Cache::store('redis')->handler()->get("token_to_data:" . $token);
            $store_id = Db::name("wechat_group")->where(["group_id" => $group_id])->value("bind_store_id");
            $store = Db::name("store")->where(['id' => $store_id])->find();
            $order_num = input("order_num");
            $redis = Cache::store('redis')->handler();
            $order = $redis->get($order_num);
            if (empty($order)) {
                $this->error("备款充值失败，请刷新后重试。");
            }
            $redis->del($order_num);
            $order = json_decode($order, true);
            if (Db::name("store_money_log")->where("order_number", $order['order_number'])->count()) {
                $this->error("该回单已充值");
            }
            Db::startTrans();
            try {
                if ($order['account_type'] == 1) {
                    $before_money = $store['public_money'];
                    $before_limit = $store['public_credit_limit'];
                    $actual_money = $order['money'];
                    $deduction_credit_limit = 0;
                    $explain = "充值公账钱包" . $order['money'] . "元";
                    if ($store['public_spending_credit_limit'] > 0) {
                        $explain .= ",已使用公账授信额度" . $store['public_spending_credit_limit'] . "元,";
                        if ($store['public_spending_credit_limit'] >= $order['money']) {
                            if (!Db::name("store")->where(['id' => ["=", $store['id']], 'public_spending_credit_limit' => ['>=', $order['money']]])->setDec('public_spending_credit_limit', $order['money'])) {
                                throw new \Exception('扣除授信额度失败');
                            }
                            $actual_money = 0;
                            $deduction_credit_limit = $order["money"];
                            $explain .= "扣除" . $order['money'] . "元";
                        } else {
                            $actual_money = $order['money'] - $store['public_spending_credit_limit'];
                            $deduction_credit_limit = $store['public_spending_credit_limit'];

                            Db::name("store")->where('id', $store['id'])->update(['public_spending_credit_limit' => 0]);
                            $explain .= "扣除" . $store['public_spending_credit_limit'] . "元";
                        }
                        $explain .= "实际到账" . $actual_money . "元";
                        Db::name("store")->where("id", $store['id'])->setInc("public_credit_limit", $deduction_credit_limit);
                    }
                    if ($actual_money > 0) {
                        Db::name("store")->where(['id' => $store['id']])->setInc("public_money", $actual_money);
                    }
                } else {
                    $before_money = $store['private_money'];
                    $before_limit = $store['private_credit_limit'];
                    $actual_money = $order['money'];
                    $deduction_credit_limit = 0;
                    $explain = "充值私账钱包" . $order['money'] . "元";
                    if ($store['private_spending_credit_limit'] > 0) {
                        $explain .= ",已使用私账授信额度" . $store['private_spending_credit_limit'] . "元,";
                        if ($store['private_spending_credit_limit'] >= $order['money']) {
                            if (!Db::name("store")->where(['id' => ["=", $store['id']], 'private_spending_credit_limit' => ['>=', $order['money']]])->setDec('private_spending_credit_limit', $order['money'])) {
                                throw new \Exception('扣除授信额度失败');
                            }
                            $actual_money = 0;
                            $deduction_credit_limit = $order["money"];
                            $explain .= "扣除" . $order['money'] . "元";
                        } else {
                            $actual_money = $order['money'] - $store['private_spending_credit_limit'];
                            $deduction_credit_limit = $store['private_spending_credit_limit'];
                            Db::name("store")->where('id', $store['id'])->update(['private_spending_credit_limit' => 0]);
                            $explain .= "扣除" . $store['private_spending_credit_limit'] . "元";
                        }
                        $explain .= "实际到账" . $actual_money . "元";
                        Db::name("store")->where("id", $store['id'])->setInc("private_credit_limit", $deduction_credit_limit);
                    }
                    if ($actual_money > 0) {
                        Db::name("store")->where(['id' => $store['id']])->setInc("private_money", $actual_money);
                    }
                }

                Db::name("store_money_log")->insert([
                    "store_id" => $store["id"],
                    "username" => $store["username"],
                    "money" => $order['money'],
                    "actual_money" => $actual_money,
                    "account_type" => $order["account_type"],
                    "deduction_credit_limit" => $deduction_credit_limit,
                    "receipt_image" => $order['image'],
                    "before_money" => $before_money,
                    "today_money" => $before_money + $actual_money,
                    "order_number" => $order["order_number"],
                    "type" => 3,
                    "explain" => $explain,
                    "create_time" => time(),
                    "balance_surplus" => $before_money + $actual_money,
                    "credit_limit_surplus" => $before_limit + $deduction_credit_limit,
                    "from" => 2
                ]);
                $msg = $explain;
                $msg .= "\n【钱包余额：".($before_money + $actual_money)."，授信余额：".($before_limit + $deduction_credit_limit)."】";
                $msg = "您于" . date("Y-m-d H:i:s", $time)."提交备款充值：\n" .$msg;
                // 提交事务
                $this->callback(json_decode($callback_data, true), $msg);
                Db::commit();
            } catch (\Exception $e) {
                // 回滚事务
                Db::rollback();
                $this->error($e->getMessage());
            }

            $user_ids = Db::name("financial_staff")->where(["state" => 1])->column("user_id");
            if (!empty($user_ids)) {
                $media_id = Api::media_upload(ROOT_PATH . "public" . $order['image']);
                if (!empty($media_id)) {
                    $user_ids = implode("|", $user_ids);
                    Api::send_image_messages($user_ids, $media_id);
                }
            }
            $this->success();
        }
        return $this->view->fetch();
    }

    public function get_image_info()
    {
        $image = input("image");
        $config_data = Db::name("qc_config")->where("id", 2)->find();
        $data = TextRecognition::get_image_info($config_data['secret'], $config_data['api_key'], request()->domain() . $image);
        $money = 0;
        $payee = '';
        $order_number = '';
        $account_type = 0;

        foreach ($data['BankSlipInfos'] as $k => $v) {
            if (!$money) {
                foreach (self::$money_name as $key => $vel) {
                    if ($v['Name'] == $vel) {
                        $money = floatval(preg_replace('/[^\d.]/', '', $v['Value']));
                        break;
                    }
                }
            }
            if (!$payee) {
                foreach (self::$payee_key as $payee_name) {
                    if ($v["Name"] == $payee_name) {
                        $payee = $v['Value'];

                        foreach (self::$payee as $key => $vel) {
                            if ($v["Value"] == $vel) {
                                if ($key == 0) {
                                    $account_type = 1;
                                } else {
                                    $account_type = 2;
                                }
                                break;
                            }
                        }
                        break;
                    }

                }
            }
            if ($account_type == 0) {
                $payee = '';
            }
            if (!$order_number) {
                foreach (self::$unique_identifier as $key => $vel) {
                    if ($v["Name"] == $vel) {
                        if (ctype_digit($v['Value']) && $v["Value"] != 0) {
                            if (!empty((int)$v['Value'])) {
                                $order_number = $v['Value'];
                            }
                        } else {
                            if (!empty($v['Value'])) {
                                $order_number = $v['Value'];
                            }
                        }
                    }
                }
            }
        }
        if ($account_type == 0) {
            return json(['code' => 0, "msg" => "识别失败,请检查回单"]);
        }

        if ($money && $payee && $order_number) {
            if (Db::name("store_money_log")->where("order_number", $order_number)->count()) {
                return json(['code' => 0, "msg" => "该回单已充值"]);
            }
            $order_num = date('YmdHis') . mt_rand(1000, 9999);
            Cache::store("redis")->set($order_num, json_encode(['money' => $money, 'account_type' => $account_type, "order_number" => $order_number, 'image' => $image], JSON_UNESCAPED_UNICODE), 3600);
            return json(['code' => 1, "msg" => "请求成功", "data" => ['money' => $money, 'payee' => $payee, 'account_type' => $account_type, 'order_num' => $order_num]]);
        }
        return json(['code' => 0, "msg" => "识别失败"]);
    }


    private function callback($data, $msg){
        $url = $data["callback_url"];
        $params = [
            "group_wxid" => $data["group_id"],
            "sender_name" => $data['callback_data']["sender_name"],
            "message" => ['msg' => $msg],
            "msg_wxid" => $data['callback_data']["msg_uuid"],
        ];
        $queue = new QueueRobot();
        $queue->addQueue('回调请求', 'app\robotapi\job\RobotBaseJob', 'robotBaseJob',[
            "job_class" => '\app\robotapi\job\sendMsg\Send',
            "url" => $url,
            "params" => $params,
        ]);
    }

}