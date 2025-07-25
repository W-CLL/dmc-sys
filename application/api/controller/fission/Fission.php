<?php

namespace app\api\controller\fission;

use app\common\model\viral_fission\FissionDeriveMaterial;
use app\common\model\viral_fission\AdvGlobalMaterial;
use app\common\model\viral_fission\AdvHotMaterialTask;
use app\common\model\viral_fission\FissionMaterialTask;
use jlqc\FundManagement;
use think\Controller;
use think\Db;

/**
 * 素材裂变类
 */
class Fission extends Controller
{

    public function genMaterialDeriveTask()
    {
        $material_model = new AdvGlobalMaterial();
        $task_model = new FissionMaterialTask();
        $res = $this->getMaterialList();

        $task = [];
        foreach ($res as $item) {
//            dump($item->getData());
            if ($item['total_stat_cost'] >= 400) {
                $task[$item['adv_id']][] = (int)$item['material_id'];
            }
        }

        $insert = [];
        foreach ($task as $adv => $material_list) {
            $info = Db::name('adv_hot_material_task')
                ->where(['adv_id'=>$adv,'material_id'=>['in',$material_list]])
                ->whereBetween('create_time',['1752509227',time()])
                ->distinct('material_id')
                ->column('material_id');
            if( count( $info) == count($material_list)){
                continue;
            }else{
                $material_list = array_values(array_diff($material_list,$info));
            }
            if(!$material_list){
                continue;
            }
            $task_model = new FissionMaterialTask();
            $params = ['advertiser_id' => (int)$adv, 'material_ids' => $material_list];
            $res = FundManagement::gen_material_derive_task($params);
            if ($res['code'] == 0) {
                foreach ($res['data']['tasks'] as $item) {
                    if ( in_array($item['status_code'] , [41010,41001] )) {
                        echo $adv;
                        dump($item);
                        continue;
                    }

                    $insert[] = [
                        'task_id' => $item['task_id'],
                        'adv_id' => $adv,
                        'material_id' => $item['material_id'],
                        'status_code' => $item['status_code'],
                        'status_message' => $item['status_message'],
                        'request_id' => $res['request_id']
                    ];
                }
                $save_res = $task_model->saveAll($insert);
            } else {
                echo $res['message'];
            }
        }

     echo "全部处理了";
    }

    public function getTaskStatus()
    {
        $model = new AdvHotMaterialTask();
        $derive_model = new FissionDeriveMaterial();
        $existed_task = $derive_model->group('task_id')->column('task_id');
        $res = $model->where(['task_id'=>['not in',$existed_task]])->group('adv_id,material_id')->order('adv_id desc')->select();
        $result = [];
        foreach ($res as $item) {
//                sleep(1);
            $param = [
                'advertiser_id' => (int)$item['adv_id'],
                'task_ids' => json_encode([(int)$item['task_id']])
            ];
            $res1 = FundManagement::get_material_derive_task_status($param);
            dump($res1);
            $result[$item['adv_id']] = $res1;
        }

        $insert_data = [];
        foreach ($result as $adv_id => $res) {
            if ($res['message'] == "OK") {
                foreach ($res['data']['task_details'] as $detail) {
                    if(!empty($detail['status_code'])){//
                        dump($detail);
                    }
                    foreach ($detail['derive_materials'] as $derive) {
                        $strategy_detail = $derive['strategy_detail'];
                        $insert_data[] = [
                            'adv_id' => $adv_id,
                            'task_id' => $detail['task_id'],
                            'old_material_id' => $detail['origin_material_id'],
                            'strategy' => $strategy_detail['strategy'],
                            'apply_times' => json_encode($strategy_detail['apply_times']),
                            'strategy_description' => $strategy_detail['strategy_description'],
                            'strategy_name' => $strategy_detail['strategy_name'],
                            'title' => $derive['title'],
                            'video_id' => $derive['video_id'],
                            'video_url' => $derive['video_url'],
                        ];
                    }

                }
            }
        }

        $res2 = $derive_model->saveAll($insert_data);

    }

    public function adoptVideo()
    {
        $derive_model = new FissionDeriveMaterial();
        $result = $derive_model->field(['adv_id', 'video_id'])->select();

        $advMaterialMap = [];
        foreach ($result as $item) {
            $advMaterialMap[$item['adv_id']][] = $item['video_id'];
        }

        foreach ($advMaterialMap as $adv_id=>$item){
            $params = [
                'advertiser_id'=>(int)$adv_id,
                'video_ids'=>$item
            ];
         $res =    FundManagement::adopt_material($params);
         dump($res);
        }

        echo "全部已采纳";

    }

    private function getMaterialList()
    {
        $material_model = new AdvGlobalMaterial();
        return $material_model
            ->field('adv_id, material_id, SUM(stat_cost_for_roi2) as total_stat_cost')
            ->where('stat_cost_for_roi2', '>', 0)
            ->whereBetween('cost_date',["1751987227","1752592027"])
            ->group('adv_id, material_id') // 按照 adv_id 和 material_id 分组
            ->order('adv_id', 'asc')
            ->order('total_stat_cost', 'desc')
            ->select();
    }
}