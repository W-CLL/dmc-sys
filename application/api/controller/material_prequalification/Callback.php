<?php

namespace app\api\controller\material_prequalification;

use app\common\controller\Api;
use app\common\model\Queue;
use think\Log;
use think\response\Json;
use think\Env;
use app\api\controller\fission\AuthTokenUtil;

class Callback
{
    /**
     * 巨量千川素材状态推送回调
     * 类型：status.material.qianchuan.realtime
     *
     * @return Json
     */
    public function materialStatusCallback(): Json
    {
        $params = input();

        // 验证webhook
        if (isset($params['event']) && $params['event'] == "verify_webhook") {
            $challenge = $_GET['challenge'];
            return $this->responseJson(200, "ok", ["challenge" => (int)$challenge]);
        }

        // 获取签名密钥
        $secret_key = Env::get('dmc_spi.change_material_spi');
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
        \think\Queue::push('app\job\prequalification\InsOrDel', json_decode($body,true), 'insOrDel');
        return $this->responseJson(200, "ok");
    }




    /**
     * 预审结果回调
     * 类型：ad.audit.material_precheck_ecp
     * url:https://open.oceanengine.com/labels/12/docs/1826355582866074
     *
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
        $secret_key = Env::get('dmc_spi.material_result_api');
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
        \think\Log::write("预审结果回调参数：".json_encode($data), 'ysjg');
        $data = json_decode($data['data'],true);
        $content = $data['content'] ?? '';
        if ($content) {
            // 将content放入redis列表，右进
            $redisKey = 'material_precheck_result';
            \think\Cache::store('redis')->handler()->rpush($redisKey, $content);
        }

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