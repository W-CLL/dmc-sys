<?php

namespace app\robotapi\service;

use app\robotapi\model\QueueRobot;
use app\robotapi\model\WechatGroup;
use app\robotapi\model\Store;
use app\robotapi\model\TencentRefund;
use think\Controller;
use txgg\Fund;

class TencentAccount extends Controller
{

    public function getBalance($data)
    {

    }


    public function transfer($data){
        $array_list = $this->calculateAndBuilding($data);
        foreach ($array_list as $array){
            $array['job_class'] = '\app\robotapi\job\tencent\TencentTransfer';
            $array['callback_data'] = [
                'url' => $data['callback_url'],
                'group_id' => $data['group_id'],
                'msg_uuid' => $data['callback_data']['msg_uuid'],
                'sender_name' => $data['callback_data']['sender_name'],
                'time' => $data['callback_data']['time'],
            ];
            $queue = new QueueRobot();
            $queue->addQueue('腾讯广告【转账】', 'app\robotapi\job\RobotBaseJob', 'robotBaseJob', $array);
        }
    }

    private function calculateAndBuilding($data){
        $wechat_group_model = new WechatGroup();
        $account_info = $wechat_group_model->getTencentAccountByStoreId($data['group_id'], $data['account_id_list']);
        $balance_info = $wechat_group_model->getDMCTencentBalance($data['group_id']);
        $wallet_array['public_balance'] = $balance_info['tencentStore']['public_money_tencent'];
        $wallet_array['public_credit_limit'] =  $balance_info['tencentStore']['public_credit_limit_tencent'];
        $wallet_array['private_balance'] = $balance_info['tencentStore']['private_money_tencent'];
        $wallet_array['private_credit_limit'] = $balance_info['tencentStore']['private_credit_limit_tencent'];
        foreach ($account_info['tencent_account'] as $account){
            if ($account['account_type'] == 1){
                //对公
                $discount_percentage = !empty(floatval($account['discount_percentage'])) ? $account['discount_percentage'] : $balance_info['tencentStore']['public_discount_percentage_tencent'];
                if(!empty(floatval($discount_percentage))){
                    $rebate = round($data['amount'] - ($data['amount'] * 100) / ($discount_percentage * 100), 2);
                }else{
                    $rebate = 0;
                }
                $prefix = 'public_';
            }else{
                //对私
                $discount_percentage = !empty(floatval($account['discount_percentage'])) ? $account['discount_percentage'] : $balance_info['tencentStore']['private_discount_percentage_tencent'];
                if(!empty(floatval($discount_percentage))){
                    $rebate = round($data['amount'] - ($data['amount'] * 100) / ($discount_percentage * 100), 2);
                }else{
                    $rebate = 0;
                }
                $prefix = 'private_';
            }
            $transfer_records_data = [
                "store_id"              => $account['store_id'],
                "tencent_account_id"    => $account['id'],
                "account_id"            => $account['account_id'],
                "account_type"          => $account['account_type'],
                "transfer_direction"    => $data['transfer_type'],
                "money"                 => $data['amount'],
                "discount_percentage"   => $discount_percentage,
                "remark"                => $data['remark'] ?? '',
                "create_time"           => time(),
                "from"                  => 2         // 机器人接口充值
            ];
            switch ($data['transfer_type']){
                case 1:
                    $transfer_records_data['rebate'] = $rebate;
                    $transfer_records_data['actual_money'] = number_format($data['amount'] - $rebate, 2, '.', '');
                    //实际交易金额 大于 钱包余额
                    if ($transfer_records_data['actual_money'] > $wallet_array[$prefix . 'balance']) {
                        //扣除钱包余额
                        $transfer_records_data["deduction_balance"] = $wallet_array[$prefix . 'balance'];
                        //扣除授信额度 总授信额度 - （总授信额度+钱包 - 实际扣除金额）
                        $transfer_records_data["deduction_credit_limit"] = $wallet_array[$prefix . 'credit_limit'] - ($wallet_array[$prefix . 'credit_limit'] + $wallet_array[$prefix . 'balance'] - $transfer_records_data['actual_money']);
                        $wallet_money = $wallet_array[$prefix . 'balance'];
                        $credit_limit = $transfer_records_data['money'] - $wallet_array[$prefix . 'balance'];
                        // 扣除剩余额度
                        $wallet_array[$prefix . 'balance'] = $wallet_array[$prefix . 'balance'] - $wallet_money;
                        $wallet_array[$prefix . 'credit_limit'] = $wallet_array[$prefix . 'credit_limit'] - $credit_limit;
                    } else {
                        $transfer_records_data["deduction_balance"] = $transfer_records_data['actual_money'];
                        $transfer_records_data["deduction_credit_limit"] = 0;
                        $wallet_money = $transfer_records_data['money'];
                        $credit_limit = 0;
                        // 扣除剩余额度
                        $wallet_array[$prefix . 'balance'] = $wallet_array[$prefix . 'balance'] - $wallet_money;
                    }
                    $money = [
                        'wallet' => $wallet_money,
                        'credit' => $credit_limit,
                    ];
                    $array[] = [
                        'money' => $money,
                        'transfer_records_data' => $transfer_records_data,
                    ];
                case 2:
                    $refund_model = new TencentRefund();
                    list($real_rebate,$actual_per) = $refund_model->getRealRefundRebate($transfer_records_data,1,false);
                    if (empty($real_rebate)) {
                        if(!empty(floatval($transfer_records_data['discount_percentage']))){
                            $real_rebate = round($transfer_records_data["money"] - ($transfer_records_data["money"] * 100) / ($transfer_records_data['discount_percentage'] * 100), 2);
                        }else{
                            $real_rebate = 0;
                        }
                    }
                    $transfer_records_data["rebate"] = $real_rebate;
                    $transfer_records_data['actual_money'] = $transfer_records_data["money"];
                    $transfer_records_data['discount_percentage'] = $actual_per;
                    $array[] = [
                        'money' => [],
                        'transfer_records_data' => $transfer_records_data,
                    ];
            }
        }
        return $array;
    }



