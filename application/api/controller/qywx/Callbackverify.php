<?php

namespace app\api\controller\qywx;

use app\api\library\qywx\WXBizMsgCrypt;
use app\api\controller\qywx\service\QianchuanService;
use app\api\controller\qywx\service\TencentService;
use think\Controller;
use think\Env;

/**
 * 企业微信回调验证控制器
 */
class Callbackverify extends Controller
{
    private const CACHE_TTL = 3600;
    private const RANDOM_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    private const REPLY_XML = '<xml>
<ToUserName><![CDATA[%s]]></ToUserName>
<FromUserName><![CDATA[%s]]></FromUserName>
<CreateTime>%s</CreateTime>
<MsgType><![CDATA[text]]></MsgType>
<Content><![CDATA[%s]]></Content>
</xml>';

    // 指令路由配置
    private static $commandRoutes = [
        '千川查询' => [
            '账户' => [QianchuanService::class, 'queryAccount'],
            '子钱包' => [QianchuanService::class, 'querySubWallet'],
            '商户' => [QianchuanService::class, 'queryMerchant'],
        ],
        '千川新增额度' => [QianchuanService::class, 'addQuota'],
        '千川绑定商户' => [QianchuanService::class, 'bindMerchant'],
        '腾讯查询' => [
            '账户' => [TencentService::class, 'queryAccount'],
            '子钱包' => [TencentService::class, 'querySubWallet'],
            '商户' => [TencentService::class, 'queryMerchant'],
        ],
        '腾讯新增额度' => [TencentService::class, 'addQuota'],
        '腾讯绑定商户' => [TencentService::class, 'bindMerchant'],
    ];

    private ?WXBizMsgCrypt $wxCrypt = null;

    private function getWxCrypt(): WXBizMsgCrypt
    {
        if ($this->wxCrypt === null) {
            $this->wxCrypt = new WXBizMsgCrypt(
                Env::get('work_wx.token'),
                Env::get('work_wx.aes_key'),
                Env::get('work_wx.receive_id')
            );
        }
        return $this->wxCrypt;
    }

    /**
     * 处理企业微信回调消息(POST)
     */
    public function index(): void
    {
        $msgSig = $this->getParam('msg_signature');
        $timestamp = $this->getParam('timestamp');
        $nonce = $this->getParam('nonce');
        $postData = file_get_contents('php://input');

        $decryptedMsg = '';
        if ($this->getWxCrypt()->decryptMsg($msgSig, $timestamp, $nonce, $postData, $decryptedMsg) !== 0) {
            $this->respond();
        }

        $xml = $this->parseXml($decryptedMsg);
        if ($xml === null) {
            $this->respond();
        }

        $msgType = (string)$xml->MsgType;
        $fromUser = (string)$xml->FromUserName;
        $msgId = isset($xml->MsgId) ? (string)$xml->MsgId : '';
        $createTime = (string)$xml->CreateTime;

        if ($this->isDuplicate($msgId, $fromUser, $createTime, $msgType)) {
            $this->respond();
        }

        $replyContent = $this->dispatch($msgType, $xml, $fromUser);
        if ($replyContent) {
            $this->sendReply($replyContent, $fromUser);
        }

        $this->respond();
    }

    /**
     * URL验证(GET)
     */
    public function index1(): void
    {
        $msgSig = $this->getParam('msg_signature');
        $timestamp = $this->getParam('timestamp');
        $nonce = $this->getParam('nonce');
        $echoStr = $this->getParam('echostr');

        $replyEchoStr = '';
        if ($this->getWxCrypt()->verifyURL($msgSig, $timestamp, $nonce, $echoStr, $replyEchoStr) === 0) {
            echo $replyEchoStr;
        }
        exit;
    }

    private function getParam(string $key): string
    {
        return isset($_GET[$key]) ? urldecode((string)$_GET[$key]) : '';
    }

    private function parseXml(string $xmlStr): ?\SimpleXMLElement
    {
        libxml_disable_entity_loader(true);
        $xml = simplexml_load_string($xmlStr, 'SimpleXMLElement', LIBXML_NOCDATA);
        return $xml === false ? null : $xml;
    }

    private function isDuplicate(string $msgId, string $fromUser, string $createTime, string $msgType): bool
    {
        $cacheKey = ($msgId && $msgType !== 'event')
            ? 'qywx_msg_' . $msgId
            : 'qywx_event_' . $fromUser . '_' . $createTime;

        if (cache($cacheKey)) {
            return true;
        }
        cache($cacheKey, 1, self::CACHE_TTL);
        return false;
    }

