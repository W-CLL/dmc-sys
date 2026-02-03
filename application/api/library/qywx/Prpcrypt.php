<?php

namespace app\api\library\qywx;

/**
 * Prpcrypt 类
 * 提供接收和推送给企业微信消息的加解密接口
 */
class Prpcrypt
{
    private const CIPHER = 'AES-256-CBC';
    private const RANDOM_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    private string $key;
    private string $iv;

    public function __construct(string $encodingAesKey)
    {
        $this->key = base64_decode($encodingAesKey . '=');
        $this->iv = substr($this->key, 0, 16);
    }

    /**
     * 加密
     * @return array [错误码, 密文]
     */
    public function encrypt(string $text, string $receiveId): array
    {
        try {
            $plaintext = $this->getRandomStr() . pack('N', strlen($text)) . $text . $receiveId;
            $plaintext = PKCS7Encoder::encode($plaintext);
            $encrypted = openssl_encrypt($plaintext, self::CIPHER, $this->key, OPENSSL_ZERO_PADDING, $this->iv);
            return [ErrorCode::OK, $encrypted];
        } catch (\Exception $e) {
            return [ErrorCode::EncryptAESError, null];
        }
    }

    /**
     * 解密
     * @return array [错误码, 明文]
     */
    public function decrypt(string $encrypted, string $receiveId): array
    {
        try {
            $decrypted = openssl_decrypt($encrypted, self::CIPHER, $this->key, OPENSSL_ZERO_PADDING, $this->iv);
            if ($decrypted === false) {
                return [ErrorCode::DecryptAESError, null];
            }

            $result = PKCS7Encoder::decode($decrypted);
            if (strlen($result) < 16) {
                return [ErrorCode::IllegalBuffer, null];
            }

            $content = substr($result, 16);
            $lenList = unpack('N', substr($content, 0, 4));
            $xmlLen = $lenList[1];
            $xmlContent = substr($content, 4, $xmlLen);
            $fromReceiveId = substr($content, $xmlLen + 4);

            if ($fromReceiveId !== $receiveId) {
                return [ErrorCode::ValidateCorpidError, null];
            }
            return [ErrorCode::OK, $xmlContent];
        } catch (\Exception $e) {
            return [ErrorCode::IllegalBuffer, null];
        }
    }

    private function getRandomStr(): string
    {
        $max = strlen(self::RANDOM_CHARS) - 1;
        $str = '';
        for ($i = 0; $i < 16; $i++) {
            $str .= self::RANDOM_CHARS[random_int(0, $max)];
        }
        return $str;
    }
}
