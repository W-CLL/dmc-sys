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
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        $queueData = $queueModel->where('job_id', $jobId)->find();
        if (!$queueData) {
            $job->delete();
            die;
        }
        try {
            list($isJobDone,$msg) = $this->doJob($data, $queueData);
            if ($isJobDone) {
                $queueData->save(['id' => $queueData['id'], 'status' => 1, 'msg' => $msg]);
                $job->delete();
            } else {
                if ($job->attempts() > 3) {
                    $job->delete();
                }
            }
        } catch (Exception $e) {
            $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => $e->getMessage()]);
            $job->delete();
        }
    }

    /**
     * @throws Exception
     */
    protected function doJob($data, $queueData)
    {
//        $queue = new Queue();
        sleep($data['delay']);
        $token = Cache::get('qc_access_token');
        $objInfo = FundManagement::get_ad_detail($token, $data['adv_id'],$data['obj_id']);

        $objDetail = $objInfo['data'];
//        if(in_array($objDetail['status'],['DELETE', "TIME_DONE", 'FROZEN'])){
            //更新计划状态
//            $upData = [
//                'adv_id'=>$data['adv_id'],
//                'obj_id'=>$item,
//                'delay'=>$seconds
//            ];
//            $queue->addQueue('修改'.$item.'计划名称','app\job\AutoUpdateObjName','autoUpdateObjName',$upData,'','延迟'.$seconds.'秒执行');

//            return [true,'处理成功,计划状态不符合更新'];
//        }
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
            $newName = preg_replace($pattern, $current_time, $objDetail['name']);
        } else {
            // 如果没有找到匹配，拼接新的内容
            $newName =  $objDetail['name'] . $current_time;
        }
        // 将提取的中文字符拼接当前时间
        $updateData['name'] =$newName;
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
           throw new Exception($res['message']);
       }
    }

    protected function removeEmptyValues(&$array) {
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



}