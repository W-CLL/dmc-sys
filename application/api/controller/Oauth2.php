<?php
namespace app\api\controller;


use app\common\controller\Api;
use fast\Random;
use jlqc\AccountRelationship;
use jlqc\FundManagement;
use jlqc\UserInfo;
use Requests;
use think\Cache;
use think\Db;


class Oauth2 extends Api {

    public function aa(){

        \qywx\Api::media_upload(ROOT_PATH . "public/receipt/20240624/d65cad0a209eccbce02c0f39d24868ae.jpg" );
    }

    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';

    //获取千川授权
    public function auth_code_callback(){
        $auth_code = input("auth_code");
        $state = input("state");
        if (!empty($auth_code)){
            $data = Db::name("qc_config")->where("id",1)->find();
            $data = \jlqc\AccessToken::get_access_token($data['app_id'],$data['secret'],$auth_code);
            if ($data["code"] == 0 && $data["message"] === "OK"){
                Cache::set("qc_access_token",$data['data']['access_token'],0);
                Cache::set("qc_refresh_token",$data['data']['refresh_token'],0);
                return "授权成功";
            }
            return "授权失败,err:".json_encode($data,JSON_UNESCAPED_UNICODE);
        }
    }

    //刷新千川授权，12小时一次
    public function access_token_save(){
        $data = Db::name("qc_config")->where("id",1)->find();
        $data = \jlqc\AccessToken::refresh_token_save($data['app_id'],$data['secret']);
        if ($data['code'] == 0 && $data['message'] == "OK"){
            Cache::set("qc_access_token",$data['data']['access_token'],0);
            Cache::set("qc_refresh_token",$data['data']['refresh_token'],0);
            return "刷新授权状态成功";
        }
        return "刷新失败,err:".json_encode($data,JSON_UNESCAPED_UNICODE);
    }

    //获取百度ai AccessToken 30天获取一次
    public function baidu_get_access_token(){
        $data = Db::name("qc_config")->where("id",2)->find();
        $data = \baidu\AccessToken::get_access_token($data['api_key'],$data['secret']);
        if (isset($data['access_token'])){
            Cache::set("baidu_access_token",$data['access_token'],0);
            return "获取百度授权成功";
        }
        return "获取百度授权失败";
    }


    //获取千川所有绑定账户并写入
    public function synchronous1(){
        $config_data = Db::name("qc_config")->where("id",1)->find();
        $access_token = Cache::get("qc_access_token");

        $data = [];
        $advertiser_data = AccountRelationship::advertiser_select($access_token,$config_data['advertiser_id'],1,100);
//        var_dump($advertiser_data['data']['advertiser_ids']);die();
        $total_page = $advertiser_data['data']['page_info']['total_page'];
        $public_info_data = UserInfo::public_info($access_token, json_encode($advertiser_data['data']['advertiser_ids']));

        for ($i=1;$i <= $total_page;$i++){
            if ($i>1){
                $advertiser_data = AccountRelationship::advertiser_select($access_token,$config_data['advertiser_id'],$i,100);
                $public_info_data = UserInfo::public_info($access_token, json_encode($advertiser_data['data']['advertiser_ids']));
            }
            foreach ($public_info_data['data'] as $k => $v){
                $salt = Random::alnum();
                $data[] = [
                    "advertiser_id" => $v['id'],
                    "company_name" => $v['company'],
                    "name" => $v['name'],
                    "first_industry_name" => $v['first_industry_name'],
                    "second_industry_name" => $v['second_industry_name'],
                    "salt" => $salt,
                    "password" => $this->auth->getEncryptPassword("123456",$salt),
                    "create_time" => time(),
                    "update_time" => time(),
                ];
            }
        }

        if (Db::name("company")->insertAll($data)){
            return "执行成功,新增数据". count($data) ."条";
        }
    }

