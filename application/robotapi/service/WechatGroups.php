<?php

namespace app\robotapi\service;

use app\robotapi\model\WechatGroup;
use think\Controller;
use think\Db;

class WechatGroups extends Controller
{

    public function updateGroupData($data){
        $wechat_group_model = new WechatGroup();
        Db::startTrans();
        try{
            foreach ($data as $v){
                $group_id = $v['group_id'];
                $update_data = [];
                $update_data['group_name'] = $v['group_name'];
                $wechat_group_model->updateGroup($group_id, $update_data);
            }
            Db::commit();
            return true;
        }catch (\Exception $e){
            Db::rollback();
            return false;
        }
    }


    public function addGroupData($data){
        $wechat_group_model = new WechatGroup();
        Db::startTrans();
        try{
            $group_id_list = $wechat_group_model->column('group_id');
            $filteredData = [];
            foreach ($data as $item) {
                // 确保是数组
                $item = (array)$item;
                // 检查是否已存在
                if (!in_array((string)$item['group_id'], $group_id_list)) {
                    $filteredData[] = $item;
                }
            }
            // 保存过滤后的数据
            if (!empty($filteredData)) {
                $wechat_group_model->isUpdate(false)->saveAll($filteredData);
            }
            Db::commit();
            return true;
        }catch (\Exception $e){
            Db::rollback();
            return false;
        }
    }

    public function deleteGroupData($data){
        $wechat_group_model = new WechatGroup();
        Db::startTrans();
        try{
            $wechat_group_model->deleteGroup($data['group_id_list']);
            Db::commit();
            return true;
        }catch (\Exception $e){
            Db::rollback();
            return false;
        }
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
                return [false, '不允许使用'];
            case 2: // post
            case 3: // put
                if (!is_array($data)) {
                    return [false, '数据与预期不一致'];
                }
                $validate = [
                    ['group_id', 'require|max:50', 'group_id 的格式不正确'],
                    ['group_name', 'require|max:100', 'group_name 的格式不正确'],
                ];
                foreach ($data as $item) {
                    if (!is_array($item) && !is_object($item)) {
                        return [false, '数据与预期不一致'];
                    }
                    $item = (array)$item;
                    $param = [
                        'group_id'   => $item['group_id'] ?? '',
                        'group_name' => $item['group_name'] ?? '',
                    ];
                    $validate_result = $this->validate($param, $validate);
                    if ($validate_result !== true) {
                        return [false, $validate_result];
                    }
                }
                return true;
            case 4: // delete
                if (!is_array($data)) {
                    return [false, '数据与预期不一致'];
                }
                $validate = [
                    ['group_id_list', 'require|array', 'group_id_list 必须是必需的数组'],
                ];
                $result = $this->validate($data, $validate);
                if ($result !== true) {
                    return [false, $result];
                }
                return true;
            default:
                return [false, '不允许使用'];
        }
    }

}