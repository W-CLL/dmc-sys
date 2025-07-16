<?php

namespace app\robotapi\service;

use app\robotapi\model\WechatGroup;
use app\robotapi\model\StoreRefund;
use app\robotapi\model\QueueRobot;
use jlqc\FundManagement;
use think\Cache;
use think\Controller;

class QcPeerTransfer extends Controller
{

    public function peerTransfer($data)
    {
        $array = $this->buildingTransferData($data);
        $array['job_class'] = '\app\robotapi\job\transfer\QcPeerTransfer';
        $array['original_data'] = $data;
        $array['callback_data'] = [
            'url' => $data['callback_url'],
            'group_id' => $data['group_id'],
            'msg_uuid' => $data['callback_data']['msg_uuid'],
            'sender_name' => $data['callback_data']['sender_name'],
        ];
        $queue = new QueueRobot();
        $queue->addQueue('千川账户【同级互转】', 'app\robotapi\job\RobotBaseJob', 'robotBaseJob', $array);
    }

    private function buildingTransferData($data){
        $wechat_group_model = new WechatGroup();
        $receive_company_info = $wechat_group_model->getCompanyByStoreId($data['group_id'], [$data['receive_adv_id']]);
        $transfer_records_data = [
            "store_id"                              => $receive_company_info['company'][0]['store_id'],
            "company_id"                            => $receive_company_info['company'][0]['id'],
            "account_type"                          => $receive_company_info['company'][0]['account_type'],
            "advertiser_id"                         => $receive_company_info['company'][0]['advertiser_id'],
            "transfer_direction"                    => 1,
            "money"                                 => $data['amount'],
            "remark"                                => input("remark", "同级互转"),
            "create_time"                           => time(),
            "from"                                  => 2     // 2： 机器人接口调用转账
        ];
        return ["transfer_records_data" => $transfer_records_data];
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
                return [false, '不允许使用'];
            case 2: // post
                if (time() > strtotime(date('Y-m-d') . ' 23:50:00')) {
                    return [false, '23:50-00:00期间不允许发起转账'];
                }
                if (!is_array($data)) {
                    return [false, '数据与预期不一致'];
                }
                $validate = [
                    ['group_id', 'require|max:50', 'group_id 的格式不正确'],
                    ['initiate_adv_id', 'require', 'initiate_adv_id 的格式不正确'],
                    ['receive_adv_id', 'require', 'receive_adv_id 的格式不正确'],
                    ['amount', 'require|min:0.01', '该金额是必需的，并且必须以最低 0.01 输入。'],
                    ['callback_url', 'require', 'callback_url是必需的'],
                    ['callback_data', 'require|array' , 'callback_data 必须为必需的数组']
                ];
                $result = $this->validate($data, $validate);
                if ($result !== true) {
                    return [false, $result];
                }
                $res = $this->checkPeerTransferParam($data);
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

    private function checkPeerTransferParam($data)
    {
        if ($data['initiate_adv_id'] == $data['receive_adv_id']) {
            return '发起方千川id 和 接收方千川id 不能相同';
        }
        $wechat_group_model = new WechatGroup();
        $initiate_company_info = $wechat_group_model->getCompanyByStoreId($data['group_id'], [$data['initiate_adv_id']]);
        $receive_company_info = $wechat_group_model->getCompanyByStoreId($data['group_id'], [$data['receive_adv_id']]);
        if (empty($initiate_company_info) || empty($initiate_company_info['company'])){
            return '无权操作此adv_id: ' . $data['initiate_adv_id'];
        }
        if (empty($receive_company_info) || empty($receive_company_info['company'])){
            return '无权操作此adv_id: ' . $data['receive_adv_id'];
        }
        $result = $this->getQcMoney($initiate_company_info['company'][0]);
        if ($result === false){
            return '千川接口异常，请重试';
        }
        if ($data['amount'] > $result['money']) {
            return '发起方千川余额不足';
        }
        $store_refund_model = new StoreRefund();
        $info = $store_refund_model->getOneRefundInfo($data['initiate_adv_id']);
        if ($info && $info['wallet'] + $info['credit'] < $data['amount']){
            return '此次转账的最高金额不得超过：' . ($info['wallet'] + $info['credit']);
        }
        return true;
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