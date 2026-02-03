<?php

namespace app\api\library\qywx;

/**
 * SHA1 签名生成类
 * 计算企业微信的消息签名接口
 */
class SHA1
{
    /**
     * 用SHA1算法生成安全签名
     * @return array [错误码, 签名]
     */
    public static function getSHA1(string $token, string $timestamp, string $nonce, string $encryptMsg): array
    {
        try {
            $array = [$encryptMsg, $token, $timestamp, $nonce];
            sort($array, SORT_STRING);
            return [ErrorCode::OK, sha1(implode($array))];
        } catch (\Exception $e) {
            return [ErrorCode::ComputeSignatureError, null];
        }
    }
}
