<?php

namespace app\api\controller\fission;

use app\admin\model\Company;
use app\common\model\viral_fission\AdvGlobalMaterial;
use app\common\model\viral_fission\FissionDeriveMaterial;
use app\common\model\viral_fission\FissionMaterialTask;
use think\Controller;
use think\Db;
use think\Queue;

class QcGlobal extends Controller
{

    public function getMaterialDayCost($hour = 2,$day=''): string
    {
        $company = new Company();
        $adv_list = $company->where(['adv_status' => 1])->column('advertiser_id');

        if($day){
            $hour = 24 * $day;
        }

        if (!$adv_list) {
            return "无数据可处理";
        }

        $chunks = array_chunk($adv_list, 30);
        foreach ($chunks as $chunk) {
            Queue::push('app\job\fission\InsertGlobalMaterial', [
                'adv_list' => $chunk,
                'start_time' => date("Y-m-d H:i:s", time() - (60 * 60 * $hour)),
                'end_time' => date('Y-m-d H:i:s')
            ], 'insertGlobalMaterial');
        }

        return "已处理所有数据，共" . count($adv_list) . "条记录";
    }

    /**
     * 生成裂变任务
     * @return string
     */
    public function genMaterialFissionTask(): string
    {
        $material = new AdvGlobalMaterial();
        $adv_list = $material
            ->alias('m')
            ->join('company c', 'm.adv_id=c.advertiser_id', 'left')
            ->where(['m.stat_cost_for_roi2' => ['>', 0], 'c.adv_status' => 1])->group('m.adv_id')
            ->column('c.company_name', 'm.adv_id');
        $chunks = array_chunk($adv_list, 20, true);
        foreach ($chunks as $chunk) {
            Queue::push('app\job\fission\GenMaterialFissionTask', [
                'adv_list' => $chunk,
            ], 'genMaterialFissionTask');
        }

        return "已处理所有数据，共" . count($adv_list) . "条记录";
    }

    public function adoptFissionMaterial()
    {
        $fission_material = new FissionDeriveMaterial();
        $list = $fission_material->where(['adopt_status_message' => null, 'create_time' => ['between', [strtotime('-6 days'), time()]]])->select();

        $chunks = [];
        foreach ($list as  $item) {
            $chunks[$item['adv_id']][] = $item['video_id'];
        }

        foreach ($chunks as $adv_id => $chunk) {
            $count = count($chunk);
            if ($count > 50) {
                $queue_datas = array_chunk($chunk, 50, true);
                foreach ($queue_datas as $data) {
                    Queue::push('app\job\fission\AdoptFissionMaterial', [
                        'adv_id' => $adv_id,
                        'video_id' => $data,
                    ], 'adoptFissionMaterial');
                }
            } else {
                Queue::push('app\job\fission\AdoptFissionMaterial', [
                    'adv_id' => $adv_id,
                    'video_id' => $chunk,
                ], 'adoptFissionMaterial');
            }
        }
        echo "全部处理完需要采纳的视频了";
    }

    public function getFissionTaskStatus()
    {
        $material_task = new FissionMaterialTask();
        $list = $material_task->where([
            'status_message' => 'success',
            'is_handle' => 0,
            'task_id' => ['>', 0]
        ])
            ->where(function ($query) {
                $query->whereNotIn('fission_status', [ 'PART_SUCCESS', 'SUCCESS'])
                    ->whereOr('fission_status', 'NULL');
            })
            ->select();


        $chunks = [];
        foreach ($list as $item) {
            $chunks[$item['adv_id']][] = $item['task_id'];
        }

        foreach ($chunks as $adv_id => $chunk) {
            $count = count($chunk);
            if ($count > 50) {
                $queue_datas = array_chunk($chunks, 50, true);
                foreach ($queue_datas as $data) {
                    Queue::push('app\job\fission\GetFissionMaterialStatus', [
                        'adv_id' => $adv_id,
                        'task_id' =>array_values( $data),
                    ], 'getFissionMaterialStatus');
                }
            } else {
                Queue::push('app\job\fission\GetFissionMaterialStatus', [
                    'adv_id' => $adv_id,
                    'task_id' =>array_values( $chunk),
                ], 'getFissionMaterialStatus');
            }
        }
        echo "主动调起成功";
    }

}