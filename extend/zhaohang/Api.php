<?php

namespace zhaohang;

class Api extends ZhClient
{

    protected static function handlePostData($funcode, $body)
    {
        return [
            "request" => [
                "body" => $body,
                "head" => [
                    "funcode" => $funcode,
                    "userid" => self::$userId,
                    "reqid" => self::getReqId()
                ]
            ],
            "signature" => [
                "sigdat" => "__signature_sigdat__",
                "sigtim" => date('YmdHis'),
            ]
        ];
    }

    protected static function baseRquest($post, $funcode){
        $data = json_encode($post, JSON_UNESCAPED_UNICODE);
        $sign = self::SMSign($data);
        // 替换签名字段
        $data = str_replace('__signature_sigdat__', $sign, $data);
        $SM4Data = self::SM4CBCEncryption($data);
        // 发送请求
        $response = self::httpPost(self::getFormData($SM4Data, $funcode));
        if ($response) {
            // 返回结果解密
            return self::decrypt($response);
        }
        return false;
    }

    /**
     * 可经办业务模式查询
     * @param $str
     * @return array|false|string|string[]
     */
    public static function getOperationModel($str)
    {
        $body = ["buscod" => $str];
        $funcode = "DCLISMOD";
        $post = self::handlePostData($funcode,$body);
        return self::baseRquest($post,$funcode);
    }

    /**
     * 新增记账子单元
     * @return array|false|string|string[]
     */
    public static function addChildAccount($arr)
    {
        $body = [
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
        ];
        $funcode = "NTDMAADD";
        $post = self::handlePostData($funcode,$body);

        if ($arr['lmtflg'] == 'Y') { //Y设定账户余额限制 N  X
            $post['request']['body']['ntdmaaddx']['ballmt'] = $arr['ballmt'];
        }
        return self::baseRquest($post,$funcode);
    }


    /**
     * 关闭记账子单元
     * @param $arr
     * @return array|false|string|string[]
     */
    public static function delChildAccount($arr)
    {
        $body = [
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
        ];
        $funcode = "NTDMADLT";
        $post = self::handlePostData($funcode,$body);
        return self::baseRquest($post,$funcode);

    }

    /**
     * 修改记账子单元
     * @param $arr
     * @return array|false|string|string[]
     */
    public static function updateChildAccount($arr)
    {
        $body = [
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
        ];
        $funcode = "NTDMAMNT";
        $post = self::handlePostData($funcode,$body);

        if ($arr['lmtflg'] == 'Y') {
            $post['request']['body']['ntdmaaddx']['ballmt'] = $arr['ballmt'];
        }
        return self::baseRquest($post,$funcode);
    }


    /**
     * 查询记账子单元信息
     * @return array|false|string|string[]
     */
    public static function getChildAccountInfo($arr)
    {
        $body = [
            "ntdmalstx" => [
                "accnbr" => $arr['accnbr'],     // 结算号
                "dmanbr" => $arr['dmanbr'] ? '' : $arr['dmanbr'],     // 子单元号
                "rsv50z" => $arr['rsv50z'] ? '' : $arr['rsv50z'],     // 续传字段（调用返回）
            ]
        ];
        $funcode = "NTDMALST";
        $post = self::handlePostData($funcode,$body);
        return self::baseRquest($post,$funcode);
    }

    /**
     * 转账
     */
    public static function zh_BB1PAYOP()
    {
        $body = [
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
        ];

        $funcode = "BB1PAYOP";
        $post = self::handlePostData($funcode,$body);
        return self::baseRquest($post,$funcode);
    }


}