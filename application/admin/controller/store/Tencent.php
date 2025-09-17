<?php

namespace app\admin\controller\store;

use app\admin\model\Store as StoreModel;
use app\common\model\txgg\TencentStore as TencentStoreModel;
use think\Db;

class Tencent extends Store
{
    public function index()
    {
        // 如果是Ajax请求，返回数据
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            
            // 查询商户信息以及对应的腾讯账户信息
            $list = StoreModel::with(['tencent'])
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);
            
            // 处理数据，确保即使没有腾讯账户信息也能正确显示
            foreach ($list as $row) {
                // 如果没有腾讯账户信息，则创建一个空对象
                if (!$row->tencent) {
                    $row->tencent = new \stdClass();
                }
            }
            
            $result = array("total" => $list->total(), "rows" => $list->items());
            
            return json($result);
        }
        
        return $this->fetch();
    }
    
    /**
     * 添加腾讯商户配置
     */
    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post("row/a");
            if ($params) {
                // 检查是否已存在该商户的腾讯配置
                $exist = TencentStoreModel::where('store_id', $params['store_id'])->find();
                
                if ($exist) {
                    $this->error("该商户的腾讯配置已存在");
                }

                // 插入数据
                $result = TencentStoreModel::create($params);

                if ($result !== false) {
                    $this->success("添加成功");
                } else {
                    $this->error("添加失败");
                }
            }
            $this->error("参数错误");
        }
        
        // 获取所有商户列表，但排除已存在腾讯配置的商户
        $existingStoreIds = TencentStoreModel::column('store_id');
        $storeList = StoreModel::whereNotIn('id', $existingStoreIds)->field('id,username')->select();
        $this->view->assign('storeList', $storeList);
        return $this->view->fetch();
    }
    
    /**
     * 编辑腾讯商户配置
     */
    public function edit($ids = null)
    {
        $row = TencentStoreModel::get(['store_id' => $ids]);
        if (!$row) {
            $this->error("记录未找到");
        }
        
        if ($this->request->isPost()) {
            $params = $this->request->post("row/a");
            if ($params) {
                // 移除可能不存在的字段
                unset($params['store_name']);

                // 确保存储ID不被修改
                unset($params['store_id']);
                
                // 处理钱包和授信额度的增减操作
                if (isset($params['public_money_tencent_add'])) {
                    $params['public_money_tencent'] = bcadd($row['public_money_tencent'], $params['public_money_tencent_add'], 2);
                    unset($params['public_money_tencent_add']);
                }
                
                if (isset($params['private_money_tencent_add'])) {
                    $params['private_money_tencent'] = bcadd($row['private_money_tencent'], $params['private_money_tencent_add'], 2);
                    unset($params['private_money_tencent_add']);
                }
                
                // 处理授信额度增减操作（在已使用授信额度处理之前）
                if (isset($params['public_credit_limit_tencent_add'])) {
                    $params['public_credit_limit_tencent'] = bcadd($row['public_credit_limit_tencent'], $params['public_credit_limit_tencent_add'], 2);
                    unset($params['public_credit_limit_tencent_add']);
                }
                
                if (isset($params['private_credit_limit_tencent_add'])) {
                    $params['private_credit_limit_tencent'] = bcadd($row['private_credit_limit_tencent'], $params['private_credit_limit_tencent_add'], 2);
                    unset($params['private_credit_limit_tencent_add']);
                }
                
                // 处理已使用授信额度影响授信额度余额的逻辑
                // 公账处理
                if (isset($params['public_spending_credit_limit_tencent'])) {
                    $spending = $params['public_spending_credit_limit_tencent'];
                    $credit_limit = isset($params['public_credit_limit_tencent']) ? $params['public_credit_limit_tencent'] : $row['public_credit_limit_tencent'];
                    $old_spending = $row['public_spending_credit_limit_tencent'];
                    
                    // 计算新的授信额度 = 原授信额度 - (新已使用额度 - 原已使用额度)
                    $credit_change = bcsub($spending, $old_spending, 2);
                    $params['public_credit_limit_tencent'] = bcsub($credit_limit, $credit_change, 2);
                    
                    // 检查授信额度是否为负数
                    if (bccomp($params['public_credit_limit_tencent'], '0', 2) < 0) {
                        $this->error("公账授信额度不能为负数");
                    }
                }
                
                // 私账处理
                if (isset($params['private_spending_credit_limit_tencent'])) {
                    $spending = $params['private_spending_credit_limit_tencent'];
                    $credit_limit = isset($params['private_credit_limit_tencent']) ? $params['private_credit_limit_tencent'] : $row['private_credit_limit_tencent'];
                    $old_spending = $row['private_spending_credit_limit_tencent'];
                    
                    // 计算新的授信额度 = 原授信额度 - (新已使用额度 - 原已使用额度)
                    $credit_change = bcsub($spending, $old_spending, 2);
                    $params['private_credit_limit_tencent'] = bcsub($credit_limit, $credit_change, 2);
                    
                    // 检查授信额度是否为负数
                    if (bccomp($params['private_credit_limit_tencent'], '0', 2) < 0) {
                        $this->error("私账授信额度不能为负数");
                    }
                }

                // 更新数据
                $result = $row->save($params);
                if ($result !== false) {
                    $this->success("更新成功");
                } else {
                    $this->error("更新失败");
                }
            }
            $this->error("参数错误");
        }
        
        // 获取关联的商户信息
        $store = StoreModel::get($row['store_id']);
        $row['store'] = $store;
        
        $this->view->assign("row", $row);
        return $this->view->fetch();
    }
    
    /**
     * 一键创建腾讯商户配置
     */
    public function batch_create()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post("row/a");
            if ($params) {
                // 获取已存在的store_id
                $existingStoreIds = TencentStoreModel::column('store_id');
                
                // 获取所有未配置腾讯账户的商户
                $stores = StoreModel::whereNotIn('id', $existingStoreIds)->field('id')->select();
                
                // 检查是否为空数组
                if (empty($stores)) {
                    $this->error("没有需要创建配置的商户");
                }
                
                // 准备批量插入的数据
                $dataList = [];
                foreach ($stores as $store) {
                    $data = [
                        'store_id' => $store['id'],
                        'public_money_tencent' => $params['public_money_tencent'] ?? 0,
                        'private_money_tencent' => $params['private_money_tencent'] ?? 0,
                        'public_credit_limit_tencent' => $params['public_credit_limit_tencent'] ?? 0,
                        'private_credit_limit_tencent' => $params['private_credit_limit_tencent'] ?? 0,
                        'public_discount_percentage_tencent' => $params['public_discount_percentage_tencent'] ?? 0,
                        'private_discount_percentage_tencent' => $params['private_discount_percentage_tencent'] ?? 0
                    ];
                    $dataList[] = $data;
                }
                
                // 批量插入数据
                $result = (new TencentStoreModel())->saveAll($dataList);
                
                if ($result !== false) {
                    $this->success("批量创建成功，共创建 " . count($dataList) . " 条记录");
                } else {
                    $this->error("批量创建失败");
                }
            }
            $this->error("参数错误");
        }
        
        return $this->view->fetch();
    }
}