<?php

namespace app\job\fission;

use app\common\model\viral_fission\AdvGlobalObjMaterial;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use think\Cache;
use think\Exception;


class InsertGlobalObjMaterial extends BaseJob
{
    protected $connectionPool;
    protected $config;

    // 优化配置 - 从配置文件加载，针对抢占请求优化
    protected $maxRetries;
    protected $chunkSize;
    protected $maxConcurrency;
    protected $requestTimeout;

    public function __construct()
    {
        $this->queueRecordModelName = '\app\common\model\viral_fission\FissionQueue';

        // 加载配置
        $this->loadConfig();
    }

    /**
     * 加载配置文件
     */
    protected function loadConfig()
    {
        $configPath = __DIR__ . '/config/job_config.php';
        if (file_exists($configPath)) {
            $this->config = include $configPath;
        } else {
            // 默认配置
            $this->config = [
                'database' => ['chunk_size' => 30],
                'http' => ['max_concurrency' => 3, 'request_timeout' => 15],
                'retry' => ['max_retries' => 3],
            ];
        }

        // 设置属性 - 针对抢占请求优化
        $this->chunkSize = $this->config['database']['chunk_size'] ?? 30;
        $this->maxConcurrency = $this->config['http']['max_concurrency'] ?? 10; // 提高并发数
        $this->requestTimeout = $this->config['http']['request_timeout'] ?? 10; // 降低超时时间
        $this->maxRetries = $this->config['retry']['max_retries'] ?? 5; // 增加重试次数
    }

    protected function getJobName(): string
    {
        return '插入全域计划素材数据';
    }