    //检测千川是否有新增绑定账户并更新
    public function synchronous(){
        $time = time();
        $config_data = Db::name("qc_config")->where("id",1)->find();
        $access_token = Cache::get("qc_access_token");
        $advertiser_ids = Cache::get("advertiser_ids");
        if ($advertiser_ids){
            $advertiser_ids = unserialize($advertiser_ids);
        }else{
            $advertiser_ids = Db::name("company")->column("advertiser_id,name,kahuna");
            Cache::set("advertiser_ids",serialize($advertiser_ids));
        }
        $advertiser_data = AccountRelationship::advertiser_select($access_token,$config_data['advertiser_id'],1,100);
        $total_page = $advertiser_data['data']['page_info']['total_page'];
        $public_info_data = UserInfo::public_info($access_token, json_encode($advertiser_data['data']['advertiser_ids']));
        $company_add_data = [];
        for ($i=1;$i <= $total_page;$i++){
            if ($i>1){
                $advertiser_data = AccountRelationship::advertiser_select($access_token,$config_data['advertiser_id'],$i,100);
                $public_info_data = UserInfo::public_info($access_token, json_encode($advertiser_data['data']['advertiser_ids']));
            }
            foreach ($public_info_data['data'] as $k => $v){
                $res1 = FundManagement::get_ad_info($access_token,json_encode([$v["id"]],JSON_UNESCAPED_UNICODE));
                if (isset($advertiser_ids[$v["id"]])){
                    if ($advertiser_ids[$v["id"]]['name'] != $v['name']) {
                        $update_data['name'] = $v['name'];
                        $update_data['update_time'] = time();
                    }
                    if ($advertiser_ids[$v["id"]]['kahuna'] != $res1['data']['account_detail_list'][0]['optimizer_name']) {
                        $update_data['kahuna'] = $res1['data']['account_detail_list'][0]['optimizer_name'];
                        $update_data['update_time'] = time();
                    }
                    if(!empty($update_data)){
                        Db::name("company")->where(["advertiser_id"=>$v["id"]])->update($update_data);
                        $advertiser_ids[$v["id"]]['name'] = $v["name"];
                        $advertiser_ids[$v["id"]]['kahuna'] = $res1['data']['account_detail_list'][0]['optimizer_name'];
                        Cache::set("advertiser_ids",serialize($advertiser_ids));
                    }
                }else{
                    $salt = Random::alnum();
                    $company_add_data[] = [
                        "advertiser_id" => $v["id"],
                        "company_name" => $v["company"],
                        "name" => $v["name"],
                        "first_industry_name" => $v["first_industry_name"],
                        "second_industry_name" => $v["second_industry_name"],
                        "salt" => $salt,
                        "password" => $this->auth->getEncryptPassword("123456",$salt),
                        "create_time" => time(),
                        "update_time" => time(),
                        "kahuna" => $res1['data']['account_detail_list'][0]['optimizer_name'],
                    ];
                    $advertiser_ids[$v["id"]]['name'] = $v["name"];
                    $advertiser_ids[$v["id"]]['kahuna'] = $res1['data']['account_detail_list'][0]['optimizer_name'];
                }
            }
        }

        try {
            if (!empty($company_add_data)){
                Db::name("company")->insertAll($company_add_data);
                Cache::set("advertiser_ids",serialize($advertiser_ids));
            }
        } catch (\Exception $e) {
            $time = time() - $time;
            return "更新失败:".$e->getMessage() . "花费时间：".$time."秒";
        }
        $time = time() - $time;
        return "更新成功,". "花费时间：".$time."秒";
    }

    //获取方舟登录cookie
    public function get_fz_cookie(){
        $url = "http://159.75.167.202:3000/jlfz/get_cookie";
        $data = [
            "email" => "apiapi@zebranumber.cn",
            "password" => "Yx147258",
        ];
        Requests::post($url, $data);
        return "执行成功";
    }

    //获取图片
    public function get_fz_transfer_image(){
        $data = Db::name("transfer_records")
            ->where(["status"=>1])
            ->whereNull("image")
            ->where(['transfer_serial'=>['>',0]])
            ->where(["create_time"=>[">",strtotime('today midnight')]])
            ->where(["create_time"=>["<",time() - 300]])
            ->select();
        foreach ($data as $k=>$v){
            $url = "http://127.0.0.1:3000/jlfz/get_transfer_image";
            $data = [
                "cookie" => Cache::store("redis")->get("jlfz_cookie"),
                "transfer_serial" =>$v['transfer_serial'],
            ];
            Requests::post($url, $data);
        }
        return "执行成功";
    }

    //获取图片url写入数据库
    public function get_transfer_image_url(){
        $data = Db::name("transfer_records")
            ->where(["status"=>1])
            ->whereNull("image")
            ->where(["create_time"=>[">",strtotime('today midnight')]])
            ->where(["create_time"=>["<",time() - 300]])
            ->select();
        foreach ($data as $k=>$v){
            $url = Cache::store("redis")->get($v['transfer_serial']);
            if ($url){
                Db::name("transfer_records")->where(["id" => $v['id']])->update(["image" => $url]);
                Cache::store("redis")->rm($v['transfer_serial']);
            }
        }
        return "执行成功";
    }


}