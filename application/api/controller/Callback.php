<?php

namespace app\api\controller;

use app\common\controller\Api;
use Mdanter\Ecc\Crypto\Signature\Signature;
use Mdanter\Ecc\Serializer\Signature\DerSignatureSerializer;
use Rtgm\sm\RtSm2;
use think\log;
use think\Db;

class Callback extends Api
{
    protected $noNeedLogin = ['*'];
    protected $noNeedRight = ['*'];
    public function callback_test(){
//        $data = array (
//            'sigtim' => '20240813160306',
//            'sigdat' => 'JcaXjlL9ta8zaI4qJ4EeW+QVWeVsNWj/2IpCbXAi4ir514nC1ekPxJc7wwFsEpimdTYqHBOUi88aIrwfMB7+Mw==',
//            'notdat' => '{"msgdat":{"chknbr":" ","infflg":"2","refsub":"","refnbr":"C0146XR0000JPEZ","trscod":"CPUA","rpyacc":"755915671610302","gsbacc":" ","otrnar":" ","rpynam":"企业网银新20161103","amtcdr":"C","naryur":"测试2","vltdat":"20240813","yurref":"20240813155411","accnam":"企业网银新20161103","gsbnam":" ","narext":" ","trsanl":" ","nusage":" ","trsdat":"20240813","reqnbr":"6662996672","trstim":"160306","rpybnk":"招商银行深圳分行营业部","gsbbbk":" ","frmcod":"0000000121","athflg":"N","rpybbn":" ","rsvflg":"N","accnbr":"755915671610407","busnam":"支付","rpybbk":" ","c_trsamt":"1","c_ccynbr":"人民币","busnar":" ","blvamt":"903648757.66","rpyadr":"广东省深圳市"},"msgtyp":"NCCRTTRS"}',
//            'notkey' => '755915671610407',
//            'notnbr' => '245040309580595200',
//            'nottyp' => 'YQN01010',
//        );
        $data = input();
        Log::write($data,'datalog');
        // 验证签名是否正确
        $sign = $data["sigdat"];
        // 将数据中的签名重置
        $data["sigdat"] = "__signature_sigdat__";
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        $publicKey = unpack("H*", base64_decode('BNRhE10qHce4PRt8hCxAPfTmMDxW0Htw9SZHoUWn7U0Qj4GbU2Tgic4EmQSFjTcTdbDvNVmoSzwQvUkfzpRC9+k='))[1];
        $signHex = bin2hex(base64_decode($sign));
        $r = substr($signHex, 0, 64);
        $s = substr($signHex, 64, 64);
        $r = gmp_init($r, 16);
        $s = gmp_init($s, 16);
        $signature = new Signature($r, $s);
        $serializer = new DerSignatureSerializer();
        $serializedSig = $serializer->serialize($signature);
        $sign = base64_encode($serializedSig);
        $sm2 = new RtSm2("base64");
        $b = $sm2->verifySign($json, $sign, $publicKey, '1234567812345678');
        if ($b === true) {    // 验签
            $info = json_decode($data['notdat'],true);
            if($info['msgtyp'] == 'NCCRTTRS'){   // 判断是到款通知
                Log::write('yes','daokuan');
                $store_id = Db::name('zh_sub_account')->where(['settle_account'=>$info['msgdat']['accnbr'],'sub_account'=>$info['msgdat']['frmcod']])->value('store_id');
                if($store_id){    //  查询是否有绑定这个鬼子账户
                    Db::startTrans();
                    try {
                        $store_info = Db::name('store')->where(['id' => $store_id])->find();
                        if($store_info['public_spending_credit_limit'] > 0){
                            if($store_info['public_spending_credit_limit'] >= $info['msgdat']['c_trsamt']){
                                if(!Db::name('store')->where(['id' => $store_id])->setDec('public_spending_credit_limit',$info['msgdat']['c_trsamt'])){
                                    throw new \Exception('减少已使用额度失败');
                                }
                                if(!Db::name('store')->where(['id' => $store_id])->setInc('public_credit_limit',$info['msgdat']['c_trsamt'])){
                                    throw new \Exception('增加额度失败');
                                }
                                if(!Db::name('store_money_log')->insert([
                                    "store_id" => $store_info["id"],
                                    "username" => $store_info["username"],
                                    "money" => $info['msgdat']['c_trsamt'],
                                    "actual_money" => 0,
                                    "account_type" => 1,
                                    "deduction_credit_limit" => $info['msgdat']['c_trsamt'],
                                    "before_money" => $store_info['public_money'],
                                    "today_money" => $store_info['public_money'],
                                    "order_number" => $info['msgdat']['yurref'],
                                    "type" => 7,
                                    "explain" => '充值公账钱包'.$info['msgdat']['c_trsamt'].'元，已使用公账授信额度'.$store_info['public_spending_credit_limit'].'元,扣除'.$info['msgdat']['c_trsamt'].'元，实际到账0元',
                                    "create_time" => time()
                                ])){
                                    throw new \Exception('新增记录失败');
                                }
                            }else{
                                $inc_money = $info['msgdat']['c_trsamt'] - $store_info['public_spending_credit_limit'];
                                if(!Db::name('store')->where(['id' => $store_id])->update(['public_spending_credit_limit' => 0,'public_credit_limit' => $store_info['public_credit_limit'] + $store_info['public_spending_credit_limit']])){
                                    throw new \Exception('更新数据失败');
                                }
                                if(!Db::name('store')->where(['id' => $store_id])->setInc('public_money',$inc_money)){
                                    throw new \Exception('增加余额失败');
                                }
                                if(!Db::name('store_money_log')->insert([
                                    "store_id" => $store_info["id"],
                                    "username" => $store_info["username"],
                                    "money" => $info['msgdat']['c_trsamt'],
                                    "actual_money" => 0,
                                    "account_type" => 1,
                                    "deduction_credit_limit" => $store_info['public_spending_credit_limit'],
                                    "before_money" => $store_info['public_money'],
                                    "today_money" => $store_info['public_money'] + $inc_money,
                                    "order_number" => $info['msgdat']['yurref'],
                                    "type" => 7,
                                    "explain" => '充值公账钱包'.$info['msgdat']['c_trsamt'].'元，已使用公账授信额度'.$store_info['public_spending_credit_limit'].'元,扣除'.$store_info['public_spending_credit_limit'].'元，实际到账'.$inc_money.'元',
                                    "create_time" => time()
                                ])){
                                    throw new \Exception('新增记录失败');
                                }
                            }
                        }else{
                            if(!Db::name('store')->where(['id' => $store_id])->setInc('public_money',$info['msgdat']['c_trsamt'])){
                                throw new \Exception('增加余额失败');
                            }
                            if(!Db::name('store_money_log')->insert([
                                "store_id" => $store_info["id"],
                                "username" => $store_info["username"],
                                "money" => $info['msgdat']['c_trsamt'],
                                "actual_money" => 0,
                                "account_type" => 1,
                                "deduction_credit_limit" => 0,
                                "before_money" => $store_info['public_money'],
                                "today_money" => $store_info['public_money'] + $info['msgdat']['c_trsamt'],
                                "order_number" => $info['msgdat']['yurref'],
                                "type" => 7,
                                "explain" => '充值公账钱包'.$info['msgdat']['c_trsamt'].'元',
                                "create_time" => time()
                            ])){
                                throw new \Exception('新增记录失败');
                            }
                        }
                        Db::commit();
                    }catch (\Exception $e) {
                        Db::rollback();
                        Log::write($e,'err');
                    }
                }
            }
        }
    }




}