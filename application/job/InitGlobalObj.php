<?php

namespace app\job;

use app\admin\model\QcGlobalObj;
use app\admin\model\QcObj;
use jlqc\FundManagement;
use think\Cache;
use think\Exception;
use think\Log;
use think\queue\Job;


class InitGlobalObj
{

    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        $queueData = $queueModel->where('job_id', $jobId)->find();
        try {
            $isJobDone = $this->doJob($data);
            if ($isJobDone) {
                if($queueData){
                    $queueData->save(['id' => $queueData['id'], 'status' => 1, 'msg' => "处理完成"]);
                }
                $job->delete();
                return '';
            } else {
                if ($job->attempts() > 3) {
                    $job->delete();
                    return '';
                }
            }
        } catch (Exception | \Exception $e) {
            $insert_data = [
                'job_name' => '获取第n页全域计划',
                'job_id' => $jobId,
                'class_name' => 'app\job\InitGlobalObj',
                'queue_name' => 'initGlobalObj',
                'relation_table' => '',
                'job_data' => json_encode($data),
                'remark' => '',
                'msg' => $e->getMessage(),
                'status' => 2,
            ];
            if($queueData){
                $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' =>$e->getMessage()]);
                $job->delete();
                return '';
            }
            $queueModel->save($insert_data);
            $job->delete();
            return '';
        }
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    protected function doJob($data)
    {
//        sleep(2);
        $data['advertiser_id'] = (int)$data['advertiser_id'];
        $resData = FundManagement::get_global_obj_list($data);
        if ($resData['code'] == 0) {
            if (empty($resData['data']['ad_list'])) {
                echo $data['advertiser_id'] . "没有新建全域计划";
                return true;
            }
            $totalPage = $resData['data']['page_info']['total_page'];
            $res = $this->saveNewObj($data['advertiser_id'], $resData['data']['ad_list']);
            if ($totalPage > $data['page'] && $res) {
                $data['page'] = $data['page'] + 1;
                \think\Queue::push('app\job\InitGlobalObj', $data, "initGlobalObj");
            }
            return true;
        } else {
            if($this->skipIfContainsError($resData['message'])){
                throw new Exception($resData['message']);
            }else{
//                dump($resData['message']);
                \think\Queue::push('app\job\InitGlobalObj', $data, "initGlobalObj");
                return true;
            }
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
        $adInfos = array_column($list, 'ad_info');

        $repObjIds = array_column($adInfos,'id');
        $objModel = new QcGlobalObj();
        $exitedIds = $objModel->where(['adv_id' => $advId, 'obj_id' => ['in', $repObjIds]])->column('obj_id');
        $afterData = array_filter($list, function ($item) use ($exitedIds) {
            return !in_array($item['ad_info']['id'], $exitedIds);
        });
        if (empty($afterData)) {
            echo "没有新插入";
            return true; // 没有新增数据，直接返回 true，避免重试
        }
        $insertData = [];
        foreach ($afterData as $item) {
            $insertData[] = [
                'adv_id' => $advId,
                'obj_id' => $item['ad_info']['id'],
                'name' => $item['ad_info']['name'],
                'obj_status' => $item['ad_info']['status'],
                'opt_status' => $item['ad_info']['opt_status'],
                'marketing_goal' => $item['ad_info']['marketing_goal'],
                'smart_bid_type' => $item['ad_info']['smart_bid_type'],
                'obj_create_time' => strtotime($item['ad_info']['create_time']),
                'obj_modify_time' => strtotime($item['ad_info']['modify_time']),
                'start_time' => strtotime($item['ad_info']['start_time']),
                'end_time' => strtotime($item['ad_info']['end_time']),
                'product_info' => json_encode($item['product_info']),
                'room_info' => json_encode($item['room_info']),
                'stats_info' => json_encode($item['stats_info']),
            ];
        }
        if ($insertData) {
            echo "写进了";
            $res = $objModel->saveAll($insertData);
            if ($res) {
                return true;
            } else {
                return false;
            }
        }
        return true;
    }

    public  function skipIfContainsError($message): bool
    {
        // 定义需要匹配的关键词列表（支持中英文）
        $keywords = [
            '/广告主账号已禁用/iu',  // 中文关键词（忽略大小写）
            '/No permission to operate account/iu',  // 英文关键词（忽略大小写）
        ];
        // 检查是否匹配其中一个关键词
        foreach ($keywords as $pattern) {

            if (preg_match($pattern, $message)) {

                return true;
            }
        }
        return false;
    }

}
