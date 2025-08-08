<?php

namespace app\api\controller\fission;

use app\admin\model\Company;
use app\admin\model\QcGlobalObj;
use app\common\model\viral_fission\AdvGlobalMaterial;
use app\common\model\viral_fission\AdvGlobalObjMaterial;
use app\common\model\viral_fission\FissionDeriveMaterial;
use app\common\model\viral_fission\FissionMaterialTask;
use jlqc\FundManagement;
use think\Cache;
use think\Controller;
use think\Db;
use think\db\Query;
use think\Exception;
use think\Queue;

class QcGlobal extends Controller
{

    public function getMaterialDayCost($hour = 2, $day = ''): string
    {
        if ($day !== '' && (!is_numeric($day) || $day < 0)) {
            return "天数参数无效";
        }
        if (!is_numeric($hour) || $hour < 0) {
            return "小时参数无效";
        }

        $cacheKey = 'active_advertiser_list';
        $company = new Company();
        $adv_list = \think\Cache::remember($cacheKey, function () use ($company) {
            return $company->where(['adv_status' => 1])
                ->order('advertiser_id', 'desc')
                ->column('advertiser_id');
        }, 300); // 缓存5分钟

        if (!$adv_list) {
            return "无数据可处理";
        }

        $currentTime = time();

        if ($day) {
            // 如果设置了天数，从指定天数前的0点开始
            $dayCount = intval($day);
            $startTime = strtotime(date('Y-m-d 00:00:00', strtotime("-{$dayCount} days")));
            $endTime = $currentTime;
        } else {
            // 如果只设置了小时数，从当前时间往前推算
            $startTime = $currentTime - (60 * 60 * $hour);
            $endTime = $currentTime;
        }

        $timeData = [
            'start_time' => date("Y-m-d H:i:s", $startTime),
            'end_time' => date('Y-m-d H:i:s', $endTime)
        ];

        $batchSize = 30;
        $chunks = array_chunk($adv_list, $batchSize);
        $queueCount = 0;

        foreach ($chunks as $chunk) {
            Queue::push('app\job\fission\InsertGlobalMaterial', [
                'adv_list' => $chunk,
                'start_time' => $timeData['start_time'],
                'end_time' => $timeData['end_time']
            ], 'insertGlobalMaterial');
            $queueCount++;
        }

        return "已处理所有数据，共" . count($adv_list) . "条记录，分" . $queueCount . "个批次处理";
    }

    public function getObjMaterialDayCost($is_first = false, $day = 32): string
    {

//        Queue::push('app\job\fission\InsertGlobalObjMaterial', [
//            'adv_id'=>1815668830616585,
//            'obj_list' => [1823682547618939],
//            'start_time' =>"2025-05-01",
//            'end_time' => "2025-08-07"
//        ], 'insertGlobalObjMaterial');
//        die;
        if ($day !== '' && (!is_numeric($day) || $day < 0)) {
            return "天数参数无效";
        }
        if ($is_first) {
            $obj_start_time = strtotime("-32 days");
            $obj_end_time = time();
        }

        $global = new QcGlobalObj();
        $obj_list = $global
            ->alias('g')
            ->join('company com', 'g.adv_id=com.advertiser_id', 'left')
            ->where([
                'com.adv_status' => 1,
                'g.obj_create_time' => ['between', [$obj_start_time, $obj_end_time]],
            ])
            ->where(function ($query) {
                $query->whereNotIn('g.obj_status', ['DELETE', 'FROZEN'])
                    ->whereOr(['g.opt_status' => ['not in', ['DELETE', 'FROZEN']]]);
            })
            ->field('g.obj_id,g.adv_id')
            ->select();

        $obj_arr = [];
        foreach ($obj_list as $item) {
            $obj_arr[$item['adv_id']][] = $item['obj_id'];
        }
        if (!$obj_list) {
            return "无数据可处理";
        }
        $currentTime = time();

        if ($day) {
            $dayCount = intval($day);
            $startTime = strtotime(date('Y-m-d ', strtotime("-{$dayCount} days")));
            $endTime = $currentTime;
        } else {
            $startTime = $currentTime;
            $endTime = $currentTime;
        }

        $timeData = [
            'start_time' => date("Y-m-d ", $startTime),
            'end_time' => date('Y-m-d ', $endTime)
        ];
        $batchSize = 30;
        foreach ($obj_arr as $adv_id => $item) {
            $chunks = array_chunk($item, $batchSize, true);
            $queueCount = 0;
            foreach ($chunks as $chunk) {
                Queue::push('app\job\fission\InsertGlobalObjMaterial', [
                    'adv_id' => 1816865699897355,
                    'obj_list' => [1824132287367443],
                    'start_time' => "2025-05-01",
                    'end_time' => "2025-08-07"
                ], 'insertGlobalObjMaterial');
                $queueCount++;
            }
        }

        return "已处理所有数据，共" . count($obj_arr) . "条记录，分" . $queueCount . "个批次处理";
    }


