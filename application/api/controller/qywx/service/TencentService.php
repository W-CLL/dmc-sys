<?php

namespace app\api\controller\qywx\service;

use think\Db;
use app\admin\model\Store;

/**
 * 腾讯服务类 - 处理腾讯相关的业务逻辑
 */
class TencentService
{
    /**
     * 处理腾讯查询账户
     *
     * @param string $accountIds 账户ID
     * @return string
     */
    public static function queryAccount($accountIds)
    {
        // 处理账户ID（支持逗号、中文逗号、空格分隔）
        $accountIds = str_replace('，', ',', $accountIds);
        $accountIdArray = preg_split('/[, ]+/', $accountIds);
        $accountIdArray = array_filter(array_map('trim', $accountIdArray));

        if (empty($accountIdArray)) {
            return "腾讯查询账户：\n请提供有效的账户ID";
        }

        // 限制最多查询10个账户
        if (count($accountIdArray) > 10) {
            return "腾讯查询账户：\n最多只能查询10个账户，您输入了" . count($accountIdArray) . "个";
        }

        // 查询账户信息
        $companies = Db::name('company')->whereIn('advertiser_id', $accountIdArray)->select();

        if (empty($companies)) {
            return "腾讯查询账户：\n未找到任何腾讯账户";
        }

        // 按商户分组
        $merchantGroups = [];
        foreach ($companies as $company) {
            $storeId = $company['store_id'];
            if (!isset($merchantGroups[$storeId])) {
                $merchantGroups[$storeId] = [
                    'store_id' => $storeId,
                    'accounts' => []
                ];
            }
            $merchantGroups[$storeId]['accounts'][] = $company;
        }

        // 查询商户信息
        $storeIds = array_keys($merchantGroups);
        $storeIds = array_filter($storeIds, function($id) { return $id > 0; });
        $stores = [];
        if (!empty($storeIds)) {
            $stores = Db::name('store')->whereIn('id', $storeIds)->column('username', 'id');
        }

        // 构建输出
        $output = "腾讯查询账户：\n";

        foreach ($merchantGroups as $storeId => $group) {
            if ($storeId > 0 && isset($stores[$storeId])) {
                $output .= "\n🏢 商户：【{$stores[$storeId]}】(ID:{$storeId})\n";
            } else {
                $output .= "\n⚪ 未绑定商户\n";
            }

            foreach ($group['accounts'] as $company) {
                $output .= "🔷账户名({$company['name']}) 腾讯ID {$company['advertiser_id']} ";
                
                // 显示账户类型
                if ($company['account_type'] == 1) {
                    $output .= "[对公]";
                } elseif ($company['account_type'] == 2) {
                    $output .= "[对私]";
                } else {
                    $output .= "[未知]";
                }
                
                // 根据账户类型显示相应字段
                if ($company['account_type'] == 1) {
                    $output .= " 公户余额:¥{$company['public_money']} 公户额度:¥{$company['public_credit_limit']}\n";
                } elseif ($company['account_type'] == 2) {
                    $output .= " 私户余额:¥{$company['private_money']} 私户额度:¥{$company['private_credit_limit']}\n";
                } else {
                    $output .= "\n";
                }
            }
        }

        return $output;
    }

