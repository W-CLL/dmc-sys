<?php

namespace zhaohang;

use FG\ASN1\ASNObject;
use Mdanter\Ecc\Crypto\Signature\Signature;
use Mdanter\Ecc\Serializer\Signature\DerSignatureSerializer;
use Rtgm\sm\RtSm2;
use Rtgm\sm\RtSm4;

class Father
{
    protected static $userId = 'N002461203';
    protected static $privateKey = "NBtl7WnuUtA2v5FaebEkU0/Jj1IodLGT6lQqwkzmd2E=";
    protected static $publicKey = "BNsIe9U0x8IeSe4h/dxUzVEz9pie0hDSfMRINRXc7s1UIXfkExnYECF4QqJ2SnHxLv3z/99gsfDQrQ6dzN5lZj0=";
    protected static $symmetricKey = 'VuAzSWQhsoNqzn0K';
    protected static $reqUrl = 'http://cdctest.cmburl.cn:80/cdcserver/api/v2';

    /**
     * 生成请求id
     * @return string
     */
    protected static function getReqId(){
        $timeStamp = microtime(true);
        $ms = $timeStamp % 1000;
        $str = date('YmdHis',$timeStamp).str_pad($ms,3,'0',STR_PAD_LEFT).self::generateRandomString();
        return $str;
    }

    /**
     * 生成随机字符串
     * @param $length
     * @return string
     */
    protected static function generateRandomString($length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    /**
     * 签名
     * @param $data
     * @return string
     */
    protected static function SMSign($data){
        // 对请求报文做排序
        $toSortArray = json_decode($data, true);
        self::recursiveArraySort($toSortArray);
        $data = json_encode($toSortArray, JSON_UNESCAPED_UNICODE);
        $private_key = unpack("H*", base64_decode(self::$privateKey))[1];
        $userId = sprintf('%-016s', self::$userId);
        // 生成签名
        $sm2 = new RtSm2("base64");
        $sign = $sm2->doSign($data, $private_key, $userId);
        // 处理签名
        $sign = base64_decode($sign);
        $point = ASNObject::fromBinary($sign)->getChildren();
        $pointX = self::formatHex($point[0]->getContent());
        $pointY = self::formatHex($point[1]->getContent());
        $sign = $pointX . $pointY;
        $sign = base64_encode(hex2bin($sign));

        return $sign;
    }

    protected static function formatHex($dec)
    {
        $hex = gmp_strval(gmp_init($dec, 10), 16);
        $len = strlen($hex);
        if ($len == 64) {
            return $hex;
        }
        if ($len < 64) {
            $hex = str_pad($hex, 64, "0", STR_PAD_LEFT);
        } else {
            $hex = substr($hex, $len - 64, 64);
        }
        return $hex;
    }

    /**
     * 拼接
     * @param $data
     * @param $funcode
     * @return array
     */
    protected static function getFormData($data, $funcode)
    {
        return array('UID' => self::$userId, 'ALG' => "SM", 'DATA' => $data, 'FUNCODE' => $funcode);
    }


    /**
     * 请求
     * @param $data
     * @return bool|string
     */
    protected static function httpPost($data)
    {
        $urlInfo = parse_url(self::$reqUrl);
        foreach ($data as $key => $value)
            $values[] = "$key=" . urlencode($value);
        $dataString = implode("&", $values);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::$reqUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $dataString);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
//            throw new \Exception("Error in making the request: " . curl_error($ch));
            return false;
        }
        if ($status != 200) {
//            throw new \Exception("Status Code: " . $status . " Response: " . $response);
            return false;
        }
        if (substr($response, 0, 10) === "CDCServer:") {
            return false;
//            throw new \Exception("访问目标地址 " . self::$reqUrl . " 失败:" . $response);
        }

        return $response;
    }

    /**
     * 加密
     * @param $data
     * @return string
     */
    protected static function SM4CBCEncryption($data){
        // 对数据进行对称加密
        $sm4 = new RtSm4(self::$symmetricKey);
        $encryptData = $sm4->encrypt($data, 'sm4-cbc', sprintf('%-016s', self::$userId), "base64");
        return $encryptData;
    }

    /**
     * 排序
     * @param $array
     * @return void
     */
    protected static function recursiveArraySort(&$array)
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::recursiveArraySort($value);
            }
        }
    }

    /**
     * 解密验签
     * @param $response
     * @return array|false|string|string[]
     */
    protected static function decrypt($response){
        $sm4 = new RtSm4(self::$symmetricKey);
        $publicKey = unpack("H*", base64_decode(self::$publicKey))[1];
        // 返回结果解密
        $json = $sm4->decrypt($response, 'sm4-cbc', sprintf('%-016s', self::$userId), 'base64');
        $respdata = json_decode($json, true);

        // 验证签名是否正确
        $sign = $respdata["signature"]["sigdat"];
        // 将数据中的签名重置
        $respdata["signature"]["sigdat"] = "__signature_sigdat__";
        $json = str_replace($sign, "__signature_sigdat__", $json);

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
        $b = $sm2->verifySign($json, $sign, $publicKey, sprintf('%-016s', self::$userId));
        if ($b === true) {
            return $json;
        } else {
            return false;
        }
    }
}