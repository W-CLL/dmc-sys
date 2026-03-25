<?php

namespace app\api\controller\material_diagnosis;

use app\api\controller\fission\AuthTokenUtil;
use think\Env;
use think\response\Json;

class Callback
{
    /**
     * 前测结果回调
     * @return Json
     */
    public function result(): Json
    {
        $params = input();

        // 验证webhook
        if (isset($params['event']) && $params['event'] == "verify_webhook") {
            $challenge = $_GET['challenge'];
            return $this->responseJson(200, "ok", ["challenge" => (int)$challenge]);
        }

        // 获取签名密钥
        $secret_key = Env::get('dmc_spi.material_diagnosis_api');
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
        \think\Log::write("结果回调参数：".json_encode($data), 'scqc');
//        $data = json_decode($data['data'],true);
//        $content = $data['content'] ?? '';
//        if ($content) {
//            // 将content放入redis列表，右进
//            $redisKey = 'material_precheck_result';
//            \think\Cache::store('redis')->handler()->rpush($redisKey, $content);
//        }

        return $this->responseJson(200, "ok");
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