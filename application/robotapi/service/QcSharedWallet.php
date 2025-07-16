<?php

namespace app\robotapi\service;

use app\robotapi\model\QueueRobot;
use app\robotapi\model\StoreRefund;
use app\robotapi\model\WechatGroup;
use jlqc\FundManagement;
use think\Cache;
use think\Controller;
use think\Env;

class QcSharedWallet extends Controller
{
    public function getWalletBalance($data)
    {
        $sub_wallet_id_list = $data['sub_wallet_id_list'];
        $group_id = $data['group_id'];
        $wechat_group_model = new WechatGroup();
        $info = $wechat_group_model->getWalletByStoreId($group_id, $sub_wallet_id_list);
        $sub_wallet_ids = is_array($info['wallet'] ?? null)
            ? array_map('intval', array_column($info['wallet'], 'sub_wallet_id'))
            : [];
        if (empty($sub_wallet_ids)){
            return false;
        }
        $balance_result = FundManagement::get_wallet_balance([
            "account_id" => Env::get('dmc_ad_config.advertiser_id'),
            "account_type" => "AGENT",
            "wallet_id_list" => json_encode($sub_wallet_ids),
            "wallet_balance_filters" => json_encode([
                "account_platform_type" => "ONLY_AD_SHARED",
                "capital_type" => "PREPAY",
                "delivery_type" => "GENERAL",
            ])
        ]);
        if ($balance_result['code'] != 0){
            return false;
        }
        $return_data = [];
        foreach ($balance_result['data']['shared_wallet_balance_info'] as $item){
            $return_data['shared_wallet_details_list'][] = [
                'sub_wallet_id' => $item['wallet_id'],
                'total_balance' => $item['basic_balance_info']['total_balance']
            ];
        }
        $return_data['group_id'] = $group_id;
        return $return_data;
    }


    public function walletTransfer($data)
    {
        $array = $this->calculateAndBuilding($data);
        $array['job_class'] = '\app\robotapi\job\transfer\QcSharedWallet';
        $array['callback_data'] = [
            'url' => $data['callback_url'],
            'group_id' => $data['group_id'],
            'msg_uuid' => $data['callback_data']['msg_uuid'],
            'sender_name' => $data['callback_data']['sender_name'],
        ];
        $queue = new QueueRobot();
        $queue->addQueue('共享钱包【转账】', 'app\robotapi\job\RobotBaseJob', 'robotBaseJob', $array);
    }


