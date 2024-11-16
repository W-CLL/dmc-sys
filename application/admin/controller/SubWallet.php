<?php

namespace app\admin\controller;

use app\common\controller\Backend;
use jlqc\FundManagement;
use think\Cache;
use app\admin\model\QcShareWallet as WalletModel;
use app\admin\model\QcConfig as QcConfigModel;
use app\admin\model\Store as StoreModel;
use app\admin\model\ShareWalletOnceBind as OnceBindModel;

class SubWallet extends Backend
{
    public function index()
    {
        $StoreModel = new StoreModel();
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



            $token = Cache::get("qc_access_token");
            $account_id = $QcConfigModel->where("id",1)->value("advertiser_id");
            $account_type = 'AGENT';


            $list = $WalletModel
                ->with('store')
                ->where($where)
//                ->field("id,sub_wallet_id,bind_store_id,sub_wallet_type,sub_wallet_type,discount_percentage")
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
        $this->view->assign('storeList', $StoreModel->field('id,username')->select());
        return $this->view->fetch();
    }

    /**
     * 修改绑定
     */
    public function edit($ids = null)
    {
        $WalletModel = new WalletModel();
        $StoreModel = new StoreModel();
        $OnceBindModel = new OnceBindModel();
        $row = $WalletModel->where("id",$ids)->find();
        if ($this->request->isPost()) {
            $this->token();
            $id = input("id");
            $data['bind_store_id'] = input("store_id");
            $data['sub_wallet_type'] = input("wallet_type");
            $data['discount_percentage'] = number_format(input("discount_percentage"), 4, '.', '');
            $once_bind = $OnceBindModel->where(['sub_wallet_id' => $row['sub_wallet_id'], 'bind_store_id' => $data['bind_store_id']])->find();
            if ($WalletModel->save($data,['id' => $id])){
                if($data['bind_store_id'] != $row['bind_store_id']){
                    $arr = [
                        'sub_wallet_id' => $row['sub_wallet_id'],
                        'bind_store_id' => $row['bind_store_id'],
                        'transfer_in_sum_public_cash' => $row['transfer_in_sum_public_cash'],
                        'transfer_out_sum_public_cash' => $row['transfer_out_sum_public_cash'],
                        'transfer_in_sum_private_cash' => $row['transfer_in_sum_private_cash'],
                        'transfer_out_sum_private_cash' => $row['transfer_out_sum_private_cash'],
                        'transfer_in_sum_public_vr' => $row['transfer_in_sum_public_vr'],
                        'transfer_out_sum_public_vr' => $row['transfer_out_sum_public_vr'],
                        'transfer_in_sum_private_vr' => $row['transfer_in_sum_private_vr'],
                        'transfer_out_sum_private_vr' => $row['transfer_out_sum_private_vr'],
                    ];
                    $OBID = $OnceBindModel->where(['sub_wallet_id' => $row['sub_wallet_id'], 'bind_store_id' => $row['bind_store_id']])->value('id');
                    if($OBID){
                        $arr['id'] = $OBID;
                    }
                    $reset['transfer_in_sum_public_cash'] = isset($once_bind['transfer_in_sum_public_cash'])?$once_bind['transfer_in_sum_public_cash']:0;
                    $reset['transfer_out_sum_public_cash'] = isset($once_bind['transfer_out_sum_public_cash'])?$once_bind['transfer_out_sum_public_cash']:0;
                    $reset['transfer_in_sum_private_cash'] = isset($once_bind['transfer_in_sum_private_cash'])?$once_bind['transfer_in_sum_private_cash']:0;
                    $reset['transfer_out_sum_private_cash'] = isset($once_bind['transfer_out_sum_private_cash'])?$once_bind['transfer_out_sum_private_cash']:0;
                    $reset['transfer_in_sum_public_vr'] = isset($once_bind['transfer_in_sum_public_vr'])?$once_bind['transfer_in_sum_public_vr']:0;
                    $reset['transfer_out_sum_public_vr'] = isset($once_bind['transfer_out_sum_public_vr'])?$once_bind['transfer_out_sum_public_vr']:0;
                    $reset['transfer_in_sum_private_vr'] = isset($once_bind['transfer_in_sum_private_vr'])?$once_bind['transfer_in_sum_private_vr']:0;
                    $reset['transfer_out_sum_private_vr'] = isset($once_bind['transfer_out_sum_private_vr'])?$once_bind['transfer_out_sum_private_vr']:0;
                    $WalletModel->where(['id' => $id])->update($reset);
                    $OnceBindModel->saveAll([$arr]);
                }
                $this->success('绑定成功');
            }
            $this->error('绑定失败/无更新');
        }
        $this->view->assign('storeList', $StoreModel->field('id,username')->select());
        $this->view->assign('row', $row);
        return $this->view->fetch();
    }