    /**
     * 生成裂变任务
     * @return string
     */
    public function genMaterialFissionTask(): string
    {
        // 最高级筛选：检查是否存在今日裂变额度不足的情况
        $quotaCheckResult = $this->checkDailyFissionQuota();
        if ($quotaCheckResult !== true) {
            return $quotaCheckResult; // 直接返回额度不足信息，不构建队列
        }

        // 获取符合条件的广告主列表（优化：添加缓存和更精确的查询）
        $cacheKey = 'qualified_advertisers_' . date('Y-m-d-H');
        $material = new AdvGlobalMaterial();

        // 获取黑名单公司列表
        $blackCompanyList = $this->getBlackCompanyList();

        $adv_list = \think\Cache::remember($cacheKey, function () use ($material, $blackCompanyList) {
            $query = $material
                ->alias('m')
                ->join('company c', 'm.adv_id = c.advertiser_id', 'left')
                ->where([
                    'm.stat_cost_for_roi2' => ['>', 0],
                    'c.adv_status' => 1
                ]);

            // 过滤黑名单公司
            if (!empty($blackCompanyList)) {
                $query->where('c.company_name', 'not in', $blackCompanyList);
            }

            return $query
                ->group('m.adv_id')
                ->column('c.company_name', 'm.adv_id');
        }, 1800); // 缓存30分钟

        if (empty($adv_list)) {
            return "无符合条件的广告主数据";
        }
        $batchSize = 20;
        $chunks = array_chunk($adv_list, $batchSize, true);
        $queueCount = 0;

        foreach ($chunks as $chunk) {
            Queue::push('app\job\fission\GenMaterialFissionTask', [
                'adv_list' => $chunk,
            ], 'genMaterialFissionTask');
            $queueCount++;
        }

        return "已处理所有数据，共" . count($adv_list) . "条记录，分" . $queueCount . "个批次处理";
    }

    /**
     * 检查今日裂变额度是否充足
     * @return true|string true表示额度充足，字符串表示额度不足的具体信息
     */
    private function checkDailyFissionQuota()
    {
        // 获取今天的时间范围
        $todayStart = strtotime(date('Y-m-d'));
        $todayEnd = strtotime(date('Y-m-d') . ' 23:59:59');

        // 检查今天是否有"今日裂变额度不足"的错误记录
        $quotaErrorCount = \think\Db::name('fission_material_task')
            ->where(function ($query) {
                $query->where('status_message', 'like', '%今日裂变额度不足%')
                    ->whereOr('fission_msg', 'like', '%今日裂变额度不足%');
            })
            ->whereBetween('create_time', [$todayStart, $todayEnd])
            ->count();

        if ($quotaErrorCount > 0) {
            // 获取最新的额度不足错误信息
            $latestQuotaError = \think\Db::name('fission_material_task')
                ->where(function ($query) {
                    $query->where('status_message', 'like', '%今日裂变额度不足%')
                        ->whereOr('fission_msg', 'like', '%今日裂变额度不足%');
                })
                ->whereBetween('create_time', [$todayStart, $todayEnd])
                ->order('create_time', 'desc')
                ->find();

            $errorMsg = $latestQuotaError['fission_msg'] ?: $latestQuotaError['status_message'];
            return "检测到今日裂变额度不足，停止生成新任务。错误信息：{$errorMsg}";
        }

        return true; // 额度充足，可以继续
    }


