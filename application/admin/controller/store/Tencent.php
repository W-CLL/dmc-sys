<?php

namespace app\admin\controller\store;

use app\admin\model\Store as StoreModel;
use app\common\model\txgg\TencentStore as TencentStoreModel;
use app\common\model\txgg\TencentTransactionLog as TencentTransactionLogModel;
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
                
                // 保存修改前的数据，用于生成交易日志
                $oldData = $row->toArray();
                
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
                    // 生成交易日志
                    $this->generateTransactionLogs($oldData, $params, $row['store_id']);
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
     * 生成交易日志
     * @param array $oldData 修改前的数据
     * @param array $newData 修改后的数据
     * @param int $storeId 商户ID
     */
    private function generateTransactionLogs($oldData, $newData, $storeId)
    {
        $logsToInsert = [];
        
        // 获取当前管理员信息
        $adminId = session('admin.id');
        $adminUsername = session('admin.username');
        
        // 处理公账余额变动
        if (isset($newData['public_money_tencent']) && 
            bccomp($oldData['public_money_tencent'], $newData['public_money_tencent'], 2) !== 0) {
            $amount = bcsub($newData['public_money_tencent'], $oldData['public_money_tencent'], 2);
            $logsToInsert[] = [
                'admin_id' => $adminId,
                'admin_username' => $adminUsername,
                'store_id' => $storeId,
                'money' => abs($amount),
                'explain' => ($amount > 0 ? '总后台增加公账余额' : '总后台减少公账余额') . '，变更前：' . $oldData['public_money_tencent'] . '，变更后：' . $newData['public_money_tencent'] . '，操作人：' . $adminUsername,
                'type' => $amount > 0 ? 1 : 2, // 1为增加余额，2为扣款
                'account_type' => 1, // 公账
                'before_money' => $oldData['public_money_tencent'], // 当前余额
                'today_money' => $newData['public_money_tencent'],  // 变动后余额
                'balance_surplus' => $newData['public_money_tencent'],
                'credit_limit_surplus' => $newData['public_credit_limit_tencent'],
                'create_time' => time()
            ];
        }
        
        // 处理私账余额变动
        if (isset($newData['private_money_tencent']) && 
            bccomp($oldData['private_money_tencent'], $newData['private_money_tencent'], 2) !== 0) {
            $amount = bcsub($newData['private_money_tencent'], $oldData['private_money_tencent'], 2);
            $logsToInsert[] = [
                'admin_id' => $adminId,
                'admin_username' => $adminUsername,
                'store_id' => $storeId,
                'money' => abs($amount),
                'explain' => ($amount > 0 ? '总后台增加私账余额' : '总后台减少私账余额') . '，变更前：' . $oldData['private_money_tencent'] . '，变更后：' . $newData['private_money_tencent'] . '，操作人：' . $adminUsername,
                'type' => $amount > 0 ? 1 : 2, // 1为增加余额，2为扣款
                'account_type' => 2, // 私账
                'before_money' => $oldData['private_money_tencent'], // 当前余额
                'today_money' => $newData['private_money_tencent'],  // 变动后余额
                'balance_surplus' => $newData['private_money_tencent'],
                'credit_limit_surplus' => $newData['private_credit_limit_tencent'],
                'create_time' => time()
            ];
        }
        
        // 处理公账授信额度变动
        if (isset($newData['public_credit_limit_tencent']) && 
            bccomp($oldData['public_credit_limit_tencent'], $newData['public_credit_limit_tencent'], 2) !== 0) {
            $amount = bcsub($newData['public_credit_limit_tencent'], $oldData['public_credit_limit_tencent'], 2);
            $spendingAmount = bcsub($newData['public_spending_credit_limit_tencent'] ?? 0, $oldData['public_spending_credit_limit_tencent'] ?? 0, 2);
            
            // 判断是授信总额度变化还是已使用额度转移
            $totalOld = bcadd($oldData['public_credit_limit_tencent'], $oldData['public_spending_credit_limit_tencent'], 2);
            $totalNew = bcadd($newData['public_credit_limit_tencent'], $newData['public_spending_credit_limit_tencent'], 2);
            $totalChange = bcsub($totalNew, $totalOld, 2);
            
            if (bccomp($totalChange, '0', 2) !== 0) {
                // 授信总额度发生变化
                $logsToInsert[] = [
                    'admin_id' => $adminId,
                    'admin_username' => $adminUsername,
                    'store_id' => $storeId,
                    'money' => abs($amount),
                    'explain' => ($amount > 0 ? '总后台增加公账授信额度' : '总后台减少公账授信额度') . '，授信总额度变更前：' . $totalOld . '，变更后：' . $totalNew . '（可用额度：' . $oldData['public_credit_limit_tencent'] . '→' . $newData['public_credit_limit_tencent'] . '，已使用额度：' . $oldData['public_spending_credit_limit_tencent'] . '→' . $newData['public_spending_credit_limit_tencent'] . '），操作人：' . $adminUsername,
                    'type' => $amount > 0 ? 1 : 2, // 1为增加额度，2为减少额度
                    'account_type' => 1, // 公账
                    'before_money' => $oldData['public_credit_limit_tencent'], // 当前余额
                    'today_money' => $newData['public_credit_limit_tencent'],  // 变动后余额
                    'balance_surplus' => $newData['public_money_tencent'],
                    'credit_limit_surplus' => $newData['public_credit_limit_tencent'],
                    'create_time' => time()
                ];
            } else {
                // 仅是已使用额度转移（总额度不变）
                $logsToInsert[] = [
                    'admin_id' => $adminId,
                    'admin_username' => $adminUsername,
                    'store_id' => $storeId,
                    'money' => abs($spendingAmount),
                    'explain' => ($spendingAmount > 0 ? '总后台增加公账已使用授信额度' : '总后台减少公账已使用授信额度') . '，授信总额度：' . $totalOld . '（可用额度：' . $oldData['public_credit_limit_tencent'] . '→' . $newData['public_credit_limit_tencent'] . '，已使用额度：' . $oldData['public_spending_credit_limit_tencent'] . '→' . $newData['public_spending_credit_limit_tencent'] . '），操作人：' . $adminUsername,
                    'type' => $spendingAmount > 0 ? 1 : 2, // 1为增加额度，2为减少额度
                    'account_type' => 1, // 公账
                    'before_money' => $oldData['public_credit_limit_tencent'], // 当前余额
                    'today_money' => $newData['public_credit_limit_tencent'],  // 变动后余额
                    'balance_surplus' => $newData['public_money_tencent'],
                    'credit_limit_surplus' => $newData['public_credit_limit_tencent'],
                    'create_time' => time()
                ];
            }
        }
        
        // 处理私账授信额度变动
        if (isset($newData['private_credit_limit_tencent']) && 
            bccomp($oldData['private_credit_limit_tencent'], $newData['private_credit_limit_tencent'], 2) !== 0) {
            $amount = bcsub($newData['private_credit_limit_tencent'], $oldData['private_credit_limit_tencent'], 2);
            $spendingAmount = bcsub($newData['private_spending_credit_limit_tencent'] ?? 0, $oldData['private_spending_credit_limit_tencent'] ?? 0, 2);
            
            // 判断是授信总额度变化还是已使用额度转移
            $totalOld = bcadd($oldData['private_credit_limit_tencent'], $oldData['private_spending_credit_limit_tencent'], 2);
            $totalNew = bcadd($newData['private_credit_limit_tencent'], $newData['private_spending_credit_limit_tencent'], 2);
            $totalChange = bcsub($totalNew, $totalOld, 2);
            
            if (bccomp($totalChange, '0', 2) !== 0) {
                // 授信总额度发生变化
                $logsToInsert[] = [
                    'admin_id' => $adminId,
                    'admin_username' => $adminUsername,
                    'store_id' => $storeId,
                    'money' => abs($amount),
                    'explain' => ($amount > 0 ? '总后台增加私账授信额度' : '总后台减少私账授信额度') . '，授信总额度变更前：' . $totalOld . '，变更后：' . $totalNew . '（可用额度：' . $oldData['private_credit_limit_tencent'] . '→' . $newData['private_credit_limit_tencent'] . '，已使用额度：' . $oldData['private_spending_credit_limit_tencent'] . '→' . $newData['private_spending_credit_limit_tencent'] . '），操作人：' . $adminUsername,
                    'type' => $amount > 0 ? 1 : 2, // 1为增加额度，2为减少额度
                    'account_type' => 2, // 私账
                    'before_money' => $oldData['private_credit_limit_tencent'], // 当前余额
                    'today_money' => $newData['private_credit_limit_tencent'],  // 变动后余额
                    'balance_surplus' => $newData['private_money_tencent'],
                    'credit_limit_surplus' => $newData['private_credit_limit_tencent'],
                    'create_time' => time()
                ];
            } else {
                // 仅是已使用额度转移（总额度不变）
                $logsToInsert[] = [
                    'admin_id' => $adminId,
                    'admin_username' => $adminUsername,
                    'store_id' => $storeId,
                    'money' => abs($spendingAmount),
                    'explain' => ($spendingAmount > 0 ? '总后台增加私账已使用授信额度' : '总后台减少私账已使用授信额度') . '，授信总额度：' . $totalOld . '（可用额度：' . $oldData['private_credit_limit_tencent'] . '→' . $newData['private_credit_limit_tencent'] . '，已使用额度：' . $oldData['private_spending_credit_limit_tencent'] . '→' . $newData['private_spending_credit_limit_tencent'] . '），操作人：' . $adminUsername,
                    'type' => $spendingAmount > 0 ? 1 : 2, // 1为增加额度，2为减少额度
                    'account_type' => 2, // 私账
                    'before_money' => $oldData['private_credit_limit_tencent'], // 当前余额
                    'today_money' => $newData['private_credit_limit_tencent'],  // 变动后余额
                    'balance_surplus' => $newData['private_money_tencent'],
                    'credit_limit_surplus' => $newData['private_credit_limit_tencent'],
                    'create_time' => time()
                ];
            }
        }
        
        // 批量插入日志记录
        if (!empty($logsToInsert)) {
            $logModel = new TencentTransactionLogModel();
            $logModel->saveAll($logsToInsert);
        }
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