    private function checkGroup($group_id)
    {
        $wechat_group = new WechatGroup();
        $store = new Store();
        $store_id = $wechat_group->getStoreId($group_id);
        if (!$store_id) {
            return "尚未绑定商户，请先联系客服绑定商户";
        }
        $status = $store->getStatus($store_id);
        if ($status != 1) {
            return "商户已禁用，请先联系客服解禁商户";
        }
        $power_list = $wechat_group->getPowerList($group_id);
        if (!in_array(2, $power_list)){
            return "尚未开通腾讯助手权限，请先联系客服开通权限";
        }
        return true;
    }

    private function checkTransferParam($data)
    {
        if (count($data['account_id_list']) > 10){
            return '一次最多只能转账10个账户';
        }
        $wechat_group_model = new WechatGroup();
        $account_info = $wechat_group_model->getTencentAccountByStoreId($data['group_id'], $data['account_id_list']);
        $no_access = [];
        if (!empty($account_info) && !empty($account_info['tencent_account'])){
            foreach ($account_info['tencent_account'] as $account){
                if (!in_array($account['account_id'],$data['account_id_list'])){
                    $no_access[] = $account['account_id'];
                }
            }
        }
        if (!empty($no_access)){
            return '无权操作这些账户：' . implode(',', $no_access);
        }
        switch ($data['transfer_type']){
            case 1:
                return $this->checkAccountDiscountAndFunds($data, $account_info);
            case 2:
                $StoreRefund = new TencentRefund();
                $str = '';
                foreach ($account_info['tencent_account'] as $account){
                    do{
                        $res = Fund::getFundAccountInfo([
                            'account_id' => (int)$account['account_id'],
                        ])['data'];
                    }while ($res['code'] != 0);
                    $fund_info = [];
                    foreach ($res['data']['list'] as $item){
                        $fund_info[$item['fund_type']] = ($item['balance'] - (isset($item['bill_deposit_amount']) ? $item['bill_deposit_amount'] : 0)) / 100;
                    }
                    if ($data['amount'] > $fund_info['FUND_TYPE_CASH'] + $fund_info['FUND_TYPE_GIFT']) {
                        return '账户'.$account['account_id'].'，余额不足以转出'.$data['amount'];
                    }
                    $last_transfer_info = $StoreRefund->getSingleItem([
                        'account_type' => $account['account_type'],
                        'store_id' => $account['store_id'],
                        'account_id' => $account['account_id']
                    ],1);
                    if(!empty($last_transfer_info)){
                        $maxTTO = $last_transfer_info['wallet'] + $last_transfer_info['credit'];
                    }
                    if(isset($maxTTO) && $data['amount'] > $maxTTO){
                        $str .= '账户：' . $account['account_id'] . '本次转出的最大金额为: ' . $maxTTO . "\n";
                        unset($maxTTO);
                    }
                }
                if (!empty($str)){
                    return $str;
                }
                return true;
            default:
                return '转账类型错误';
        }
    }