    public function adoptFissionMaterial()
    {
        $fission_material = new FissionDeriveMaterial();
        $list = $fission_material
            ->where([
                'adopt_status_message' => null,
                'create_time' => ['between', [strtotime('-6 days'), time()]]
            ])
            ->field('adv_id, video_id') // 只查询需要的字段
            ->select();

        if (empty($list)) {
            echo "无待采纳的裂变素材";
            return;
        }

        $chunks = [];
        foreach ($list as $item) {
            $chunks[$item['adv_id']][] = $item['video_id'];
        }

        $totalQueues = 0;
        $batchSize = 50;

        foreach ($chunks as $adv_id => $video_ids) {
            $video_chunks = array_chunk($video_ids, $batchSize);
            foreach ($video_chunks as $chunk) {
                Queue::push('app\job\fission\AdoptFissionMaterial', [
                    'adv_id' => $adv_id,
                    'video_id' => array_values($chunk),
                ], 'adoptFissionMaterial');
                $totalQueues++;
            }
        }

        echo "全部处理完需要采纳的视频了，共" . count($list) . "条素材，分" . $totalQueues . "个批次处理";
    }

    public function getFissionTaskStatus()
    {
        $material_task = new FissionMaterialTask();
        $list = $material_task
            ->where([
                'status_message' => 'success',
                'is_handle' => 0,
                'task_id' => ['>', 0]
            ])
            ->where(function ($query) {
                $query->whereNotIn('fission_status', ['PART_SUCCESS', 'SUCCESS'])
                    ->whereOr('fission_status', null);
            })
            ->field('adv_id, task_id') // 只查询需要的字段
            ->select();

        if (empty($list)) {
            echo "无待处理的裂变任务";
            return;
        }

        $chunks = [];
        foreach ($list as $item) {
            $chunks[$item['adv_id']][] = $item['task_id'];
        }

        $totalQueues = 0;
        $batchSize = 50;

        foreach ($chunks as $adv_id => $task_ids) {
            $task_chunks = array_chunk($task_ids, $batchSize);
            foreach ($task_chunks as $chunk) {
                Queue::push('app\job\fission\GetFissionMaterialStatus', [
                    'adv_id' => $adv_id,
                    'task_id' => array_values($chunk),
                ], 'getFissionMaterialStatus');
                $totalQueues++;
            }
        }

        echo "主动调起成功，共" . count($list) . "条任务，分" . $totalQueues . "个批次处理";
    }

