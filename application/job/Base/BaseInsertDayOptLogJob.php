<?php

namespace app\job\Base;

use think\Cache;
use think\Exception;
use think\queue\Job;
use app\common\model\Queue;

abstract class BaseInsertDayOptLogJob
{
    /**
     * 获取日志模型类名（子类必须实现）
     */
    abstract protected function getLogModelClass();

    /**
     * 获取下一页处理的 Job 类名（子类必须实现）
     */
    abstract protected function getNextJobClass();

    /**
     * 获取队列名称（子类必须实现）
     */
    abstract protected function getQueueName();

    public function fire(Job $job, $data): string
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        $queueData = $queueModel->where('job_id', $jobId)->find();

        if (!$queueData || $queueData['status'] != 0) {
            $job->delete();
            return '';
        }

        try {
            $isJobDone = $this->doJob($data, $queueData);
            if ($isJobDone) {
                $queueData->save(['id' => $queueData['id'], 'status' => 1, 'msg' => "处理完成"]);
            } else if ($job->attempts() > 3) {
                $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => '超过最大重试次数']);
            }
            $job->delete();
            return '';

        } catch (Exception $e) {
            $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => $e->getMessage()]);
            $job->delete();
            return '';
        }
    }

    protected function doJob($params, $queueData)
    {
        $accessToken = Cache::get("qc_access_token");
        $params['advertiser_id'] = (int)$params['advertiser_id'];
        $params['object_id'] = array_map('intval', $params['object_id']);

        $resData = $this->fetchOptLog($accessToken, $params);

        if ($resData['code'] == 0) {
            if (empty($resData['data']['logs'])) {
                echo $params['advertiser_id'] . "当天没有新的操作日志\n";
                return true;
            }

            $totalNumber = $resData['data']['page_info']['total_number'];
            $totalPage = $resData['data']['page_info']['total_page'];

            if ($totalNumber <= 20 && $totalNumber > 0) {
                $this->handleInsertData($resData['data']['logs'], $params['advertiser_id']);
            } elseif ($totalNumber > 20) {
                // 写入第一页数据
                $res = $this->handleInsertData($resData['data']['logs'], $params['advertiser_id']);
                if (!$res) throw new Exception("插入失败：" . $resData['message']);

                // 推送后续页任务
                $nextQueueData = [
                    'adv_id' => $params['advertiser_id'],
                    'obj_ids' => $params['object_id'],
                    'start_time' => $params['start_time'],
                    'end_time' => $params['end_time'],
                    'total_page' => $totalPage,
                    'total_number' => $totalNumber,
                    'from_page' => 2,
                ];

                $queue = new Queue();
                $queue->addQueue('插入计划操作日志', $this->getNextJobClass(), $this->getQueueName(), $nextQueueData);
            }

            return true;
        } else {
            throw new Exception($resData['message']);
        }
    }

    /**
     * 获取操作日志（子类实现）
     */
    protected function fetchOptLog($accessToken, $params)
    {
        return \jlqc\FundManagement::get_opt_log($accessToken, $params);
    }

    /**
     * 插入日志数据
     * @throws Exception
     */
    protected function handleInsertData($data, $advId)
    {
        $insertData = [];
        $class_name = $this->getLogModelClass();
        $objOptLogModel = new $class_name();

        foreach ($data as $item) {
            $count = $objOptLogModel
                ->where([
                    'adv_id' => $advId,
                    'obj_id' => $item['object_id'],
                    'opt_ip' => $item['opt_ip'],
                    'opt_time' => strtotime($item['create_time']),
                    'object_name' => $item['object_name'],
                    'content_title' => $item['content_title']
                ])
                ->count();

            if ($count > 0) continue;

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
            $res =  $objOptLogModel->saveAll($insertData);
            if(!$res){
                throw  new Exception($res);
            }
        }
        return true;
//        return $insertData ? $objOptLogModel->saveAll($insertData) : true;
    }
}
