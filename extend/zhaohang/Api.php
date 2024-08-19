<?php
namespace zhaohang;

class Api extends Father
{
    /**
     * 可经办业务模式查询
     * @param $str
     * @return mixed
     */
    public static function zh_DCLISMOD($str){
        $post = [
            "request" => [
                "body" => [
                    "buscod" => $str
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

    /**
     * 新增记账子单元
     * @return array|false|string|string[]
     */
    public static function zh_NTDMAADD($arr){
        $post = [
            "request" => [
                "body" => [
                    "ntbusmody" => [
                        "busmod" => $arr['busmod']
                    ],
                    "ntdmaaddx" => [
                        "accnbr" => $arr['accnbr'],   // 填入结算户
                        "dmanbr" => '',
                        "dmanam" => $arr['dmanam'],   // 子账户名称
                        "ovrctl" => $arr['ovrctl'],
                        "bcktyp" => $arr['bcktyp'],
                        "clstyp" => $arr['clstyp'],
                        "yurref" => self::getOrderNum(),
                        "lmtflg" => $arr['lmtflg'],
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
        if($arr['lmtflg'] == 'Y'){
            $post['request']['body']['ntdmaaddx']['ballmt'] = $arr['ballmt'];
        }
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

    /**
     * 查询记账子单元信息
     * @return void
     */
    public static function zh_NTDMALST($arr){
        $post = [
            "request" => [
                "body" => [
                    "ntdmalstx" => [
                        "accnbr" => $arr['accnbr'],     // 结算号
                        "dmanbr" => $arr['dmanbr']?'':$arr['dmanbr'],     // 子单元号
                        "rsv50z" => $arr['rsv50z']?'':$arr['rsv50z'],     // 续传字段（调用返回）
                    ]
                ],
                "head" => [
                    "funcode" => "NTDMALST",
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
        $response = self::httpPost(self::getFormData($SM4Data, "NTDMALST"));
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


    /**
     * 关闭记账子单元
     * @param $data
     * @return array|false|string|string[]
     */
    public static function zh_NTDMADLT($arr){
        $post = [
            "request" => [
                "body" => [
                    "ntbusmody" => [
                        "busmod" => $arr['bus_mod']
                    ],
                    "ntdmadltx1" => [
                        "accnbr" => $arr['settle_account'],   // 填入结算户
                        "yurref" => self::getOrderNum(),
                    ],
                    "ntdmadltx2" => [
                        "dmanbr" => $arr['sub_account']
                    ]
                ],
                "head" => [
                    "funcode" => "NTDMADLT",
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
        $response = self::httpPost(self::getFormData($SM4Data, "NTDMADLT"));
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

    /**
     * 修改记账子单元
     * @param $arr
     * @return array|false|string|string[]
     */
    public static function zh_NTDMAMNT($arr){
        $post = [
            "request" => [
                "body" => [
                    "ntbusmody" => [
                        "busmod" => $arr['busmod']
                    ],
                    "ntdmamntx1" => [
                        "bbknbr" => $arr['bbknbr'],
                        "accnbr" => $arr['accnbr'],   // 填入结算户
                        "dmanbr" => $arr['dmanbr'],
                        "dmanam" => $arr['dmanam'],   // 子账户名称
                        "ovrctl" => $arr['ovrctl'],
                        "bcktyp" => $arr['bcktyp'],
                        "clstyp" => $arr['clstyp'],
                        "yurref" => self::getOrderNum(),
                        "lmtflg" => $arr['lmtflg'],
                    ]
                ],
                "head" => [
                    "funcode" => "NTDMAMNT",
                    "userid" => self::$userId,
                    "reqid" => self::getReqId()
                ]
            ],
            "signature" => [
                "sigdat" => "__signature_sigdat__",
                "sigtim" => date('YmdHis'),
            ]
        ];
        if($arr['lmtflg'] == 'Y'){
            $post['request']['body']['ntdmaaddx']['ballmt'] = $arr['ballmt'];
        }
        $data = json_encode($post,JSON_UNESCAPED_UNICODE);
        $sign = self::SMSign($data);
        // 替换签名字段
        $data = str_replace('__signature_sigdat__', $sign, $data);
        $SM4Data = self::SM4CBCEncryption($data);
        // 发送请求
        $response = self::httpPost(self::getFormData($SM4Data, "NTDMAMNT"));
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



    /**
     * 转账
     */
    public static function zh_BB1PAYOP(){

        $post = [
            "request" => [
                "body" => [
                    "bb1paybmx1" => [
                        [
                            "busMod" => '00002',
                            "busCod" => 'N02030'
                        ],
                    ],
                    "bb1payopx1" => [
                        [
                            "ccyNbr" => '10',
                            "crtAcc" => '755915671610501',   // 填入结算户
                            "crtNam" => '企业网银新20161103',
                            "nusAge" => '测试',   // 子账户名称
                            "bnkFlg" => 'Y',
                            "trsAmt" => '1.00',
                            "dbtAcc" => '755915671610302',
                            "yurRef" => self::getOrderNum(),
                        ]
                    ]
                ],
                "head" => [
                    "funcode" => "BB1PAYOP",
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
        $response = self::httpPost(self::getFormData($SM4Data, "BB1PAYOP"));


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