<?php

namespace app\job;

use app\admin\model\QcGlobalObj;
use app\common\model\Queue;
use jlqc\FundManagement;
use think\Cache;
use think\Exception;
use think\queue\Job;

class AutoUpdateGlobalObjName
{

    public function fire(Job $job, $data)
    {
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
            if($this->checkRebootMsg($e->getMessage())){
                $queueModel->rebootOne($queueData['id']);
            }else {
                $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => $e->getMessage()]);
            }
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
        $objInfo = FundManagement::get_global_obj_detail($data['adv_id'],$data['obj_id']);
        if($objInfo['code'] !=0){
            throw new Exception($objInfo['message']);
        }

        $objDetail = $objInfo['data'];
        if(in_array($objDetail['opt_status'],['DELETE']) ){
            $id = $this->getId(['obj_id'=>$data['obj_id']],'qc_global_obj');
            $this->pushUpdateData(['opt_status'=>$objDetail['opt_status'],'id' => $id]);
            $this->deleteRedundantJob($queueData);
            throw new Exception("计划状态不符合更新,该计划操作状态为:".$this->convertStatus($objDetail['opt_status']));
        }
        if(in_array($objDetail['status'],['DELETE',  'FROZEN']) ){
            $id = $this->getId(['obj_id'=>$data['obj_id']],'qc_global_obj');
            $this->pushUpdateData(['obj_status'=>$objDetail['status'],'id' => $id]);
            $this->deleteRedundantJob($queueData);
            throw new Exception("计划状态不符合更新,该计划投放状态为:".$this->convertStatus($objDetail['status']));
        }
        $this->removeEmptyValues($objDetail['multi_product_creative_list']);
        $updateData = $this->buildData($objDetail);
        $updateData['advertiser_id'] = (int)$data['adv_id'];
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
        $url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/uni_aweme/ad/update/";
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
            '不在素材库',
            '服务内部错误',
            'No permission',
            '抖音原生视频的imageModel',
            '当前广告主状态已禁用',
            '计划状态不符合更新',
            '账户已失去该抖音号下对应店铺的商品全域推广权限',
            '用户没有绑定千川权限',
            '体验分低于60分'
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


    private function buildData($objDetail){
        return array(
            'ad_id' => $objDetail['ad_id'],
            'delivery_setting' => [
                'qcpx_mode' => $objDetail['delivery_setting']['qcpx_mode'],
                'budget' => $objDetail['delivery_setting']['budget'],
                'video_schedule_type' => $objDetail['delivery_setting']['video_schedule_type'],
                'start_time' => $objDetail['delivery_setting']['start_time'],
                'end_time' => $objDetail['delivery_setting']['end_time'],
            ],
            'multi_product_creative_list' => $objDetail['multi_product_creative_list'],
        );
    }


    // 检查需要重启的错误信息
    private function checkRebootMsg($str){
        $msg_arr = [
            '系统开小差啦',
            'Internal service timed out',
            'Too many requests',
            'remote or network error[remote]',
            'SQLSTATE[42S02]',
            '计划正在更新中',
            '存在正在处理的全域推广项目'
        ];
        foreach ($msg_arr as $msg){
            if (strpos($str, $msg) !== false) {
                return true;
            }
        }
        return false;
    }


    // 此处推送的data数据，需要包含主键id，不然批量更新无法操作
    private function pushUpdateData(array $data){
        $pushRedisApiUrl = API_BASE_URL."/pushRedisApi/";
        $params = [
            "key_name" => "updateGlobalObjStatus",
            "value" => json_encode($data,  JSON_UNESCAPED_UNICODE)
        ];
        $result = sendApiRes($pushRedisApiUrl,$params);
        if ($result['status'] != 0){
            throw new Exception($result['msg']);
        }
    }

    private function getId(array $where, string $table_name){
        $getIdApiUrl = API_BASE_URL."/getIdApi/";
        $params = [
            "table_name" => $table_name,
            "where" => $where,
        ];
        $result = sendApiRes($getIdApiUrl,$params,'POST');
        if ($result['status'] != 0){
            throw new Exception($result['msg']);
        }
        return $result['data'];
    }


}