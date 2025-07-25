<?php

namespace app\fission\job;

use app\common\model\viral_fission\AdvGlobalMaterial;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use think\Cache;
use think\Exception;


class InsertGlobalMaterial extends BaseJob
{


    protected function getJobName(): string
    {
        return '插入全域素材数据';
    }


    protected function getQueueName(): string
    {
        return 'insertGlobalMaterial';
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    protected function doJob($data): bool
    {
        $requests = $this->buildGuzzleRequest($data);
        $insertData = $this->sendGuzzleRequest($requests);

        if (empty($insertData)) {
            echo "空的";
            return true;
        } else {
            echo "即将插入" . count($insertData) . "条";
            return $this->saveMaterial($insertData);
        }

    }

    /**
     * 插入素材消耗
     * @param $list
     * @return bool
     * @throws \Exception
     */
    protected function saveMaterial($list)
    {
        $materialModel = new AdvGlobalMaterial();
        foreach ($list as &$item) {
            $where = [
                'adv_id' => $item['adv_id'],
                'material_id' => $item['material_id'],
                'cost_date' => $item['cost_date']
            ];
            $info = $materialModel->where($where)->find();
            if ($info) {
                $item['id'] = $info['id'];
            }
        }
        $res = $materialModel->saveAll($list);
        if ($res) {
            return true;
        } else {
            return false;
        }
    }


    /**
     * 构建请求
     * @param $data
     * @return array
     */
    protected function buildGuzzleRequest($data): array
    {

        $access_token = Cache::get("qc_access_token");
        $url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/report/custom/get/";
        $headers = [
            'Access-Token' => $access_token,
            'Content-Type' => 'application/json'
        ];
        $requests = [];
        foreach ($data['adv_list'] as $adv_id) {
            $params = [
                'advertiser_id' => (int)$adv_id,
                'data_topic' => "SITE_PROMOTION_PRODUCT_POST_DATA_VIDEO",
                'dimensions' => json_encode([
                    'stat_time_day', 'roi2_material_video_name'
                ]),
                'metrics' => json_encode([
                    "product_show_count_for_roi2",
                    "product_click_count_for_roi2",
                    "product_cvr_rate_for_roi2",
                    "product_convert_rate_for_roi2",
                    "stat_cost_for_roi2",
                    "total_prepay_and_pay_order_roi2",
                    "total_pay_order_gmv_for_roi2",
                    "total_pay_order_count_for_roi2",
                    "total_cost_per_pay_order_for_roi2",
                    "total_pay_order_coupon_amount_for_roi2",
                    "total_unfinished_estimate_order_gmv_for_roi2",
                ]),
                'filters' => json_encode([]),
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'order_by' => json_encode([['type' => 1, 'field' => 'stat_time_day']]),
                'page' => $data['page'] ?? 1,
                'page_size' => 100,
            ];

            $query = http_build_query($params);
            $urlWithQuery = $url . '?' . $query;
            $request = new Request('GET', $urlWithQuery, $headers);
            $requests[] = ['request' => $request, 'params' => $params];
        }
        return $requests;
    }

    /**
     * 发送请求
     * @param $requests
     * @return array
     */
    protected function sendGuzzleRequest($requests)
    {

        $insertData = [];
        if (empty($this->connectionPool)) {
            $this->connectionPool = new Client([
                'timeout' => 10,
                'verify' => false,
                'curl' => [CURLOPT_FORBID_REUSE => true, CURLOPT_FRESH_CONNECT => true],
                'pool' => ['max_connections' => 50, 'idle_timeout' => 30]
            ]);
        }
        $pool = new Pool($this->connectionPool, array_column($requests, 'request'), [
            'concurrency' => 5,  // 控制并发数
            'fulfilled' => function ($response, $index) use (&$insertData, $requests, &$adv_list, &$requestParams) {
                $resData = json_decode($response->getBody()->getContents(), true);

                $request_info = $requests[$index]['params'];
                $adv_id = $request_info['advertiser_id'];
                if ($resData['code'] ==0 && !empty($resData['data']['rows'])) {
                    foreach ($resData['data']['rows'] as $item) {
                        $dimensions = $item['dimensions'];
                        $metrics = $item['metrics'];
                        $material_id = $dimensions['material_id'];
                        $material_name = $dimensions['roi2_material_video_name'];
                        $stat_time_day = strtotime($dimensions['stat_time_day']);
                        $cost = str_replace(',', '', $metrics['stat_cost_for_roi2']); ;
                        $total_pay = $metrics['total_pay_order_count_for_roi2'];
                        $insertData[] = [
                            'adv_id' => $adv_id,
                            'material_id' => $material_id,
                            'roi2_material_video_name' => $material_name,
                            'stat_cost_for_roi2' => $cost,
                            'total_pay_order_count_for_roi2' => $total_pay,
                            'cost_date' => $stat_time_day,
                        ];
                    }
                }
                if ($resData['data']['pagination']['total_page'] > $request_info['page']) {
                    echo $resData['data']['pagination']['total_page'] . "页";
                    echo $adv_id . "  " . $request_info['page'];
                    $next = ['adv_list' => [$adv_id], 'start_time' => $request_info['start_time'], 'end_time' => $request_info['end_time'], 'page' => $request_info['page'] + 1];
                    \think\Queue::later(10, 'app\fission\job\InsertGlobalMaterial', $next, "insertGlobalMaterial");
                } elseif ($resData['code'] != 0) {
                    if (!skipIfContainsError($resData['message'], ['当前广告主状态已禁用'])) {
                        $next = ['adv_list' => [$adv_id], 'start_time' => $request_info['start_time'], 'end_time' => $request_info['end_time'], 'page' => $request_info['page']];
                        \think\Queue::later(10, 'app\fission\job\InsertGlobalMaterial', $next, "insertGlobalMaterial");
                    }
                }
            },
            'rejected' => function ($reason, $index) use ($requests) {
                echo "Request {$index} failed: ";
                $request_info = $requests[$index]['params'];
                $adv_id = $request_info['advertiser_id'];
                $next = ['adv_list' => [$adv_id], 'start_time' => $request_info['start_time'], 'end_time' => $request_info['end_time'], 'page' => $request_info['page']];
                \think\Queue::later(10, 'app\fission\job\InsertGlobalMaterial', $next, "insertGlobalMaterial");
                echo "失败请求重启\n";
            },]);
// 等待所有请求完成
        $promise = $pool->promise();
        $promise->wait();
        return $insertData;
    }


}
