<?php

namespace txy;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Ocr\V20181119\OcrClient;
use TencentCloud\Ocr\V20181119\Models\BankSlipOCRRequest;

class TextRecognition {

    public static function get_image_info($SecretId,$SecretKey,$url){
        $cred = new Credential($SecretId, $SecretKey);
        // 实例化一个http选项，可选的，没有特殊需求可以跳过
        $httpProfile = new HttpProfile();
        $httpProfile->setEndpoint("ocr.tencentcloudapi.com");

        // 实例化一个client选项，可选的，没有特殊需求可以跳过
        $clientProfile = new ClientProfile();
        $clientProfile->setHttpProfile($httpProfile);
        // 实例化要请求产品的client对象,clientProfile是可选的
        $client = new OcrClient($cred, "ap-guangzhou", $clientProfile);

        // 实例化一个请求对象,每个接口都会对应一个request对象
        $req = new BankSlipOCRRequest();
        $params = array(
            "ImageUrl" => $url,
        );

        $req->fromJsonString(json_encode($params));
        //本地使用以下操作
//        if(true) {
//            $image_data = file_get_contents($url);
//            if ($image_data === false) {
//                echo "Failed to load image from URL.";
//            } else {
//                $base64_image = base64_encode($image_data);
//                $params = array(
//                    "ImageBase64" => $base64_image,
//                );
//                $req->fromJsonString(json_encode($params));
//            }
//        }

        // 返回的resp是一个BankSlipOCRResponse的实例，与请求对象对应
        $resp = $client->BankSlipOCR($req);

        return json_decode(json_encode($resp),true);
        // 输出json格式的字符串回包
        return $resp->toJsonString();

    }
}
