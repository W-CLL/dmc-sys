<?php

namespace app\api\controller\txgg;

use txgg\Fund;
use app\common\model\txgg\TencentShareWallet;

class Wallet
{
    public function getWallet(){
        $total = [];
        $update = [];
        $insert = [];
        $res = Fund::getShareWalletInfo([
            'account_id' => 64568612,
            'page' => 1,
            'page_size' => 100
        ])['data'];
        if ($res['code'] === 0){
            $total = array_merge($total, $res['data']['wallet_list']);
            $total_page = $res['data']['page_info']['total_page'];
            for ($i = 2; $i <= $total_page; $i++){
                $res = Fund::getShareWalletInfo([
                    'account_id' => 64568612,
                    'page' => $i,
                    'page_size' => 100
                ])['data'];
                if ($res['code'] !== 0){
                    echo '获取第'.$i.'页失败';
                    continue;
                }
                $total = array_merge($total, $res['data']['wallet_list']);
            }
        }
        $model = new TencentShareWallet();
        // 获取数据库中的数据
        $db_wallet_info = $model->column('id, sub_wallet_name, name', 'sub_wallet_id');
        // 处理数据
        foreach ($total as $item){
            // 处理需要插入的数据
            if (!isset($db_wallet_info[$item['wallet_id']])){
                $insert[] = [
                    'sub_wallet_id' => $item['wallet_id'],
                    'sub_wallet_name' => $item['wallet_name'],
                    'name' => $item['mdm_name']
                ];
            }
            // 处理需要更新的数据
            if (isset($db_wallet_info[$item['wallet_id']]) && ($db_wallet_info[$item['wallet_id']]['sub_wallet_name'] != $item['wallet_name'] || $db_wallet_info[$item['wallet_id']]['name'] != $item['mdm_name'])){
                $update[] = [
                    'id' => $db_wallet_info[$item['wallet_id']]['id'],
                    'sub_wallet_name' => $item['wallet_name'],
                    'name' => $item['mdm_name']
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