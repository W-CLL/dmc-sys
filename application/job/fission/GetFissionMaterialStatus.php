<?php

namespace app\job\fission;

use app\common\model\Queue;
use app\common\model\viral_fission\FissionDeriveMaterial;
use app\common\model\viral_fission\FissionMaterialTask;
use jlqc\FundManagement;
use think\Db;
use think\Exception;

class GetFissionMaterialStatus extends BaseJob
{

    public function __construct()
    {
        $this->queueRecordModelName = '\app\common\model\viral_fission\FissionQueue';
        $this->task_model = new FissionMaterialTask();
        $this->queue_model = new Queue();
    }

    protected function getJobName(): string
    {
        return "获取裂变任务状态";
    }

    protected function getQueueName(): string
    {
        return 'getFissionMaterialStatus';
    }

    /**
     * @throws Exception
     */
    protected function doJob($data)
    {
        $derive_model = new FissionDeriveMaterial();
        $material_task = new FissionMaterialTask();
        $adv_id = $data['adv_id'];
        $param = [
            'advertiser_id' => (int)$adv_id,
            'task_ids' => json_encode(array_map('intval', $data['task_id']))
        ];
        $res = FundManagement::get_material_derive_task_status($param);
dump($res);
        $material_ids = [];
        $insert_data = [];
        if ($res['message'] == "OK" && !empty($res['data']['task_details'])) {
//            dump($res['message']);
            foreach ($res['data']['task_details'] as $detail) {
                $update_data = ['fission_status' => $detail['status'],'fission_msg'=>$detail['status_message']];
                if(in_array($detail['status'], ["PART_SUCCESS", "SUCCESS","FAILED"])){
                    $update_data['is_handle'] = 1;
                }
                $material_task->where(['material_id' => $detail['origin_material_id'], 'task_id' => $detail['task_id']])->update($update_data);
                if (in_array($detail['status'], ["PART_SUCCESS", "SUCCESS"])) {
                    $material_ids[] = $detail['origin_material_id'];
                    if(empty($detail['derive_materials'])){
                        continue;
                    }
                    foreach ($detail['derive_materials'] as $derive) {
                        $strategy_detail = $derive['strategy_detail'];
                        $where = [
                            'adv_id' => $adv_id,
                            'task_id' => $detail['task_id'],
                            'old_material_id' => $detail['origin_material_id'],
                            'strategy' => $strategy_detail['strategy'],
                            'strategy_name' => $strategy_detail['strategy_name'],
                            'video_id' => $derive['video_id'],
                        ];
                      $has =   $derive_model->where($where)->find();
                      if($has){
                          continue;
                      }
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
            if ($insert_data) {
                Db::startTrans();
                try {
                    $res = $derive_model->saveAll($insert_data);
                    if ($res) {
                        $material_task->where([
                            'adv_id' => $adv_id,
                            'task_id' => ['in', $data['task_id']],
                            'material_id' => ['in', $material_ids]
                        ])->update(['is_handle' => 1]);
                    }
                    Db::commit();
                    return true;
                } catch (Exception $e) {
                    Db::rollback();
                    throw new Exception($e->getMessage());
                }
            }
            return true;
        } else {
            throw new Exception($res['message']."请求id:".$res['request_id']);
        }
    }
}