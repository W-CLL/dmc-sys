<?php

namespace app\job;

use app\admin\model\QcObj;
use app\common\model\Queue;
use jlqc\FundManagement;
use think\Cache;
use think\Exception;
use think\queue\Job;

/**
 * 每天早上八点，下午三点自动跑刷名称
 */

class AutoUpdateObjName
{

    public function fire(Job $job, $data)
    {
//        $currentHour = date('H');

        // 判断是否在凌晨1点到5点之间
//        if ($currentHour >= 2 && $currentHour < 5) {
//            // 记录日志或返回信息
//            \think\Log::info('每天凌晨2点到5点不刷计划');
//            return ''; // 直接返回，停止执行
//        }
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        $queueData = $queueModel->where('job_id', $jobId)->lock(true)->find();
        if (!$queueData) {
            $job->delete();
            return '';
        }
        if($queueData['status'] !=0){
            $job->delete();
            return '';
        }
        try {
            list($isJobDone,$msg) = $this->doJob($data, $queueData);
            if ($isJobDone) {
                $queueData->save(['id' => $queueData['id'], 'status' => 1, 'msg' => $msg]);
                $job->delete();
                return '';
            } else {
                if ($job->attempts() > 3) {
                    $job->delete();
                }
            }
        } catch (Exception $e) {
            $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => $e->getMessage()]);
            $job->delete();
            return '';
        }
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    protected function doJob($data, $queueData)
    {
//        $queue = new Queue();
        $delay = $data['delay'];
        if($delay>5&&$delay<10){
            $delay = $delay-2;
        }else if($delay>10){
            $delay = $delay-6;
        }
        sleep($delay);
        $token = Cache::get('qc_access_token');
        $objInfo = FundManagement::get_ad_detail($token, $data['adv_id'],$data['obj_id']);
        $qcObj = new QcObj();
        if($objInfo['code'] !=0){
            throw new Exception($objInfo['message']);
        }

        $objDetail = $objInfo['data'];
        if(in_array($objDetail['opt_status'],['DELETE','FROZEN']) ){
           $qcObj->where(['obj_id'=>$data['obj_id']])->update(['opt_status'=>$objDetail['opt_status']]);
           $this->deleteRedundantJob($queueData);
            throw new Exception("计划状态不符合更新,该计划状态为:".$this->convertStatus($objDetail['opt_status']));
        }
        if(in_array($objDetail['status'],['DELETE',  'FROZEN']) ){
            $qcObj->where(['obj_id'=>$data['obj_id']])->update(['opt_status'=>$objDetail['status']]);
            $this->deleteRedundantJob($queueData);
            throw new Exception("计划状态不符合更新,该计划状态为:".$this->convertStatus($objDetail['opt_status']));
        }
        $this->removeEmptyValues($objDetail);
        unset($objDetail['ad_create_time']);
        unset($objDetail['ad_modify_time']);
        $updateData = $objDetail;
        $updateData['advertiser_id'] = $data['adv_id'];
        $pattern = '/\(\.\d+_\d+\.\)/';
        // 获取当前时间，精确到秒
        $current_time = "(.".date('md_His').".)";
        if (preg_match($pattern, $objDetail['name'])) {
            // 如果找到了匹配的内容，进行替换
            if($data['last_one']){//如果是最后一次，还原计划名字
                $newName = preg_replace($pattern, '', $objDetail['name']);
            }else{
                $newName = preg_replace($pattern, $current_time, $objDetail['name']);
            }
        } else {
            // 如果没有找到匹配，拼接新的内容
            $newName =  $objDetail['name'] . $current_time;
        }
        // 将提取的中文字符拼接当前时间
        $updateData['name'] = $newName;
        if(isset($updateData['delivery_setting']['schedule_time'])) {
            if (preg_match('/^0+$/', $updateData['delivery_setting']['schedule_time']) || preg_match('/^1+$/', $updateData['delivery_setting']['schedule_time'])) {
                unset($updateData['delivery_setting']['schedule_time']);
            }
        }
        $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/ad/update/";
        $header = array(
            'Access-Token:' . $token,
            'Content-Type:application/json',
        );
       $res = \Requests::post($url, json_encode($updateData, JSON_UNESCAPED_UNICODE), $header);
       if($res['code'] == 0 && $res['message'] == "OK"){
           return [true,'处理成功'];
       }else{
           if($this->checkResultMsg($res)){
               $this->deleteRedundantJob($queueData);
           }
           throw new Exception($res['message'].json_encode($updateData));
       }
    }

    protected function removeEmptyValues(&$array) {
        //日常销售-商品-搜索-托管
        if(isset($array['marketing_scene']) && $array['marketing_scene'] == "SEARCH"){
            unset($array['audience']['new_customer']);
            $programmatic = ['programmatic_creative_card',"multi_product_creative_list","programmatic_creative_media_list","programmatic_creative_title_list"];
           foreach ($programmatic as $item){
               if(!$array[$item]){
                   unset($array[$item]);
               }
           }
        }
        if(isset($array['audience']['new_customer']) && $array['audience']['new_customer']=="NO_BUY_DOUYIN"){
            $array['audience']['new_customer'] = "NO_BUY";
        }

        foreach ($array as $key => &$value) {
            // 如果值是数组，则递归处理
            if (is_array($value)) {
                $this->removeEmptyValues($value);
            }

            // 如果值为空且不是数组，则删除该键
            if (empty($value)) {
                unset($array[$key]);
            }
        }
    }

    public function convertStatus($status)
    {

        switch ($status){
            case 'DELETE':
                $text = "已删除";
                break;
            case "TIME_DONE":
                $text = "已终止";
                break;
            case "FROZEN":
                $text = "已冻结";
                break;
            default :
                $text = '';
        }
        return $text;
    }


    private function checkResultMsg($res){
        $msg_arr = [
            '低效素材',
            '不在素材库中',
            '服务内部错误',
            '商品托管计划',
            'No permission',
            '抖音原生视频的imageModel',
            '当前广告主状态已禁用',
            '计划状态不符合更新',
            '搜索计划只支持',
            '成本稳投通投广告不',
        ];
        foreach ($msg_arr as $msg){
            if (strpos($res['message'], $msg) !== false) {
                return true;
            }
        }
        return false;
    }


    private function deleteRedundantJob($queueData){
        $queue = new Queue();
        $where['job_name'] = $queueData['job_name'];
        $where['queue_name'] = $queueData['queue_name'];
        $where['status'] = ['in',[0,2]];
        $where['id'] = ['neq',$queueData['id']];
        $queue->where($where)->delete();
    }


}