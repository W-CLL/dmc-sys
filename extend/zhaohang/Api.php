<?php
namespace zhaohang;

class Api extends Father
{
    /**
     * 查询商户的入网审核结果
     * @param $data
     * @param $mchId
     * @return mixed
     */
    public static function zh_DCLISMOD(){
        $post = [
            "request" => [
                "body" => [
                    "buscod" => "N36090"
                ],
                "head" => [
                    "funcode" => "DCLISMOD",
                    "userid" => self::$userId,
                    "reqid" => self::getReqId()
                ]
            ],
            "signature" => [
                "sigdat" => "__signature_sigdat__",
                "sigtim" => date('YmdHis'),
            ]
        ];
        $data = json_encode($post,JSON_UNESCAPED_UNICODE);
        $sign = self::SMSign($data);
        // 替换签名字段
        $data = str_replace('__signature_sigdat__', $sign, $data);
        $SM4Data = self::SM4CBCEncryption($data);
        // 发送请求
        $response = self::httpPost(self::getFormData($SM4Data, "DCLISMOD"));
        if($response){
            // 返回结果解密
            $res = self::decrypt($response);
            if($res){
                return $res;
            }else{
                return false;
            }
        }else{
            return false;
        }
    }


    public static function zh_NTDMAADD(){
        $post = [
            "request" => [
                "body" => [
                    "ntbusmody" => [
                        "busmod" => '00001'
                    ],
                    "ntdmaaddx" => [
                        "accnbr" => '755945410210111',
                        "dmanbr" => '',
                        "dmanam" => '测001',
                        "yurref" => self::getReqId(),
                        "lmtflg" => 'Y'
                    ]
                ],
                "head" => [
                    "funcode" => "NTDMAADD",
                    "userid" => self::$userId,
                    "reqid" => self::getReqId()
                ]
            ],
            "signature" => [
                "sigdat" => "__signature_sigdat__",
                "sigtim" => date('YmdHis'),
            ]
        ];
        $data = json_encode($post,JSON_UNESCAPED_UNICODE);
        $sign = self::SMSign($data);
        // 替换签名字段
        $data = str_replace('__signature_sigdat__', $sign, $data);
        $SM4Data = self::SM4CBCEncryption($data);
        // 发送请求
        $response = self::httpPost(self::getFormData($SM4Data, "NTDMAADD"));
        if($response){
            // 返回结果解密
            $res = self::decrypt($response);
            if($res){
                return $res;
            }else{
                return false;
            }
        }else{
            return false;
        }
    }





}