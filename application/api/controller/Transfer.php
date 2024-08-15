<?php
namespace app\api\controller;


use app\common\controller\Api;
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
                        if (!Db::name("money_log")->insert($money_log)){
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
    
    
    public function test(){
        $a = zh_Api::zh_NTDMAADD();
        $a = json_decode($a,TRUE);
        var_dump($a);
    }
}