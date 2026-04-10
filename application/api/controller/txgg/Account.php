<?php

namespace app\api\controller\txgg;

use think\Env;
use txgg\AdvInfo;
use app\common\model\txgg\TencentAccount;

class Account
{
    /**
     * 账号状态
     */
    private $system_status = [
        'CUSTOMER_STATUS_NORMAL'                => 1,           // 有效
        'CUSTOMER_STATUS_PENDING'               => 2,           // 待审核
        'CUSTOMER_STATUS_DENIED'                => 3,           // 审核不通过
        'CUSTOMER_STATUS_FROZEN'                => 4,           // 封禁
        'CUSTOMER_STATUS_TOBE_ACCEPTED'         => 5,           // 待接受
        'CUSTOMER_STATUS_TOBE_ACTIVATED'        => 6,           // 待激活
        'CUSTOMER_STATUS_SUSPEND'               => 7,           // 暂停
        'CUSTOMER_STATUS_MATERIAL_PREPARED'     => 8,           // 广告主资料准备
        'CUSTOMER_STATUS_DELETED'               => 9,           // 删除
        'CUSTOMER_STATUS_FROZEN_TEMPORARILY'    => 10,          // 临时冻结
        'CUSTOMER_STATUS_UNREGISTERED'          => 11,          // 未注册
    ];


    /**
     * 获取腾讯广告子客账号
     * @return void
     */
    public function getAccount($agency = 1)
    {
        $total = [];
        $cursor = ''; // 初始化游标
        $retryCount = 0;
        $maxRetries = 3;
        $model = new TencentAccount();
        $agency_id = Env::get('txgg.agency_'.$agency);
        do {
            $res = AdvInfo::getAdvInfo(array (
                'agency_id' => (int)$agency_id,
                'fields' => json_encode([
                    "account_id",
                    "mdm_name",
                    "system_status",
                    "reject_message",
                    "operators",
                    "agency_account_id",
                ], JSON_UNESCAPED_UNICODE),
                'pagination_mode' => 'PAGINATION_MODE_CURSOR',
                'cursor' => $cursor,
                'page_size' => 100,
            ))['data'];
            if ($res['code'] == 0) {
                // 收集数据
                $total = array_merge($total, $res['data']['list']);
                // 更新游标
                $cursor = $res['data']['cursor_page_info']['cursor'] ?? '';
                // 检查是否还有更多数据
                $hasMore = $res['data']['cursor_page_info']['has_more'];
                // 重置计数器
                $retryCount = 0;
            } else {
                $retryCount++;
                if ($retryCount > $maxRetries) {
                    break;
                }
                sleep(1);
            }
        } while ($hasMore); // 当还有更多数据时继续循环
        // 获取已绑定的账号ID与状态
        $idBindAccount = $model->where('id','>',0)->column('id, status, name, store_id, account_type','account_id');
        $array = $model->where('store_id','neq', 0)->where('name','neq', '')->group('name')->column('store_id, account_type','name');
        $update = [];
        $insert = [];
        // 处理数据
        foreach ($total as $item){
            if (!isset($idBindAccount[$item['account_id']])){
                if(in_array($this->system_status[$item['system_status']],[1,4])){ // 只获取账号有效and封禁状态的
                    $insert[] = [
                        'account_id' => $item['account_id'],
                        'name' => $item['mdm_name'],
                        'status' => $this->system_status[$item['system_status']],
                        'agency_account_id' => $item['agency_account_id'],
                        'store_id' => $array[$item['mdm_name']]['store_id'] ?? 0,
                        'account_type' => $array[$item['mdm_name']]['account_type'] ?? 1,
                        'agency' => $agency
                    ];
                }
            }
            if (isset($idBindAccount[$item['account_id']]) && ($idBindAccount[$item['account_id']]['status'] != $this->system_status[$item['system_status']] || $idBindAccount[$item['account_id']]['name'] != $item['mdm_name'])){
                $update[] = [
                    'id' => $idBindAccount[$item['account_id']]['id'],
                    'name' => $item['mdm_name'],
                    'status' => $this->system_status[$item['system_status']],
                    'store_id' => $array[$item['mdm_name']]['store_id'] ?? $idBindAccount[$item['account_id']]['store_id'],
                    'account_type' => $array[$item['mdm_name']]['account_type'] ?? $idBindAccount[$item['account_id']]['account_type'],
                    'agency' => $agency
                ];
            }
        }
        try {
            if ($insert){
                echo '新增数据条数：'.count($insert)."\n";
                $model->insertAll($insert);
            }
            if ($update){
                echo '更新数据条数：'.count($update)."\n";
                $model->saveAll($update);
            }
            echo '处理完成！';
        }catch (\Exception $e){
            echo $e->getMessage();
        }
    }

}