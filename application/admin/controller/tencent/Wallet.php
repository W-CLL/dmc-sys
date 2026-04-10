<?php

namespace app\admin\controller\tencent;

use app\common\controller\Backend;
use app\admin\model\TencentShareWallet as WalletModel;
use app\admin\model\Store as StoreModel;
use think\Db;

class Wallet extends Backend
{
    /**
     * 查看
     */
    public function index()
    {
        //设置过滤方法
        $this->request->filter(['strip_tags']);
        
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            
            $agency = $this->request->get("agency");
            if ($agency) {
                $where[] = ['agency', '=', $agency];
            }
            
            $model = new WalletModel();
            $list = $model
                ->with('store')
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();
            $total = $model
                ->where($where)
                ->count();
            
            $result = array("total" => $total, "rows" => $list);
            
            return json($result);
        }
        
        return $this->view->fetch();
    }
    
    /**
     * 编辑
     */
    public function edit($ids = null)
    {
        $WalletModel = new WalletModel();
        $StoreModel = new StoreModel();
        // 修复参数获取问题
        $ids = $ids ? $ids : input('ids');
        $row = $WalletModel->where("id", $ids)->find();
        if (!$row) {
            $this->error("记录未找到");
        }
        
        if ($this->request->isPost()) {
            $this->token();
            $id = input("id");
            $data['store_id'] = input("store_id");
            $data['wallet_type'] = input("wallet_type");
            $data['discount_percentage'] = number_format(input("discount_percentage"), 4, '.', '');
            
            if ($WalletModel->save($data, ['id' => $id])) {
                $this->success('更新成功');
            }
            $this->error('更新失败');
        }
        
        $this->view->assign('storeList', $StoreModel->field('id,username')->select());
        $this->view->assign('row', $row);
        return $this->view->fetch();
    }
    
    /**
     * 批量绑定
     */
    public function batch_binding()
    {
        $WalletModel = new WalletModel();
        $StoreModel = new StoreModel();
        
        if ($this->request->isPost()) {
            $this->token();
            $ids = $this->request->post("ids");
            if (!$ids) {
                $this->error(__('Invalid parameters'));
            }
            
            $data['store_id'] = $this->request->post("store_id");
            $data['wallet_type'] = $this->request->post("wallet_type");
            // 格式化折扣比例字段
            $data['discount_percentage'] = number_format($this->request->post("discount_percentage", 0), 4, '.', '');
            
            // 将逗号分隔的ID转换为数组
            $idArray = explode(',', $ids);
            
            if ($WalletModel->where(["id"=>["in",$idArray]])->update($data)){
                $this->success();
            }
            $this->error();
        }
        
        $store_data = $StoreModel->column('id,username');
        $store_data[0] = "不绑定";
        $this->view->assign('storeList', build_select('store_id', $store_data, 0, ['class' => 'form-control selectpicker' ,'data-live-search'=>'true']));
        return $this->view->fetch();
    }
    
    /**
     * 根据钱包ID进行批量绑定
     */
    public function bind_by_wallet_id()
    {
        $WalletModel = new WalletModel();
        $StoreModel = new StoreModel();
        
        if ($this->request->isPost()) {
            $err_num = 0;
            $err_id = '';
            $public_wallet_id_list = [];
            $private_wallet_id_list = [];
            
            $this->token();
            $post = $this->request->post();
            $post['discount_percentage'] = number_format($post['discount_percentage'], 4, '.', '');
            
            if (empty($post['store_id'])) {
                $this->error('请选择绑定账号');
            }
            
            if (empty($post['public_wallet_id']) && empty($post['private_wallet_id'])) {
                $this->error('空提交');
            }
            
            // 处理公账钱包ID列表
            if (!empty($post['public_wallet_id'])) {
                $public_wallet_id_list = array_filter(explode("\n", $post['public_wallet_id']), function ($value) {
                    return trim($value) !== '';
                });
                $public_wallet_id_list = array_combine($public_wallet_id_list, array_fill(0, count($public_wallet_id_list), 1));
            }
            
            // 处理私账钱包ID列表
            if (!empty($post['private_wallet_id'])) {
                $private_wallet_id_list = array_filter(explode("\n", $post['private_wallet_id']), function ($value) {
                    return trim($value) !== '';
                });
                $private_wallet_id_list = array_combine($private_wallet_id_list, array_fill(0, count($private_wallet_id_list), 2));
            }
            
            // 合并公账和私账列表
            $wallet_id_list = $public_wallet_id_list + $private_wallet_id_list;
            
            // 遍历处理每个钱包ID
            foreach ($wallet_id_list as $wallet_id => $wallet_type) {
                $wallet_id = trim($wallet_id);
                
                if (!$WalletModel->where(["sub_wallet_id" => $wallet_id])->update([
                    'store_id' => $post['store_id'], 
                    'wallet_type' => $wallet_type, 
                    'discount_percentage' => $post['discount_percentage']
                ])) {
                    $err_num++;
                    $err_id .= $wallet_id . ",";
                }
            }
            
            if ($err_num != 0) {
                $this->error("部分成功，失败了" . $err_num . "次，绑定失败的ID为：" . $err_id);
            } else {
                $this->success("批量绑定成功");
            }
        }
        
        $store_data = $StoreModel->column('id,username');
        $store_data[0] = "不绑定";
        $this->view->assign('storeList', build_select('store_id', $store_data, 0, ['class' => 'form-control selectpicker' ,'data-live-search'=>'true']));
        return $this->view->fetch();
    }
}