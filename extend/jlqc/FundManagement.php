<?php

namespace jlqc;

use Requests;
Class FundManagement{

    public static $auth_return_code = [
        '40102',//access_token已过期
        '40103',//refresh_token已过期
        '40104',//The access_token is empty.
        '40107',//refresh_token无效，请传入最新的refresh_token
        '40115'//授权码无效
    ];

    //获取账户余额
    public static function account_balance($access_token,$advertiser_id){
        $url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/account/balance/get/?advertiser_id=".$advertiser_id;
        $header = array(
            'Access-Token:'. $access_token,
        );
        return Requests::get($url,$header);
    }

    public static function account_balance_wallet($access_token,$advertiser_id){
        $url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/finance/wallet/get/?advertiser_id=".$advertiser_id;
        $header = array(
            'Access-Token:'. $access_token,
        );
        return Requests::get($url,$header);
    }

    //获取财务流水信息
    public static function finance($access_token,$advertiser_id,$start_date,$end_date,$page,$page_size){
        $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/finance/detail/get/?advertiser_id=".$advertiser_id."&start_date=".$start_date."&end_date=".$end_date."&page=".$page."&page_size=".$page_size;
        $header = array(
            'Access-Token:'. $access_token,
        );
        return Requests::get($url,$header);
    }

    //查询财务流水明细
    public static function fund_transaction($access_token,$advertiser_id,$start_date,$end_date,$page,$page_size,$transaction_type){
        if ($transaction_type == 1){
            //充值
            $transaction_type = "RECHARGE";
        }else{
            //转账
            $transaction_type = "TRANSFER";
        }
        $url = "https://api.oceanengine.com/open_api/2/advertiser/fund/transaction/get/?advertiser_id=".$advertiser_id."&start_date=".$start_date."&end_date=".$end_date."&page=".$page."&page_size=".$page_size."&transaction_type=".$transaction_type;
        $header = array(
            'Access-Token:'. $access_token,
        );
        return Requests::get($url,$header);
    }

    //创建转账交易号（方舟）
    public static function fund_create($access_token,$agent_id ,$account_id ,$transfer_type ,$amount){
        $url = "https://api.oceanengine.com/open_api/2/agent/fund/transfer_seq/create/";
        $header = array(
            'Access-Token:'. $access_token,
            'Content-Type:application/json',
        );
        $data = array(
            "agent_id"=>$agent_id,
            "account_id"=>$account_id,
            "transfer_type"=>$transfer_type,
            "amount"=>$amount,
        );
        return Requests::post($url,$data,$header);
    }

    //提交转账交易号（方舟）
    public static function fund_commit($access_token,$transfer_seq ,$agent_id ){
        $url = "https://api.oceanengine.com/open_api/2/agent/fund/transfer_seq/commit/";
        $header = array(
            'Access-Token:'. $access_token,
            'Content-Type:application/json',
        );
        $data = array(
            "agent_id"=>$agent_id,
            "transfer_seq"=>$transfer_seq,
        );
        return Requests::post($url,$data,$header);
    }

    //创建退款交易号（方舟）
    public static function refund_create($access_token,$agent_id ,$account_id ,$transfer_type ,$amount){
        $url = "https://api.oceanengine.com/open_api/2/agent/refund/transfer_seq/create/";
        $header = array(
            'Access-Token:'. $access_token,
            'Content-Type:application/json',
        );
        $data = array(
            "agent_id"=>$agent_id,
            "account_id"=>$account_id,
            "transfer_type"=>$transfer_type,
            "amount"=>$amount,
        );
        return Requests::post($url,$data,$header);
    }

    //提交退款交易号（方舟）
    public static function refund_commit($access_token,$agent_id ,$refund_seq ){
        $url = "https://api.oceanengine.com/open_api/2/agent/refund/transfer_seq/commit/";
        $header = array(
            'Access-Token:'. $access_token,
            'Content-Type:application/json',
        );
        $data = array(
            "agent_id"=>$agent_id,
            "refund_seq"=>$refund_seq,
        );
        return Requests::post($url,$data,$header);
    }

    //获取最大可转余额
//    文档地址：https://open.oceanengine.com/labels/7/docs/1789754975045699
    public static function can_transfer_balance($access_token,$biz_request_no,$agent_id,$account_id,$target_account_id_list,$transfer_direction){

        $header = array(
            'Access-Token:'. $access_token
        );
        if ($transfer_direction == 1){
            //转入
            $transfer_direction = "TRANSFER_IN";
        }else{
            //转出
            $transfer_direction = "TRANSFER_OUT";
        }
        $url = "https://api.oceanengine.com/open_api/v3.0/cg_transfer/query_can_transfer_balance/?biz_request_no=".$biz_request_no."&agent_id=".$agent_id."&account_id=".$account_id."&target_account_id_list=".$target_account_id_list."&transfer_direction=".$transfer_direction;
        return Requests::get($url,$header);
    }

    //发起转账
//    文档地址：https://open.oceanengine.com/labels/7/docs/1789755060558916
    public static function create_transfer($access_token,$biz_request_no,$agent_id,$account_id ,$target_account_detail_list ,$transfer_direction,$remark){
        $url = "https://api.oceanengine.com/open_api/v3.0/cg_transfer/create_transfer/";
        $header = array(
            'Access-Token:'. $access_token,
            'Content-Type:application/json',
        );
        $data = array(
            "biz_request_no" => strval($biz_request_no),
            "agent_id"=>(int)$agent_id,
            "account_id" => (int)$account_id,
            "target_account_detail_list"=>$target_account_detail_list,
            "transfer_direction"=>$transfer_direction,
            "remark"=>$remark,
        );
        return Requests::post($url,json_encode($data,JSON_UNESCAPED_UNICODE),$header);
    }

    //查询转账单信息
    //文档地址：https://open.oceanengine.com/labels/7/docs/1789755120706634
    public static function transfer_detail($access_token,$biz_request_no,$agent_id,$transfer_serial){

        $header = array(
            'Access-Token:'. $access_token
        );

        $url = "https://api.oceanengine.com/open_api/v3.0/cg_transfer/query_transfer_detail/?biz_request_no=".$biz_request_no."&agent_id=".$agent_id."&transfer_serial=".$transfer_serial;
        return Requests::get($url,$header);
    }

    //查询账户转账余额
//    文档地址：https://open.oceanengine.com/labels/7/docs/1789754859486282
    public static function transfer_balance($access_token,$biz_request_no,$agent_id,$account_id_list){

        $header = array(
            'Access-Token:'. $access_token
        );
        $url = "https://api.oceanengine.com/open_api/v3.0/cg_transfer/query_transfer_balance/?biz_request_no=".$biz_request_no."&agent_id=".$agent_id."&account_id_list=".$account_id_list;
        return Requests::get($url,$header);
    }

    // 获取钱包id信息
    // https://open.oceanengine.com/labels/7/docs/1798907322782729?origin=left_nav
    public static function get_wallet_info($access_token,$account_id,$account_type){

        $header = array(
            'Access-Token:'. $access_token
        );
        $url = "https://api.oceanengine.com/open_api/v3.0/shared_wallet/account_relation/get/?account_id=".$account_id."&account_type=".$account_type;
        return Requests::get($url,$header);
    }

    // 获取钱包信息列表
    // https://open.oceanengine.com/labels/7/docs/1798465839055872?origin=left_nav
    public static function get_wallet_info_list($access_token,$account_id,$wallet_id_list,$account_type){

        $header = array(
            'Access-Token:'. $access_token
        );
        $url = "https://api.oceanengine.com/open_api/v3.0/shared_wallet/wallet_info/get/?account_id=".$account_id."&wallet_id_list=".$wallet_id_list."&account_type=".$account_type;
        return Requests::get($url,$header);
    }

    // 获取最大可转余额
    // https://open.oceanengine.com/labels/7/docs/1799667820221452?origin=left_nav
    public static function get_max_transfer($access_token,$account_id,$account_type,$biz_request_no,$main_wallet_id,$sub_wallet_list,$transfer_direction){
        $header = array(
            'Access-Token:'. $access_token
        );
        $url = "https://api.oceanengine.com/open_api/v3.0/cg_transfer/wallet/transfer/can_transfer_balance/?account_id=".$account_id."&account_type=".$account_type."&biz_request_no=".$biz_request_no."&main_wallet_id=".$main_wallet_id."&sub_wallet_list=".$sub_wallet_list."&transfer_direction=".$transfer_direction;
        return Requests::get($url,$header);
    }


    // 共享钱包转账
    // https://open.oceanengine.com/labels/7/docs/1799669807408128?origin=left_nav
    public static function wallet_transfer($access_token,$account_id,$account_type,$biz_request_no,$main_wallet_id ,$target_wallet_detail_list,$transfer_direction,$remark){
        $url = "https://api.oceanengine.com/open_api/v3.0/cg_transfer/wallet/transfer/create/";
        $header = array(
            'Access-Token:'. $access_token,
            'Content-Type:application/json',
        );
        $data = array(
            "account_id" => (int)$account_id,
            "account_type"=>strval($account_type),
            "biz_request_no" => strval($biz_request_no),
            "main_wallet_id"=>(int)$main_wallet_id,
            "target_wallet_detail_list"=>$target_wallet_detail_list,
            "transfer_direction"=>$transfer_direction,
            "remark"=>$remark,
        );
        return Requests::post($url,json_encode($data,JSON_UNESCAPED_UNICODE),$header);
    }

}







