<?php

namespace app\job;

use app\admin\model\QcObj;
use jlqc\FundManagement;
use think\Cache;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\Exception;
use think\exception\DbException;
use think\Log;
use think\queue\Job;


class InitObj
{

    /**
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws DataNotFoundException
     */
    public function fire(Job $job, $data)
    {
        if ($job->attempts() >=2 ) {
            $job->delete();
            return '';
        }
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        $queueData = $queueModel->where('job_id', $jobId)->find();
        try {
            $isJobDone = $this->doJob($data);
            if ($isJobDone) {
                if ($queueData) {
                    $queueData->save(['id' => $queueData['id'], 'status' => 1, 'msg' => "处理完成"]);
                }
                $job->delete();
                echo "处理完了这一条了";
                return '';
            } else {
                if ($job->attempts() >=2 ) {
                    $job->delete();
                    return '';
                }
            }
        } catch (Exception | \Exception $e) {
            $insert_data = [
                'job_name' => '获取第n页计划',
                'job_id' => $jobId,
                'class_name' => 'app\job\InitObj',
                'queue_name' => 'initObj',
                'relation_table' => '',
                'job_data' => $data,
                'remark' => '',
                'msg' => $e->getMessage(),
                'status' => 2,
            ];
            if ($queueData) {
                $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => $e->getMessage()]);
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
    protected function doJob($data): bool
    {
        $accessToken = Cache::get("qc_access_token");
        $data['advertiser_id'] = (int)$data['advertiser_id'];
        $resData = FundManagement::get_ad_list($accessToken, $data);
        if ($resData['code'] == 0 && !empty($resData['data']['list'])) {
//            dump($resData['data']);
            // if (empty($resData['data']['list'])) {
            //     echo $data['advertiser_id'] . "当天没有新建计划";
            //     return true;
            // }
            $totalPage = $resData['data']['page_info']['total_page'];

            $res =  $this->saveNewObj($data['advertiser_id'], $resData['data']['list']);
            if ($totalPage > $data['page'] && $res) {
//                echo $totalPage."总:后".$data['page'];
                $data['page'] = $data['page'] + 1;
//                dump($data);
                \think\Queue::push('app\job\InitObj', $data, "initObj");
                return true;
            }
            return $res;
        } else {
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
    protected function saveNewObj($advId, $list): bool
    {
        $repObjIds = array_column($list, 'ad_id');
        $objModel = new QcObj();
        $exitedIds = $objModel->where(['adv_id' => $advId, 'obj_id' => ['in', $repObjIds]])->column('obj_id');
        $afterData = array_filter($list, function ($item) use ($exitedIds) {
            return !in_array($item['ad_id'], $exitedIds);
        });
        if (empty($afterData)) {
            echo "没有新增数据需要插入";
            return true; // 没有新增数据，直接返回 true，避免重试
        }
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

}
