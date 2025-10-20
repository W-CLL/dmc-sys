<?php

namespace app\robotapi\service;

use app\robotapi\model\QueueRobot;
use app\robotapi\model\Store;
use app\robotapi\model\WechatGroup;
use think\Controller;

class TencentRefundAll extends Controller
{
    public function refundAll($data)
    {
        foreach ($data['account_id_list'] as $account_id){
            $array['account_id'] = $account_id;
            $array['job_class'] = '\app\robotapi\job\tencent\TencentRefundAll';
            $array['callback_data'] = [
                'url' => $data['callback_url'],
                'group_id' => $data['group_id'],
                'msg_uuid' => $data['callback_data']['msg_uuid'],
                'sender_name' => $data['callback_data']['sender_name'],
                'time' => $data['callback_data']['time'],
            ];
            $queue = new QueueRobot();
            $queue->addQueue('腾讯广告【全额转出】', 'app\robotapi\job\RobotBaseJob', 'robotBaseJob', $array);
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
                if (!is_array($data)) {
                    return [false, '数据与预期不一致'];
                }
                $validate = [
                    ['group_id', 'require|max:50', 'group_id 的格式不正确'],
                    ['account_id_list', 'require|array', 'account_id_list 是必需的且必须是数组'],
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


    private function checkTransferParam($data)
    {
        if (count($data['account_id_list']) > 10){
            return '一次最多只能转账10个账户';
        }
        $wechat_group_model = new WechatGroup();
        $account_info = $wechat_group_model->getTencentAccountByStoreId($data['group_id'], $data['account_id_list']);
        $no_access = [];
        $can_option = [];
        if (!empty($account_info) && !empty($account_info['tencent_account'])){
            foreach ($account_info['tencent_account'] as $account){
                $can_option[] = $account['account_id'];
            }
            $no_access = array_diff($data['account_id_list'], $can_option);
        }elseif (empty($account_info['tencent_account'])){
            return '未查询到账户信息，请确认是否绑定';
        }
        if (!empty($no_access)){
            return '无权操作这些账户：' . implode(',', $no_access);
        }
        return true;
    }

}