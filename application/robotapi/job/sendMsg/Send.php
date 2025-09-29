<?php

namespace app\robotapi\job\sendMsg;

use Requests;
class Send
{
    public function doJob($data)
    {
        usleep(500000); // 500毫秒
        $url = $data["url"];
        $params = $data["params"];
        try {
            $res = Requests::post($url, json_encode($params, JSON_UNESCAPED_UNICODE), array(
                'Content-Type:' . 'application/json'
            ));
            if ($res == "ok"){
                return true;
            }
            return false;
        }catch (\Exception $e){
            return false;
        }
    }
}