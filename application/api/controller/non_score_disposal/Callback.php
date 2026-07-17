<?php

namespace app\api\controller\non_score_disposal;

use app\api\controller\fission\AuthTokenUtil;
use think\Env;
use think\response\Json;

class Callback
{
    /**
     * 巨量千川素材状态推送回调
     * 类型：status.material.qianchuan.realtime
     *
     * @return Json
     */
    public function nonScoreDisposalCallback(): Json
    {
        $params = input();

        // 验证webhook
        if (isset($params['event']) && $params['event'] == "verify_webhook") {
            $challenge = $_GET['challenge'];
            return $this->responseJson(200, "ok", ["challenge" => (int)$challenge]);
        }

        // 获取签名密钥
        $secret_key = Env::get('dmc_spi.non_score_disposal_spi');
        if (!$secret_key) {
            \think\Log::error("千川spi密钥没设置");
            return $this->responseJson(400, "invalid signature");
        }

        // 验证签名
        $util = new AuthTokenUtil($secret_key);
        $request = \think\Request::instance();
        $headers = $request->header();
        $body = $request->getInput();
        $signature = $headers['x-open-signature'] ?? '';

        $is_valid_token = $util->is_valid_token($body, $signature);

        if (!$is_valid_token) {
            return $this->responseJson(400, "invalid signature");
        }
        $data = json_decode($body, true);
//        \think\Log::write("结果回调参数：" . json_encode($data), 'nsd');

        // 保存回调数据到数据库
        $this->saveCallbackData($data);

        return $this->responseJson(200, "ok");
    }

    /**
     * 测试方法 - 模拟回调数据
     * 
     * @return Json
     */
    public function testCallback(): Json
    {
        // 模拟测试数据
        $data = [
            'message_id' => '23433432005634',
            'advertiser_ids' => [1826468578718724],
            'account_relation' => '{"core_user_ids":{"4128011118203595":[1826468578718724]}}',
            'service_label' => 'adv.disposal.non_score_disposal',
            'data' => '{"user_id":1826468578718724,"advertiser_id":1826468578718724,"account_id":1826468578718724,"account_type":"ADVERTISER","content":"{\\"adv_id\\":\\"1826468578718724\\",\\"create_time\\":1778061279,\\"disposal_id\\":\\"7636712749267404815\\",\\"disposal_reason\\":\\"您好，因该千川账户的关联主体或关联账户存在严重违规记录，现该账户已被永久封停。\\",\\"disposal_term\\":\\"永久\\",\\"disposal_type\\":30001}"}',
            'publish_time' => 1778061279885,
            'timestamp' => 1778061280253,
            'nonce' => 7398102413060399367,
            'subscribe_task_id' => 1863694932603160,
            'app_id' => 123456,
        ];

        \think\Log::write("测试回调数据：" . json_encode($data), 'nsd');

        // 保存回调数据到数据库
        $this->saveCallbackData($data);

        return $this->responseJson(200, "ok");
    }

