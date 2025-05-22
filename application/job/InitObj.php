<?php

namespace app\job;

use app\job\Base\BaseInitObjJob;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use think\Cache;

class InitObjJob extends BaseInitObjJob
{
    public function getJobName(): string
    {
        return '获取第n页普通计划';
    }

    public function getQueueName(): string
    {
        return 'initObj';
    }

    protected function getModelClass(): string
    {
        return '\app\admin\model\QcObj';
    }

    /**
     * @throws GuzzleException
     */
    protected function fetchData(array $data): array
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/ad/get/";

        $client = new Client();
        $params = [
            "advertiser_id" => $data['advertiser_id'],
            "page" => $data['page'] ?? 1,
            "page_size" => 200,
            'filtering' => json_encode($data['filter']),
        ];

        $response = $client->get($url, [
            'headers' => [
                'Access-Token' => $access_token,
                'Content-Type' => 'application/json'
            ],
            'json' => $params
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    protected function buildInsertData(int $advId, array $item): array
    {
        return [
            'adv_id' => $advId,
            'obj_id' => $item['ad_id'],
            'name' => $item['name'],
            'obj_status' => $item['status'],
            'opt_status' => $item['opt_status'],
            'marketing_goal' => $item['marketing_goal'],
            'marketing_scene' => $item['marketing_scene'],
            'campaign_scene' => $item['campaign_scene'],
            'campaign_id' => $item['campaign_id'],
            'lab_ad_type' => $item['lab_ad_type'],
            'obj_create_time' => strtotime($item['ad_create_time']),
            'obj_modify_time' => strtotime($item['ad_modify_time']),
            'product_info' => json_encode($item['product_info']),
            'aweme_info' => json_encode($item['product_info']),
            'delivery_setting' => json_encode($item['delivery_setting']),
        ];
    }
}
