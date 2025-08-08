<?php

namespace app\job\fission;

use app\common\model\Queue;
use app\common\model\viral_fission\FissionDeriveMaterial;
use app\common\model\viral_fission\FissionMaterialTask;
use jlqc\FundManagement;
use think\Exception;

class AdoptFissionMaterial extends BaseJob
{

    public function __construct()
    {
        $this->queueRecordModelName = '\app\common\model\viral_fission\FissionQueue';
    }

    protected function getJobName(): string
    {
        return "采纳裂变素材";
    }

    protected function getQueueName(): string
    {
        return 'adoptFissionMaterial';
    }

    /**
     * @throws Exception
     */
    protected function doJob($data)
    {
        $derive_model = new FissionDeriveMaterial();
        $adv_id = $data['adv_id'];
        $param = [
            'advertiser_id' => (int)$adv_id,
            'video_ids' =>array_values( $data['video_id'])
        ];

        $res = FundManagement::adopt_material($param);
        if ($res['message'] == "OK" && $res['data']['results']) {
           foreach ($res['data']['results'] as $item){
               $material_info = $item['material_info'];
                $update_data = [
                    'material_info'=>json_encode($material_info),
                    'adopt_video_url'=>$material_info["video_url"],
                    'adopt_material_id'=>$material_info["material_id"],
                    'adopt_status_code'=>$item["status_code"],
                    'adopt_status_message'=>$item['status_message'],
                ];
             $derive_model->where(['video_id'=>$item['video_id'],'adv_id'=>$adv_id])->update($update_data);

           }
           return true;
        }else{
            if($res == "接口异常"){
                \think\Queue::push('app\job\fission\AdoptFissionMaterial', [
                    'adv_id' => $adv_id,
                    'video_id' => $data['video_id'],
                ], 'adoptFissionMaterial');
                return true;
            }else{
                throw new Exception($res['message']." 请求id:".$res['request_id']);
            }
        }
    }
}