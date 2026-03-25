<?php

namespace app\api\controller\material_diagnosis;

use think\Db;

class api extends \app\common\controller\Api
{

    // 推送前测
    public function submitDiagnosis(){
        $info = Db::name('material_prequalification')
            ->where(['status' => 2,'to_diagnosis' => 0])
            ->field('id,advertiser_id,video_id')
            ->limit(1000)->select();
        if (!$info){
            echo "no more";
            die;
        }
        foreach ($info  as $item){
            $arr[$item['advertiser_id']][] = $item['video_id'];
            $id[] = $item['id'];
        }
        foreach ($arr as $key => $value){
            $chunk = array_chunk($value,100);
            foreach ($chunk as $v){
                \think\Queue::push('app\job\material_diagnosis\SubmitDiagnosis', ['advertiser_id' => $key, 'video_ids' => $v], 'submitDiagnosis');
            }
        }
        Db::name('material_prequalification')->where(['id' => ['in',$id]])->update(['to_diagnosis' => 1]);
        echo "ok";
    }


    // 获取前测结果
    public function getDiagnosisInfo(){
        $task_ids = Db::name('material_diagnosis')->where(['status' => 0])->column('task_id');
        $chunk = array_chunk($task_ids,100);
        foreach ($chunk as $item){
            $item = array_map('intval', $item);
            \think\Queue::push('app\job\material_diagnosis\GetDiagnosisInfo', ['task_ids' => $item], 'getDiagnosisInfo');
        }
        Db::name('material_diagnosis')->where(['task_id' => ['in',$task_ids]])->update(['is_get' => 1]);
        echo "ook";
    }

}