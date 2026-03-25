<?php

namespace app\api\controller\material_prequalification;

use think\Db;
use think\Exception;

class Api extends \app\common\controller\Api
{

    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';

    // 推送素材预审
    public function prequalification(){
        $fiveMinutesAgo = time() - 300;
        $info = Db::name('material_prequalification')->where([
            "status" => 0,
            "create_time" => ["<=", $fiveMinutesAgo],// 取五分钟之前的数据
            "object_id" => NULL,
        ])->field("material_id,advertiser_id,id")->limit(1000)->select();
        $result = [];
        $ids = [];
        // 按material_id去重，保留第一条记录
        $materialIdMap = [];
        foreach ($info as $value) {
            if (isset($materialIdMap[$value['material_id']])) {
                continue; // 已存在，跳过
            }
            $materialIdMap[$value['material_id']] = true;
            $result[$value['advertiser_id']][] = $value['material_id'];
            $ids[] = $value['id'];
        }
        foreach ($result as $key => $value){
            $chunk = array_chunk($value,20);
            foreach ($chunk as $v){
                // 确保material_ids中的值为int类型
                $v = array_map('intval', $v);
                \think\Queue::push('app\job\prequalification\Prequalification', ['advertiser_id' => $key, 'material_ids' => $v], 'prequalification');
            }
        }
        // 更新为已推送状态，防止重复推送
        if (!empty($ids)) {
            Db::name('material_prequalification')->where(['id' => ['in', $ids]])->update(['status' => 1]);
        }
        echo "ok";
    }




    // 更新素材预审结果
    public function updatePrequalificationStatus(){
        $key = 'material_precheck_result';
        for ($i = 0 ; $i < 50 ; $i++){
            $content = \think\Cache::store('redis')->handler()->lpop($key);
            if (empty($content)){
                return "implement:". $i ."    no more";
            }
            $content = json_decode($content,true);
            try {
                if ($content['status'] == 'APPROVE'){
                    $update = [
                        'status' => 2
                    ];
                }else{
                    $update = [
                        'status' => 3,
                        'reason_text' => $content['reason_text']
                    ];
                }
                Db::name('material_prequalification')->where(['object_id' => $content['object_id']])->update($update);
            }catch (Exception $e){
                \think\Cache::store('redis')->handler()->rpush($key, json_encode($content));  // 重新放回缓存
            }
        }
        return "all";
    }
}