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
            $id = input("id");
            $data['bind_store_id'] = input("store_id");
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
        return $this->view->fetch();
    }

}