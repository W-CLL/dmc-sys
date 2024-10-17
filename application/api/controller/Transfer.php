<?php
namespace app\api\controller;


use app\common\controller\Api;
use app\store\model\StoreMoneyLog;
use jlqc\FundManagement;
use think\Cache;
use think\Db;


class Transfer extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';

    //检查转账中状态的转账记录并更新
    public function transfer_records_save(){
        $transfer_records_data = Db::name("transfer_records")->where("status",4)->select();
        if (empty($transfer_records_data)){
            return "暂无更新";
        }
        $advertiser_id = Db::name("qc_config")->where("id",1)->value("advertiser_id");
        $access_token = Cache::get("qc_access_token");
        foreach ($transfer_records_data as $k=>$v){

            $transfer_detail_data = FundManagement::transfer_detail($access_token,$v['id'],$advertiser_id,$v['transfer_serial']);
            if (isset($transfer_detail_data['code']) && isset($transfer_detail_data['message']) && $transfer_detail_data['code'] == 0 && $transfer_detail_data['message'] == "OK"){
                if ($transfer_detail_data['data']['transfer_status'] === 'TRANSFER_SUCCESS'){
                    //转账成功
                    Db::startTrans();
                    try{
                        $money_log = [
                            "company_id" => $v['company_id'],
                            "advertiser_id" => $v['advertiser_id'],
                            "transfer_records_id" => $v['id'],
                            "money" => $v['money'],
                            "account_type" => $transfer_records_data['account_type'],
                            //记录到数据库
                            "rebate" => $transfer_records_data["rebate"],
                            "discount_percentage" => $transfer_records_data['discount_percentage'],
                            "create_time" => time()
                        ];
                        if ($v['transfer_direction'] == 1){
                            if (!Db::name("company")->where(["id"=>["=",$v['company_id']],"money"=>[">=",$v['money']]])->setDec("money",$v['money'])){
                                throw new \Exception('转账成功，平台扣款失败');
                            }
                            $money_log['type'] = 4;
                            $money_log['explain'] = "转入千川".$v['money']."元";
                        }else{
                            if (!Db::name("company")->where(["id"=>["=",$v['company_id']]])->setInc("money",$v['money'])){
                                throw new \Exception('转账成功，平台打款失败');
                            }
                            $money_log['type'] = 5;
                            $money_log['explain'] = "千川转出".$v['money']."元";
                        }
                        $storeMoneyLogModel =new StoreMoneyLog();
                        $logId = $storeMoneyLogModel->insertGetId($money_log);
                        if (!$logId){
                            throw new \Exception('转账成功，资金记录写入失败');
                        }
                        if (!Db::name("transfer_records")->where(["id"=>$v['id']])->update(['status'=>1])){
                            throw new \Exception('转账成功，状态更新失败');
                        }

                        Db::commit();
                    } catch (\Exception $e) {
                        Db::rollback();
                        Db::name("transfer_records")->where(["id"=>$v['id']])->update(['status'=>6,'explain'=>$e->getMessage()]);
                    }
                }else if ($transfer_detail_data['data']['transfer_status'] == 'NO_TRANSFER'){
                    //未转账
                    Db::name("transfer_records")->where(["id"=>$v['id']])->update(['status'=>3]);

                }else if ($transfer_detail_data['data']['transfer_status'] == 'TRANSFER_ING'){
                    //转账中
                    Db::name("transfer_records")->where(["id"=>$v['id']])->update(['status'=>4]);
                }else if ($transfer_detail_data['data']['transfer_status'] == 'TRANSFER_FAILED'){
                    Db::name("transfer_records")->where(["id"=>$v['id']])->update(['status'=>2,'explain'=>$transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason']]);
                }
            }
        }
        return "更新成功,本次更新".count($transfer_records_data)."条数据";
    }


    // 更新子钱包列表
    public function check_sub_wallet_list(){
        $token = Cache::get("qc_access_token");
        $account_id = Db::name("qc_config")->where("id",1)->value("advertiser_id");
        $account_type = 'AGENT';
        $data = FundManagement::get_wallet_info($token,$account_id,$account_type);
        $info = Db::name('qc_share_wallet')->where(['id'=>[">",0]])->field('sub_wallet_id')->select();
        $out_list = array_diff(array_column($info,'sub_wallet_id'),$data['data']['sub_wallet_ids']);
        $new_list = array_diff($data['data']['sub_wallet_ids'],array_column($info,'sub_wallet_id'));
        if(!empty($out_list)){
            Db::name('qc_share_wallet')->where('sub_wallet_id','in',$out_list)->delete();
        }
        if(!empty($new_list)){
            foreach (array_values($new_list) as $v){
                $ins[] = [
                    'sub_wallet_id'=> $v,
                    'main_wallet_id' => $data['data']['main_wallet_id'],
                ];
            }
            Db::name('qc_share_wallet')->insertAll($ins);
        }
    }


    // 更新子钱包转账记录
    public function update_sub_wallet_transfer_log(){
        $token = Cache::get("qc_access_token");
        $account_id = Db::name("qc_config")->where("id",1)->value("advertiser_id");
        $account_type = 'AGENT';
        $biz_request_no = generate_random_string(10,true);
        $list = Db::name('share_wallet_transfer_log')
            ->where(['status' => ['=',0],'transfer_serial' =>['neq','']])
            ->select();
        Db::startTrans();
        try{
            foreach ($list as $v){
                $data = FundManagement::check_transfer_detail($token,$account_id,$account_type,$biz_request_no,$v['transfer_serial']);
                if(!isset($data['data']['transfer_status'])){
                    \think\Log::write($data,'err');
                    continue;
                }
                if($data['data']['transfer_status'] == 'TRANSFER_FAILED'){
                    $update['status'] = 2;
                    $update['fail_reason'] = $data['data']['transfer_wallet_record_list'][0]['transfer_capital_record_list'][0]['fail_reason'];
                    $update['update_time'] = time();
                    // 退款
                    $refund_info = Db::name('store_money_log')->where(['swtl_id' => $v['id']])->find();
                    if($refund_info['account_type'] == 1){
                        $field = 'public_money';
                    }elseif ($refund_info['account_type'] == 2){
                        $field = 'private_money';
                    }
                    if(!Db::name('store')->where('id',$refund_info['store_id'])->setInc($field,$refund_info['actual_money'])){
                        throw new \Exception('退款失败');
                    }
                }
                elseif ($data['data']['transfer_status'] == 'TRANSFER_SUCCESS'){
                    $update['status'] = 1;
                    $update['update_time'] = time();
                }
                if(!Db::name('share_wallet_transfer_log')->where('id',$v['id'])->update($update)){
                    throw new \Exception('更新失败');
                }
                Db::commit();
            }
        }catch(\Exception $e){
            \think\Log::write($e->getMessage(),'Exception');
            Db::rollback();
        }
    }
}