    protected function getQueueName(): string
    {
        return 'insertGlobalObjMaterial';
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
            echo "空的数据，跳过处理\n";
            return true;
        } else {
            echo "即将插入" . count($insertData) . "条数据\n";
            return $this->saveMaterial($insertData);
        }
    }

    /**
     * 插入素材消耗 - 优化版本，支持批量处理和分块插入
     * @param $list
     * @return bool
     * @throws \Exception
     */
    protected function saveMaterial($list): bool
    {
        if (empty($list)) {
            return true;
        }
        $update = 0;
        $materialModel = new AdvGlobalObjMaterial();
        foreach ($list as &$item) {
            $where = [
                'adv_id' => $item['adv_id'],
                'material_id' => $item['material_id'],
                'obj_id' => $item['obj_id']
            ];
            $info = $materialModel->where($where)->find();

            if ($info) {
                $item['id'] = $info['id'];
                $update++;
            }
        }
        $res = $materialModel->saveAll($list);
        echo $update."更新\n";
        echo count($list)-$update."插入\n";
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
        $url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/uni_promotion/ad/material/get/";
        $headers = [
            'Access-Token' => $access_token,
            'Content-Type' => 'application/json'
        ];
        $requests = [];
        foreach ($data['obj_list'] as $obj_id) {
            $filters = [
                'material_type' => 'VIDEO',
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'material_select_type' => 'ALL',
                'material_status' => 'ALL',
                'analysis_type' => [
                    "FIRST_PUBLISH_MATERIAL",
                    "HIGH_QUALITY_MATERIAL",
                    "LOW_QUALITY_MATERIAL",
                    "INEFFICIENT_MATERIAL",
                    "CARRY_MATERIAL",
                    "SIMILAR_MATERIAL",
                ],
            ];
            $params = [
                'advertiser_id' => (int)$data['adv_id'],
                'ad_id' => $obj_id,
                'filtering' => json_encode($filters),
                "fields" => [
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
                ],
                'order_by' => json_encode([['type' => "DESC", 'field' => 'stat_cost_for_roi2']]),
                'page' => $data['page'] ?? 1,
                'page_size' => 100,
            ];

            $query = http_build_query($params);
            $urlWithQuery = $url . '?' . $query;
            $request = new Request('GET', $urlWithQuery, $headers);
            $requests[] = [
                'request' => $request,
                'params' => $params,
                'filters' => $filters  // 保存原始 filters 用于重试
            ];
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
                'timeout' => $this->requestTimeout,
                'connect_timeout' => 2, // 降低连接超时
                'read_timeout' => $this->requestTimeout,
                'verify' => false,
                'curl' => [
                    CURLOPT_FORBID_REUSE => false, // 允许连接复用
                    CURLOPT_FRESH_CONNECT => false, // 不强制新连接
                    CURLOPT_TCP_KEEPALIVE => 1,
                    CURLOPT_TCP_KEEPIDLE => 10, // 降低保活时间
                    CURLOPT_TCP_KEEPINTVL => 5,
                    CURLOPT_NOSIGNAL => 1, // 避免信号中断
                ],
                'pool' => ['max_connections' => 50, 'idle_timeout' => 30] // 增加连接池
            ]);
        }
        $pool = new Pool($this->connectionPool, array_column($requests, 'request'), [
            'concurrency' => $this->maxConcurrency,  // 提高并发数抢占请求
            'fulfilled' => function ($response, $index) use (&$insertData, $requests) {
                $resData = json_decode($response->getBody()->getContents(), true);
                $request_info = $requests[$index]['params'];
                $adv_id = $request_info['advertiser_id'];
                $obj_id = $request_info['ad_id'];

                if (!empty($resData['data']['ad_material_infos'])) {
                    foreach ($resData['data']['ad_material_infos'] as $item) {
                        $material_info = $item['material_info'] ?? [];
                        $product_info = $item['product_info'] ?? [];
                        $stats_info = $item['stats_info'] ?? [];

                        // 处理数值字段，移除逗号并转换为数值
                        $insertData[] = [
                            'adv_id' => $adv_id,
                            'obj_id' => $obj_id,
                            'material_id' => $material_info['video_material']['material_id'] ?? '',
                            'product_show_count_for_roi2' => $this->formatDecimal($stats_info['product_show_count_for_roi2'] ?? 0),
                            'product_click_count_for_roi2' => $this->formatDecimal($stats_info['product_click_count_for_roi2'] ?? 0),
                            'product_cvr_rate_for_roi2' => $this->formatDecimal($stats_info['product_cvr_rate_for_roi2'] ?? 0),
                            'product_convert_rate_for_roi2' => $this->formatDecimal($stats_info['product_convert_rate_for_roi2'] ?? 0),
                            'stat_cost_for_roi2' => $this->formatDecimal($stats_info['stat_cost_for_roi2'] ?? 0),
                            'total_prepay_and_pay_order_roi2' => $this->formatDecimal($stats_info['total_prepay_and_pay_order_roi2'] ?? 0),
                            'total_pay_order_gmv_for_roi2' => $this->formatDecimal($stats_info['total_pay_order_gmv_for_roi2'] ?? 0),
                            'total_pay_order_count_for_roi2' => $this->formatDecimal($stats_info['total_pay_order_count_for_roi2'] ?? 0),
                            'total_cost_per_pay_order_for_roi2' => $this->formatDecimal($stats_info['total_cost_per_pay_order_for_roi2'] ?? 0),
                            'total_pay_order_coupon_amount_for_roi2' => $this->formatDecimal($stats_info['total_pay_order_coupon_amount_for_roi2'] ?? 0),
                            'total_unfinished_estimate_order_gmv_for_roi2' => $this->formatDecimal($stats_info['total_unfinished_estimate_order_gmv_for_roi2'] ?? 0),
                            'is_delete' => $item['is_delete'] ? 1 : 0,
                            'product_info' => !empty($product_info) ? json_encode($product_info) : null,
                            'material_status' => $item['material_status'] ?? null,
                            'material_type' => $material_info['material_type'] ?? null,
                            'material_select_type' => $item['material_select_type'] ?? null,
                            'material_info' => !empty($material_info) ? json_encode($material_info) : null,
                            'audit_status' => $item['audit_status'] ?? null,
                        ];
                    }
                    if (isset($resData['data']['page_info']) && $resData['data']['page_info']['total_page'] > $request_info['page']) {
                        echo $resData['data']['page_info']['total_page'] . "页";
                        echo $adv_id . "  " . $request_info['page'];
                        $filters = $requests[$index]['filters'];
                        $next = [
                            'adv_id' => $adv_id,
                            'obj_list' => [$obj_id],
                            'start_time' => $filters['start_time'],
                            'end_time' => $filters['end_time'],
                            'page' => $request_info['page'] + 1
                        ];
                        try {
                            \think\Queue::later(1, 'app\job\fission\InsertGlobalObjMaterial', $next, "insertGlobalObjMaterial"); // 立即执行
                        } catch (\Exception $e) {
                            echo "队列操作失败: " . $e->getMessage() . "\n";
                        }
                    }
                } elseif ($resData['code'] != 0) {
//                    echo $resData['message'];
                    if (!skipIfContainsError($resData['message'], ['当前广告主状态已禁用'])) {
//                        echo $resData['message'];
                        $filters = $requests[$index]['filters'];
                        $next = [
                            'adv_id' => $adv_id,
                            'obj_list' => [$obj_id],
                            'start_time' => $filters['start_time'],
                            'end_time' => $filters['end_time'],
                            'page' => $request_info['page']
                        ];
                        try {
                            \think\Queue::later(1, 'app\job\fission\InsertGlobalObjMaterial', $next, "insertGlobalObjMaterial"); // 立即执行
                        } catch (\Exception $e) {
                            echo "队列操作失败: " . $e->getMessage() . "\n";
                        }
                    }
                }
            },
            'rejected' => function ($reason, $index) use ($requests) {
                echo "Request {$index} failed: " . $reason;
                $request_info = $requests[$index]['params'];
                $filters = $requests[$index]['filters'];
                $adv_id = $request_info['advertiser_id'];
                $obj_id = $request_info['ad_id'];
                $next = [
                    'adv_id' => $adv_id,
                    'obj_list' => [$obj_id],
                    'start_time' => $filters['start_time'],
                    'end_time' => $filters['end_time'],
                    'page' => $request_info['page']
                ];
                try {
                    \think\Queue::later(1, 'app\job\fission\InsertGlobalObjMaterial', $next, "insertGlobalObjMaterial"); // 立即执行
                } catch (\Exception $e) {
                    echo "队列操作失败: " . $e->getMessage() . "\n";
                }
                echo "失败请求重启\n";
            },]);

        $promise = $pool->promise();
        $promise->wait();
        return $insertData;
    }

    /**
     * 格式化数值字段，移除逗号并转换为数值
     * @param mixed $value
     * @return float
     */
    protected function formatDecimal($value): float
    {
        if (is_null($value) || $value === '') {
            return 0.00;
        }

        // 移除逗号和其他非数字字符（保留小数点和负号）
        $cleanValue = preg_replace('/[^\d.-]/', '', $value);

        return (float)$cleanValue;
    }

}
