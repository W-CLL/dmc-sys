<?php

namespace app\robotapi\service;

use think\Controller;
use think\Cache;

use app\robotapi\model\WechatGroup;


class Reserve extends Controller
{

    public function getUrl($data)
    {
        $group_id = $data['group_id'];
        $redis = Cache::store('redis')->handler();

        // 先检查是否已有 group_id 对应的 token
        $oldToken = $redis->get("group_to_token:" . $group_id);

        if ($oldToken) {
            // 如果存在旧 token，删除旧的 token 映射
            $redis->del("token_to_group:" . $oldToken);
            $redis->del("token_to_data:" . $oldToken);
        }

        // 生成新 token
        $newToken = generate_random_string();

        // 设置新的映射
        $redis->set("token_to_group:" . $newToken, $group_id, 300);
        $redis->set("group_to_token:" . $group_id, $newToken, 300);
        $redis->set("token_to_data:" . $newToken, json_encode($data, JSON_UNESCAPED_UNICODE), 300);

        // 返回 URL
        $domain = request()->domain();
        $url = "$domain/index.php/recharge/$newToken";
        return ["url" => $url, "tip" => "链接有效期5分钟，请在有效期内进行操作。【以最新链接为准】"];
    }

    /**
     * @param $data mixed 参数
     * @param $type int 验证类型（1：get，2：post， 3：put， 4：delete）
     * @return bool|string[]
     */
    public function validateParam($data, $type = 0)
    {
        switch ($type) {
            case 1: // get
                if (!is_array($data)) {
                    return [false, '数据与预期不一致'];
                }
                $validate = [
                    ['group_id', 'require|max:50', 'group_id 的格式不正确'],
                    ['callback_url', 'require', 'callback_url是必需的'],
                    ['callback_data', 'require|array' , 'callback_data 必须为必需的数组']
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
        $store_id = $wechat_group->getStoreId($group_id);
        if (!$store_id) {
            return "尚未绑定商户，请先联系客服绑定商户";
        }
        return true;
    }

}