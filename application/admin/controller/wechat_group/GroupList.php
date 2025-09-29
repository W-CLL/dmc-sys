<?php

namespace app\admin\controller\wechat_group;

use app\common\controller\Backend;

use app\admin\model\WechatGroup;
use app\admin\model\Store;

class GroupList extends Backend
{
    public function index()
    {
        if ($this->request->isAjax()) {
            $wechat_group_model = new WechatGroup();
            $sort = input("sort","id");
            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);
            $list = $wechat_group_model
                ->with('store')
                ->order($sort, $order)
                ->limit($offset,$limit)
                ->select();
            $count = $wechat_group_model->count();
            $result = array("total" => $count, "rows" => $list);
            return json($result);
        }
        return $this->view->fetch();
    }


    public function edit($ids = null)
    {
        $wechat_group_model = new WechatGroup();
        $store_model = new Store();
        if ($this->request->isPost()) {
            $this->token();
            $id = $this->request->param("id");
            $data['bind_store_id'] = $this->request->param("store_id");
            // 处理权限多选框
            $power = [];
            if ($this->request->has('power')) {
                $power_param = $this->request->only('power')['power'];
                // 确保$power是数组类型
                if (is_array($power_param)) {
                    $power = $power_param;
                } elseif (is_string($power_param) && !empty($power_param)) {
                    // 如果是字符串，可能是单个值
                    $power = [$power_param];
                }
            }
            
            // 过滤并确保权限值是有效的
            $valid_powers = [];
            foreach ($power as $p) {
                if (in_array($p, ['1', '2'])) {
                    $valid_powers[] = $p;
                }
            }
            
            if (!empty($valid_powers)) {
                $data['power'] = implode(',', $valid_powers);
            } else {
                $data['power'] = '';
            }
            
            if ($wechat_group_model->where("id",$id)->update($data)){
                $this->success();
            }
            $this->error();
        }
        $row = $wechat_group_model
            ->where("id",$ids)
            ->find();
        $this->modelValidate = true;
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $this->view->assign("row",$row);
        $this->view->assign('storeList', build_select('store_id', [0=>"不绑定"]+$store_model->column('id,username'), $row['bind_store_id'], ['class' => 'form-control selectpicker','data-live-search'=>'true']));
        
        // 处理权限选项
        $power_options = [
            '1' => '千川助手权限',
            '2' => '腾讯助手权限'
        ];
        $this->view->assign('power_options', $power_options);
        
        return $this->view->fetch();
    }

}