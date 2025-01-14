<?php

namespace app\job;

use app\admin\model\QcObj;
use jlqc\FundManagement;
use think\Cache;
use think\Exception;
use think\queue\Job;


class InsertDayObj
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
            $isJobDone = $this->doJob($data, $queueData);
            if ($isJobDone) {
                $queueData->save(['id' => $queueData['id'], 'status' => 1, 'msg' => "处理完成"]);
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
//        sleep(1);
        $accessToken =  Cache::get("qc_access_token");
        $data['advertiser_id'] = (int)$data['advertiser_id'];
        $resData = FundManagement::get_ad_list($accessToken,$data);
        if($resData['code'] == 0){
            if(empty($resData['data']['list'])){
                echo $data['advertiser_id']."当天没有新建计划";
            }
            $totalPage = $resData['data']['page_info']['total_page'];
            if($totalPage>2){
              for($i=2;$i<=$totalPage;$i++){
                  $filtering = json_decode($data['filtering'],true);
                  $filtering['page'] = $i;
                  $data['filtering'] = json_encode($filtering);
                  $resData = FundManagement::get_ad_list($accessToken,$data);
                  if($resData['code'] == 0 && !empty($resData['data']['list']) ){
                    $res =   $this->saveNewObj($data['advertiser_id'],$resData['data']['list']);
                    if(!$res){
                        return false;
                    }
                  }
              }
            }elseif($totalPage==1){
             return  $this->saveNewObj($data['advertiser_id'],$resData['data']['list']);
            }
            return true;
        }else{
            throw new Exception($resData['message']);
        }

    }

    /**
     * 插入当天新增计划
     * @param $advId
     * @param $list
     * @return boolean
     * @throws \Exception
     */
    protected function saveNewObj($advId, $list)
    {
        $repObjIds = array_column($list, 'ad_id');
        $objModel = new QcObj();
        $exitedIds = $objModel->where(['adv_id' => $advId, 'obj_id' => ['in', $repObjIds]])->column('obj_id');
        $afterData = array_filter($list, function ($item) use ($exitedIds) {
            return !in_array($item['ad_id'], $exitedIds);
        });
        $insertData = [];
        foreach ($afterData as $item) {
            $insertData[] = [
                'adv_id' => $advId,
                'obj_id' => $item['ad_id'],
                'name' => $item['name'],
                'obj_status' => $item['status'],
                'opt_status' => $item['opt_status'],
                'marketing_goal' => $item['marketing_goal'],
                'marketing_scene' => $item['marketing_scene'],
                'campaign_scene' => $item['campaign_scene'],
                'campaign_id' => $item['campaign_id'],
                'lab_ad_type' => $item['lab_ad_type'],
                'obj_create_time' => strtotime($item['ad_create_time']),
                'obj_modify_time' => strtotime($item['ad_modify_time']),
                'product_info' => json_encode($item['product_info']),
                'aweme_info' => json_encode($item['product_info']),
                'delivery_setting' => json_encode($item['product_info']),
            ];
        }
        if ($insertData) {
            $res = $objModel->saveAll($insertData);
            if ($res) {
                return true;
            } else {
                return false;
            }
        }
        return false;
    }

}
