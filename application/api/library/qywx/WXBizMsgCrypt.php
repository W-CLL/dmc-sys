<?php

namespace app\api\library\qywx;

/**
 * 企业微信回调消息加解密类
 */
class WXBizMsgCrypt
{
    private string $token;
    private string $encodingAesKey;
    private string $receiveId;

    public function __construct(string $token, string $encodingAesKey, string $receiveId)
    {
        $this->token = $token;
        $this->encodingAesKey = $encodingAesKey;
        $this->receiveId = $receiveId;
    }

    /**
     * 验证URL
     * @return int 成功0，失败返回对应的错误码
     */
    public function verifyURL(string $msgSignature, string $timestamp, string $nonce, string $echoStr, ?string &$replyEchoStr): int
    {
        if (strlen($this->encodingAesKey) !== 43) {
            return ErrorCode::IllegalAesKey;
        }

        [$ret, $signature] = SHA1::getSHA1($this->token, $timestamp, $nonce, $echoStr);
        if ($ret !== ErrorCode::OK) {
            return $ret;
        }

        if ($signature !== $msgSignature) {
            return ErrorCode::ValidateSignatureError;
        }

        $pc = new Prpcrypt($this->encodingAesKey);
        [$ret, $decrypted] = $pc->decrypt($echoStr, $this->receiveId);
        if ($ret !== ErrorCode::OK) {
            return $ret;
        }

        $replyEchoStr = $decrypted;
        return ErrorCode::OK;
    }

    /**
     * 将公众平台回复用户的消息加密打包
     * @return int 成功0，失败返回对应的错误码
     */
    public function encryptMsg(string $replyMsg, ?string $timestamp, string $nonce, ?string &$encryptMsg): int
    {
        $timestamp = $timestamp ?? (string)time();
        $pc = new Prpcrypt($this->encodingAesKey);

        [$ret, $encrypted] = $pc->encrypt($replyMsg, $this->receiveId);
        if ($ret !== ErrorCode::OK) {
            return $ret;
        }

        [$ret, $signature] = SHA1::getSHA1($this->token, $timestamp, $nonce, $encrypted);
        if ($ret !== ErrorCode::OK) {
            return $ret;
        }

        $encryptMsg = XMLParse::generate($encrypted, $signature, $timestamp, $nonce);
        return ErrorCode::OK;
    }

    /**
     * 检验消息的真实性，并且获取解密后的明文
     * @return int 成功0，失败返回对应的错误码
     */
    public function decryptMsg(string $msgSignature, ?string $timestamp, string $nonce, string $postData, ?string &$msg): int
    {
        if (strlen($this->encodingAesKey) !== 43) {
            return ErrorCode::IllegalAesKey;
        }

        $timestamp = $timestamp ?? (string)time();

        [$ret, $encrypt] = XMLParse::extract($postData);
        if ($ret !== ErrorCode::OK) {
            return $ret;
        }

        [$ret, $signature] = SHA1::getSHA1($this->token, $timestamp, $nonce, $encrypt);
        if ($ret !== ErrorCode::OK) {
            return $ret;
        }

        if ($signature !== $msgSignature) {
            return ErrorCode::ValidateSignatureError;
        }

        $pc = new Prpcrypt($this->encodingAesKey);
        [$ret, $decrypted] = $pc->decrypt($encrypt, $this->receiveId);
        if ($ret !== ErrorCode::OK) {
            return $ret;
        }

        $msg = $decrypted;
        return ErrorCode::OK;
    }
}
