<?php

namespace app\api\controller;

use app\common\controller\Api;
use Mdanter\Ecc\Crypto\Signature\Signature;
use Mdanter\Ecc\Serializer\Signature\DerSignatureSerializer;
use Rtgm\sm\RtSm2;
use think\Log;
use think\Db;

class Callback extends Api
{
    protected $noNeedLogin = ['*'];
    protected $noNeedRight = ['*'];
    public function callback_test(){
//         $data = array (
//   'sigtim' => '20240828113707',
//   'sigdat' => 'aGra9QJy7zWbpn8INDB6ufMAGLL6Aii70G4gjC7ZRo8uklxaj32DH/EuiAK3GZU4T4qcVMOGxao3Tbu7Yv6uIg==',
//   'notdat' => '{"msgdat":{"chknbr":" ","infflg":"2","refsub":" ","refnbr":"C0146Y6000LM56Z","trscod":"NPT2","rpyacc":"8110901012601609428","gsbacc":" ","otrnar":" ","rpynam":"广州优布网络科技有限公司","amtcdr":"C","naryur":"服务费","vltdat":"20240828","yurref":" ","accnam":"广州斑马数字科技有限公司","gsbnam":" ","narext":" ","trsanl":" ","nusage":" ","trsdat":"20240828","reqnbr":" ","trstim":"112706","rpybnk":"中信银行","gsbbbk":" ","frmcod":"0000000001","athflg":"N","rpybbn":" ","rsvflg":"N","accnbr":"120926190210001","busnam":" ","rpybbk":" ","c_trsamt":"1000","c_ccynbr":"人民币","busnar":" ","blvamt":"2000","rpyadr":" "},"msgtyp":"NCCRTTRS"}',
//   'notkey' => '120926190210001',
//   'notnbr' => '247787888222928897',
//   'nottyp' => 'YQN01010',
// );
        $data = input();
        $data['notdat'] = html_entity_decode($data['notdat']);
        Log::write($data,'datalog');
        // 验证签名是否正确
        $sign = $data["sigdat"];
        // 将数据中的签名重置
        $data["sigdat"] = "__signature_sigdat__";
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        $publicKey = unpack("H*", base64_decode('BNJ1hbqyLRx6RkQqQH+NuppGHooDLcBXBNAqy8H75AU+HQAqUYinnbSo21YD/8SmC8kUJfWnHfcMiWuqEG/D/OQ='))[1];
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
                                    "order_number" => $info['msgdat']['refnbr'],
                                    "type" => 7,
                                    "explain" => '充值公账钱包'.$info['msgdat']['c_trsamt'].'元，已使用公账授信额度'.$store_info['public_spending_credit_limit'].'元,扣除'.$info['msgdat']['c_trsamt'].'元，实际到账0元',
                                    "create_time" => time(),
                                    "balance_surplus" => $store_info['public_money'],
                                    "credit_limit_surplus" => $store_info['public_credit_limit'] + $info['msgdat']['c_trsamt'],
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
                                    "create_time" => time(),
                                    "balance_surplus" => $store_info['public_money'] + $inc_money,
                                    "credit_limit_surplus" => $store_info['public_credit_limit'] + $store_info['public_spending_credit_limit'],
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
                                "create_time" => time(),
                                "balance_surplus" => $store_info['public_money'] + $info['msgdat']['c_trsamt'],
                                "credit_limit_surplus" => $store_info['public_credit_limit'],
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