    /**
     * 处理腾讯查询子钱包
     *
     * @param string $subWalletIds 子钱包ID
     * @return string
     */
    public static function querySubWallet($subWalletIds)
    {
        // 处理子钱包ID（支持逗号、中文逗号、空格分隔）
        $subWalletIds = str_replace('，', ',', $subWalletIds);
        $subWalletIdArray = preg_split('/[, ]+/', $subWalletIds);
        $subWalletIdArray = array_filter(array_map('trim', $subWalletIdArray));

        if (empty($subWalletIdArray)) {
            return "腾讯查询子钱包：\n请提供有效的子钱包ID";
        }

        // 限制最多查询10个子钱包
        if (count($subWalletIdArray) > 10) {
            return "腾讯查询子钱包：\n最多只能查询10个子钱包，您输入了" . count($subWalletIdArray) . "个";
        }

        // 查询子钱包信息
        $wallets = Db::name('tencent_share_wallet')->whereIn('sub_wallet_id', $subWalletIdArray)->select();

        if (empty($wallets)) {
            return "腾讯查询子钱包：\n未找到任何子钱包";
        }

        // 按商户分组
        $merchantGroups = [];
        foreach ($wallets as $wallet) {
            $storeId = $wallet['store_id'];
            if (!isset($merchantGroups[$storeId])) {
                $merchantGroups[$storeId] = [
                    'store_id' => $storeId,
                    'wallets' => []
                ];
            }
            $merchantGroups[$storeId]['wallets'][] = $wallet;
        }

        // 查询商户信息
        $storeIds = array_keys($merchantGroups);
        $storeIds = array_filter($storeIds, function($id) { return $id > 0; });
        $stores = [];
        if (!empty($storeIds)) {
            $stores = Db::name('store')->whereIn('id', $storeIds)->column('username', 'id');
        }

        // 构建输出
        $output = "腾讯查询子钱包：\n";

        foreach ($merchantGroups as $storeId => $group) {
            if ($storeId > 0 && isset($stores[$storeId])) {
                $output .= "\n🏢 商户：【{$stores[$storeId]}】(ID:{$storeId})\n";
            } else {
                $output .= "\n⚪ 未绑定商户\n";
            }

            foreach ($group['wallets'] as $wallet) {
                $output .= "🔐子钱包ID {$wallet['sub_wallet_id']} ";
                
                // 显示账户类型
                if ($wallet['wallet_type'] == 1) {
                    $output .= "[对公]";
                } elseif ($wallet['wallet_type'] == 2) {
                    $output .= "[对私]";
                } else {
                    $output .= "[未绑定]";
                }

                // 显示返点
                if ($wallet['discount_percentage'] > 0) {
                    $output .= " 返点:{$wallet['discount_percentage']}%";
                }

                $output .= " 名称:{$wallet['sub_wallet_name']}\n";
            }
        }

        return $output;
    }

    /**
     * 处理腾讯查询商户
     *
     * @param string $merchant 商户名称或ID
     * @return string
     */
    public static function queryMerchant($merchant)
    {
        $storeModel = new Store();
        $where = is_numeric($merchant) ? ['id' => $merchant] : ['username' => ['like', "%{$merchant}%"]];
        $store = $storeModel->where($where)->find();

        if (empty($store)) {
            return "腾讯查询商户：\n未找到商户：{$merchant}";
        }

        $output = "腾讯查询商户：\n";
        $output .= "🏢 商户：【{$store['username']}】(ID:{$store['id']})\n\n";

        // 查询tencent_store表获取腾讯专用额度
        $tencentStore = Db::name('tencent_store')->where('store_id', $store['id'])->find();

        // 查询商户关联的账户
        $companies = Db::name('company')->where('store_id', $store['id'])->select();
        if (!empty($companies)) {
            $output .= "📋 关联账户 (" . count($companies) . "个)：\n";
            foreach ($companies as $company) {
                $output .= "  • 腾讯ID {$company['advertiser_id']} {$company['name']} ";
                if ($company['account_type'] == 1) {
                    $output .= "[对公] 公户余额:¥{$company['public_money']} 公户额度:¥{$company['public_credit_limit']}\n";
                } elseif ($company['account_type'] == 2) {
                    $output .= "[对私] 私户余额:¥{$company['private_money']} 私户额度:¥{$company['private_credit_limit']}\n";
                } else {
                    $output .= "\n";
                }
            }
            $output .= "\n";
        }

        // 查询商户关联的子钱包
        $wallets = Db::name('tencent_share_wallet')->where('store_id', $store['id'])->select();
        if (!empty($wallets)) {
            $output .= "🔐 关联子钱包 (" . count($wallets) . "个)：\n";
            foreach ($wallets as $wallet) {
                $output .= "  • 子钱包ID {$wallet['sub_wallet_id']} ";
                if ($wallet['wallet_type'] == 1) {
                    $output .= "[对公]";
                } elseif ($wallet['wallet_type'] == 2) {
                    $output .= "[对私]";
                } else {
                    $output .= "[未绑定]";
                }
                if ($wallet['discount_percentage'] > 0) {
                    $output .= " 返点:{$wallet['discount_percentage']}%";
                }
                $output .= "\n";
            }
        }

        // 显示商户额度信息（使用腾讯专用字段）
        $output .= "\n💰 商户额度：\n";
        if ($tencentStore) {
            $output .= "  对公余额：¥{$tencentStore['public_money']}  对公额度：¥{$tencentStore['public_credit_limit_tencent']}\n";
            $output .= "  对私余额：¥{$tencentStore['private_money']}  对私额度：¥{$tencentStore['private_credit_limit_tencent']}\n";
        } else {
            $output .= "  对公余额：¥{$store['public_money']}  对公额度：¥{$store['public_credit_limit']}\n";
            $output .= "  对私余额：¥{$store['private_money']}  对私额度：¥{$store['private_credit_limit']}\n";
        }

        return $output;
    }

