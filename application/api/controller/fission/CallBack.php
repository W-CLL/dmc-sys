<?php

namespace app\api\controller\fission;

use app\common\model\Queue;
use think\Env;
use think\response\Json;
use app\fission\AuthTokenUtil;

class CallBack
{

    public function spiCallback(): Json
    {

        $params = input();
        if (isset($params['event']) && $params['event'] == "verify_webhook") {
            $challenge = $_GET['challenge'];
            return $this->responseJson(200, "ok", ["Challenge" => (int)$challenge]);
        }

        $secret_key = Env::get('dmc_spi.fission_spi');
        if(!$secret_key){
            \think\Log::error("spi密钥没设置");
            return $this->responseJson(400, "invalid signature");
        }
        $util = new AuthTokenUtil($secret_key);
        $request = \think\Request::instance();
        $headers = $request->header();
        $body = $request->getInput();
        $signature = $headers['x-open-signature'];
        $is_valid_token = $util->is_valid_token($body, $signature);

        $queue_model = new Queue();
        if (!$is_valid_token) {
            $queue_model->addQueue('事件推送','app\job\fission\Fission','fission',['type'=>'fail','data'=>json_decode($body,true),'msg'=>'验签失败']);
            return $this->responseJson(400, "invalid signature");
        }
        $queue_model->addQueue('事件推送','app\job\fission\Fission','fission',['type'=>"success","data"=>json_decode($body,true),'msg'=>'成功']);
        return $this->responseJson(200, "ok");
    }

    private function responseJson(int $statusCode, string $statusMessage, array $data = []): Json
    {
        $responseData = [
            "BaseResp" => [
                "StatusCode" => $statusCode,
                "StatusMessage" => $statusMessage
            ]
        ];

        $responseData = array_merge($responseData, $data);
        return json($responseData);
    }
}