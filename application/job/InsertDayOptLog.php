<?php

namespace app\job;

use app\admin\model\QcObj;
use app\admin\model\QcObjOptLog;
use app\common\model\Queue;
use app\qcdatahandle\controller\ComFun;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use jlqc\FundManagement;
use think\Cache;
use think\Exception;
use think\queue\Job;


class InsertDayOptLog
{

    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        $queueData = $queueModel->where('job_id', $jobId)->find();
        if (!$queueData) {
            $job->delete();
            return '';
        }
        if($queueData['status'] !=0){
            $job->delete();
            return '';
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
    protected function doJob($params, $queueData)
    {
        $accessToken =  Cache::get("qc_access_token");
        $params['advertiser_id'] = (int)$params['advertiser_id'];
        $params['object_id'] = array_map('intval', $params['object_id']);
        $resData = FundManagement::get_opt_log($accessToken,$params);
        $queue= new Queue();
        if($resData['code'] == 0) {
            if (empty($resData['data']['logs'])) {
                echo $params['advertiser_id'] . "当天没有新的操作日志";
                return true;
            }
            $totalNumber = $resData['data']['page_info']['total_number'];
            $totalPage = $resData['data']['page_info']['total_page'];
            if ($totalNumber <= 20 && $totalNumber > 0) {
                $this->handleInsertData($resData['data']['logs'], $params['advertiser_id']);
            } elseif ($totalNumber > 20) {
                $queueData = [
                    'adv_id' => $params['advertiser_id'],
                    'obj_ids' => $params['object_id'],
                    'start_time' => $params['start_time'],
                    'end_time' => $params['end_time'],
                    'total_page' => $totalPage,
                    'total_number' => $totalNumber,
                    'from_page' => 2,
                ];
                //先把第一页写进去
                $res = $this->handleInsertData($resData['data']['logs'], $params['advertiser_id']);
                if (!$res) {
                    throw new Exception($resData['message']);
                }
                //从第二页开始用队列进行写入
                $queue->addQueue('插入计划操作日志', 'app\job\InsertObjOptLog', 'insertObjOptLog', $queueData);
            }
            return true;
        } else {
            throw new Exception($resData['message']);
        }
    }

    /**
     * @throws \Exception
     */
    protected function handleInsertData($data, $advId)
    {
        $insertData = [];
        foreach ($data as $item) {
            $insertData[] = [
                'adv_id' => $advId,
                'obj_id' => $item['object_id'],
                'content_log' => json_encode($item['content_log']),
                'content_title' => $item['content_title'],
                'object_name' => $item['object_name'],
                'object_type' => $item['object_type'],
                'operator' => $item['operator'],
                'opt_ip' => $item['opt_ip'],
                'opt_time' => strtotime($item['create_time']),
            ];
        }
        if($insertData){
            $objOptLogModel = new QcObjOptLog();
            return $objOptLogModel->saveAll($insertData);
        }
        return true;
    }
}