    public function handleTaskHistory()
    {
        $adv_model = new Company();
        $page = Cache::get('test_task_page', 1);
        $adv_list = $adv_model->where(['adv_status' => 1])->page($page)->limit(40)->order('advertiser_id desc')->column('advertiser_id');
        if (!$adv_list) {
//            Cache::rm('test_task_page');
            echo "全部处理完成了";
            die;
        }
        $save_data = [];
        foreach ($adv_list as $adv_id) {
            $params = [
                'advertiser_id' => (int)$adv_id,
                'filtering' => json_encode(['start_time' => '2025-07-01 00:00:00', 'end_time' => '2025-07-27 00:00:00']),
                'page' => 1,
                'page_size' => 50
            ];
            $res = FundManagement::get_hot_material_derive_task_list($params);

            if (!empty($res['data']['data'])) {
                $save_data[$adv_id] = $res['data']['data'];
                // 处理大数据量账户
            }
        }
        $task_model = new FissionMaterialTask();
        $fission_model = new FissionDeriveMaterial();
        $task_save_data = [];
        $fission_data = [];
        if ($save_data) {
            foreach ($save_data as $adv_id => $item) {
                foreach ($item as $value) {
                    $task_where = [
                        'adv_id' => $adv_id,
                        'task_id' => $value['task_id'] ?? 0,
                        'material_id' => $value['origin_material_id'],
                    ];
                    $task_info = $task_model->where($task_where)->find();
                    $task_where['status_code'] = $value['status_code'] ?? 0;
                    $task_where['fission_status'] = $value['status'] ?? 0;
                    $task_where['status_message'] = $value['status_message'] ?? "success";
                    $task_where['is_handle'] = 1;
                    $task_where['create_time'] = strtotime($value['create_time']);
                    if ($task_info) {
                        $task_where['id'] = $task_info['id'];
                    }
                    $task_save_data[] = $task_where;
                    if ($value['derive_materials']) {
                        foreach ($value['derive_materials'] as $derive) {
                            $strategy_detail = $derive['strategy_detail'];
                            $where = [
                                'adv_id' => $adv_id,
                                'task_id' => $value['task_id'],
                                'old_material_id' => $value['origin_material_id'],
                                'strategy' => $strategy_detail['strategy'],
                                'strategy_name' => $strategy_detail['strategy_name'],
                                'video_id' => $derive['video_id'],
                            ];
                            $has = $fission_model->where($where)->find();
                            if ($has) {
                                continue;
                            }
                            $fission_data[] = [
                                'adv_id' => $adv_id,
                                'task_id' => $value['task_id'],
                                'old_material_id' => $value['origin_material_id'],
                                'strategy' => $strategy_detail['strategy'],
                                'apply_times' => json_encode($strategy_detail['apply_times']),
                                'strategy_description' => $strategy_detail['strategy_description'],
                                'strategy_name' => $strategy_detail['strategy_name'],
                                'title' => $derive['title'],
                                'video_id' => $derive['video_id'],
                                'video_url' => $derive['video_url'],
                                'adopt_status_code' => 0,
                                'adopt_status_message' => 'success',
                                'create_time' => strtotime($value['create_time']),
                                'update_time' => strtotime($value['modify_time']),
                            ];
                        }
                    }
                }
            }
            Db::startTrans();
            try {
                $task_model->saveAll($task_save_data);
                $fission_model->saveAll($fission_data);
                Db::commit();
            } catch (Exception $e) {
                Db::rollback();
                dump($e->getMessage());
                die;
            }
        }

        echo "处理完了第" . $page. "页，准备处理下一页";
        Cache::set('test_task_page', $page+1);
    }


    public function adoptMaterialIntoObj()
    {
        // 检查是否在短时间内重复调用
        $lastCallTime = Cache::get('adoptMaterialIntoObj_last_call', 0);
        $currentTime = time();

        if ($currentTime - $lastCallTime < 300) { // 5分钟内不允许重复调用
            echo "调用过于频繁，请等待 " . (300 - ($currentTime - $lastCallTime)) . " 秒后再试";
            return;
        }
        // 记录本次调用时间
        Cache::set('adoptMaterialIntoObj_last_call', $currentTime, 600);

        // 分页处理大数据量
        $this->adoptMaterialIntoObjWithPagination();
        echo  '已处理了当前分页';
    }

