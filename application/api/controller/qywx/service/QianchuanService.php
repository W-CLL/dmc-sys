<?php

namespace app\api\controller\qywx\service;

use think\Db;
use app\admin\model\Store;

use function Symfony\Component\DependencyInjection\Loader\Configurator\ref;

/**
 * 千川服务类 - 处理千川相关的业务逻辑
 */
class QianchuanService
{
    /**
     * 处理千川查询账户
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
            return "千川查询账户：\n请提供有效的账户ID";
        }

        // 限制最多查询10个账户
        if (count($accountIdArray) > 10) {
            return "千川查询账户：\n最多只能查询10个账户，您输入了" . count($accountIdArray) . "个";
        }

        // 查询账户信息
        $companies = Db::name('company')->whereIn('advertiser_id', $accountIdArray)->select();

        if (empty($companies)) {
            return "千川查询账户：\n未找到任何千川账户";
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
            $stores = Db::name('store')->whereIn('id', $storeIds)->column('*', 'id');
        }

        // 构建输出
        $output = "千川查询账户：";
try {
        foreach ($merchantGroups as $storeId => $group) {
            if ($storeId > 0 && isset($stores[$storeId])) {
                $publicMoney = isset($stores[$storeId]['public_money']) ? $stores[$storeId]['public_money'] : 0;
                $publicCreditLimit = isset($stores[$storeId]['public_credit_limit']) ? $stores[$storeId]['public_credit_limit'] : 0;
                $privateMoney = isset($stores[$storeId]['private_money']) ? $stores[$storeId]['private_money'] : 0;
                $privateCreditLimit = isset($stores[$storeId]['private_credit_limit']) ? $stores[$storeId]['private_credit_limit'] : 0;
                $output .= "\n【商户】{$stores[$storeId]['username']}(ID:{$storeId}) 私户余额:¥{$privateMoney} 私户额度:¥{$privateCreditLimit}\n";
            } else {
                $output .= "\n[未绑定商户]\n";

            }

            foreach ($group['accounts'] as $company) {
                $output .= " - 账户名({$company['name']}) 千川ID {$company['advertiser_id']} ";

                // 显示账户类型
                if ($company['account_type'] == 1) {
                    $output .= "[对公]\n";
                } elseif ($company['account_type'] == 2) {
                    $output .= "[对私]\n";
                } else {
                    $output .= "[未知]\n";
                }
            }
        }
}catch (\Exception $e) {
            $output .= "错误信息：" . $e->getMessage();
        }

        return $output;
    }

    /**
     * 处理千川查询子钱包
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
            return "千川查询子钱包：\n请提供有效的子钱包ID";
        }

        // 限制最多查询10个子钱包
        if (count($subWalletIdArray) > 10) {
            return "千川查询子钱包：\n最多只能查询10个子钱包，您输入了" . count($subWalletIdArray) . "个";
        }

        // 查询子钱包信息
        $wallets = Db::name('qc_share_wallet')->whereIn('sub_wallet_id', $subWalletIdArray)->select();

        if (empty($wallets)) {
            return "千川查询子钱包：\n未找到任何子钱包";
        }

        // 按商户分组
        $merchantGroups = [];
        foreach ($wallets as $wallet) {
            $storeId = $wallet['bind_store_id'];
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

        // 获取API配置
        $token = \think\Cache::get("qc_access_token");
        $accountId = \app\admin\model\QcConfig::where("id", 1)->value("advertiser_id");

        // 构建输出
        $output = "千川查询子钱包：\n";

        foreach ($merchantGroups as $storeId => $group) {
            if ($storeId > 0 && isset($stores[$storeId])) {
                $output .= "\n【商户】{$stores[$storeId]}(ID:{$storeId})\n";
            } else {
                $output .= "\n[未绑定商户]\n";
            }

            // 通过API获取子钱包详细信息
            $walletIds = array_column($group['wallets'], 'sub_wallet_id');
            $walletIdsInt = array_map('intval', $walletIds);
            
            $res = \jlqc\FundManagement::get_wallet_info_list($token, $accountId, json_encode($walletIdsInt), 'AGENT');
            $walletDetails = [];
            if ($res['code'] == 0 && !empty($res['data']['wallet_info'])) {
                foreach ($res['data']['wallet_info'] as $item) {
                    $walletDetails[$item['wallet_id']] = $item;
                }
            }

            foreach ($group['wallets'] as $wallet) {
                $output .= " - 子钱包ID {$wallet['sub_wallet_id']} ";
                
                // 显示账户类型
                if ($wallet['sub_wallet_type'] == 1) {
                    $output .= "[对公]";
                } elseif ($wallet['sub_wallet_type'] == 2) {
                    $output .= "[对私]";
                } else {
                    $output .= "[未绑定]";
                }

                // 显示返点
                if ($wallet['discount_percentage'] > 0) {
                    $output .= " 返点:{$wallet['discount_percentage']}%";
                }

                // 显示钱包余额
                if (isset($walletDetails[$wallet['sub_wallet_id']])) {
                    $detail = $walletDetails[$wallet['sub_wallet_id']];
                    $walletName = $detail['common_wallet_info']['wallet_name'] ?? '';
                    $balance = $detail['sub_wallet_info']['cash_balance'] ?? 0;
                    $output .= "\n   名称:{$walletName} 余额:¥{$balance}\n";
                } else {
                    $output .= "\n";
                }
            }
        }

        return $output;
    }

    /**
     * 处理千川查询商户
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
            return "千川查询商户：\n未找到商户：{$merchant}";
        }

        $output = "千川查询商户：\n";
        $output .= "【商户】{$store['username']}(ID:{$store['id']})\n\n";

        // 查询商户关联的账户
        $companies = Db::name('company')->where('store_id', $store['id'])->select();
        if (!empty($companies)) {
            $output .= "[关联账户] " . count($companies) . "个：\n";
            foreach ($companies as $company) {
                $output .= "   - 千川ID {$company['advertiser_id']} {$company['name']} ";
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
        $wallets = Db::name('qc_share_wallet')->where('bind_store_id', $store['id'])->select();
        if (!empty($wallets)) {
            $output .= "[关联子钱包] " . count($wallets) . "个：\n";
            foreach ($wallets as $wallet) {
                $output .= "   - 子钱包ID {$wallet['sub_wallet_id']} ";
                if ($wallet['sub_wallet_type'] == 1) {
                    $output .= "[对公]";
                } elseif ($wallet['sub_wallet_type'] == 2) {
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

        // 显示商户额度信息
        $output .= "\n[商户额度]\n";
        $output .= "  对公余额：¥{$store['public_money']}  对公额度：¥{$store['public_credit_limit']}\n";
        $output .= "  对私余额：¥{$store['private_money']}  对私额度：¥{$store['private_credit_limit']}\n";

        return $output;
    }

    /**
     * 处理千川新增额度
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
            return "千川新增额度：\n参数格式错误\n正确格式：千川新增额度 [商户名称/ID] [账户类型] [金额] [备注]\n示例：千川新增额度 张三店铺 对公 1000 补充1月预算";
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
            return "千川新增额度：\n账户类型错误\n账户类型必须是：对公 或 对私";
        }

        // 验证金额
        if (!is_numeric($amount) || $amount <= 0) {
            return "千川新增额度：\n金额必须是正数";
        }
        $amount = floatval($amount);

        // 查询商户
        $storeModel = new Store();
        $where = is_numeric($merchant) ? ['id' => $merchant] : ['username' => ['like', "%{$merchant}%"]];
        $store = $storeModel->where($where)->find();

        if (empty($store)) {
            return "千川新增额度：\n未找到商户：{$merchant}";
        }

        // 确定更新字段
        if ($accountType == 1) {
            $updateField = 'public_credit_limit';
            $typeLabel = '公户';
        } else {
            $updateField = 'private_credit_limit';
            $typeLabel = '私户';
        }

        // 启动事务
        Db::startTrans();
        try {
            $newCreditLimit = bcadd($store[$updateField], $amount, 2);
            $result = Db::name('store')->where('id', $store['id'])->update([$updateField => $newCreditLimit]);

            if (!$result) {
                throw new \Exception($result);
            }

            // 记录日志
            $logData = [
                'admin_id' => 0,
                'admin_username' => $fromUserName,
                'store_id' => $store['id'],
                'money' => $amount,
                'explain' => '企业微信用户' . $fromUserName . '增加' . $typeLabel . '授信额度' . $amount . '元，变更前：' . $store[$updateField] . '，变更后：' . $newCreditLimit . '，备注：' . $remark,
                'type' => 1,
                'account_type' => $accountType,
                'before_money' => $store[$updateField],
                'today_money' => $newCreditLimit,
                'balance_surplus' => $accountType == 1 ? $store['public_money'] : $store['private_money'],
                'credit_limit_surplus' => $newCreditLimit,
                'create_time' => time()
            ];

            $logResult = Db::name('store_money_log')->insert($logData);

            if (!$logResult) {
                throw new \Exception('日志写入失败');
            }

            // 提交事务
            Db::commit();

            return "千川新增额度：成功\n[商户]{$store['username']}(ID:{$store['id']})\n[类型]{$typeLabel}\n[金额]¥{$amount}\n[原额度]¥{$store[$updateField]}\n[新额度]¥{$newCreditLimit}\n[备注]{$remark}\n[操作人]{$fromUserName}";

        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            return "千川新增额度：失败\n错误信息：" . $e->getMessage();
        }
    }

    /**
     * 处理千川绑定商户
     *
     * @param string $params 参数
     * @param string $fromUserName 操作人
     * @return string
     */
    public static function bindMerchant($params, $fromUserName)
    {
        // 解析参数：[广告账户ID1,子钱包ID2] [商户名称/ID] [账户类型] [返点]
        // 1开头 = 千川广告账户ID，7开头 = 子钱包ID
        $params = trim($params);
        $parts = preg_split('/\s+/', $params);

        if (count($parts) < 2) {
            return "千川绑定商户：\n参数格式错误\n正确格式：千川绑定商户 [广告账户ID1,子钱包ID2] [商户名称/ID] [账户类型] [返点]\n• 1开头=千川广告账户ID\n• 7开头=子钱包ID\n账户类型：对公/对私\n示例：千川绑定商户 1828197229,723456789 张三店铺 对公 1.04";
        }

        $idList = $parts[0];          // ID列表（逗号或空格分隔），支持广告账户ID或子钱包ID
        $merchant = $parts[1];        // 商户名称/ID
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
                return "千川绑定商户：\n账户类型错误\n账户类型必须是：对公 或 对私";
            }
        }

        // 验证返点
        $discountPercent = number_format(floatval($discountPercent), 4, '.', '');

        // 查询商户
        $storeModel = new Store();
        $where = is_numeric($merchant) ? ['id' => $merchant] : ['username' => ['like', "%{$merchant}%"]];
        $store = $storeModel->where($where)->find();

        if (empty($store)) {
            return "千川绑定商户：\n未找到商户：{$merchant}";
        }

        // 处理ID列表（支持逗号、中文逗号、空格分隔）
        $idList = str_replace('，', ',', $idList);
        $idArray = preg_split('/[, ]+/', $idList);
        $idArray = array_filter(array_map('trim', $idArray));

        if (empty($idArray)) {
            return "千川绑定商户：\n请提供有效的ID";
        }

        // 限制最多绑定10个
        if (count($idArray) > 10) {
            return "千川绑定商户：\n最多只能绑定10个，您输入了" . count($idArray) . "个";
        }

        // 分类ID：广告账户ID(1开头) 和 子钱包ID(7开头)
        $advertiserIds = [];
        $subWalletIds = [];
        foreach ($idArray as $id) {
            if (strpos($id, '1') === 0) {
                $advertiserIds[] = $id;
            } elseif (strpos($id, '7') === 0) {
                $subWalletIds[] = $id;
            }
        }

        // 获取API配置
        $token = \think\Cache::get("qc_access_token");
        $accountId = \app\admin\model\QcConfig::where("id", 1)->value("advertiser_id");

        // 处理广告账户绑定（1开头）
        $bindResult = [
            'advertiser_success' => [],
            'advertiser_failed' => [],
            'advertiser_first_bind' => [],
            'advertiser_not_same_entity' => [],
            'advertiser_already_bound' => [],
            'advertiser_not_exist' => [],
            'wallet_success' => [],
            'wallet_failed' => [],
            'wallet_first_bind' => [],
            'wallet_not_same_entity' => [],
            'wallet_already_bound' => [],
            'wallet_not_exist' => []
        ];

        // 处理广告账户绑定
        if (!empty($advertiserIds)) {
            // 查询所有广告账户信息
            $companies = Db::name('company')->whereIn('advertiser_id', $advertiserIds)->select();
            $companyMap = [];  // advertiser_id => company
            foreach ($companies as $company) {
                $companyMap[$company['advertiser_id']] = $company;
            }

            foreach ($advertiserIds as $advertiserId) {
                if (!isset($companyMap[$advertiserId])) {
                    $bindResult['advertiser_not_exist'][] = $advertiserId . '(账户不存在)';
                    continue;
                }

                $company = $companyMap[$advertiserId];

                // 检查是否已绑定
                if ($company['store_id'] > 0) {
                    $boundStore = Db::name('store')->where('id', $company['store_id'])->value('username');
                    $bindResult['advertiser_already_bound'][] = $advertiserId . '(已绑定商户:' . $boundStore . ')';
                    continue;
                }

                // 获取主体名称
                $companyName = $company['company_name'] ?? '';
                if (empty($companyName)) {
                    $bindResult['advertiser_failed'][] = $advertiserId . '(主体名称为空)';
                    continue;
                }

                // 查询同主体下所有账户的绑定情况
                $sameEntityCompanies = Db::name('company')
                    ->where('company_name', $companyName)
                    ->where('advertiser_id', '<>', $advertiserId)
                    ->column('store_id');

                // 查询同主体的子钱包绑定情况
                $sameEntityWallets = Db::name('qc_share_wallet qcw')
                    ->join('company c', 'c.company_name = ' . Db::raw("'{$companyName}'"))
                    ->where('qcw.bind_store_id', '>', 0)
                    ->column('qcw.bind_store_id');

                // 合并绑定情况
                $boundStoreIds = array_merge($sameEntityCompanies, $sameEntityWallets);
                $boundStoreIds = array_unique($boundStoreIds);

                // 检查同主体下是否有账户已绑定
                $hasBoundInEntity = false;
                $entityStoreId = 0;
                foreach ($boundStoreIds as $storeId) {
                    if ($storeId > 0) {
                        $hasBoundInEntity = true;
                        $entityStoreId = $storeId;
                        break;
                    }
                }

                // 第一次绑定需要到web后台
                if (!$hasBoundInEntity) {
                    $bindResult['advertiser_first_bind'][] = $advertiserId . '(主体:' . $companyName . ')';
                    continue;
                }

                // 检查是否绑定到相同主体的商户
                if ($entityStoreId != $store['id']) {
                    $boundStore = Db::name('store')->where('id', $entityStoreId)->value('username');
                    $bindResult['advertiser_not_same_entity'][] = $advertiserId . '(主体已绑定商户:' . $boundStore . ')';
                    continue;
                }

                // 绑定到company表
                $result = Db::name('company')->where('id', $company['id'])->update([
                    'store_id' => $store['id']
                ]);
                if ($result !== false) {
                    $bindResult['advertiser_success'][] = $advertiserId;
                } else {
                    $bindResult['advertiser_failed'][] = $advertiserId . '(绑定失败)';
                }
            }
        }

        // 处理子钱包绑定（7开头）
        if (!empty($subWalletIds)) {
            foreach ($subWalletIds as $subWalletId) {
                // 检查子钱包是否存在
                $walletInfo = Db::name('qc_share_wallet')->where('sub_wallet_id', $subWalletId)->find();
                if (!$walletInfo) {
                    $bindResult['wallet_not_exist'][] = $subWalletId . '(子钱包不存在)';
                    continue;
                }

                // 检查是否已绑定
                if ($walletInfo['bind_store_id'] > 0) {
                    $boundStore = Db::name('store')->where('id', $walletInfo['bind_store_id'])->value('username');
                    $bindResult['wallet_already_bound'][] = $subWalletId . '(已绑定商户:' . $boundStore . ')';
                    continue;
                }

                // 通过API获取子钱包关联的广告账户ID
                $advList = [];
                $pageNum = 1;
                $error = 0;
                $totalNum = 0;
                do {
                    $res = \jlqc\FundManagement::getShareWalletAdvList([
                        'account_id' => $accountId,
                        'shared_wallet_id' => (int)$subWalletId,
                        'page' => $pageNum,
                        'page_size' => 100,
                        'account_type' => 'AGENT'
                    ]);
                    if ($res['code'] == 0 && !empty($res['data']['results'])) {
                        $pageNum++;
                        $totalNum = $res['data']['page_info']['total_number'];
                        $advList = array_merge($advList, $res['data']['results']);
                    } else {
                        $error++;
                    }
                } while ($error < 3 && $pageNum * 100 < $totalNum);

                if ($error >= 3 || empty($advList)) {
                    $bindResult['wallet_failed'][] = $subWalletId . '(无法获取广告账户)';
                    continue;
                }

                // 获取第一个广告账户ID用于主体检测
                $advertiserId = $advList[0]['advertiser_id'];

                // 查询公司信息
                $company = Db::name('company')->where('advertiser_id', $advertiserId)->find();
                if (!$company) {
                    $bindResult['wallet_failed'][] = $subWalletId . '(未找到广告账户信息)';
                    continue;
                }

                // 获取主体名称
                $companyName = $company['company_name'] ?? '';
                if (empty($companyName)) {
                    $bindResult['wallet_failed'][] = $subWalletId . '(主体名称为空)';
                    continue;
                }

                // 查询同主体下所有账户的绑定情况（包括子钱包和账户）
                $sameEntityCompanies = Db::name('company')
                    ->where('company_name', $companyName)
                    ->where('store_id', '>', 0)
                    ->column('store_id');

                $sameEntityWallets = Db::name('qc_share_wallet')
                    ->alias('qcw')
                    ->join('company c', 'c.company_name = ' . Db::raw("'{$companyName}'"))
                    ->where('qcw.bind_store_id', '>', 0)
                    ->column('qcw.bind_store_id');

                // 合并绑定情况
                $boundStoreIds = array_merge($sameEntityCompanies, $sameEntityWallets);
                $boundStoreIds = array_unique($boundStoreIds);

                // 检查同主体下是否有账户已绑定
                $hasBoundInEntity = !empty($boundStoreIds);
                $entityStoreId = $hasBoundInEntity ? $boundStoreIds[0] : 0;

                // 第一次绑定需要到web后台
                if (!$hasBoundInEntity) {
                    $bindResult['wallet_first_bind'][] = $subWalletId . '(主体:' . $companyName . ')';
                    continue;
                }

                // 检查是否绑定到相同主体的商户
                if ($entityStoreId != $store['id']) {
                    $boundStore = Db::name('store')->where('id', $entityStoreId)->value('username');
                    $bindResult['wallet_not_same_entity'][] = $subWalletId . '(主体已绑定商户:' . $boundStore . ')';
                    continue;
                }

                // 绑定到qc_share_wallet表
                $updateData = [
                    'bind_store_id' => $store['id'],
                    'sub_wallet_type' => $accountType ? $accountType : $walletInfo['sub_wallet_type'],
                    'discount_percentage' => $discountPercent
                ];

                $result = Db::name('qc_share_wallet')->where('id', $walletInfo['id'])->update($updateData);
                if ($result !== false) {
                    $bindResult['wallet_success'][] = $subWalletId;
                } else {
                    $bindResult['wallet_failed'][] = $subWalletId . '(更新失败)';
                }
            }
        }

        // 构建返回结果
        $output = "千川绑定商户：\n";
        $output .= "[商户]{$store['username']}(ID:{$store['id']})\n";
        $output .= "[返点]{$discountPercent}%\n";
        if ($accountType) {
            $typeLabel = $accountType == 1 ? '对公' : '对私';
            $output .= "[账户类型]{$typeLabel}\n";
        }
        $output .= "\n";

        // 绑定结果 - 广告账户
        if (!empty($advertiserIds)) {
            $output .= "[广告账户绑定结果]\n";
            if (!empty($bindResult['advertiser_success'])) {
                $output .= "[成功] " . count($bindResult['advertiser_success']) . "个：\n";
                foreach ($bindResult['advertiser_success'] as $id) {
                    $output .= "   - {$id}\n";
                }
                $output .= "\n";
            }

            if (!empty($bindResult['advertiser_already_bound'])) {
                $output .= "[已绑定] " . count($bindResult['advertiser_already_bound']) . "个：\n";
                foreach ($bindResult['advertiser_already_bound'] as $item) {
                    $output .= "   - {$item}\n";
                }
                $output .= "\n";
            }

            if (!empty($bindResult['advertiser_first_bind'])) {
                $output .= "[首次绑定需到web后台] " . count($bindResult['advertiser_first_bind']) . "个：\n";
                foreach ($bindResult['advertiser_first_bind'] as $item) {
                    $output .= "   - {$item}\n";
                }
                $output .= "\n";
            }

            if (!empty($bindResult['advertiser_not_same_entity'])) {
                $output .= "[主体已绑定其他商户] " . count($bindResult['advertiser_not_same_entity']) . "个：\n";
                foreach ($bindResult['advertiser_not_same_entity'] as $item) {
                    $output .= "   - {$item}\n";
                }
                $output .= "\n";
            }

            if (!empty($bindResult['advertiser_not_exist'])) {
                $output .= "[账户不存在] " . count($bindResult['advertiser_not_exist']) . "个：\n";
                foreach ($bindResult['advertiser_not_exist'] as $item) {
                    $output .= "   - {$item}\n";
                }
                $output .= "\n";
            }

            if (!empty($bindResult['advertiser_failed'])) {
                $output .= "[绑定失败] " . count($bindResult['advertiser_failed']) . "个：\n";
                foreach ($bindResult['advertiser_failed'] as $item) {
                    $output .= "   - {$item}\n";
                }
                $output .= "\n";
            }
        }

        // 绑定结果 - 子钱包
        if (!empty($subWalletIds)) {
            $output .= "[子钱包绑定结果]\n";
            if (!empty($bindResult['wallet_success'])) {
                $output .= "[成功] " . count($bindResult['wallet_success']) . "个：\n";
                foreach ($bindResult['wallet_success'] as $id) {
                    $output .= "   - {$id}\n";
                }
                $output .= "\n";
            }

            if (!empty($bindResult['wallet_already_bound'])) {
                $output .= "[已绑定] " . count($bindResult['wallet_already_bound']) . "个：\n";
                foreach ($bindResult['wallet_already_bound'] as $item) {
                    $output .= "   - {$item}\n";
                }
                $output .= "\n";
            }

            if (!empty($bindResult['wallet_first_bind'])) {
                $output .= "[首次绑定需到web后台] " . count($bindResult['wallet_first_bind']) . "个：\n";
                foreach ($bindResult['wallet_first_bind'] as $item) {
                    $output .= "   - {$item}\n";
                }
                $output .= "\n";
            }

            if (!empty($bindResult['wallet_not_same_entity'])) {
                $output .= "[主体已绑定其他商户] " . count($bindResult['wallet_not_same_entity']) . "个：\n";
                foreach ($bindResult['wallet_not_same_entity'] as $item) {
                    $output .= "   - {$item}\n";
                }
                $output .= "\n";
            }

            if (!empty($bindResult['wallet_not_exist'])) {
                $output .= "[子钱包不存在] " . count($bindResult['wallet_not_exist']) . "个：\n";
                foreach ($bindResult['wallet_not_exist'] as $item) {
                    $output .= "   - {$item}\n";
                }
                $output .= "\n";
            }

            if (!empty($bindResult['wallet_failed'])) {
                $output .= "[绑定失败] " . count($bindResult['wallet_failed']) . "个：\n";
                foreach ($bindResult['wallet_failed'] as $item) {
                    $output .= "   - {$item}\n";
                }
                $output .= "\n";
            }
        }

        return $output;
    }
}
