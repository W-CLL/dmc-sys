<?php

namespace app\job\Base;

use think\Cache;
use think\Exception;
use think\queue\Job;
use app\common\model\Queue;

abstract class BaseInitObjJob
{
    /**
     * 获取任务名称（子类必须实现）
     * @return string
     */
    abstract public function getJobName(): string;

    /**
     * 获取任务对应的队列名称（子类必须实现）
     * @return string
     */
    abstract public function getQueueName(): string;

    /**
     * 获取模型类名（子类必须实现）
     * @return string
     */
    abstract protected function getModelClass(): string;

    /**
     * 获取数据（子类实现）
     * @param array $data
     * @return array
     */
    abstract protected function fetchData(array $data): array;

    /**
     * 构建插入数据结构（子类实现）
     * @param int $advId
     * @param array $item
     * @return array
     */
    abstract protected function buildInsertData(int $advId, array $item): array;

    /**
     * fire 入口方法
     */
    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        $queueData = $queueModel->where('job_id', $jobId)->find();

        try {
            $isJobDone = $this->doJob($data);
            if ($isJobDone) {
                if ($queueData) {
                    $queueData->save([
                        'id' => $queueData['id'],
                        'status' => 1,
                        'msg' => "处理完成"
                    ]);
                }
                $job->delete();
                return '';
            } else {
                if ($job->attempts() > 3) {
                    $job->delete();
                }
            }
        } catch (Exception $e) {
            $insert_data = [
                'job_name' => $this->getJobName(),
                'job_id' => $jobId,
                'class_name' => static::class,
                'queue_name' => $this->getQueueName(),
                'relation_table' => '',
                'job_data' => json_encode($data),
                'remark' => '',
                'msg' => $e->getMessage(),
                'status' => 2,
            ];
            if ($queueData) {
                $queueData->save([
                    'id' => $queueData['id'],
                    'status' => 2,
                    'msg' => $e->getMessage()
                ]);
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
     */
    protected function doJob($data): bool
    {
        $advId = (int)$data['advertiser_id'];

        // 获取数据
        $resData = $this->fetchData($data);

        if ($resData['code'] == 0) {
            if (empty($resData['data']['list']) && empty($resData['data']['ad_list'])) {
                echo "$advId 没有新建计划\n";
                return true;
            }

            $totalPage = $resData['data']['page_info']['total_page'] ?? 1;
            $list = $resData['data']['list'] ?? $resData['data']['ad_list'] ?? [];

            $res = $this->saveNewObj($advId, $list);

            if ($totalPage > ($data['page'] ?? 1) && $res) {
                $data['page'] = ($data['page'] ?? 1) + 1;
                \think\Queue::push(get_class($this), $data, $this->getQueueName());
            }

            return true;
        } else {
            if ($this->skipIfContainsError($resData['message'])) {
                throw new Exception($resData['message']);
            } else {
                \think\Queue::push(get_class($this), $data, $this->getQueueName());
                return true;
            }
        }
    }

    protected function saveNewObj(int $advId, array $list): bool
    {
        $model_class = $this->getModelClass();
        $objModel = new $model_class();

        $repObjIds = array_column($list, 'id');
        $exitedIds = $objModel->where(['adv_id' => $advId, 'obj_id' => ['in', $repObjIds]])->column('obj_id');

        $afterData = array_filter($list, function ($item) use ($exitedIds) {
            return !in_array($item['id'], $exitedIds);
        });

        if (empty($afterData)) {
            echo "没有新插入\n";
            return true;
        }

        $insertData = [];
        foreach ($afterData as $item) {
            $insertData[] = $this->buildInsertData($advId, $item);
        }

        if ($insertData) {
            echo "写进了\n";
            return (bool)$objModel->saveAll($insertData);
        }

        return true;
    }

    protected function skipIfContainsError(string $message): bool
    {
        $keywords = [
            '/广告主账号已禁用/iu',
            '/No permission to operate account/iu',
        ];

        foreach ($keywords as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }
}
