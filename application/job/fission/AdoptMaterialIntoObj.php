<?php

namespace app\job\fission;

use app\common\model\Queue;
use app\common\model\viral_fission\FissionDeriveMaterial;
use app\common\model\viral_fission\FissionMaterialTask;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use jlqc\FundManagement;
use think\Cache;
use think\Exception;
use think\Db;

class AdoptMaterialIntoObj extends BaseJob
{

    public function __construct()
    {
        $this->queueRecordModelName = '\app\common\model\viral_fission\FissionQueue';
    }

    protected function getJobName(): string
    {
        return "采纳素材到计划里";
    }

    protected function getQueueName(): string
    {
        return 'adoptMaterialIntoObj';
    }

    /**
     * @throws Exception
     */
    protected function doJob($data)
    {
        // 检查广告主是否在黑名单中
        $advId = $data['adv_id'] ?? '';
        if ($this->isAdvInBlacklist($advId)) {
            echo "跳过黑名单广告主: {$advId}\n";
            return true;
        }

        // 检查是否有相同的任务正在执行或已完成
        if ($this->isDuplicateTask($data)) {
            echo "发现重复任务，跳过执行\n";
            return true;
        }

        $requests = $this->buildGuzzleRequest($data);
        if (!$requests) {
            echo "都是空吗";
            return true;
        }
        $insertData = $this->sendGuzzleRequest($requests);

        if (empty($insertData)) {
            echo "空的";
            return true;
        } else {
            echo "sendGuzzleRequest已处理" . count($insertData) . "条记录";
            return true;
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
        $url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/uni_promotion/ad/material/add/";
        $headers = [
            'Access-Token' => $access_token,
            'Content-Type' => 'application/json'
        ];
        $requests = [];


        // 先构建所有video_material
        $video_materials = [];
        foreach ($data['video_ids'] as $video_id) {
            if ($video_id && isset($data['cover_ids'][$video_id])) {
                $cover_id = $data['cover_ids'][$video_id];
                // 检查是否有对应的图片ID
                if (!empty($cover_id)) {
                    $video_materials[] = [
                        'image_mode' => 'VIDEO_VERTICAL',
                        'video_id' => $video_id,
                        'video_cover_id' => $cover_id
                    ];
                }
//                else {
//                    // 如果没有图片ID，仍然添加视频素材但不包含封面
//                    $video_materials[] = [
//                        'image_mode' => 'VIDEO_VERTICAL',
//                        'video_id' => $video_id,
//                    ];
//                }
            }
        }

        // 只有当有视频素材时才处理
        if (!empty($video_materials)) {
            foreach ($data['obj_ids'] as $obj_id => $product_ids) {
                // 为同一个ad_id构建所有product的创意列表
                $multi_product_creative_list = [];
                foreach ($product_ids as $product_id) {
                    $multi_product_creative_list[] = [
                        'product_id' => $product_id,
                        'video_material' => $video_materials
                    ];
                }

                // 为每个ad_id创建一个请求，包含所有product_id
                $params = [
                    'advertiser_id' => (int)$data['adv_id'],
                    'ad_id' => (int)$obj_id,
                    'multi_product_creative_list' => $multi_product_creative_list
                ];
                // 设置Content-Type为JSON格式
                $headers['Content-Type'] = 'application/json';
                // 将参数编码为JSON格式
                $body = json_encode($params);
                $request = new Request('POST', $url, $headers, $body);
                $requests[] = ['request' => $request, 'params' => $params];
            }
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
                'pool' => ['max_connections' => 50, 'idle_timeout' => 30],
                'proxy'=>"183.6.112.44:23100"
            ]);
        }
        $pool = new Pool($this->connectionPool, array_column($requests, 'request'), [
            'concurrency' => 5,  // 控制并发数
            'fulfilled' => function ($response, $index) use (&$insertData, $requests) {
                $resData = json_decode($response->getBody()->getContents(), true);
                $request_info = $requests[$index]['params'];
                $adv_id = $request_info['advertiser_id'];
                $obj_id = $request_info['ad_id'];

                if($resData['code'] == 0 && $resData['message'] == "OK"){
//                    dump($resData['data']);
                    // 请求成功，收集每个产品的成功记录
                    foreach ($request_info['multi_product_creative_list'] as $product_creative) {
                        $product_id = $product_creative['product_id'];
                        // 提取视频ID列表
                        $video_ids = array_column($product_creative['video_material'], 'video_id');
                        $mid = implode(',', $video_ids);

                        $insertData[] = $this->createRecordData(
                            $adv_id,
                            $obj_id,
                            $product_id,
                            $mid,
                            'success',
                            $resData['request_id'] ?? ''
                        );
                    }
                    echo "Success: adv_id={$adv_id}, obj_id={$obj_id}\n";
                }else{
                    // 请求失败，根据错误类型决定处理方式
                    $errorMessage = $resData['message'] ?? 'Unknown error';
                    // 定义需要跳过（不重试）的错误类型
                    $skipErrors = ['当前广告主状态已禁用', '抖音',
                        '视频不存在，请删除后重新制作上传','在当前计划中不存在',
                        '当前账户已失去该抖音号下对应店铺的商品全域推广权限','添加素材数量超过上限，请刷新后重试',
                        '操作失败，至少需要一个标题用于投放','视频时长需要<=300s，请调整后上传，当前视频时长',
                        '计划已删除，不支持修改','No permission to operate account'];
                    $shouldSkip = false;

                    foreach ($skipErrors as $skipError) {
                        if (strpos($errorMessage, $skipError) !== false) {
                            $shouldSkip = true;
                            break;
                        }
                    }
                    if ($shouldSkip) {
                        // 跳过的错误，直接记录到数据库
                        foreach ($request_info['multi_product_creative_list'] as $product_creative) {
                            $product_id = $product_creative['product_id'];
                            $video_ids = array_column($product_creative['video_material'], 'video_id');
                            $mid = implode(',', $video_ids);
                            $insertData[] = $this->createRecordData(
                                $adv_id,
                                $obj_id,
                                $product_id,
                                $mid,
                                'failed',
                                $resData['request_id'] ?? '',
                                $errorMessage
                            );
                        }
                        echo "Failed (skipped): {$errorMessage}, adv_id={$adv_id}, obj_id={$obj_id}\n";
                    } else {
                        // 其他错误，记录失败状态，不重试（避免重复）
                        foreach ($request_info['multi_product_creative_list'] as $product_creative) {
                            $product_id = $product_creative['product_id'];
                            $video_ids = array_column($product_creative['video_material'], 'video_id');
                            $mid = implode(',', $video_ids);
                            $insertData[] = $this->createRecordData(
                                $adv_id,
                                $obj_id,
                                $product_id,
                                $mid,
                                'failed',
                                $resData['request_id'] ?? '',
                                $errorMessage
                            );
                        }
                        echo "Failed (recorded): {$errorMessage}, adv_id={$adv_id}, obj_id={$obj_id}\n";
                    }
                }
            },
            'rejected' => function ($reason, $index) use ($requests, &$insertData) {
                $request_info = $requests[$index]['params'];
                $adv_id = $request_info['advertiser_id'];
                $obj_id = $request_info['ad_id'];

                // 网络请求失败，记录失败状态，不重试（避免重复）
                foreach ($request_info['multi_product_creative_list'] as $product_creative) {
                    $product_id = $product_creative['product_id'];
                    $video_ids = array_column($product_creative['video_material'], 'video_id');
                    $mid = implode(',', $video_ids);
                    $insertData[] = $this->createRecordData(
                        $adv_id,
                        $obj_id,
                        $product_id,
                        $mid,
                        'network_failed',
                        '',
                        $reason
                    );
                }

                echo "Network Request {$index} failed: " . $reason . ", adv_id={$adv_id}, obj_id={$obj_id}, 已记录失败状态\n";
            },]);
        $promise = $pool->promise();
        $promise->wait();
        // 去重后批量插入所有记录
        $this->saveAdoptRecord($this->removeDuplicateRecords($insertData));
        return $insertData;
    }

    private function saveAdoptRecord(array $insertData)
    {
        // 批量插入记录
        if (!empty($insertData)) {
            try {
                Db::name('fission_into_obj_record')->insertAll($insertData);
                echo "成功插入 " . count($insertData) . " 条记录\n";
            } catch (\Exception $e) {
                // 如果批量插入失败（可能是重复键冲突），尝试逐条插入
                echo "批量插入失败，尝试逐条插入: " . $e->getMessage() . "\n";
                $successCount = 0;
                $skipCount = 0;

                foreach ($insertData as $record) {
                    try {
                        Db::name('fission_into_obj_record')->insert($record);
                        $successCount++;
                    } catch (\Exception $ex) {
                        if (strpos($ex->getMessage(), 'Duplicate entry') !== false) {
                            $skipCount++;
                            echo "跳过重复记录: adv_id={$record['adv_id']}, obj_id={$record['obj_id']}, product_id={$record['product_id']}\n";
                        } else {
                            echo "插入失败: " . $ex->getMessage() . "\n";
                        }
                    }
                }

                echo "逐条插入完成: 成功 {$successCount} 条, 跳过重复 {$skipCount} 条\n";
            }
        }
    }

    /**
     * 去除重复记录
     * @param array $insertData
     * @return array
     */
    private function removeDuplicateRecords($insertData)
    {
        $uniqueRecords = [];
        $seen = [];

        foreach ($insertData as $record) {
            // 使用 adv_id + obj_id + product_id + mid 作为唯一标识
            // 因为同一个产品可以添加不同的素材组合
            $key = $record['adv_id'] . '_' . $record['obj_id'] . '_' . $record['product_id'] . '_' . $record['mid'];

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $uniqueRecords[] = $record;
            } else {
                echo "发现重复记录: adv_id={$record['adv_id']}, obj_id={$record['obj_id']}, product_id={$record['product_id']}, mid={$record['mid']}\n";
            }
        }

        $duplicateCount = count($insertData) - count($uniqueRecords);
        if ($duplicateCount > 0) {
            echo "去重完成: 原始 " . count($insertData) . " 条, 去重后 " . count($uniqueRecords) . " 条, 去除重复 {$duplicateCount} 条\n";
        }

        return $uniqueRecords;
    }

    /**
     * 检查是否为重复任务
     * @param array $data
     * @return bool
     */
    private function isDuplicateTask($data)
    {
        // 生成任务唯一标识
        $taskKey = $this->generateTaskKey($data);

        // 检查是否有相同的任务在队列中（状态为0待执行或1已完成）
        $existingTasks = \think\Db::name('fission_queue')
            ->where('class_name', static::class)
            ->where('status', 'in', [0, 1]) // 0=待执行, 1=已完成
            ->where('create_time', '>', time() - 7200) // 检查2小时内的任务
            ->select();

        foreach ($existingTasks as $existingTask) {
            $existingData = json_decode($existingTask['job_data'], true);
            if ($existingData && isset($existingData['adv_id'])) {
                $existingKey = $this->generateTaskKey($existingData);

                if ($taskKey === $existingKey) {
                    echo "发现重复任务: task_id={$existingTask['id']}, status={$existingTask['status']}, adv_id={$existingData['adv_id']}\n";
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 生成任务唯一标识
     * @param array $data
     * @return string
     */
    private function generateTaskKey($data)
    {
        // 使用 adv_id + obj_ids + video_ids 生成唯一标识
        $objIds = is_array($data['obj_ids']) ? array_keys($data['obj_ids']) : [];
        $videoIds = is_array($data['video_ids']) ? $data['video_ids'] : [];

        sort($objIds);
        sort($videoIds);

        return md5($data['adv_id'] . '|' . implode(',', $objIds) . '|' . implode(',', $videoIds));
    }

    /**
     * 创建标准的插入数据结构
     * @param string $adv_id
     * @param string $obj_id
     * @param string $product_id
     * @param string $mid
     * @param string $status
     * @param string $request_id
     * @param string|null $reason
     * @return array
     */
    private function createRecordData($adv_id, $obj_id, $product_id, $mid, $status, $request_id = '', $reason = null)
    {
        return [
            'adv_id' => $adv_id,
            'obj_id' => $obj_id,
            'product_id' => $product_id,
            'mid' => $mid,
            'reason' => $reason,
            'status' => $status,
            'request_id' => $request_id,
            'create_time' => time()
        ];
    }

    /**
     * 检查广告主是否在黑名单中
     * @param string $advId 广告主ID
     * @return bool
     */
    private function isAdvInBlacklist($advId): bool
    {
        if (empty($advId)) {
            return false;
        }

        // 获取黑名单公司列表
        $blackCompanyList = $this->getBlackCompanyList();
        if (empty($blackCompanyList)) {
            return false;
        }

        // 获取广告主对应的公司名称
        $companyName = $this->getCompanyNameByAdvId($advId);
        if (empty($companyName)) {
            return false;
        }

        return in_array($companyName, $blackCompanyList);
    }

    /**
     * 获取黑名单公司列表
     * @return array
     */
    private function getBlackCompanyList(): array
    {
        $config_file_path = APP_PATH . 'api/controller/fission/black_company_config_fission.php';

        if (file_exists($config_file_path)) {
            try {
                $black_company_list = include $config_file_path;
                if (is_array($black_company_list) && !empty($black_company_list)) {
                    return $black_company_list;
                }
            } catch (\Exception $e) {
                echo "读取黑名单配置文件失败: " . $e->getMessage() . "\n";
            }
        }

        return [];
    }

    /**
     * 根据广告主ID获取公司名称
     * @param string $advId 广告主ID
     * @return string
     */
    private function getCompanyNameByAdvId($advId): string
    {
        try {
            $company = \think\Db::name('company')
                ->where('advertiser_id', $advId)
                ->value('company_name');

            return $company ?: '';
        } catch (\Exception $e) {
            echo "获取公司名称失败: " . $e->getMessage() . "\n";
            return '';
        }
    }
}