    /**
     * 分页处理裂变素材采纳（适合大数据量）
     */
    private function adoptMaterialIntoObjWithPagination()
    {
        $fission_material = new FissionDeriveMaterial();
        $obj_material = new AdvGlobalObjMaterial();
        $company = new Company();

        // 分页参数
        $pageSize = $this->getOptimalPageSize();
        $lastProcessedId = Cache::get('adoptMaterial_last_id', 0);

        // 黑名单公司列表
        $blackCompanyList = $this->getBlackCompanyList();
        $blackAdvList = $company->where(['company_name' => ['in', $blackCompanyList]])->column('advertiser_id');

        do {
            // 基于ID分页查询
            $list = $fission_material
                ->where([
                    'adopt_status_message' => "success",
                    'create_time' => ['between', [strtotime('-30 days'), time()]],
                    'id' => ['>', $lastProcessedId]
                ])
                ->field('id,adv_id,old_material_id,video_id')
                ->order('id asc')
                ->limit($pageSize)
                ->select();

            if (empty($list)) {
                Cache::rm('adoptMaterial_last_id');
                break;
            }

            // 处理当前批次数据
            $this->processBatchMaterials($list, $obj_material, $blackAdvList);

            // 更新最后处理的ID
            $lastId = end($list)['id'];
            $lastProcessedId = $lastId;
            Cache::set('adoptMaterial_last_id', $lastProcessedId, 3600 * 24);

            // 避免内存泄漏
            unset($list);

            // 短暂休息，避免数据库压力过大
            usleep(100000);

        } while (true);
    }

    /**
     * 处理单个批次的素材数据
     */
    private function processBatchMaterials($list, $obj_material, $blackAdvList)
    {
        if (empty($list)) {
            return;
        }

        // 组织数据：按 adv_id + old_material_id 分组
        $old_material_list = [];
        foreach ($list as $item) {
            $key = $item['adv_id'] . "_" . $item['old_material_id'];
            if (!isset($old_material_list[$key])) {
                $old_material_list[$key] = [];
            }
            // 避免重复的video_id
            if (!in_array($item['video_id'], $old_material_list[$key])) {
                $old_material_list[$key][] = $item['video_id'];
            }
        }

        foreach ($old_material_list as $key => $material_list) {
            list($adv_id, $old_material_id) = explode('_', $key);
            // 检查是否在黑名单中
            if (in_array($adv_id, $blackAdvList)) {
                continue;
            }

            $obj_list_data = $obj_material
                ->alias('om')
                ->join('qc_global_obj qo', 'om.adv_id=qo.adv_id', 'left')
                ->field('om.obj_id,ANY_VALUE(om.product_info) as product_info')
                ->where(['om.adv_id' => $adv_id, 'om.material_id' => $old_material_id])
                ->where(['om.material_status' => 'DELIVERY_OK'])
                ->whereNotNull('om.product_info')
                ->where(function ($query) {
                    $query->whereIn('qo.obj_status', ['DELIVERY_OK', 'DISABLE', 'SYSTEM_DISABLE'])
                        ->whereOr(['qo.opt_status' => ['in', ['ENABLE', 'DISABLE']]]);
                })
                ->group('om.obj_id')
                ->select();

            // 转换为原来的数据格式
            $obj_list = [];
            foreach ($obj_list_data as $item) {
                $obj_list[$item['obj_id']] = $item['product_info'];
            }

            if ($obj_list) {
                $obj_product = [];
                foreach ($obj_list as $obj_id => $product_info) {
                    $productIds = array_column(json_decode($product_info, true), 'product_id');
                    if (!empty($productIds)) {
                        $obj_product[$obj_id] = $productIds;
                    }
                }

                if (!empty($obj_product)) {
                    // 过滤已存在的记录
                    $filteredObjProduct = $this->filterExistingRecords($adv_id, $obj_product, $material_list);
                    if (!empty($filteredObjProduct)) {
                        // 分批处理video_ids，每批最多200个
                        $videoIds = array_values($material_list);
                        $videoBatches = array_chunk($videoIds, 200);

                        foreach ($videoBatches as $videoBatch) {
                            $taskData = [
                                'adv_id' => $adv_id,
                                'obj_ids' => $filteredObjProduct,
                                'video_ids' => $videoBatch,
                            ];
                            Queue::push('app\job\fission\AdoptMaterialIntoObj', $taskData, 'adoptMaterialIntoObj');
                        }
                    }
                }
            }
        }
    }

