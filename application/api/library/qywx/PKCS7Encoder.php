<?php

namespace app\api\library\qywx;

/**
 * PKCS7Encoder 类
 * 提供基于PKCS7算法的加解密接口
 */
class PKCS7Encoder
{
    private const BLOCK_SIZE = 32;

    /**
     * 对需要加密的明文进行填充补位
     */
    public static function encode(string $text): string
    {
        $amountToPad = self::BLOCK_SIZE - (strlen($text) % self::BLOCK_SIZE);
        return $text . str_repeat(chr($amountToPad), $amountToPad);
    }

    /**
     * 对解密后的明文进行补位删除
     */
    public static function decode(string $decrypted): string
    {
        $pad = ord(substr($decrypted, -1));
        if ($pad < 1 || $pad > self::BLOCK_SIZE) {
            return $decrypted;
        }
        return substr($decrypted, 0, -$pad);
    }
}


