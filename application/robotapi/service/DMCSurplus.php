<?php

namespace app\robotapi\service;

use app\robotapi\model\Store;
use app\robotapi\model\WechatGroup;
use think\Controller;

class DMCSurplus extends Controller
{
    public function getDMCBalance($data){
        $wechat_group = new WechatGroup();
        $store = new Store();
        $group_id = $data['group_id'];
        $store_id = $wechat_group->getStoreId($group_id);
        $store_info = $store->getStoreInfo($store_id);
        $balance = [
            "store_name" => $store_info['username'],
            "public_money" => $store_info['public_money'],
            "private_money" => $store_info['private_money'],
            "public_credit_limit" => $store_info['public_credit_limit'],
            "private_credit_limit" => $store_info['private_credit_limit'],
            "public_spending_credit_limit" => $store_info['public_spending_credit_limit'],
            "private_spending_credit_limit" => $store_info['private_spending_credit_limit'],
            "public_discount_percentage" => $store_info['public_discount_percentage'],
            "private_discount_percentage" => $store_info['private_discount_percentage'],
        ];
        return $balance;
    }

    public function validateParam($data, $type = 0){
        switch ($type) {
            case 1: // get
                if (!is_array($data)) {
                    return [false, '数据与预期不一致'];
                }
                $validate = [
                    ['group_id', 'require|max:50', 'group_id 的格式不正确'],
                ];
                $validate_result = $this->validate($data, $validate);
                if ($validate_result !== true) {
                    return [false, $validate_result];
                }
                $res = $this->checkGroup($data['group_id']);
                if ($res !== true){
                    return [false, $res];
                }
                return true;
            case 2: // post
                return [false, '不允许使用'];
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
        if (!in_array(1, $power_list)){
            return "尚未开通千川助手权限，请先联系客服开通权限";
        }
        return true;
    }

}