    /**
     * 重置处理进度（用于重新开始处理）
     */
    public function resetAdoptMaterialProgress()
    {
        Cache::rm('adoptMaterial_last_id');
        Cache::rm('adoptMaterialIntoObj_last_call');
    }

    /**
     * 获取页面大小
     */
    private function getOptimalPageSize()
    {
        return 1000; // 固定1000条，简单有效
    }

    /**
     * 检查采纳素材任务的队列状态
     */
    public function checkAdoptMaterialQueue()
    {
        $queueStats = \think\Db::name('fission_queue')
            ->where('class_name', 'app\\job\\fission\\AdoptMaterialIntoObj')
            ->where('create_time', '>', time() - 86400) // 24小时内
            ->field('status, COUNT(*) as count')
            ->group('status')
            ->select();

        echo "=== 采纳素材队列状态（24小时内）===\n";
        foreach ($queueStats as $stat) {
            $statusName = ['0' => '待执行', '1' => '已完成', '2' => '失败'][$stat['status']] ?? '未知';
            echo "状态 {$stat['status']} ({$statusName}): {$stat['count']} 个任务\n";
        }

        // 检查最近的失败任务
        $failedTasks = \think\Db::name('fission_queue')
            ->where('class_name', 'app\\job\\fission\\AdoptMaterialIntoObj')
            ->where('status', 2)
            ->where('create_time', '>', time() - 3600) // 1小时内
            ->field('id, job_data, msg, create_time')
            ->order('create_time desc')
            ->limit(5)
            ->select();

        if (!empty($failedTasks)) {
            echo "\n=== 最近失败的任务（1小时内）===\n";
            foreach ($failedTasks as $task) {
                $jobData = json_decode($task['job_data'], true);
                $advId = $jobData['adv_id'] ?? '未知';
                echo "任务ID: {$task['id']}, 账户: {$advId}, 失败原因: {$task['msg']}\n";
            }
        }
    }

    /**
     * 过滤已存在的记录
     * @param string $adv_id 广告主ID
     * @param array $obj_product 计划产品数据
     * @param array $material_list 素材列表
     * @return array 过滤后的计划产品数据
     */
    private function filterExistingRecords($adv_id, $obj_product, $material_list)
    {
        $filteredObjProduct = [];

        // 构建所有需要检查的组合
        $checkCombinations = [];
        foreach ($obj_product as $obj_id => $product_ids) {
            foreach ($product_ids as $product_id) {
                $checkCombinations[] = [
                    'obj_id' => $obj_id,
                    'product_id' => $product_id,
                    'key' => $obj_id . '_' . $product_id
                ];
            }
        }

        if (empty($checkCombinations)) {
            return $filteredObjProduct;
        }

        // 批量查询已存在的记录
        $existingRecords = \think\Db::name('fission_into_obj_record')
            ->where('adv_id', $adv_id)
            ->whereIn('obj_id', array_keys($obj_product))
            ->field('obj_id, product_id, mid')
            ->select();

        // 构建已存在记录的索引（检查是否有任何video_id重叠）
        $existingKeys = [];
        foreach ($existingRecords as $record) {
            // 检查当前批次的video_id是否与已存在记录的mid有重叠
            $existingVideoIds = explode(',', $record['mid']);
            $hasOverlap = !empty(array_intersect($material_list, $existingVideoIds));

            if ($hasOverlap) {
                $key = $record['obj_id'] . '_' . $record['product_id'];
                $existingKeys[$key] = true;
            }
        }

        $skippedCount = 0;

        // 过滤掉已存在的记录
        foreach ($obj_product as $obj_id => $product_ids) {
            $filteredProductIds = [];

            foreach ($product_ids as $product_id) {
                $key = $obj_id . '_' . $product_id;

                if (!isset($existingKeys[$key])) {
                    $filteredProductIds[] = $product_id;
                } else {
                    $skippedCount++;
                }
            }

            // 如果该计划还有未处理的产品，则保留
            if (!empty($filteredProductIds)) {
                $filteredObjProduct[$obj_id] = $filteredProductIds;
            }
        }

        // if ($skippedCount > 0) {
        //     echo "账户 {$adv_id} 跳过已存在记录: {$skippedCount} 条\n";
        // }

        return $filteredObjProduct;
    }

