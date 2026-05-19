<?php

namespace app\job;

use think\Env;
use txgg\Fund;
use think\Cache;
use think\Db;
use think\Exception;
use think\queue\Job;
use Requests;
use function fast\e;

class checkTencentFund
{

    public function fire(Job $job, $data)
    {
        try {
            $isJobDone = $this->doJob($data);
            if ($isJobDone) {
                $job->delete();
            } else {
                if ($job->attempts() > 3) {
                    $job->delete();
                }
            }
        } catch (Exception $e) {
            $job->delete();
        }
    }


    public function doJob($data)
    {
        $warning_value = Db::name('wechat_group')->where("group_id", $data['group_id'])->value("tx_warning");
        switch ($data['type']){
            case 1:
                foreach ($data['account_id_list'] as $account_id){
                    $params = [
                        'account_id' => (int)$account_id,
                    ];
                    $res = Fund::getFundAccountInfo($params)['data'];
                    $balance = 0;
                    if($res['code'] == 0){
                        foreach ($res['data']['list'] as $item){
                            $balance += $item['balance'];
                        }

                        if($balance <= $warning_value * 100){
                            $is_send = Cache::get('send_warning_msg_'.$account_id);
                            if(!$is_send){
                                if (!isset($msg)){
                                    $msg = "⚠ 注 意 ⚠：\n 下列【子客账户】余额💰不足，请及时充值！\n 🔔". $account_id . "，💰总余额：" . number_format($balance / 100, 2) . "元";
                                }else{
                                    $msg .= "\n 🔔". $account_id . "，💰总余额：" . number_format($balance / 100, 2) . "元";
                                }
                                Cache::set('send_warning_msg_'.$account_id,1,1);
                            }
                        }
                    }
                }
                break;
            case 2:
                foreach ($data['sub_wallet_id_list'] as $wallet_id){
                    $params = [
                        'account_id' => Env::get('txgg.agency_1'),
                        'wallet_id' => (int)$wallet_id
                    ];
                    $res = Fund::getWalletBasicInfo($params)['data'];
                    $balance = 0;
                    if($res['code'] == 0){
                        foreach ($res['data']['wallet_info']['balance_info_list'] as $item){
                            $balance += $item['balance'];
                        }
                        if($balance <= $warning_value){
                            $is_send = Cache::get('send_warning_msg_'.$wallet_id);
                            if(!$is_send){
                                if (!isset($msg)){
                                    $msg = "⚠ 注 意 ⚠：\n 下列【腾讯钱包】余额💰不足，请及时充值！\n 🔔". $wallet_id . "，💰总余额：" . $balance . "元";
                                }else{
                                    $msg .= "\n 🔔". $wallet_id . "，💰总余额：" . $balance . "元";
                                }
                                Cache::set('send_warning_msg_'.$wallet_id,1,1);
                            }
                        }
                    }
                }

                break;
        }
        if (isset($msg)) {
            return $this->sendMsg($msg, $data);
        }
        return true;
    }


    private function sendMsg($msg, $data): bool
    {
        $url_list = [
//            'http://test.frp.zebranumber.cn/add_task',   // 测试
            'http://robot1.frp.zebranumber.cn/add_task',
            'http://robot2.frp.zebranumber.cn/add_task',
        ];
        do{
            if (empty($url_list)){
                return false;
            }
            $url = $url_list[array_rand($url_list)];
            $params = [
                "group_wxid" => $data['group_id'],
                "sender_name" => "企微外部联系人",  // 不需@人
                "message" => ['msg'=>$msg],
                "msg_wxid" => "",
            ];
            $res = Requests::post($url, json_encode($params, JSON_UNESCAPED_UNICODE), array(
                'Content-Type:' . 'application/json'
            ));
            if ($res == 'ok'){
                $bool = false;
            }else{
                $bool = true;
                unset($url_list[array_search($url, $url_list)]);
            }
        }while($bool);
        return true;
    }



}