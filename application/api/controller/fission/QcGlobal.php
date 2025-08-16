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
        $blackCompanyList = $this->getBlackCompanyList('black_company_config_fission.php');
//        $blackCompanyList = [];

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

        $adv_list = $query
            ->group('m.adv_id')
            ->column('c.company_name', 'm.adv_id');


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

        echo "处理完了第" . $page . "页，准备处理下一页";
        Cache::set('test_task_page', $page + 1);
    }

    /**
     * 将素材添加到计划里
     */

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

        // 获取总数统计
        $stats = $this->getAdoptMaterialStats();
        echo "=== 处理统计 ===\n";
        echo "总记录数: {$stats['total']}\n";
        echo "预计总页数: {$stats['estimated_pages']}\n";
        echo "当前进度: {$stats['progress_info']}\n";
        echo "状态: {$stats['status']}\n";

        // 如果有新数据且之前已完成，提示重新开始
        if ($stats['has_new_data'] && Cache::get('adoptMaterial_completed', false)) {
            echo "🔄 检测到 {$stats['new_data_count']} 条新数据，将自动重新开始处理\n";
        }
        echo "\n";

        // 分页处理大数据量
        $result = $this->adoptMaterialIntoObjWithPagination();

        if ($result['completed']) {
            echo "\n✅ 所有数据处理完成！\n";
            echo "📊 本次处理统计:\n";
            echo "- 处理记录数: {$result['total_processed']} 条\n";
            echo "- 处理页数: {$result['pages_processed']} 页\n";
            echo "- 消息: {$result['message']}\n";
            echo "\n💡 如果后续有新数据，系统会自动检测并重新处理\n";
        } else {
            echo "📄 已处理了当前分页，共 {$result['current_batch_size']} 条记录";
        }
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
        $page_size = $this->getOptimalPageSize();
        $last_processed_id = Cache::get('adoptMaterial_last_id', 0);

        // 检查是否有新数据需要重新开始
        $stats = $this->getAdoptMaterialStats();
        if ($stats['has_new_data'] && Cache::get('adoptMaterial_completed', false)) {
            echo "🔄 检测到新数据，重新开始处理...\n";
            $this->resetAdoptMaterialProgress();
            $last_processed_id = 0;
        }

        // 统计变量
        $total_processed = 0;
        $pages_processed = 0;
        $current_batch_size = 0;

        // 黑名单公司列表
        $black_company_list = $this->getBlackCompanyList();
        $black_adv_list = $company->where(['company_name' => ['in', $black_company_list]])->column('advertiser_id');

        do {
            // 基于ID分页查询
            $list = $fission_material
                ->where([
                    'adopt_status_message' => "success",
                    'create_time' => ['between', [strtotime('-7 days'), time()]],
                    'id' => ['>', $last_processed_id]
                ])
                ->field('id,adv_id,old_material_id,video_id')
                ->order('id asc')
                ->limit($page_size)
                ->select();

            if (empty($list)) {
                // 标记为完成状态
                Cache::set('adoptMaterial_completed', true, 3600 * 24);
                Cache::rm('adoptMaterial_last_id');

                echo "✅ 当前批次处理完成，无更多数据\n";

                // 返回完成状态
                return [
                    'completed' => true,
                    'total_processed' => $total_processed,
                    'pages_processed' => $pages_processed,
                    'current_batch_size' => $current_batch_size,
                    'message' => '所有数据处理完成'
                ];
            }

            $current_batch_size = count($list);
            $total_processed += $current_batch_size;
            $pages_processed++;

            echo "📄 正在处理第 {$pages_processed} 页，{$current_batch_size} 条记录\n";

            // 内存检查
            $memory_before = memory_get_usage(true);
            echo "💾 处理前内存: " . $this->formatBytes($memory_before) . "\n";

            // 处理当前批次数据
            $this->processBatchMaterials($list, $obj_material, $black_adv_list);

            // 更新最后处理的ID
            $last_id = end($list)['id'];
            $last_processed_id = $last_id;
            Cache::set('adoptMaterial_last_id', $last_processed_id, 3600 * 24);

            // 更新处理统计到缓存
            Cache::set('adoptMaterial_total_processed', $total_processed, 3600 * 24);
            Cache::set('adoptMaterial_pages_processed', $pages_processed, 3600 * 24);

            // 内存检查和清理
            $memory_after = memory_get_usage(true);
            echo "💾 处理后内存: " . $this->formatBytes($memory_after) . "\n";

            // 如果内存使用过高，提前退出
            if ($memory_after > 200 * 1024 * 1024) { // 200MB
                echo "⚠️ 内存使用过高，提前结束本次处理\n";
                break;
            }

            // 清除完成标记（因为还在处理中）
            Cache::rm('adoptMaterial_completed');

            // 避免内存泄漏
            unset($list);

            // 短暂休息，避免数据库压力过大
            usleep(100000);

        } while (true);

        // 返回未完成状态（理论上不会到达这里）
        return [
            'completed' => false,
            'total_processed' => $total_processed,
            'pages_processed' => $pages_processed,
            'current_batch_size' => $current_batch_size,
            'message' => '处理中断'
        ];
    }

    /**
     * 处理单个批次的素材数据 - 高性能版本
     */
    private function processBatchMaterials($list, $obj_material, $black_adv_list)
    {
        if (empty($list)) {
            return;
        }

        // 1. 数据预处理和分组
        $processed_data = $this->preprocessBatchData($list, $black_adv_list);
        if (empty($processed_data)) {
            return;
        }

        // 2. 批量查询所有需要的数据（一次性查询）
        $batch_data = $this->batchQueryAllData($processed_data, $obj_material);

        // 3. 批量处理和推送队列
        $this->batchProcessAndQueue($batch_data);

        // 4. 内存清理
        $this->cleanupMemory();
    }

    /**
     * 内存清理和监控
     */
    private function cleanupMemory()
    {
        $memory_usage = memory_get_usage(true);
        $memory_peak = memory_get_peak_usage(true);

        echo "💾 内存使用: " . $this->formatBytes($memory_usage) .
             " / 峰值: " . $this->formatBytes($memory_peak) . "\n";

        // 强制垃圾回收
        gc_collect_cycles();

        $memory_after = memory_get_usage(true);
        if ($memory_after < $memory_usage) {
            echo "🧹 内存清理: 释放了 " . $this->formatBytes($memory_usage - $memory_after) . "\n";
        }
    }

    /**
     * 格式化字节数
     */
    private function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * 数据预处理和分组 - 高性能版本
     */
    private function preprocessBatchData($list, $black_adv_list)
    {
        $processed_data = [];
        $black_adv_set = array_flip($black_adv_list); // 转为哈希表，O(1)查找

        foreach ($list as $item) {
            $adv_id = $item['adv_id'];
            $old_material_id = $item['old_material_id'];
            $video_id = $item['video_id'];

            // 快速跳过黑名单 - O(1)查找
            if (isset($black_adv_set[$adv_id])) {
                continue;
            }

            // 使用哈希表去重video_id，避免in_array的O(n)查找
            $processed_data[$adv_id][$old_material_id][$video_id] = true;
        }

        // 转换为数组格式
        $result = [];
        foreach ($processed_data as $adv_id => $material_data) {
            foreach ($material_data as $old_material_id => $video_set) {
                $result[$adv_id][$old_material_id] = array_keys($video_set);
            }
        }

        return $result;
    }

    /**
     * 批量查询所有需要的数据
     */
    private function batchQueryAllData($processed_data, $obj_material)
    {
        $all_adv_ids = array_keys($processed_data);
        $all_material_ids = [];

        // 收集所有material_id
        foreach ($processed_data as $adv_data) {
            $all_material_ids = array_merge($all_material_ids, array_keys($adv_data));
        }
        $all_material_ids = array_unique($all_material_ids);

        // 批量查询计划数据 - 利用 idx_adv_material_hash 索引
        $plan_data = $this->batchQueryPlanData($all_adv_ids, $all_material_ids, $obj_material);

        // 批量查询素材数量统计 - 利用 idx_adv_obj_cost_stat 索引
        $material_counts = $this->batchQueryMaterialCountsOptimized($all_adv_ids);

        // 批量查询已存在记录
        $existing_records = $this->batchQueryExistingRecords($all_adv_ids);

        return [
            'processed_data' => $processed_data,
            'plan_data' => $plan_data,
            'material_counts' => $material_counts,
            'existing_records' => $existing_records
        ];
    }

    /**
     * 批量处理和推送队列
     */
    private function batchProcessAndQueue($batch_data)
    {
        $processed_data = $batch_data['processed_data'];
        $plan_data = $batch_data['plan_data'];
        $material_counts = $batch_data['material_counts'];
        $existing_records = $batch_data['existing_records'];

        foreach ($processed_data as $adv_id => $material_data) {
            foreach ($material_data as $old_material_id => $video_ids) {
                // 获取该素材对应的计划数据
                $key = "{$adv_id}_{$old_material_id}";
                if (!isset($plan_data[$key])) {
                    continue;
                }

                $obj_product = $plan_data[$key];

                // 应用素材数量限制过滤
                $filtered_obj_product = $this->applyMaterialLimitFilter($adv_id, $obj_product, $material_counts);

                // 应用已存在记录过滤
                $final_obj_product = $this->applyExistingRecordsFilter($adv_id, $filtered_obj_product, $video_ids, $existing_records);

                if (!empty($final_obj_product)) {
                    // 分批推送到队列
                    $this->pushToQueue($adv_id, $final_obj_product, $video_ids);
                }
            }
        }
    }

    /**
     * 批量查询计划数据 - 内存优化版本
     */
    private function batchQueryPlanData($all_adv_ids, $all_material_ids, $obj_material)
    {
        $plan_data = [];
        $cost_date = strtotime(date('Y-m-d'));

        // 分批查询，避免内存溢出
        $adv_batch_size = 5; // 每次处理5个广告主
        $adv_batches = array_chunk($all_adv_ids, $adv_batch_size);

        foreach ($adv_batches as $adv_batch) {
            // 使用 idx_adv_material_hash 索引进行高效查询
            $results = $obj_material
                ->alias('om')
                ->join('qc_global_obj qo', 'om.obj_id = qo.obj_id AND om.adv_id = qo.adv_id', 'INNER')
                ->field('om.adv_id, om.material_id, om.obj_id, om.product_info')
                ->whereIn('om.adv_id', $adv_batch)
                ->whereIn('om.material_id', $all_material_ids)
                ->where([
                    'om.material_status' => 'DELIVERY_OK',
                    'om.cost_date' => $cost_date
                ])
                ->whereNotNull('om.product_info')
                ->where(function ($query) {
                    $query->whereIn('qo.obj_status', ['DELIVERY_OK', 'DISABLE', 'SYSTEM_DISABLE'])
                        ->whereOr(['qo.opt_status' => ['in', ['ENABLE', 'DISABLE']]]);
                })
                ->limit(5000) // 限制单次查询结果数量
                ->select();

            // 组织数据结构
            foreach ($results as $row) {
                $key = "{$row['adv_id']}_{$row['material_id']}";
                $obj_id = $row['obj_id'];
                $product_info = $row['product_info'];

                // 解析产品信息
                $decoded_product_info = json_decode($product_info, true);
                if ($decoded_product_info === null && is_string($product_info)) {
                    $decoded_product_info = json_decode(json_decode($product_info, true), true);
                }

                if (is_array($decoded_product_info)) {
                    $product_ids = array_column($decoded_product_info, 'product_id');
                    if (!empty($product_ids)) {
                        $plan_data[$key][$obj_id] = $product_ids;
                    }
                }
            }

            // 释放内存
            unset($results);

            // 内存检查和垃圾回收
            if (memory_get_usage() > 150 * 1024 * 1024) { // 150MB
                gc_collect_cycles();
            }
        }

        return $plan_data;
    }

    /**
     * 批量查询素材数量统计 - 内存优化版本
     */
    private function batchQueryMaterialCountsOptimized($all_adv_ids)
    {
        $material_counts = [];

        // 分批查询，避免内存溢出
        $batch_size = 10; // 每次处理10个广告主
        $adv_batches = array_chunk($all_adv_ids, $batch_size);

        foreach ($adv_batches as $adv_batch) {
            // 使用 idx_adv_obj_cost_stat 索引进行高效查询，只查询当天数据
            $results = Db::name('fission_global_obj_material_202508')
                ->field('adv_id, obj_id, product_info')
                ->whereIn('adv_id', $adv_batch)
                ->where([
                    'material_status' => 'DELIVERY_OK',
                    'cost_date' => strtotime(date('Y-m-d')) // 只查询当天的数据
                ])
                ->whereNotNull('product_info')
                ->limit(10000) // 限制单次查询结果数量
                ->select();

            // 在内存中统计，避免重复查询
            foreach ($results as $row) {
                $adv_id = $row['adv_id'];
                $obj_id = $row['obj_id'];
                $product_info = $row['product_info'];

                // 解析产品信息
                $decoded_product_info = json_decode($product_info, true);
                if ($decoded_product_info === null && is_string($product_info)) {
                    $decoded_product_info = json_decode(json_decode($product_info, true), true);
                }

                if (is_array($decoded_product_info)) {
                    $product_ids = array_column($decoded_product_info, 'product_id');
                    foreach ($product_ids as $product_id) {
                        $key = "{$adv_id}_{$obj_id}_{$product_id}";
                        $material_counts[$key] = ($material_counts[$key] ?? 0) + 1;
                    }
                }
            }

            // 释放内存
            unset($results);

            // 如果内存使用过高，强制垃圾回收
            if (memory_get_usage() > 200 * 1024 * 1024) { // 200MB
                gc_collect_cycles();
            }
        }

        return $material_counts;
    }

    /**
     * 批量查询已存在记录
     */
    private function batchQueryExistingRecords($all_adv_ids)
    {
        $existing_records = [];

        $results = Db::name('fission_into_obj_record')
            ->field('adv_id, obj_id, product_id, mid')
            ->whereIn('adv_id', $all_adv_ids)
            ->select();

        foreach ($results as $row) {
            $key = "{$row['adv_id']}_{$row['obj_id']}_{$row['product_id']}";
            $existing_video_ids = explode(',', $row['mid']);
            $existing_records[$key] = $existing_video_ids;
        }

        return $existing_records;
    }

    /**
     * 获取采纳素材的统计信息
     */
    private function getAdoptMaterialStats()
    {
        $fission_material = new FissionDeriveMaterial();

        // 获取总记录数（使用与分页查询相同的时间范围）
        $total = $fission_material
            ->where([
                'adopt_status_message' => "success",
                'create_time' => ['between', [strtotime('-7 days'), time()]]
            ])
            ->count();

        // 获取当前进度
        $last_processed_id = Cache::get('adoptMaterial_last_id', 0);
        $processed = 0;

        if ($last_processed_id > 0) {
            $processed = $fission_material
                ->where([
                    'adopt_status_message' => "success",
                    'create_time' => ['between', [strtotime('-7 days'), time()]],
                    'id' => ['<=', $last_processed_id]
                ])
                ->count();
        }

        // 检查是否有新数据（ID大于最后处理的ID）
        $new_data_count = 0;
        if ($last_processed_id > 0) {
            $new_data_count = $fission_material
                ->where([
                    'adopt_status_message' => "success",
                    'create_time' => ['between', [strtotime('-10 days'), time()]],
                    'id' => ['>', $last_processed_id]
                ])
                ->count();
        }

        $page_size = $this->getOptimalPageSize();
        $estimated_pages = ceil($total / $page_size);
        $current_page = ceil($processed / $page_size);

        $progress_percent = $total > 0 ? round(($processed / $total) * 100, 2) : 0;

        // 如果之前已完成但现在有新数据，重置状态
        $has_new_data = $new_data_count > 0;
        $was_completed = Cache::get('adoptMaterial_completed', false);

        if ($was_completed && $has_new_data) {
            // 发现新数据，准备重新开始
            $status = "发现新数据，准备重新处理";
        } elseif ($new_data_count == 0 && $processed >= $total && $total > 0) {
            // 真正完成
            Cache::set('adoptMaterial_completed', true, 3600 * 24);
            $status = "全部处理完成";
        } elseif ($processed == 0) {
            $status = "尚未开始处理";
        } else {
            $status = "处理中";
        }

        return [
            'total' => $total,
            'processed' => $processed,
            'remaining' => $total - $processed,
            'new_data_count' => $new_data_count,
            'has_new_data' => $has_new_data,
            'estimated_pages' => $estimated_pages,
            'current_page' => $current_page,
            'progress_percent' => $progress_percent,
            'status' => $status,
            'progress_info' => "已处理 {$processed}/{$total} 条记录 ({$progress_percent}%)" .
                ($has_new_data ? "，发现 {$new_data_count} 条新数据" : "")
        ];
    }

    /**
     * 应用素材数量限制过滤
     */
    private function applyMaterialLimitFilter($adv_id, $obj_product, $material_counts)
    {
        $filtered_obj_product = [];
        $material_limit = 500;

        foreach ($obj_product as $obj_id => $product_ids) {
            $filtered_product_ids = [];

            foreach ($product_ids as $product_id) {
                $key = "{$adv_id}_{$obj_id}_{$product_id}";
                $current_count = $material_counts[$key] ?? 0;
                $remaining_slots = $material_limit - $current_count;

                if ($remaining_slots > 0) {
                    $filtered_product_ids[] = $product_id;
                    echo "✅ 计划 {$obj_id} 产品 {$product_id}: 已有 {$current_count} 个素材，还可添加 {$remaining_slots} 个\n";
                } else {
                    echo "⚠️ 计划 {$obj_id} 产品 {$product_id}: 已达到上限 ({$current_count}/{$material_limit})，跳过\n";
                }
            }

            if (!empty($filtered_product_ids)) {
                $filtered_obj_product[$obj_id] = $filtered_product_ids;
            }
        }

        return $filtered_obj_product;
    }

    /**
     * 应用已存在记录过滤
     */
    private function applyExistingRecordsFilter($adv_id, $obj_product, $video_ids, $existing_records)
    {
        $filtered_obj_product = [];

        foreach ($obj_product as $obj_id => $product_ids) {
            $filtered_product_ids = [];

            foreach ($product_ids as $product_id) {
                $key = "{$adv_id}_{$obj_id}_{$product_id}";
                $existing_video_ids = $existing_records[$key] ?? [];

                // 检查是否有重叠的video_id
                $has_overlap = !empty(array_intersect($video_ids, $existing_video_ids));

                if (!$has_overlap) {
                    $filtered_product_ids[] = $product_id;
                } else {
                    echo "🔄 计划 {$obj_id} 产品 {$product_id}: 已存在相同素材，跳过\n";
                }
            }

            if (!empty($filtered_product_ids)) {
                $filtered_obj_product[$obj_id] = $filtered_product_ids;
            }
        }

        return $filtered_obj_product;
    }

    /**
     * 推送到队列
     */
    private function pushToQueue($adv_id, $obj_product, $video_ids)
    {
        // 分批处理video_ids，每批最多200个
        $video_batches = array_chunk($video_ids, 200);

        foreach ($video_batches as $video_batch) {
            $task_data = [
                'adv_id' => $adv_id,
                'obj_ids' => $obj_product,
                'video_ids' => $video_batch,
            ];
            Queue::push('app\job\fission\AdoptMaterialIntoObj', $task_data, 'adoptMaterialIntoObj');
        }
    }

    /**
     * 重置处理进度（用于重新开始处理）
     */
    public function resetAdoptMaterialProgress()
    {
        Cache::rm('adoptMaterial_last_id');
        Cache::rm('adoptMaterialIntoObj_last_call');
        Cache::rm('adoptMaterial_total_processed');
        Cache::rm('adoptMaterial_pages_processed');
        Cache::rm('adoptMaterial_completed');
        echo "处理进度已重置，将从头开始处理";
    }

    /**
     * 获取页面大小 - 内存优化版本
     */
    private function getOptimalPageSize()
    {
        // 根据可用内存动态调整页面大小
        $memory_limit = ini_get('memory_limit');
        $memory_limit_bytes = $this->parseMemoryLimit($memory_limit);

        if ($memory_limit_bytes < 512 * 1024 * 1024) { // 小于512MB
            return 200;
        } elseif ($memory_limit_bytes < 1024 * 1024 * 1024) { // 小于1GB
            return 500;
        } else {
            return 1000; // 大于1GB
        }
    }

    /**
     * 解析内存限制字符串
     */
    private function parseMemoryLimit($memory_limit)
    {
        if ($memory_limit == -1) {
            return PHP_INT_MAX; // 无限制
        }

        $unit = strtolower(substr($memory_limit, -1));
        $value = (int) $memory_limit;

        switch ($unit) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return $value;
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
        $filtered_obj_product = [];

        // 构建所有需要检查的组合
        $check_combinations = [];
        foreach ($obj_product as $obj_id => $product_ids) {
            foreach ($product_ids as $product_id) {
                $check_combinations[] = [
                    'obj_id' => $obj_id,
                    'product_id' => $product_id,
                    'key' => "{$obj_id}_{$product_id}"
                ];
            }
        }

        if (empty($check_combinations)) {
            return $filtered_obj_product;
        }

        // 批量查询已存在的记录
        $existing_records = Db::name('fission_into_obj_record')
            ->where('adv_id', $adv_id)
            ->whereIn('obj_id', array_keys($obj_product))
            ->field('obj_id, product_id, mid')
            ->select();

        // 构建已存在记录的索引（检查是否有任何video_id重叠）
        $existing_keys = [];
        foreach ($existing_records as $record) {
            // 检查当前批次的video_id是否与已存在记录的mid有重叠
            $existing_video_ids = explode(',', $record['mid']);
            $has_overlap = !empty(array_intersect($material_list, $existing_video_ids));

            if ($has_overlap) {
                $key = $record['obj_id'] . '_' . $record['product_id'];
                $existing_keys[$key] = true;
            }
        }

        $skipped_count = 0;

        // 过滤掉已存在的记录
        foreach ($obj_product as $obj_id => $product_ids) {
            $filtered_product_ids = [];

            foreach ($product_ids as $product_id) {
                $key = "{$obj_id}_{$product_id}";

                if (!isset($existing_keys[$key])) {
                    $filtered_product_ids[] = $product_id;
                } else {
                    $skipped_count++;
                }
            }

            // 如果该计划还有未处理的产品，则保留
            if (!empty($filtered_product_ids)) {
                $filtered_obj_product[$obj_id] = $filtered_product_ids;
            }
        }

        // if ($skipped_count > 0) {
        //     echo "账户 {$adv_id} 跳过已存在记录: {$skipped_count} 条\n";
        // }

        return $filtered_obj_product;
    }



    /**
     * 获取黑名单公司列表
     * 优先从black_company_config.php文件读取，如果文件不存在则使用默认列表
     * @param string $file_name
     * @return array
     */
    private function getBlackCompanyList(string $file_name="black_company_config.php"): array
    {
        $config_file_path = __DIR__ . '/'.$file_name;

        // 尝试从PHP配置文件读取
        if (file_exists($config_file_path)) {
            try {
                $black_company_list = include $config_file_path;
                if (is_array($black_company_list) && !empty($black_company_list)) {
//                    echo "从 black_company_config.php 文件读取到 " . count($black_company_list) . " 个黑名单公司\n";
                    return $black_company_list;
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

}