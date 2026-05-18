<?php

namespace app\robotapi\service;

use app\robotapi\model\WechatGroup;
use app\robotapi\model\StoreRefund;
use app\robotapi\model\QueueRobot;
use app\robotapi\model\Store;
use jlqc\FundManagement;
use think\Cache;
use think\Controller;
use think\Env;

class QcAccountFunds extends Controller
{
    public function getBalance($data){
        $account_id_list = $data['account_id_list'];
        $group_id = $data['group_id'];
        $wechat_group_model = new WechatGroup();
        $info = $wechat_group_model->getCompanyByStoreId($group_id, $account_id_list);
        $adv_ids = is_array($info['company'] ?? null)
            ? array_map('intval', array_column($info['company'], 'advertiser_id'))
            : [];
        if (empty($adv_ids)){
            return false;
        }
        $access_token = Cache::get("qc_access_token");
        $biz_request_no = generate_random_string(16);
        $agent_id = Env::get('dmc_ad_config.advertiser_id');
        $res = FundManagement::transfer_balance($access_token, $biz_request_no, (int)$agent_id, $adv_ids);
        if ($res['code'] != 0){
            return false;
        }
        $return_data = [];
        foreach ($res['data']['accont_amount_detail_list'] as $k => $v){
            $return_data['account_amount_details_list'][$k]['account_id'] = $v['account_id'];
            foreach ($v['capital_detail_list'] as $value){
                if ($value['capital_type'] == 'PREPAY_GENERAL'){
                    $return_data['account_amount_details_list'][$k]['balance'] = $value['transfer_balance'] / 100;
                }
            }
        }
        $return_data['group_id'] = $group_id;
        return $return_data;
    }

    public function transfer($data){
        $queue = new QueueRobot();
        foreach ($data['adv_id'] as $item){
            $array = $this->calculateAndBuilding($data,$item);
            $array['job_class'] = '\app\robotapi\job\transfer\QcAccountFunds';
            $array['callback_data'] = [
                'url' => $data['callback_url'],
                'group_id' => $data['group_id'],
                'msg_uuid' => $data['callback_data']['msg_uuid'],
                'sender_name' => $data['callback_data']['sender_name'],
                'time' => $data['callback_data']['time'],
            ];
            $queue->addQueue('千川账户【转账】', 'app\robotapi\job\RobotBaseJob', 'robotBaseJob', $array);
        }
    }


