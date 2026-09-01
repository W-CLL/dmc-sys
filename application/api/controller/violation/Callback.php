<?php

namespace app\api\controller\violation;

use app\api\controller\fission\AuthTokenUtil;
use qywx\Api;
use think\Cache;
use think\Db;
use think\Env;
use think\response\Json;

class Callback
{

    protected $status = [
        "APPEAL" => 1,
        "FAILAPPEAL" => 2,
        "ONAPPEAL" => 3,
        "VALID" => 4,
    ];

    protected $illegal_type = [
        "ONECLASS" => 1,
        "TWOTHREECLASS" => 2,
    ];


    protected $event = [
        "INSERT" => 1,
        "UPDATE" => 2,
    ];

    /**
     * 违规积分回调
     * 类型：adv.violation.score_qianchuan
     * url:https://open.oceanengine.com/labels/12/docs/1809815674614937?origin=left_nav
     *
     * @return Json
     */
    public function violation(): Json
    {
        $params = input();

        // 验证webhook
        if (isset($params['event']) && $params['event'] == "verify_webhook") {
            $challenge = $_GET['challenge'];
            return $this->responseJson(200, "ok", ["challenge" => (int)$challenge]);
        }

        // 获取签名密钥
        $secret_key = Env::get('dmc_spi.material_violation_api');
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
        $info = json_decode($data['data'],true);
        $content = json_decode($info['content'],true);
        \think\Log::write("违规积分回调参数：".json_encode($data), 'wgjf');
        $insert = [
            "advertiser_id" => $content['advertiser_id'],
            "ad_id" => $content['ad_id'],
            "material_id" => $content['material_id'],
            "event_id" => $content['event_id'],
            "violation_evidence_img" => $content['violation_evidence_img'] ?? NULL,
            "type" => $this->event[$info['event']] ?? 0,
            "score" => $content['score'],
            "reject_reason" => $content['reject_reason'],
            "status" => $this->status[$content['status']] ?? 0,
            "illegal_type" => $this->illegal_type[$content['illegal_type']] ?? 0,
            "create_time" => time()
        ];
        Db::name('violation')->insert($insert);
        if (in_array($insert['status'],[1,4])){
            $prefix = $insert['status'] == 1 ? '扣分' : '回调';
            $msg = "千川ID：".$insert['advertiser_id'].'。积分变动('.$prefix.')：'.$insert['score']."。";
            Api::send_application_messages('WuZhongJie|WuLiQiong01', $msg);
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