    private function calculateAndBuilding($data){
        $wechat_group_model = new WechatGroup();
        $wallet_info = $wechat_group_model->getWalletByStoreId($data['group_id'], [$data['sub_wallet_id']]);
        list($discount_percentage, $balance, $credit_limit, $rebate) = $this->getThisSubWalletDiscountAndFunds($data, $wallet_info);
        $insert_data = [
            'store_id' => $wallet_info['wallet'][0]['bind_store_id'],
            'sub_wallet_id' => $wallet_info['wallet'][0]['sub_wallet_id'],
            'main_wallet_id' => $wallet_info['wallet'][0]['main_wallet_id'],
            'transfer_direction' => $data['transfer_type'],
            'money' => $data['amount'],
            'deduction_credit_limit' => 0,
            'deduction_balance' => 0,
            "remark" => $data['remark'] ?? '',
            'status' => 0,
            'account_type' => $wallet_info['wallet'][0]['sub_wallet_type'],
            'discount_percentage' => $discount_percentage,
            'create_time' => time(),
            'from' => 2
        ];
        switch ($data['transfer_type']){
            case 1:
                $insert_data['rebate'] =  $rebate;
                $insert_data['actual_money'] = number_format($data['amount'] - $rebate, 2, '.', '');
                if($insert_data['actual_money'] > $balance){
                    $insert_data['deduction_balance'] = $balance;
                    $insert_data['deduction_credit_limit'] = $insert_data['actual_money'] - $balance;
                    // 计算商户使用钱包、额度的返点记录
                    $wallet_money = $balance;
                    $credit_limit = $insert_data['money'] - $balance;
                }else{
                    $insert_data['deduction_balance'] = $insert_data['actual_money'];
                    // 计算商户使用钱包、额度的返点记录
                    $wallet_money = $insert_data['money'];
                    $credit_limit = 0;
                }
                $money = [
                    'wallet' => $wallet_money,
                    'credit' => $credit_limit,
                ];
                return [
                    'money' => $money,
                    'insert_data' => $insert_data,
                ];
            case 2:
                if(!empty(floatval($discount_percentage))) {
                    $store_refund_model = new StoreRefund();
                    list($real_rebate,$actual_per) = $store_refund_model->getRealRefundRebate($insert_data,2,false);
                    if (empty($real_rebate)) {
                        $real_rebate = round($insert_data["money"] - ($insert_data["money"] * 100) / ($insert_data['discount_percentage'] * 100), 2);
                    }
                    $insert_data['discount_percentage'] = $actual_per; // 获取实际退款比例
                }else{
                    $real_rebate = 0;
                }
                $insert_data["rebate"] = $real_rebate;
                $insert_data['actual_money'] = $insert_data["money"];
                return [
                    'money' => [],
                    'insert_data' => $insert_data,
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
                    ['sub_wallet_id_list', 'require|array', 'sub_wallet_id_list 必须是必需的数组'],
                    ['group_id', 'require|max:50', 'group_id 的格式不正确'],
                ];
                $result = $this->validate($data, $validate);
                if ($result !== true) {
                    return [false, $result];
                }
                $wechat_group_model = new WechatGroup();
                $wallet_info = $wechat_group_model->getWalletByStoreId($data['group_id'], $data['sub_wallet_id_list']);
                if (empty($wallet_info) || empty($wallet_info['wallet'])){
                    return [false, '无权操作这些子钱包id'];
                }
                return true;
            case 2: // post
                if (!is_array($data)) {
                    return [false, '数据与预期不一致'];
                }
                $validate = [
                    ['group_id', 'require|max:50', 'group_id 的格式不正确'],
                    ['sub_wallet_id', 'require', 'sub_wallet_id 的格式不正确'],
                    ['amount', 'require|min:0.01', '该金额是必需的，并且必须以最低 0.01 输入。'],
                    ['transfer_type', 'require|in:1,2', 'transfer_type 的格式只能传输 1 或 2'],
                    ['callback_url', 'require', 'callback_url是必需的'],
                    ['callback_data', 'require|array' , 'callback_data 必须为必需的数组']
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


    private function checkTransferParam($data)
    {
        $wechat_group_model = new WechatGroup();
        $wallet_info = $wechat_group_model->getWalletByStoreId($data['group_id'], [$data['sub_wallet_id']]);
        if (empty($wallet_info) || empty($wallet_info['wallet'])){
            return '无权操作此子钱包id';
        }
        switch ($data['transfer_type']){
            case 1:
                $in_result = FundManagement::get_max_transfer(
                    Cache::get("qc_access_token"),
                    Env::get('dmc_ad_config.advertiser_id'),
                    'AGENT',
                    generate_random_string(16),
                    $wallet_info['wallet'][0]['main_wallet_id'],
                    json_encode([(int)$wallet_info['wallet'][0]['sub_wallet_id']]),
                    'TRANSFER_IN');
                if ($in_result['code'] != 0){
                    return '千川接口异常';
                }
                $min_transfer = $in_result['data']['can_transfer_detail_list'][0]['payee_transfer_amount_detail_list'][0]['non_brand_min_transfer_balance'] / 100;
                if ($data['amount'] < $min_transfer){
                    return '本次转账金额不得低于最低转账金额： ' . $min_transfer;
                }
                list($discount_percentage, $balance, $credit_limit, $rebate) = $this->getThisSubWalletDiscountAndFunds($data, $wallet_info);
                if(is_null($balance) && is_null($credit_limit) && is_null($discount_percentage) && is_null($rebate)){
                    return '群聊未绑定商户，请先联系客服绑定商户';
                }
                if (($data['amount'] - $rebate) > ($balance + $credit_limit)){
                    return '余额不足';
                }
                return true;
            case 2:
                $StoreRefund = new StoreRefund();
                $last_transfer_info = $StoreRefund->getSingleItem([
                    'account_type' => $wallet_info['wallet'][0]['sub_wallet_type'],
                    'store_id' => $wallet_info['wallet'][0]['bind_store_id'],
                    'sub_wallet_id' => $wallet_info['wallet'][0]['sub_wallet_id']
                ],2);
                if(!empty($last_transfer_info)){
                    $maxTTO = $last_transfer_info['wallet'] + $last_transfer_info['credit'];
                }
                if(isset($maxTTO) && $data['amount'] > $maxTTO){
                    return '本次转出的最大金额为：' . $maxTTO;
                }
                $out_result = FundManagement::get_max_transfer(
                    Cache::get("qc_access_token"),
                    Env::get('dmc_ad_config.advertiser_id'),
                    'AGENT',
                    generate_random_string(16),
                    $wallet_info['wallet'][0]['main_wallet_id'],
                    json_encode([(int)$wallet_info['wallet'][0]['sub_wallet_id']]),
                    'TRANSFER_OUT');
                if ($out_result['code'] != 0){
                    return '千川接口异常';
                }
                $max_transfer_out = $out_result['data']['can_transfer_detail_list'][0]['non_brand_max_transfer_balance'] / 100;
                if ($data['amount'] > $max_transfer_out){
                    return '本次转出的最大金额为：' . $max_transfer_out;
                }
                return true;
            default:
                return 'transfer_type不正确';
        }
    }



    /**
     * 获取子钱包的折扣和余额
     * @param $data
     * @param $wallet_info
     * @return array|null[]
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    private function getThisSubWalletDiscountAndFunds($data, $wallet_info){
        $wechat_group_model = new WechatGroup();
        $balance_info = $wechat_group_model->getDMCBalance($data['group_id']);
        if (empty($balance_info) || empty($balance_info['store'])){
            return [null, null, null, null];
        }
        if ($wallet_info['wallet'][0]['sub_wallet_type'] == '1'){
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
        if(!empty(floatval($wallet_info['wallet'][0]['discount_percentage']))){
            $discount_percentage = $wallet_info['wallet'][0]['discount_percentage'];
        }
        if(!empty(floatval($discount_percentage))){
            $rebate = round($data['amount'] - ($data['amount'] * 100) / ($discount_percentage * 100), 2);
        }else{
            $rebate = 0;
        }
        return [$discount_percentage, $balance, $credit_limit, $rebate];
    }


    private function checkGroup($group_id)
    {
        $wechat_group = new WechatGroup();
        $store_id = $wechat_group->getStoreId($group_id);
        if (!$store_id) {
            return "尚未绑定商户，请先联系客服绑定商户";
        }
        return true;
    }

}