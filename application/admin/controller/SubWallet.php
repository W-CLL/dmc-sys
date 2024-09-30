<?php

namespace app\admin\controller;

use app\common\controller\Backend;
use jlqc\FundManagement;
use think\Cache;
use app\admin\model\QcShareWallet as WalletModel;
use app\admin\model\QcConfig as QcConfigModel;
use app\admin\model\Store as StoreModel;

class SubWallet extends Backend
{
    public function index()
    {

        if ($this->request->isAjax()) {
            $WalletModel = new WalletModel();
            $QcConfigModel = new QcConfigModel();
            $sort = input("sort","id");
            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);
            $filter = input("filter", '');


            if ($filter != '') {
                $filter = (array)json_decode($filter, true);
                $where = $this->screen_filter($filter);
            }
            if(!empty($where['bind_store_id'])){
                if($where['bind_store_id'][1] == 0){
                    $where['bind_store_id'][0] = 'NULL';
                }else{
                    $where['bind_store_id'][0] = 'NOT NULL';
                }
            }



            $token = Cache::get("qc_access_token");
            $account_id = $QcConfigModel->where("id",1)->value("advertiser_id");
            $account_type = 'AGENT';


            $list = $WalletModel
                ->with('store')
                ->where($where)
                ->field("id,sub_wallet_id,bind_store_id,sub_wallet_type,sub_wallet_type")
                ->order($sort, $order)
                ->limit($offset,$limit)
                ->select();
            $count = $WalletModel->where($where)->count();
            $list_info = array_column($list,'sub_wallet_id');
            $list_info = array_map(function($value) {
                return (int)$value;
            }, $list_info);
            $list_info = json_encode($list_info);
            if(!empty($list_info)){
                $res = FundManagement::get_wallet_info_list($token,$account_id,$list_info,$account_type);
                if($res['code'] == 0) {
                    foreach ($res['data']['wallet_info'] as $v) {
                        foreach ($list as $k => $item) {
                            if ($item['sub_wallet_id'] == $v['wallet_id']) {
                                $k = $k;
                                break;
                            }
                        }
                        $list[$k]['sub_wallet_name'] = $v['common_wallet_info']['wallet_name'];
                        $list[$k]['main_wallet_id'] = $v['sub_wallet_info']['main_wallet_id'];
                        $list[$k]['adv_cnt'] = $v['sub_wallet_info']['adv_cnt'];
                        $list[$k]['create_time'] = strtotime($v['common_wallet_info']['create_time']);
                    }
                }
            }
            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }

    /**
     * 修改绑定
     */
    public function edit($ids = null)
    {
        $WalletModel = new WalletModel();
        $StoreModel = new StoreModel();
        if ($this->request->isPost()) {
            $this->token();
            $id = input("id");
            $data['bind_store_id'] = input("store_id");
            $data['sub_wallet_type'] = input("wallet_type");
            if ($WalletModel->where("id",$id)->update($data)){
                $this->success('绑定成功');
            }
            $this->error('绑定失败');
        }
        $this->view->assign('storeList', $StoreModel->field('id,username')->select());
        $this->view->assign('row', $WalletModel->where("id",$ids)->find());
        return $this->view->fetch();
    }


    /**
     * 批量绑定
     */
    public function batch_binding(){
        $WalletModel = new WalletModel();
        $StoreModel = new StoreModel();
        if ($this->request->isPost()) {
            $this->token();
            $wallet_ids = input("wallet_ids");
            if(empty(input("store_id")) || empty(input("wallet_type"))){
                $this->error("参数错误");
            }
            $data['bind_store_id'] = input("store_id");
            $data['sub_wallet_type'] = input("wallet_type");
            if ($WalletModel->where(["id"=>["in",$wallet_ids]])->update($data)){
                $this->success('批量绑定成功');
            }
            $this->error('批量绑定失败');
        }
        $this->view->assign('storeList', $StoreModel->field('id,username')->select());

        return $this->view->fetch();
    }

    /**
     * 根据子钱包ID去进行批量绑定
     */
    public function bind_by_sub_wallet_id(){
        $StoreModel = new StoreModel();
        $WalletModel = new WalletModel();
        if ($this->request->isPost()) {
            $err_num = 0;
            $this->token();
            $post = $this->request->post();
            if(empty($post['store_id'])){
                $this->error('请选择绑定账号');
            }
            if(empty($post['public_sub_wallet_id']) && empty($post['private_sub_wallet_id'])){
                $this->error('空提交');
            }
            if(!empty($post['public_sub_wallet_id'])){
                $public_sub_wallet_id_list = explode(",",$post['public_sub_wallet_id']);
                $public_sub_wallet_id_list = array_combine($public_sub_wallet_id_list,array_fill(0,count($public_sub_wallet_id_list),1));
            }
            if (!empty($post['private_sub_wallet_id'])){
                $private_sub_wallet_id_list = explode(",",$post['private_sub_wallet_id']);
                $private_sub_wallet_id_list = array_combine($private_sub_wallet_id_list,array_fill(0,count($private_sub_wallet_id_list),2));
            }
            $sub_wallet_id_list = $public_sub_wallet_id_list + $private_sub_wallet_id_list;
            foreach ($sub_wallet_id_list as $k=>$v){
                if ($WalletModel->where(["id"=>$k])->update(['bind_store_id' => $post['store_id'],'sub_wallet_type' => $v])){
                    $err_num++;
                }
            }
            if($err_num != 0){
                $this->error("失败了".$err_num."次");
            }else{
                $this->success("批量绑定成功");
            }
        }
        $this->view->assign('storeList', $StoreModel->field('id,username')->select());
        return $this->view->fetch();
    }
}