    private function checkAccountDiscountAndFunds($data, $account_info)
    {
        if($data['amount'] < 50){
            return '转账金额不能低于50元';
        }
        if ($data['amount'] > 20000000){
            return '转账金额不能高于20,000,000元';
        }
        $wechat_group_model = new WechatGroup();
        $balance_info = $wechat_group_model->getDMCTencentBalance($data['group_id']);
        if (empty($balance_info) || empty($balance_info['tencent_store'])){
            return "无法获取商户信息";
        }
        $public_all = $balance_info['tencentStore']['public_money_tencent'] + $balance_info['tencentStore']['public_credit_limit_tencent'];
        $private_all = $balance_info['tencentStore']['private_money_tencent'] + $balance_info['tencentStore']['private_credit_limit_tencent'];
        foreach ($account_info['tencent_account'] as $account){
            if ($account['account_type'] == '1'){
                //对公
                $discount_percentage = !empty(floatval($account['discount_percentage'])) ? $account['discount_percentage'] :$balance_info['tencentStore']['public_discount_percentage_tencent'];
                if(!empty(floatval($discount_percentage))){
                    $rebate = round($data['amount'] - ($data['amount'] * 100) / ($discount_percentage * 100), 2);
                }else{
                    $rebate = 0;
                }
                if (($data['amount'] - $rebate) > ($public_all)){
                    return '公帐总额不足以充值，目前公帐总额度（余额+授信）为：' . $public_all;
                }
                $public_all -= ($data['amount'] - $rebate);
            }else{
                //对私
                $discount_percentage = !empty(floatval($account['discount_percentage'])) ? $account['discount_percentage'] :$balance_info['tencentStore']['private_discount_percentage_tencent'];
                if(!empty(floatval($discount_percentage))){
                    $rebate = round($data['amount'] - ($data['amount'] * 100) / ($discount_percentage * 100), 2);
                }else{
                    $rebate = 0;
                }
                if (($data['amount'] - $rebate) > ($private_all)){
                    return '私帐总额不足以充值，目前私帐总额度（余额+授信）为：' . $private_all;
                }
                $private_all -= ($data['amount'] - $rebate);
            }
        }
        return true;
    }

    /**
     * @param $data mixed 参数
     * @param $type int 验证类型（1：get，2：post， 3：put， 4：delete）
     * @return bool|string[]
     */
    public function validateParam($data, $type = 0)
    {
        $check = $this->checkGroup($data['group_id']);
        if($check !== true){
            return [false, $check];
        }
        switch ($type) {
            case 1: // get
                return [false, '暂未开放'];
                if (!is_array($data)) {
                    return [false, '数据与预期不一致'];
                }
                $validate = [
                    ['account_id_list', 'require|array', 'account_id_list 是必需的且必须是数组'],
                    ['group_id', 'require|max:50', 'group_id 的格式不正确'],
                ];
                $result = $this->validate($data, $validate);
                if ($result !== true) {
                    return [false, $result];
                }
                $wechat_group_model = new WechatGroup();
                $account_info = $wechat_group_model->getTencentAccountByStoreId($data['group_id'], $data['account_id_list']);
                $no_access = [];
                if (!empty($account_info) && !empty($account_info['tencent_account'])){
                    foreach ($account_info['tencent_account'] as $account){
                        if (!in_array($account['account_id'],$data['account_id_list'])){
                            $no_access[] = $account['account_id'];
                        }
                    }
                }
                if (!empty($no_access)){
                    return '无权操作这些账户：' . implode(',', $no_access);
                }
                return true;
            case 2: // post
                if (!is_array($data)) {
                    return [false, '数据与预期不一致'];
                }
                $validate = [
                    ['group_id', 'require|max:50', 'group_id 的格式不正确'],
                    ['account_id_list', 'require|array', 'account_id_list 是必需的且必须是数组'],
                    ['amount', 'require', '该金额是必需的'],
                    ['transfer_type', 'require|in:1,2', 'transfer_type格式不正确'],
                    ['callback_url', 'require', 'callback_url是必需的'],
                    ['callback_data', 'require|array' , 'callback_data 是必需的且必须是数组']
                ];
                $result = $this->validate($data, $validate);
                if ($result !== true) {
                    return [false, $result];
                }
                $res = $this->checkTransferParam($data);
                if($res !== true){
                    return [false, $res];
                }
                return true;
            case 3: // put
                return [false, '不允许使用'];
            case 4: // delete
                return [false, '不允许使用'];
            default:
                return [false, '不允许使用'];
        }
    }

}