    /**
     * 处理腾讯新增额度
     *
     * @param string $params 参数
     * @param string $fromUserName 操作人
     * @return string
     */
    public static function addQuota($params, $fromUserName)
    {
        // 解析参数：[商户名称/ID] [账户类型] [金额] [备注]
        $parts = preg_split('/\s+/', trim($params));

        if (count($parts) < 3) {
            return "腾讯新增额度：\n参数格式错误\n正确格式：腾讯新增额度 [商户名称/ID] [账户类型] [金额] [备注]\n示例：腾讯新增额度 张三店铺 对公 1000 补充1月预算";
        }

        $merchant = $parts[0];        // 商户名称/ID
        $accountTypeStr = $parts[1]; // 账户类型：对公/对私
        $amount = $parts[2];         // 金额
        $remark = isset($parts[3]) ? $parts[3] : ''; // 备注（可选）

        // 转换账户类型
        if ($accountTypeStr == '对公') {
            $accountType = 1;
        } elseif ($accountTypeStr == '对私') {
            $accountType = 2;
        } else {
            return "腾讯新增额度：\n账户类型错误\n账户类型必须是：对公 或 对私";
        }

        // 验证金额
        if (!is_numeric($amount) || $amount <= 0) {
            return "腾讯新增额度：\n金额必须是正数";
        }
        $amount = floatval($amount);

        // 查询商户
        $storeModel = new Store();
        $where = is_numeric($merchant) ? ['s.id' => $merchant] : ['s.username' => ['like', "%{$merchant}%"]];
        $store = $storeModel->alias('s')->join('tencent_store ts', 's.id = ts.store_id')
        ->field('s.id,s.username,ts.public_credit_limit_tencent,ts.private_credit_limit_tencent')
        ->where($where)
        ->find();

        if (empty($store)) {
            return "腾讯新增额度：\n未找到商户：{$merchant}";
        }

        // 确定更新字段（使用腾讯专用字段）
        if ($accountType == 1) {
            $updateField = 'public_credit_limit_tencent';
            $typeLabel = '公户';
        } else {
            $updateField = 'private_credit_limit_tencent';
            $typeLabel = '私户';
        }

        // 启动事务
        Db::startTrans();
        try {
            // 参考Tencent.php的edit方法，使用bcadd进行精确计算
            $newCreditLimit = bcadd($store[$updateField], $amount, 2);
            
            $result = Db::name('tencent_store')->where(['store_id' => $store['id']])->update([$updateField => $newCreditLimit]);

            if (!$result) {
                throw new \Exception($result);
            }

            // 记录日志到腾讯交易日志表（参考Tencent.php的generateTransactionLogs方法）
            $logData = [
                'admin_id' => 0,  // 企业微信用户，设为0
                'admin_username' => $fromUserName,
                'store_id' => $store['id'],
                'money' => $amount,
                'explain' => '企业微信用户' . $fromUserName . '增加' . $typeLabel . '授信额度' . $amount . '元，变更前：' . $store[$updateField] . '，变更后：' . $newCreditLimit . '，备注：' . $remark,
                'type' => 1,  // 1=增加额度，2=减少额度
                'account_type' => $accountType,  // 1=公账，2=私账
                'before_money' => $store[$updateField],  // 当前授信额度
                'today_money' => $newCreditLimit,  // 变动后授信额度
                'balance_surplus' => $accountType == 1 ? $store['public_credit_limit_tencent'] : $store['private_credit_limit_tencent'],  // 钱包余额
                'credit_limit_surplus' => $newCreditLimit,  // 授信额度余额
                'create_time' => time()
            ];

            $logResult = Db::name('tencent_transaction_log')->insert($logData);

            if (!$logResult) {
                throw new \Exception('日志写入失败');
            }

            // 提交事务
            Db::commit();

            return "腾讯新增额度：成功\n✅ 商户：【{$store['username']}】(ID:{$store['id']})\n✅ 类型：{$typeLabel}\n✅ 金额：¥{$amount}\n✅ 原额度：¥{$store[$updateField]}\n✅ 新额度：¥{$newCreditLimit}\n✅ 备注：{$remark}\n✅ 操作人：{$fromUserName}";

        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            return "腾讯新增额度：失败\n错误信息：" . $e->getMessage();
        }
    }