    /**
     * 保存回调数据到数据库
     * 使用 insert 替代 save，避免模型事件开销，并利用 ON DUPLICATE KEY UPDATE 防重
     *
     * @param array $data 回调数据
     * @return void
     */
    private function saveCallbackData(array $data): void
    {
        try {
            $messageId = $data['message_id'] ?? '';
            $accountRelation = $data['account_relation'] ?? [];
            $callbackData = $data['data'] ?? '';

            // 解析data字段内容 - data字段是JSON字符串
            $dataContent = '';
            $advertiserId = 0;
            $accountId = 0;
            $accountType = '';
            $contentAdvId = '';
            $contentDisposalType = 0;
            $contentDisposalId = '';
            $contentCreateTime = 0;
            $contentDisposalReason = '';
            $contentDisposalTerm = '';

            if (!empty($callbackData)) {
                $dataContent = is_string($callbackData) ? $callbackData : json_encode($callbackData);

                // 尝试解析data字段 (它是JSON字符串)
                $parsedData = is_string($callbackData) ? json_decode($callbackData, true) : $callbackData;
                if (json_last_error() !== JSON_ERROR_NONE) {
                    \think\Log::write("data字段JSON解析错误: " . json_last_error_msg() . ", 原始数据: " . substr($dataContent, 0, 500), 'nsd');
                }
                \think\Log::write("解析data字段 - 原始: " . substr(is_string($callbackData) ? $callbackData : json_encode($callbackData), 0, 200) . ", 解析结果: " . json_encode($parsedData), 'nsd');
                if (is_array($parsedData)) {
                    $advertiserId = $parsedData['advertiser_id'] ?? 0;
                    $accountId = $parsedData['account_id'] ?? 0;
                    $accountType = $parsedData['account_type'] ?? '';
                    \think\Log::write("提取字段 - advertiser_id: {$advertiserId}, account_id: {$accountId}, account_type: {$accountType}", 'nsd');

                    // 解析content字段 (它也是JSON字符串，需要二次解码)
                    $contentStr = $parsedData['content'] ?? '';
                    \think\Log::write("content字段原始值: " . substr($contentStr, 0, 200), 'nsd');
                    if (!empty($contentStr)) {
                        $content = is_string($contentStr) ? json_decode($contentStr, true) : $contentStr;
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            \think\Log::write("content字段JSON解析错误: " . json_last_error_msg() . ", 原始数据: " . substr($contentStr, 0, 500), 'nsd');
                        }
                        \think\Log::write("content解码结果: " . json_encode($content), 'nsd');
                        if (is_array($content)) {
                            $contentAdvId = $content['adv_id'] ?? '';
                            $contentCreateTime = $content['create_time'] ?? 0;
                            $contentDisposalId = $content['disposal_id'] ?? '';
                            $contentDisposalReason = $content['disposal_reason'] ?? '';
                            $contentDisposalTerm = $content['disposal_term'] ?? '';
                            $contentDisposalType = $content['disposal_type'] ?? 0;
                            \think\Log::write("提取content字段 - adv_id: {$contentAdvId}, disposal_type: {$contentDisposalType}", 'nsd');
                        }
                    }
                } else {
                    \think\Log::write("data字段解析失败，不是数组", 'nsd');
                }
            }

            // 解析account_relation中的core_user_ids
            $coreUserIds = '[]';
            if (!empty($accountRelation)) {
                $relation = is_string($accountRelation) ? json_decode($accountRelation, true) : $accountRelation;
                if (is_array($relation) && isset($relation['core_user_ids'])) {
                    $coreUserIds = json_encode(is_array($relation['core_user_ids']) ? $relation['core_user_ids'] : []);
                }
            }

            $accountRelationJson = is_string($accountRelation) ? $accountRelation : json_encode($accountRelation);

            // 使用原生 insert 避免模型事件开销，ON DUPLICATE KEY UPDATE 保证幂等性
            $now = time();
            \think\Db::name('non_score_disposal')->insert([
                'message_id'            => $messageId,
                'advertiser_id'         => $advertiserId,
                'account_id'            => $accountId,
                'account_type'          => $accountType,
                'account_relation'      => $accountRelationJson,
                'core_user_ids'         => $coreUserIds,
                'data_content'          => $dataContent,
                'content_adv_id'        => $contentAdvId,
                'content_disposal_type' => $contentDisposalType,
                'content_disposal_id'   => $contentDisposalId,
                'content_create_time'   => $contentCreateTime,
                'content_disposal_reason' => $contentDisposalReason,
                'content_disposal_term' => $contentDisposalTerm,
                'create_time'           => $now,
                'update_time'           => $now,
            ], true, true);

            \think\Log::write("非积分处置回调数据保存成功，message_id: {$messageId}, advertiser_id: {$advertiserId}, disposal_type: {$contentDisposalType}", 'nsd');
        } catch (\Exception $e) {
            \think\Log::error("非积分处置回调数据保存失败: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString(), 'nsd');
        }
    }

    /**
     * 返回标准JSON响应
     *
     * @param int $statusCode
     * @param string $statusMessage
     * @param array $data
     * @return Json
     */
    private function responseJson(int $statusCode, string $statusMessage, array $data = []): Json
    {
        $responseData = [
            "base_resp" => [
                "status_code" => $statusCode,
                "status_message" => $statusMessage
            ]
        ];
        $responseData = array_merge($responseData, $data);
        return json($responseData);
    }

}