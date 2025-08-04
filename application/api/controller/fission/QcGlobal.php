<?php

namespace app\api\controller\fission;

use app\admin\model\Company;
use app\admin\model\QcGlobalObj;
use app\common\model\viral_fission\AdvGlobalMaterial;
use app\common\model\viral_fission\FissionDeriveMaterial;
use app\common\model\viral_fission\FissionMaterialTask;
use jlqc\FundManagement;
use think\Cache;
use think\Controller;
use think\Db;
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
        $adv_list = \think\Cache::remember($cacheKey, function() use ($company) {
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

    public function getObjMaterialDayCost($is_first=false, $day = 92): string
    {
        if ($day !== '' && (!is_numeric($day) || $day < 0)) {
            return "天数参数无效";
        }
        if($is_first){
            $obj_start_time = strtotime("-92 days");
            $obj_end_time =time();
        }

        $global = new QcGlobalObj();
        $obj_list = $global
            ->alias('g')
            ->join('company com','g.adv_id=com.advertiser_id','left')
            ->where([
                'com.adv_status' => 1,
                'g.obj_create_time'=>['between',[$obj_start_time,$obj_end_time]],
                'g.obj_status'=>['not in', ['DELETE', 'FROZEN']],
                'g.opt_status'=>['not in', ['DELETE', 'FROZEN']],
            ])
            ->field('g.obj_id,g.adv_id')
            ->select();
        $obj_arr = [];
        foreach ($obj_list as $item){
            $obj_arr[$item['adv_id']][]=$item['obj_id'];
        }
        if (!$obj_list) {
            return "无数据可处理";
        }
        $currentTime = time();

        if ($day) {
            $dayCount = intval($day);
            $startTime = strtotime(date('Y-m-d ', strtotime("-{$dayCount} days")));
            $endTime = $currentTime;
        }else{
            $startTime = $currentTime;
            $endTime = $currentTime;
        }

        $timeData = [
            'start_time' => date("Y-m-d ", $startTime),
            'end_time' => date('Y-m-d ', $endTime)
        ];
        $batchSize = 30;
        foreach ($obj_arr as $adv_id=> $item){
            $chunks = array_chunk($item, $batchSize,true);
            $queueCount = 0;
            foreach ($chunks as $chunk) {
                Queue::push('app\job\fission\InsertGlobalObjMaterial', [
                    'adv_id'=>$adv_id,
                    'obj_list' => $chunk,
                    'start_time' => $timeData['start_time'],
                    'end_time' => $timeData['end_time']
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
        // 获取符合条件的广告主列表（优化：添加缓存和更精确的查询）
        $cacheKey = 'qualified_advertisers_' . date('Y-m-d-H');
        $material = new AdvGlobalMaterial();

        $adv_list = \think\Cache::remember($cacheKey, function() use ($material) {
            return $material
                ->alias('m')
                ->join('company c', 'm.adv_id = c.advertiser_id', 'left')
                ->where([
                    'm.stat_cost_for_roi2' => ['>', 0],
                    'c.adv_status' => 1
                ])
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
                if($res['data']['pagination']['total_number'] >50){
                    echo  $adv_id;
                }
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
                    $task_where['status_code'] = $value['status_code']??0;
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
            }catch (Exception $e){
                Db::rollback();
                dump($e->getMessage());
                die;
            }
        }

        echo "处理完了第" . $page. "页，准备处理下一页";
        Cache::set('test_task_page', $page+1);
    }

}