    /**
     * 测试过滤已存在记录的功能
     */
    public function testFilterExistingRecords()
    {
        // 示例数据
        $adv_id = '1773111916738573'; // 使用一个实际的adv_id
        $obj_product = [
            '1836170738573' => ['1754634974', '1754634975']
        ];
        $material_list = ['3765012918891491', '3765012918891492'];

        echo "=== 测试过滤已存在记录功能 ===\n";
        echo "测试账户ID: {$adv_id}\n";
        echo "测试计划产品: " . json_encode($obj_product) . "\n";
        echo "测试素材列表: " . json_encode($material_list) . "\n";

        $filteredResult = $this->filterExistingRecords($adv_id, $obj_product, $material_list);

        echo "过滤结果: " . json_encode($filteredResult) . "\n";
        echo "=== 测试完成 ===\n";
    }

    /**
     * 获取黑名单公司列表
     * 优先从black_company_config.php文件读取，如果文件不存在则使用默认列表
     * @return array
     */
    private function getBlackCompanyList()
    {
        $configFilePath = __DIR__ . '/black_company_config.php';

        // 尝试从PHP配置文件读取
        if (file_exists($configFilePath)) {
            try {
                $blackCompanyList = include $configFilePath;
                if (is_array($blackCompanyList) && !empty($blackCompanyList)) {
//                    echo "从 black_company_config.php 文件读取到 " . count($blackCompanyList) . " 个黑名单公司\n";
                    return $blackCompanyList;
                }
            } catch (Exception $e) {
                echo "读取配置文件失败: " . $e->getMessage() . "\n";
            }
        }
        return [
            '海口龙华旋月悦电子商务商行',
            '海口秀英区诚交电子商务商行',
            '海口秀英区悦兴隆电子商务商行',
            '海口秀英区粤凤雷贸易商店',
            '海口秀英旺德福贸易商行',
            '海口秀英伍易贸易商行',
            '海口秀英粤兴顺贸易商行',
            '长沙市望城区晴桑晖百货商行（个体工商户）',
            '南漳县福发辰苗木店',
            '重庆麟睿家居有限公司',
            '海口龙华区金丰宇百货商行',
            '海口美兰区澜荼缇百货商行',
            '沈阳市和平区库苑青百货店（个体工商户）',
            '佛山市南海区法奥拾伍皮具商行',
            '佛山市禅城区法奥壹壹伍皮具商行',
            '佛山市禅城区法奥壹壹柒皮具商行',
            '西藏善正蜂产业科技开发有限公司',
            '茂名市茂南区不嘻嘻百货商行（个体工商户）',
            '海口美兰秋秋服装商行',
            '深圳市熊熊服饰有限公司',
            '深圳市品余电子商务有限公司',
            '深圳市态杜电子商务有限公司',
            '海口美兰苏苏优品服装商行（个体工商户）',
            '昆明江锦文化传媒有限公司',
            '沅江市树建百货商行（个体工商户）',
            '长沙市望城区衙并百货商行（个体工商户）',
            '佛山市禅城区洋吉宝百货商行（个体工商户）',
            '长沙市望城区毯渠百货商行（个体工商户）',
            '兰溪市木荣贸易有限公司',
            '兰溪市胜耀贸易有限公司',
            '仙游县鲤城橘惟工艺品店',
            '柏乡县晨悦百货经营部',
            '铂尔曼（天津）生物科技有限公司',
            '济南市莱芜区藻鲜生水产经营店',
        ];
    }

    /**
     * 测试黑名单配置文件读取功能
     */
}