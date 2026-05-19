<?php

namespace app\robotapi\service;

use app\robotapi\model\QueueRobot;
use app\robotapi\model\Store;
use app\robotapi\model\WechatGroup;
use think\Controller;
use think\Exception;
use txgg\Fund;

class TransferVirtualFund extends Controller
{
    public function transfer($data){
        $array = $this->buildingTransferData($data);
        $array['job_class'] = '\app\robotapi\job\tencent\TransferVirtualFund';
        $array['callback_data'] = [
            'url' => $data['callback_url'],
            'group_id' => $data['group_id'],
            'msg_uuid' => $data['callback_data']['msg_uuid'],
            'sender_name' => $data['callback_data']['sender_name'],
            'time' => $data['callback_data']['time'],
        ];
        $array['account_id'] = $data['account_id'];
        $array['to_account_id'] = $data['to_account_id'];
        $array['fund_type'] = $data['type'] == "现金" ? 'FUND_TYPE_AD_RECHARGE' : ($data['type'] == "测试虚拟金" ? 'FUND_TYPE_TEST_VIRTUAL' : 'FUND_TYPE_COMPENSATE_VIRTUAL');
        if ($data['amount'] != "全额"){
            $array['amount'] = $data['amount'];
        }
        $queue = new QueueRobot();
        $queue->addQueue('腾讯广告【同级转账】', 'app\robotapi\job\RobotBaseJob', 'robotBaseJob', $array);
    }


    public function buildingTransferData($data){
        $wechat_group_model = new WechatGroup();
        $account_info = $wechat_group_model->getTencentAccountByStoreId($data['group_id'], [$data['to_account_id']]);
        $transfer_records_data = [
            "store_id" => $account_info['tencent_account'][0]['store_id'],
            "tencent_account_id" => $account_info['tencent_account'][0]['id'],
            "account_type" => $account_info['tencent_account'][0]['account_type'],
            "account_id" => $account_info['tencent_account'][0]['account_id'],
            "transfer_direction" => 1,
            "money" => 0,
            "remark" => input("remark", ""),
            "create_time" => time(),
            'from' => 2
        ];
        return ['data' => $transfer_records_data];
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
                    ['wallet_id_list', 'require|array', 'wallet_id_list 是必需的且必须是数组'],
                    ['group_id', 'require|max:50', 'group_id 的格式不正确'],
                ];
                $result = $this->validate($data, $validate);
                if ($result !== true) {
                    return [false, $result];
                }
                $wechat_group_model = new WechatGroup();
                $account_info = $wechat_group_model->getTencentWalletByStoreId($data['group_id'], $data['wallet_id_list']);
                $no_access = [];
                if (!empty($account_info) && !empty($account_info['tencent_share_wallet'])){
                    foreach ($account_info['tencent_share_wallet'] as $account){
                        if (!in_array($account['sub_wallet_id'],$data['wallet_id_list'])){
                            $no_access[] = $account['sub_wallet_id'];
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
                    ['account_id', 'require', 'account_id 是必需的'],
                    ['to_account_id', 'require', 'to_account_id 是必需的'],
                    ['type','require','type是必需的'],
                    ['amount','require','amount是必需的'],
                    ['callback_url', 'require', 'callback_url是必需的'],
                    ['callback_data', 'require|array' , 'callback_data 是必需的且必须是数组']
                    // 此处得传多一个amount,不传则默认全转
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
        // 验证type只能为"现金"或"虚拟金"
        if (!in_array($data['type'], ['现金', '补偿虚拟金', '测试虚拟金'])) {
            return 'type只能是"现金"，"补偿虚拟金"，"测试虚拟金"';
        }

        // 验证amount：不为"全额"时只能是数字
        if ($data['amount'] != "全额" && !is_numeric($data['amount'])) {
            return 'amount不为全额时，仅可输入数字';
        }

        if ($data['account_id'] == $data['to_account_id']){
            return '发起方和接收方不能相同';
        }
        $wechat_group_model = new WechatGroup();
        $wallet_info = $wechat_group_model->getTencentAccountByStoreId($data['group_id'], [$data['account_id'], $data['to_account_id']]);
        $found_account_ids = [];
        $agency = [];
        foreach ($wallet_info['tencent_account'] as $wallet) {
            $found_account_ids[] = $wallet['account_id'];
            $agency[$wallet['agency']] = 1;
        }

//        if (count($agency) != 1 && $data['type'] != '现金'){
//            return '跨代理转账类型仅支持现金';
//        }
        if (count($agency) != 1){
            return '所属代理商不同，不允许转账';
        }

        // 检查发起方账户是否存在
        if (!in_array($data['account_id'], $found_account_ids)) {
            return '无权操作发起方id: ' . $data['account_id'];
        }

        // 检查接收方账户是否存在
        if (!in_array($data['to_account_id'], $found_account_ids)) {
            return '无权操作接收方id: ' . $data['to_account_id'];
        }

        if($data['amount'] != "全额"){
            $amount = $data['amount'] * 100;
        }

        $fund_type = $data['type'] == "现金" ? 'FUND_TYPE_AD_RECHARGE' : ($data['type'] == "测试虚拟金" ? 'FUND_TYPE_TEST_VIRTUAL' : 'FUND_TYPE_COMPENSATE_VIRTUAL');

        $check = Fund::accountToAccountTransfer([
            'account_id' => (int)$data['account_id'],
            'to_account_id' => (int)$data['to_account_id'],
            'fund_type' => $fund_type,
            'amount' => 0,
            'pre_fetch_amount' => 1,
        ])['data'];
        if ($check['code'] != 0){
            return '查询余额返回异常';
        }
        if(isset($amount)){
            if ($check['data']['recommend_amount'] < $amount){
                return '发起方可操作余额不足';
            }
        }else{
            if ($check['data']['recommend_amount'] <= 0){
                return '发起方可操作余额不足';
            }
        }

        return true;
    }
}