    /**
     * 处理腾讯绑定商户
     *
     * @param string $params 参数
     * @param string $fromUserName 操作人
     * @return string
     */
    public static function bindMerchant($params, $fromUserName)
    {
        // 解析参数：[账户ID1,账户ID2] [商户名称/ID] [账户类型] [返点]
        $params = trim($params);
        $parts = preg_split('/\s+/', $params);

        if (count($parts) < 2) {
            return "腾讯绑定商户：\n参数格式错误\n正确格式：腾讯绑定商户 [账户ID1,账户ID2] [商户名称/ID] [账户类型] [返点]\n账户类型：对公/对私\n示例：腾讯绑定商户 12345678,87654321 张三店铺 对公 1.04";
        }

        $accountIds = $parts[0];        // 账户ID（逗号或空格分隔）
        $merchant = $parts[1];         // 商户名称/ID
        $accountTypeStr = isset($parts[2]) ? $parts[2] : '';  // 账户类型：对公/对私（可选）
        $discountPercent = isset($parts[3]) ? $parts[3] : '0.0000';  // 返点（可选）

        // 转换账户类型
        $accountType = null;
        if ($accountTypeStr) {
            if ($accountTypeStr == '对公') {
                $accountType = 1;
            } elseif ($accountTypeStr == '对私') {
                $accountType = 2;
            } else {
                return "腾讯绑定商户：\n账户类型错误\n账户类型必须是：对公 或 对私";
            }
        }

        // 验证返点
        $discountPercent = number_format(floatval($discountPercent), 4, '.', '');

        // 查询商户
        $storeModel = new Store();
        $where = is_numeric($merchant) ? ['id' => $merchant] : ['username' => ['like', "%{$merchant}%"]];
        $store = $storeModel->where($where)->find();

        if (empty($store)) {
            return "腾讯绑定商户：\n未找到商户：{$merchant}";
        }

        // 处理账户ID（支持逗号、中文逗号、空格分隔）
        $accountIds = str_replace('，', ',', $accountIds);
        $accountIdArray = preg_split('/[, ]+/', $accountIds);
        $accountIdArray = array_filter(array_map('trim', $accountIdArray));

        if (empty($accountIdArray)) {
            return "腾讯绑定商户：\n请提供有效的账户ID";
        }

        // 限制最多绑定10个账户
        if (count($accountIdArray) > 10) {
            return "腾讯绑定商户：\n最多只能绑定10个账户，您输入了" . count($accountIdArray) . "个";
        }

        // 查询所有账户信息
        $companies = Db::name('company')->whereIn('advertiser_id', $accountIdArray)->select();

        if (empty($companies)) {
            return "腾讯绑定商户：\n未找到任何腾讯账户";
        }

        // 检查主体绑定情况
        $companyMap = [];  // advertiser_id => company
        foreach ($companies as $company) {
            $companyMap[$company['advertiser_id']] = $company;
        }

        // 检查每个账户的绑定情况
        $bindResult = [
            'success' => [],
            'failed' => [],
            'first_bind_need_web' => [],
            'not_same_entity' => [],
            'already_bound' => []
        ];

        foreach ($accountIdArray as $advertiserId) {
            if (!isset($companyMap[$advertiserId])) {
                $bindResult['failed'][] = $advertiserId . '(账户不存在)';
                continue;
            }

            $company = $companyMap[$advertiserId];

            // 检查是否已绑定
            if ($company['store_id'] > 0) {
                $boundStore = Db::name('store')->where('id', $company['store_id'])->value('username');
                $bindResult['already_bound'][] = $advertiserId . '(已绑定商户:' . $boundStore . ')';
                continue;
            }

            // 获取主体名称
            $companyName = $company['company_name'] ?? '';
            if (empty($companyName)) {
                $bindResult['failed'][] = $advertiserId . '(主体名称为空)';
                continue;
            }

            // 查询同主体下所有账户的绑定情况
            $sameEntityCompanies = Db::name('company')
                ->where('company_name', $companyName)
                ->where('advertiser_id', '<>', $advertiserId)
                ->column('store_id');

            // 检查同主体下是否有账户已绑定
            $hasBoundInEntity = false;
            $entityStoreId = 0;
            foreach ($sameEntityCompanies as $storeId) {
                if ($storeId > 0) {
                    $hasBoundInEntity = true;
                    $entityStoreId = $storeId;
                    break;
                }
            }

            // 第一次绑定需要到web后台
            if (!$hasBoundInEntity) {
                $bindResult['first_bind_need_web'][] = $advertiserId . '(主体:' . $companyName . ')';
                continue;
            }

            // 检查是否绑定到相同主体的商户
            if ($entityStoreId != $store['id']) {
                $boundStore = Db::name('store')->where('id', $entityStoreId)->value('username');
                $bindResult['not_same_entity'][] = $advertiserId . '(主体已绑定商户:' . $boundStore . ')';
                continue;
            }

            // 绑定到company表
            $result = Db::name('company')->where('id', $company['id'])->update([
                'store_id' => $store['id']
            ]);
            if ($result !== false) {
                $bindResult['success'][] = $advertiserId;
            } else {
                $bindResult['failed'][] = $advertiserId . '(绑定失败)';
            }
        }

        // 构建返回结果
        $output = "腾讯绑定商户：\n";
        $output .= "🏢 商户：【{$store['username']}】(ID:{$store['id']})\n";
        $output .= "💰 返点：{$discountPercent}%\n";
        if ($accountType) {
            $typeLabel = $accountType == 1 ? '对公' : '对私';
            $output .= "📌 账户类型：{$typeLabel}\n";
        }
        $output .= "\n";

        // 绑定结果
        if (!empty($bindResult['success'])) {
            $output .= "✅ 绑定成功 (" . count($bindResult['success']) . "个)：\n";
            foreach ($bindResult['success'] as $advertiserId) {
                $output .= "  • {$advertiserId}\n";
            }
            $output .= "\n";
        }

        if (!empty($bindResult['already_bound'])) {
            $output .= "⚠️ 已绑定 (" . count($bindResult['already_bound']) . "个)：\n";
            foreach ($bindResult['already_bound'] as $item) {
                $output .= "  • {$item}\n";
            }
            $output .= "\n";
        }

        if (!empty($bindResult['first_bind_need_web'])) {
            $output .= "🔒 首次绑定需到web后台 (" . count($bindResult['first_bind_need_web']) . "个)：\n";
            foreach ($bindResult['first_bind_need_web'] as $item) {
                $output .= "  • {$item}\n";
            }
            $output .= "\n";
        }

        if (!empty($bindResult['not_same_entity'])) {
            $output .= "⛔ 主体已绑定其他商户 (" . count($bindResult['not_same_entity']) . "个)：\n";
            foreach ($bindResult['not_same_entity'] as $item) {
                $output .= "  • {$item}\n";
            }
            $output .= "\n";
        }

        if (!empty($bindResult['failed'])) {
            $output .= "❌ 绑定失败 (" . count($bindResult['failed']) . "个)：\n";
            foreach ($bindResult['failed'] as $item) {
                $output .= "  • {$item}\n";
            }
        }

        return $output;
    }
}
