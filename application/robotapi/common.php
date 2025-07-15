<?php

use jlqc\FundManagement;
use think\Cache;
use think\Db;
use think\Env;

if (!function_exists('build_json')) {

    /**
     * 生成下拉列表
     * @param int $code
     * @param mixed  $data
     * @param string  $msg
     * @param mixed  $flags
     * @return string
     */
    function build_json(int $code, $data, string $msg = '', $flags = JSON_UNESCAPED_UNICODE)
    {
        $result = [
            'code' => $code,
            'data' => $data,
            'msg'  => $msg,
        ];
        return json_encode($result, $flags);
    }
}

if (!function_exists('encryption')) {

    /**
     * @param $data array 待加密数据【传入数组】
     * @return string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    function encryption(array $data)
    {
        $info = Db::name("external_accounts")->where(["platform" => 'robot_api'])->find();
        $iv = generate_random_string(16);
        $json_data = json_encode($data, JSON_UNESCAPED_UNICODE);
        return base64_encode($iv . openssl_encrypt($json_data, 'AES-128-CBC', $info["secret"], OPENSSL_RAW_DATA, $iv));
    }
}


if (!function_exists('decrypt')) {
    /**
     * @param $data string 待解密数据【json】
     * @return false|string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    function decrypt(string $data)
    {
        $info = Db::name("external_accounts")->where(["platform" => 'robot_api'])->find();
        $decoded = base64_decode($data);
        $iv = substr($decoded, 0, 16);
        try {
            $data = gzinflate(openssl_decrypt(substr($decoded, 16), 'AES-128-CBC', $info["secret"], OPENSSL_RAW_DATA, $iv));
        }catch (Exception $e){
            return false;
        }
        return $data;
    }
    // 判断是否是url编码【此处用于判断是否已经被自动urldecode】
//    function is_urlencoded($str) : bool
//    {
//        $decoded = urldecode($str);
//        var_dump($str);
//        var_dump(urlencode($decoded));
//        die;
//        return ($str === urlencode($decoded));
//    }
}





