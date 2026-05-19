<?php

namespace app\api\controller\txgg;

use app\common\model\Queue;
use think\Db;

class Sundry
{
    public function getTencentFund($type = 1){
        // 设置免扰时间段，如果处于每天1-6点直接，则跳过
        $time = time();
        if ($time >= strtotime("today 01:00:00") && $time < strtotime("today 06:00:00")){
            echo "免扰时间段";
            die;
        }
        $array = [];
        $user_list = Db::name('wechat_group')->where(['power' => ['like', '%4%']])->field('bind_store_id,power,group_id')->select();
        if (empty($user_list)){
            echo "无操作用户";
            die;
        }
        switch ($type){
            case 1:
                foreach ($user_list as $item){
                    $account_list = Db::name('tencent_account')->where(['store_id' => $item['bind_store_id'],'status' => 1])->field('account_id')->column('account_id');
                    $array[$item['group_id']] = $account_list;
                }
                foreach ($array as $group_id => $account_id_list){
                    $chunk = array_chunk($account_id_list, 10);
                    foreach ($chunk as $item){
                        $data = [
                            'account_id_list' => $item,
                            'group_id' => $group_id,
                            'type' => $type
                        ];
                        \think\Queue::push('app\job\checkTencentFund', $data, "checkTencentFund");
                    }
                }
                break;
            case 2:
                foreach ($user_list as $item){
                    $wallet_list = Db::name('tencent_share_wallet')->where(['store_id' => $item['bind_store_id']])->field('sub_wallet_id')->column('sub_wallet_id');
                    $array[$item['group_id']] = $wallet_list;
                }
                foreach ($array as $group_id => $sub_wallet_id_list){
                    $chunk = array_chunk($sub_wallet_id_list, 10);
                    foreach ($chunk as $item){
                        $data = [
                            'sub_wallet_id_list' => $item,
                            'group_id' => $group_id,
                            'type' => $type
                        ];
                        \think\Queue::push('app\job\checkTencentFund', $data, "checkTencentFund");
                    }
                }
        }
        echo "添加任务成功";
    }

}