<?php

namespace app\api\library\qywx;

/**
 * XMLParse 类
 * 提供提取消息格式中的密文及生成回复消息格式的接口
 */
class XMLParse
{
    private const XML_TEMPLATE = '<xml>
<Encrypt><![CDATA[%s]]></Encrypt>
<MsgSignature><![CDATA[%s]]></MsgSignature>
<TimeStamp>%s</TimeStamp>
<Nonce><![CDATA[%s]]></Nonce>
</xml>';

    /**
     * 提取出xml数据包中的加密消息
     * @return array [错误码, 加密消息字符串]
     */
    public static function extract(string $xmlText): array
    {
        try {
            $xml = new \DOMDocument();
            $xml->loadXML($xmlText, LIBXML_NOENT | LIBXML_DTDLOAD | LIBXML_NOERROR | LIBXML_NOWARNING);
            $encryptNode = $xml->getElementsByTagName('Encrypt')->item(0);
            if ($encryptNode === null) {
                return [ErrorCode::ParseXmlError, null];
            }
            return [ErrorCode::OK, $encryptNode->nodeValue];
        } catch (\Exception $e) {
            return [ErrorCode::ParseXmlError, null];
        }
    }

    /**
     * 生成xml消息
     */
    public static function generate(string $encrypt, string $signature, string $timestamp, string $nonce): string
    {
        return sprintf(self::XML_TEMPLATE, $encrypt, $signature, $timestamp, $nonce);
    }
}