    private function dispatch(string $msgType, \SimpleXMLElement $xml, string $fromUser): ?string
    {
        try {
            switch ($msgType) {
                case 'text':
                    return $this->handleText((string)$xml->Content, $fromUser);
                case 'event':
                    return $this->handleEvent((string)$xml->Event, $xml);
                default:
                    return null;
            }
        } catch (\Exception $e) {
            trace('企业微信消息处理异常: ' . $e->getMessage(), 'error');
            return null;
        }
    }

    private function handleText(string $content, string $fromUser): ?string
    {
        $content = trim($content);

        // 基础指令
        if (in_array($content, ['help', '帮助', '帮助我', '?'], true)) {
            return $this->getHelpText();
        }
        if (in_array($content, ['time', '时间', '几点', '现在几点'], true)) {
            return '当前时间：' . date('Y-m-d H:i:s');
        }
        if (str_starts_with($content, 'echo ') || str_starts_with($content, '回复 ')) {
            return preg_replace('/^(echo |回复 )/', '', $content);
        }

        // 路由指令
        return $this->routeCommand($content, $fromUser);
    }

    private function routeCommand(string $content, string $fromUser): ?string
    {
        foreach (self::$commandRoutes as $prefix => $handler) {
            if (!str_starts_with($content, $prefix)) {
                continue;
            }

            $params = trim(mb_substr($content, mb_strlen($prefix)));

            // 嵌套路由（如：千川查询 账户）
            if (is_array($handler) && !is_callable($handler)) {
                foreach ($handler as $subPrefix => $subHandler) {
                    if (str_starts_with($params, $subPrefix)) {
                        $subParams = trim(mb_substr($params, mb_strlen($subPrefix)));
                        return call_user_func($subHandler, $subParams);
                    }
                }
                return "{$prefix}指令格式错误\n请参考帮助信息";
            }

            // 直接路由（如：千川新增额度）
            return call_user_func($handler, $params, $fromUser);
        }

        return null;
    }

    private function handleEvent(string $event, \SimpleXMLElement $xml): ?string
    {
        switch ($event) {
            case 'subscribe':
                return "欢迎关注企业微信应用！\n\n发送「帮助」查看可用指令。";
            case 'click':
                return $this->handleMenuClick((string)$xml->EventKey);
            default:
                return null;
        }
    }

    private function handleMenuClick(string $eventKey): string
    {
        switch ($eventKey) {
            case 'help':
                return $this->getHelpText();
            default:
                return "您点击了菜单：{$eventKey}";
        }
    }

    private function sendReply(string $content, string $fromUser): void
    {
        $replyXml = sprintf(self::REPLY_XML, $fromUser, $fromUser, time(), $content);
        $encryptedMsg = '';
        $nonce = $this->randomStr(16);

        if ($this->getWxCrypt()->encryptMsg($replyXml, (string)time(), $nonce, $encryptedMsg) === 0) {
            echo $encryptedMsg;
        }
    }

    private function respond(): void
    {
        http_response_code(200);
        exit;
    }

    private function randomStr(int $length): string
    {
        $max = strlen(self::RANDOM_CHARS) - 1;
        $str = '';
        for ($i = 0; $i < $length; $i++) {
            $str .= self::RANDOM_CHARS[random_int(0, $max)];
        }
        return $str;
    }

    private function getHelpText(): string
    {
        return <<<HELP
欢迎使用企业微信机器人！

📖 可用指令：

🔹 基础指令：
• help 或 帮助 - 显示帮助信息
• time 或 时间 - 查看当前时间
• echo [内容] - 回复您说的话

🔹 千川指令：
查询相关：
• 千川查询 账户 [账户ID1,账户ID2] (最多10个)
• 千川查询 子钱包 [子钱包ID1,子钱包ID2] (最多10个)
• 千川查询 商户 [商户名称/ID]
操作相关：
• 千川新增额度 [商户名称/ID] [账户类型] [金额] [备注]
• 千川绑定商户 [广告账户ID1,子钱包ID2] [商户名称/ID] [账户类型] [返点]

🔹 腾讯指令：
查询相关：
• 腾讯查询 账户 [账户ID1,账户ID2] (最多10个)
• 腾讯查询 子钱包 [子钱包ID1,子钱包ID2] (最多10个)
• 腾讯查询 商户 [商户名称/ID]
操作相关：
• 腾讯新增额度 [商户名称/ID] [账户类型] [金额] [备注]
• 腾讯绑定商户 [账户ID1,账户ID2] [商户名称/ID] [账户类型] [返点]

💡 示例：
• 千川查询 账户 12345678
• 千川新增额度 张三店铺 对公 1000 补充1月预算
• 腾讯绑定商户 12345678,87654321 张三店铺 对公 1.04

说明：账户/子钱包ID支持逗号或空格分隔，可批量查询
HELP;
    }
}

