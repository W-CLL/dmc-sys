<?php

namespace app\admin\controller\store;

use app\admin\model\Store as StoreModel;
use app\common\controller\Backend;
use app\common\model\txgg\TencentStore as TencentStoreModel;
use app\common\model\txgg\TencentTransactionLog as TencentTransactionLogModel;
use think\Db;

class Tencent extends Backend
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
        
        $oldData = $row->toArray(); // 保存修改前的数据用于日志记录
        
        if ($this->request->isPost()) {
            $params = $this->request->post("row/a");
            if ($params) {
                // 移除可能不存在的字段
                unset($params['store_name']);

                // 确保存储ID不被修改
                unset($params['store_id']);
                
                // 尝试多种方式获取文件
                $file = null;
                $imagePath = ''; // 初始化图片路径变量
                if (!empty($_FILES) && isset($_FILES['image']) && !empty($_FILES['image']['name'])) {
                    $file = $this->request->file('image');
                } else if (!empty($_FILES)) {
                    // 尝试其他可能的文件字段名
                    $allFiles = $this->request->file();
                    if (!empty($allFiles)) {
                        // 如果有文件上传但字段名不对，尝试获取第一个文件
                        $file = reset($allFiles);
                    }
                }
                
                // 验证：如果填写了已使用授信额度操作金额，则必须上传图片
                if ((isset($params['public_spending_credit_limit_tencent_add']) && $params['public_spending_credit_limit_tencent_add'] !== '') || 
                    (isset($params['private_spending_credit_limit_tencent_add']) && $params['private_spending_credit_limit_tencent_add'] !== '')) {
                    
                    // 检查是否有文件被上传
                    if (empty($file)) {
                        $this->error("操作已使用授信额度时必须上传凭证图片");
                    }
                }

                
                if (!empty($file)) {
                    // 验证文件是否有效
                    if (!$file->isValid()) {
                        $this->error("上传的图片文件无效: " . $file->getError());
                    }
                    
                    // 验证文件类型
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                    $fileMime = $file->getMime();
                    if (!in_array($fileMime, $allowedTypes)) {
                        $this->error("只允许上传 JPG、PNG、GIF 格式的图片，当前文件类型：" . $fileMime);
                    }
                    
                    // 验证文件大小（限制为5MB）
                    $fileSize = $file->getSize();
                    if ($fileSize > 5 * 1024 * 1024) {
                        $this->error("图片文件大小不能超过5MB，当前文件大小：" . ($fileSize/1024/1024) . "MB");
                    }
                    
                    // 移动文件到指定目录
                    $uploadPath = ROOT_PATH . 'public' . DS . 'receipt';
                    if (!is_dir($uploadPath)) {
                        mkdir($uploadPath, 0755, true);
                    }
                    
                    try {
                        $saveName = $file->move($uploadPath);
                        if (!$saveName) {
                            $this->error("图片上传失败：" . $file->getError());
                        }
                        // 保存图片路径，格式为 /receipt/年月日/文件名，确保使用正斜杠
                        $imagePath = '/receipt/' . str_replace('\\', '/', $saveName->getSaveName());
                    } catch (\Exception $e) {
                        $this->error("图片上传异常：" . $e->getMessage());
                    }
                }
                
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
                if (isset($params['public_spending_credit_limit_tencent_add'])) {
                    // 获取用户输入的数值
                    $inputValue = $params['public_spending_credit_limit_tencent_add'];
                    
                    // 获取当前已使用授信额度
                    $currentSpending = $row['public_spending_credit_limit_tencent'];
                    
                    // 确保输入值不为负数
                    if (bccomp($inputValue, '0', 2) < 0) {
                        $inputValue = '0.00';
                    }
                    
                    // 比较输入值与当前已使用授信额度
                    if (bccomp($inputValue, $currentSpending, 2) >= 0) {
                        // 输入值 >= 当前已使用授信额度
                        // 将当前已使用授信额度清零
                        $params['public_spending_credit_limit_tencent'] = '0.00';
                        
                        // 计算差值：输入值 - 当前已使用授信额度
                        $diff = bcsub($inputValue, $currentSpending, 2);
                        
                        // 如果差值大于0，将差值部分加入钱包余额
                        if (bccomp($diff, '0', 2) > 0) {
                            $params['public_money_tencent'] = bcadd($row['public_money_tencent'], $diff, 2);
                            // 记录差值用于日志生成
                            $publicMoneyDiff = $diff;
                        }
                        
                        // 将原来的已使用授信额度加回到授信额度中
                        if (isset($params['public_credit_limit_tencent'])) {
                            $params['public_credit_limit_tencent'] = bcadd($params['public_credit_limit_tencent'], $currentSpending, 2);
                        } else {
                            $params['public_credit_limit_tencent'] = bcadd($row['public_credit_limit_tencent'], $currentSpending, 2);
                        }
                    } else {
                        // 输入值 < 当前已使用授信额度
                        // 从当前已使用授信额度中扣除输入值
                        $newSpending = bcsub($currentSpending, $inputValue, 2);
                        $params['public_spending_credit_limit_tencent'] = $newSpending;
                        
                        // 将扣除的部分加回到授信额度中
                        if (isset($params['public_credit_limit_tencent'])) {
                            $params['public_credit_limit_tencent'] = bcadd($params['public_credit_limit_tencent'], $inputValue, 2);
                        } else {
                            $params['public_credit_limit_tencent'] = bcadd($row['public_credit_limit_tencent'], $inputValue, 2);
                        }
                    }
                    
                    unset($params['public_spending_credit_limit_tencent_add']);
                } else if (isset($params['public_spending_credit_limit_tencent'])) {
                    // 保持原有逻辑以兼容其他可能的调用方式
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
                if (isset($params['private_spending_credit_limit_tencent_add'])) {
                    // 获取用户输入的数值
                    $inputValue = $params['private_spending_credit_limit_tencent_add'];
                    
                    // 获取当前已使用授信额度
                    $currentSpending = $row['private_spending_credit_limit_tencent'];
                    
                    // 确保输入值不为负数
                    if (bccomp($inputValue, '0', 2) < 0) {
                        $inputValue = '0.00';
                    }
                    
                    // 比较输入值与当前已使用授信额度
                    if (bccomp($inputValue, $currentSpending, 2) >= 0) {
                        // 输入值 >= 当前已使用授信额度
                        // 将当前已使用授信额度清零
                        $params['private_spending_credit_limit_tencent'] = '0.00';
                        
                        // 计算差值：输入值 - 当前已使用授信额度
                        $diff = bcsub($inputValue, $currentSpending, 2);
                        
                        // 如果差值大于0，将差值部分加入钱包余额
                        if (bccomp($diff, '0', 2) > 0) {
                            $params['private_money_tencent'] = bcadd($row['private_money_tencent'], $diff, 2);
                            // 记录差值用于日志生成
                            $privateMoneyDiff = $diff;
                        }
                        
                        // 将原来的已使用授信额度加回到授信额度中
                        if (isset($params['private_credit_limit_tencent'])) {
                            $params['private_credit_limit_tencent'] = bcadd($params['private_credit_limit_tencent'], $currentSpending, 2);
                        } else {
                            $params['private_credit_limit_tencent'] = bcadd($row['private_credit_limit_tencent'], $currentSpending, 2);
                        }
                    } else {
                        // 输入值 < 当前已使用授信额度
                        // 从当前已使用授信额度中扣除输入值
                        $newSpending = bcsub($currentSpending, $inputValue, 2);
                        $params['private_spending_credit_limit_tencent'] = $newSpending;
                        
                        // 将扣除的部分加回到授信额度中
                        if (isset($params['private_credit_limit_tencent'])) {
                            $params['private_credit_limit_tencent'] = bcadd($params['private_credit_limit_tencent'], $inputValue, 2);
                        } else {
                            $params['private_credit_limit_tencent'] = bcadd($row['private_credit_limit_tencent'], $inputValue, 2);
                        }
                    }
                    
                    unset($params['private_spending_credit_limit_tencent_add']);
                } else if (isset($params['private_spending_credit_limit_tencent'])) {
                    // 保持原有逻辑以兼容其他可能的调用方式
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
                    // 生成交易日志（传递图片路径和差值信息）
                    $this->generateTransactionLogs($oldData, $params, $row['store_id'], $imagePath ?? '', $publicMoneyDiff ?? null, $privateMoneyDiff ?? null);
                    // 使用JSON格式返回成功响应，确保前端能正确解析
                    return json([
                        'code' => 1,
                        'msg' => '更新成功',
                        'data' => []
                    ]);
                } else {

                }
            }
            // 使用JSON格式返回参数错误响应
            return json([
                'code' => 0,
                'msg' => '参数错误',
                'data' => []
            ]);
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
     * @param string $imagePath 图片路径
     * @param string $publicMoneyDiff 公账因已使用额度调整产生的差值
     * @param string $privateMoneyDiff 私账因已使用额度调整产生的差值
     */
    private function generateTransactionLogs($oldData, $newData, $storeId, $imagePath = '', $publicMoneyDiff = null, $privateMoneyDiff = null)
    {
        $logsToInsert = [];
        
        // 获取当前管理员信息
        $adminId = session('admin.id');
        $adminUsername = session('admin.username');
        
        // 处理公账余额变动
        if (isset($newData['public_money_tencent']) && 
            bccomp($oldData['public_money_tencent'], $newData['public_money_tencent'], 2) !== 0) {
            $amount = bcsub($newData['public_money_tencent'], $oldData['public_money_tencent'], 2);
            
            // 检查是否是由于已使用额度调整导致的钱包余额增加
            $isFromSpendingAdjustment = !is_null($publicMoneyDiff) && bccomp($publicMoneyDiff, '0', 2) > 0;
            
            $logEntry = [
                'admin_id' => $adminId,
                'admin_username' => $adminUsername,
                'store_id' => $storeId,
                'money' => abs($amount),
                'explain' => ($isFromSpendingAdjustment ? '总后台清账导致公账余额增加' : ($amount > 0 ? '总后台增加公账余额' : '总后台减少公账余额')) . '，变更前：' . $oldData['public_money_tencent'] . '，变更后：' . $newData['public_money_tencent'] . '，操作人：' . $adminUsername,
                'type' => $isFromSpendingAdjustment ? 3 : ($amount > 0 ? 1 : 2), // 3为充值类型，1为增加余额，2为扣款
                'account_type' => 1, // 公账
                'before_money' => $oldData['public_money_tencent'], // 当前余额
                'today_money' => $newData['public_money_tencent'],  // 变动后余额
                'balance_surplus' => $newData['public_money_tencent'],
                'credit_limit_surplus' => $newData['public_credit_limit_tencent'],
                'create_time' => time()
            ];
            
            // 如果是由于已使用额度调整导致的余额增加，则添加凭证图片路径
            if ($isFromSpendingAdjustment && !empty($imagePath)) {
                $logEntry['receipt_image'] = $imagePath;
            }
            
            $logsToInsert[] = $logEntry;
        }
        
        // 处理私账余额变动
        if (isset($newData['private_money_tencent']) && 
            bccomp($oldData['private_money_tencent'], $newData['private_money_tencent'], 2) !== 0) {
            $amount = bcsub($newData['private_money_tencent'], $oldData['private_money_tencent'], 2);
            
            // 检查是否是由于已使用额度调整导致的钱包余额增加
            $isFromSpendingAdjustment = !is_null($privateMoneyDiff) && bccomp($privateMoneyDiff, '0', 2) > 0;
            
            $logEntry = [
                'admin_id' => $adminId,
                'admin_username' => $adminUsername,
                'store_id' => $storeId,
                'money' => abs($amount),
                'explain' => ($isFromSpendingAdjustment ? '总后台清账导致私账余额增加' : ($amount > 0 ? '总后台增加私账余额' : '总后台减少私账余额')) . '，变更前：' . $oldData['private_money_tencent'] . '，变更后：' . $newData['private_money_tencent'] . '，操作人：' . $adminUsername,
                'type' => $isFromSpendingAdjustment ? 3 : ($amount > 0 ? 1 : 2), // 3为充值类型，1为增加余额，2为扣款
                'account_type' => 2, // 私账
                'before_money' => $oldData['private_money_tencent'], // 当前余额
                'today_money' => $newData['private_money_tencent'],  // 变动后余额
                'balance_surplus' => $newData['private_money_tencent'],
                'credit_limit_surplus' => $newData['private_credit_limit_tencent'],
                'create_time' => time()
            ];
            
            // 如果是由于已使用额度调整导致的余额增加，则添加凭证图片路径
            if ($isFromSpendingAdjustment && !empty($imagePath)) {
                $logEntry['receipt_image'] = $imagePath;
            }
            
            $logsToInsert[] = $logEntry;
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
                    'type' => 3,   // 清账
                    'account_type' => 1, // 公账
                    'before_money' => $oldData['public_credit_limit_tencent'], // 当前余额
                    'today_money' => $newData['public_credit_limit_tencent'],  // 变动后余额
                    'balance_surplus' => $newData['public_money_tencent'],
                    'credit_limit_surplus' => $newData['public_credit_limit_tencent'],
                    'create_time' => time(),
                    'receipt_image' => $imagePath // 添加图片路径
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
                    'type' => 3, // 3为充值类型
                    'account_type' => 2, // 私账
                    'before_money' => $oldData['private_credit_limit_tencent'], // 当前余额
                    'today_money' => $newData['private_credit_limit_tencent'],  // 变动后余额
                    'balance_surplus' => $newData['private_money_tencent'],
                    'credit_limit_surplus' => $newData['private_credit_limit_tencent'],
                    'create_time' => time(),
                    'receipt_image' => $imagePath // 添加图片路径
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