    /**
     * 批量绑定
     */
    public function batch_binding(){
        $WalletModel = new WalletModel();
        $StoreModel = new StoreModel();
        $OnceBindModel = new OnceBindModel();
        if ($this->request->isPost()) {
            $this->token();
            $wallet_ids = input("wallet_ids");
            if(empty(input("store_id")) || empty(input("wallet_type"))){
                $this->error("参数错误");
            }
            $list = $WalletModel->where(["id"=>["in",$wallet_ids]])->select();
            $data['bind_store_id'] = input("store_id");
            $data['sub_wallet_type'] = input("wallet_type");
            $data['discount_percentage'] = number_format(input("discount_percentage"), 4, '.', '');
            if ($WalletModel->where(["id"=>["in",$wallet_ids]])->update($data)){
                foreach ($list as $k => $v){
                    $once_bind = $OnceBindModel->where(['sub_wallet_id' => $v['sub_wallet_id'], 'bind_store_id' => $data['bind_store_id']])->find();
                    if($data['bind_store_id'] != $v['bind_store_id']){
                        $arr = [
                            'sub_wallet_id' => $v['sub_wallet_id'],
                            'bind_store_id' => $v['bind_store_id'],
                            'transfer_in_sum_public_cash' => $v['transfer_in_sum_public_cash'],
                            'transfer_out_sum_public_cash' => $v['transfer_out_sum_public_cash'],
                            'transfer_in_sum_private_cash' => $v['transfer_in_sum_private_cash'],
                            'transfer_out_sum_private_cash' => $v['transfer_out_sum_private_cash'],
                            'transfer_in_sum_public_vr' => $v['transfer_in_sum_public_vr'],
                            'transfer_out_sum_public_vr' => $v['transfer_out_sum_public_vr'],
                            'transfer_in_sum_private_vr' => $v['transfer_in_sum_private_vr'],
                            'transfer_out_sum_private_vr' => $v['transfer_out_sum_private_vr'],
                        ];
                        $OBID = $OnceBindModel->where(['sub_wallet_id' => $v['sub_wallet_id'], 'bind_store_id' => $v['bind_store_id']])->value('id');
                        if($OBID){
                            $arr['id'] = $OBID;
                        }
                        $reset['transfer_in_sum_public_cash'] = isset($once_bind['transfer_in_sum_public_cash'])?$once_bind['transfer_in_sum_public_cash']:0;
                        $reset['transfer_out_sum_public_cash'] = isset($once_bind['transfer_out_sum_public_cash'])?$once_bind['transfer_out_sum_public_cash']:0;
                        $reset['transfer_in_sum_private_cash'] = isset($once_bind['transfer_in_sum_private_cash'])?$once_bind['transfer_in_sum_private_cash']:0;
                        $reset['transfer_out_sum_private_cash'] = isset($once_bind['transfer_out_sum_private_cash'])?$once_bind['transfer_out_sum_private_cash']:0;
                        $reset['transfer_in_sum_public_vr'] = isset($once_bind['transfer_in_sum_public_vr'])?$once_bind['transfer_in_sum_public_vr']:0;
                        $reset['transfer_out_sum_public_vr'] = isset($once_bind['transfer_out_sum_public_vr'])?$once_bind['transfer_out_sum_public_vr']:0;
                        $reset['transfer_in_sum_private_vr'] = isset($once_bind['transfer_in_sum_private_vr'])?$once_bind['transfer_in_sum_private_vr']:0;
                        $reset['transfer_out_sum_private_vr'] = isset($once_bind['transfer_out_sum_private_vr'])?$once_bind['transfer_out_sum_private_vr']:0;
                        $WalletModel->where(['id' => $v['id']])->update($reset);
                        $OnceBindModel->saveAll([$arr]);
                    }
                }
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
        $OnceBindModel = new OnceBindModel();
        if ($this->request->isPost()) {
            $err_num = 0;
            $err_id = '';
            $public_sub_wallet_id_list = [];
            $private_sub_wallet_id_list = [];
            $this->token();
            $post = $this->request->post();
            $post['discount_percentage'] = number_format($post['discount_percentage'], 4, '.', '');
            if(empty($post['store_id'])){
                $this->error('请选择绑定账号');
            }
            if(empty($post['public_sub_wallet_id']) && empty($post['private_sub_wallet_id'])){
                $this->error('空提交');
            }
            if(!empty($post['public_sub_wallet_id'])){
                $public_sub_wallet_id_list = array_filter(explode("\n",$post['public_sub_wallet_id']), function($value) {
                    return trim($value) !== '';
                });
                $public_sub_wallet_id_list = array_combine($public_sub_wallet_id_list,array_fill(0,count($public_sub_wallet_id_list),1));
            }
            if (!empty($post['private_sub_wallet_id'])){
                $private_sub_wallet_id_list = array_filter(explode("\n",$post['private_sub_wallet_id']), function($value) {
                    return trim($value) !== '';
                });
                $private_sub_wallet_id_list = array_combine($private_sub_wallet_id_list,array_fill(0,count($private_sub_wallet_id_list),2));
            }
            $sub_wallet_id_list = $public_sub_wallet_id_list + $private_sub_wallet_id_list;
            foreach ($sub_wallet_id_list as $k=>$v){
                $k = trim($k);
                $info = $WalletModel->where("sub_wallet_id",$k)->find();
                if (!$WalletModel->where(["sub_wallet_id"=>$k])->update(['bind_store_id' => $post['store_id'], 'sub_wallet_type' => $v, 'discount_percentage' => $post['discount_percentage']])){
                    $err_num++;
                    $err_id .= $k.",";
                }else{
                    $once_bind = $OnceBindModel->where(['sub_wallet_id' => $info['sub_wallet_id'], 'bind_store_id' => $post['store_id']])->find();
                    if($post['store_id'] != $info['bind_store_id']){
                        $arr = [
                            'sub_wallet_id' => $info['sub_wallet_id'],
                            'bind_store_id' => $info['bind_store_id'],
                            'transfer_in_sum_public_cash' => $info['transfer_in_sum_public_cash'],
                            'transfer_out_sum_public_cash' => $info['transfer_out_sum_public_cash'],
                            'transfer_in_sum_private_cash' => $info['transfer_in_sum_private_cash'],
                            'transfer_out_sum_private_cash' => $info['transfer_out_sum_private_cash'],
                            'transfer_in_sum_public_vr' => $info['transfer_in_sum_public_vr'],
                            'transfer_out_sum_public_vr' => $info['transfer_out_sum_public_vr'],
                            'transfer_in_sum_private_vr' => $info['transfer_in_sum_private_vr'],
                            'transfer_out_sum_private_vr' => $info['transfer_out_sum_private_vr'],
                        ];
                        $OBID = $OnceBindModel->where(['sub_wallet_id' => $info['sub_wallet_id'], 'bind_store_id' => $info['bind_store_id']])->value('id');
                        if($OBID){
                            $arr['id'] = $OBID;
                        }
                        $reset['transfer_in_sum_public_cash'] = isset($once_bind['transfer_in_sum_public_cash'])?$once_bind['transfer_in_sum_public_cash']:0;
                        $reset['transfer_out_sum_public_cash'] = isset($once_bind['transfer_out_sum_public_cash'])?$once_bind['transfer_out_sum_public_cash']:0;
                        $reset['transfer_in_sum_private_cash'] = isset($once_bind['transfer_in_sum_private_cash'])?$once_bind['transfer_in_sum_private_cash']:0;
                        $reset['transfer_out_sum_private_cash'] = isset($once_bind['transfer_out_sum_private_cash'])?$once_bind['transfer_out_sum_private_cash']:0;
                        $reset['transfer_in_sum_public_vr'] = isset($once_bind['transfer_in_sum_public_vr'])?$once_bind['transfer_in_sum_public_vr']:0;
                        $reset['transfer_out_sum_public_vr'] = isset($once_bind['transfer_out_sum_public_vr'])?$once_bind['transfer_out_sum_public_vr']:0;
                        $reset['transfer_in_sum_private_vr'] = isset($once_bind['transfer_in_sum_private_vr'])?$once_bind['transfer_in_sum_private_vr']:0;
                        $reset['transfer_out_sum_private_vr'] = isset($once_bind['transfer_out_sum_private_vr'])?$once_bind['transfer_out_sum_private_vr']:0;
                        $WalletModel->where(['id' => $info['id']])->update($reset);
                        $OnceBindModel->saveAll([$arr]);
                    }
                }
            }
            if($err_num != 0){
                $this->error("部分成功，失败了".$err_num."次，绑定失败的ID为：".$err_id);
            }else{
                $this->success("批量绑定成功");
            }
        }
        $this->view->assign('storeList', $StoreModel->field('id,username')->select());
        return $this->view->fetch();
    }
}