    private function calculateAndBuilding($data,$adv_id){
        $wechat_group_model = new WechatGroup();
        $company_info = $wechat_group_model->getCompanyByStoreId($data['group_id'], [$adv_id]);
        list($discount_percentage, $balance, $credit_limit, $rebate) = $this->getThisAdvDiscountAndFunds($data, $company_info);
        $transfer_records_data = [
            "store_id"              => $company_info['company'][0]['store_id'],
            "company_id"            => $company_info['company'][0]['id'],
            "account_type"          => $company_info['company'][0]['account_type'],
            "advertiser_id"         => $company_info['company'][0]['advertiser_id'],
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
                if ($transfer_records_data['actual_money'] > $balance) {
                    //扣除钱包余额
                    $transfer_records_data["deduction_balance"] = $balance;
                    //扣除授信额度 总授信额度 - （总授信额度+钱包 - 实际扣除金额）
                    $transfer_records_data["deduction_credit_limit"] = $credit_limit - ($credit_limit + $balance - $transfer_records_data['actual_money']);
                    $wallet_money = $balance;
                    $credit_limit = $transfer_records_data['money'] - $balance;
                } else {
                    $transfer_records_data["deduction_balance"] = $transfer_records_data['actual_money'];
                    $transfer_records_data["deduction_credit_limit"] = 0;
                    $wallet_money = $transfer_records_data['money'];
                    $credit_limit = 0;
                }
                $money = [
                    'wallet' => $wallet_money,
                    'credit' => $credit_limit,
                ];
                return [
                    'money' => $money,
                    'transfer_records_data' => $transfer_records_data,
                    'agent_id' => $company_info['company'][0]['agent_id'],
                ];
            case 2:
                $store_refund_model = new StoreRefund();
                list($real_rebate,$actual_per) = $store_refund_model->getRealRefundRebate($transfer_records_data,1,false);
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
                return [
                    'money' => [],
                    'transfer_records_data' => $transfer_records_data,
                    'agent_id' => $company_info['company'][0]['agent_id'],
                ];
        }
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
                $company_info = $wechat_group_model->getCompanyByStoreId($data['group_id'], $data['account_id_list']);
                if (empty($company_info) || empty($company_info['company'])){
                    return [false, '无权操作这些账户'];
                }
                return true;
            case 2: // post
                if (time() > strtotime(date('Y-m-d') . ' 23:50:00')) {
                    return [false, '23:50-00:00期间不允许转账'];
                }
                if (!is_array($data)) {
                    return [false, '数据与预期不一致'];
                }
                $validate = [
                    ['group_id', 'require|max:50', 'group_id 的格式不正确'],
                    ['adv_id', 'require', 'adv_id 的格式不正确'],
                    ['amount', 'require|min:0.01', '该金额是必需的，并且必须以最低 0.01 输入。'],
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

    private function checkTransferParam($data){
        if (count($data['adv_id']) > 5){
            return '一次最多只能转账5个账户';
        }
        $wechat_group_model = new WechatGroup();
        $company_info = $wechat_group_model->getCompanyByStoreId($data['group_id'], $data['adv_id']);
        if (empty($company_info) || empty($company_info['company'])){
            return '无权操作此千川账户';
        }
        $no_agent = [];
        foreach ($company_info['company'] as $item){
            if(empty($item['agent_id'])) {
                $no_agent[] = $item['advertiser_id'];
            }
        }
        if (!empty($no_agent)){
            $no_agent_str = implode(',',$no_agent);
            $no_agent_str .= '代理未绑定';
            return $no_agent_str;
        }
        switch ($data['transfer_type']){
            case 1:
                $res = $this->checkFunds($data, $company_info);
                if ($res !== true){
                    return $res;
                }
                return true;
            case 2:
                $StoreRefund = new StoreRefund();
                foreach ($company_info['company'] as $item){
                    $result = $this->getQcMoney($item);
                    if ($result === false){
                        return '千川接口异常，请重试';
                    }
                    if ($data['amount'] > $result['money']) {
                        return '千川账户余额不足';
                    }
                    $last_transfer_info = $StoreRefund->getSingleItem([
                        'account_type' => $item['account_type'],
                        'store_id' => $item['store_id'],
                        'advertiser_id' => $item['advertiser_id']
                    ],1);
                    if(!empty($last_transfer_info)){
                        $maxTTO = $last_transfer_info['wallet'] + $last_transfer_info['credit'];
                    }
                    if(isset($maxTTO) && $data['amount'] > $maxTTO){
                        return $item['advertiser_id'].'本次转出的最大金额为: ' . $maxTTO;
                    }
                    unset($maxTTO);
                }
                return true;
            default:
                return '转账类型错误';
        }
    }

    private function getQcMoney($company_info)
    {
        $access_token = Cache::get("qc_access_token");
        $qc_money = FundManagement::account_balance_wallet($access_token, $company_info['advertiser_id']); //获取钱包详细信息
        if ($qc_money['code'] != 0) {
            return false;
        }
        $total_money = $qc_money['data']['total_balance_abs'];
        $grant_balance = $qc_money['data']['grant_balance'];
        $actual_money = $total_money - $grant_balance;
        return [
            "money" => $actual_money / 100000,
            "total_money" => $total_money / 100000,
            "grant_balance" => $grant_balance / 100000,
        ];
    }


    /**
     * 获取adv的折扣和余额
     * @param $data
     * @param $company_info
     * @return array|null[]
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    private function getThisAdvDiscountAndFunds($data, $company_info){
        $wechat_group_model = new WechatGroup();
        $balance_info = $wechat_group_model->getDMCBalance($data['group_id']);
        if (empty($balance_info) || empty($balance_info['store'])){
            return [null, null, null, null];
        }
        if ($company_info['company'][0]['account_type'] == '1'){
            //对公
            $discount_percentage = $balance_info['store']['public_discount_percentage'];
            $balance = $balance_info['store']["public_money"];
            $credit_limit = $balance_info['store']["public_credit_limit"];
        }else{
            //对私
            $discount_percentage = $balance_info['store']['private_discount_percentage'];
            $balance = $balance_info['store']["private_money"];
            $credit_limit = $balance_info['store']["private_credit_limit"];
        }
        // 是否设置特定折扣
        if(!empty(floatval($company_info['company'][0]['discount_percentage']))){
            $discount_percentage = $company_info['company'][0]['discount_percentage'];
        }
        if(!empty(floatval($discount_percentage))){
            $rebate = round($data['amount'] - ($data['amount'] * 100) / ($discount_percentage * 100), 2);
        }else{
            $rebate = 0;
        }
        return [$discount_percentage, $balance, $credit_limit, $rebate];
    }


    private function checkFunds($data, $company_info){
        $wechat_group_model = new WechatGroup();
        $balance_info = $wechat_group_model->getDMCBalance($data['group_id']);
        if (empty($balance_info) || empty($balance_info['store'])){
            return '群聊未绑定商户，请先联系客服绑定商户';
        }
        $public_balance = $balance_info['store']["public_money"];
        $public_credit_limit = $balance_info['store']["public_credit_limit"];
        $private_balance = $balance_info['store']["private_money"];
        $private_credit_limit = $balance_info['store']["private_credit_limit"];
        $total_rebate = [];
        $total_rebate['public'] = 0;
        $total_rebate['private'] = 0;
        $total_funds = [];
        $total_funds['public'] = 0;
        $total_funds['private'] = 0;
        // 是否设置特定折扣
        foreach ($company_info['company'] as $item){
            if ($item['account_type'] == '1'){
                //对公
                $discount_percentage = $balance_info['store']['public_discount_percentage'];
                $key = 'public';
            }else{
                //对私
                $discount_percentage = $balance_info['store']['private_discount_percentage'];
                $key = 'private';
            }
            if(!empty(floatval($item['discount_percentage']))){
                $discount_percentage = $item['discount_percentage'];
            }
            if(!empty(floatval($discount_percentage))){
                $rebate = round($data['amount'] - ($data['amount'] * 100) / ($discount_percentage * 100), 2);
            }else{
                $rebate = 0;
            }
            $total_rebate[$key] += $rebate;
            $total_funds[$key] += $data['amount'];
        }
        if (($total_funds['public'] - $total_rebate['public']) > ($public_balance + $public_credit_limit)){
            return '私账余额不足';
        }
        elseif(($total_funds['private'] - $total_rebate['private']) > ($private_balance + $private_credit_limit)){
            return '公账余额不足';
        }

        return true;
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
        if (!in_array(1, $power_list)){
            return "尚未开通千川助手权限，请先联系客服开通权限";
        }
        return true;
    }


}