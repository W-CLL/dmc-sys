<?php

namespace app\robotapi\controller;

use think\Db;
class Base
{
    public function _initialize()
    {
        $this->writeLog();
    }

    protected function check($account, $encrypted_data)
    {
        $info = Db::name("external_accounts")->where(["platform" => 'robot_api'])->find();
        if($info["account"] == $account && openssl_decrypt($encrypted_data, 'AES-128-ECB', $info["secret"])){
            return true;
        }
        return false;
    }

}