<?php

namespace app\job\Base;

use app\admin\model\QcObj;
use jlqc\FundManagement;
use think\Cache;
use think\Exception;
use think\queue\Job;

abstract class BaseUpdateStandJob
{
    /**
     * @return string 队列模型类名（子类需指定）
     */
    protected abstract function getQueueModelClass(): string;

    /**
     * @var string 队列模型实例
     */
    protected $queueModel;

    public function fire(Job $job, $data)
    {
        $queue_model = $this->getQueueModelClass();
        $this->queueModel = new $queue_model();
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueData = $this->queueModel->where('job_id', $jobId)->lock(true)->find();

        if (!$queueData) {
            $job->delete();
            return '';
        }

        if ($queueData['status'] != 0) {
            $job->delete();
            return '';
        }

        try {
            list($isJobDone, $msg) = $this->doJob($data, $queueData);
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

    protected function doJob($data, $queueData)
    {
        // 可选延迟
        if (isset($data['delay'])) {
            $delay = $data['delay'];
            if ($delay > 5 && $delay < 10) {
                $delay -= 2;
            } elseif ($delay > 10) {
                $delay -= 6;
            }
            sleep($delay);
        }

        $token = Cache::get('qc_access_token');
        $objInfo = FundManagement::get_ad_detail($token, $data['adv_id'], $data['obj_id']);

        if ($objInfo['code'] != 0) {
            throw new Exception($objInfo['message']);
        }

        $objDetail = $objInfo['data'];

        if (in_array($objDetail['opt_status'], ['DELETE', 'FROZEN'])) {
            $this->deleteRedundantJob($queueData);
            throw new Exception("计划状态不符合更新,该计划状态为:" . $this->convertStatus($objDetail['opt_status']));
        }

        if (in_array($objDetail['status'], ['DELETE', 'FROZEN'])) {
            $this->deleteRedundantJob($queueData);
            throw new Exception("计划状态不符合更新,该计划状态为:" . $this->convertStatus($objDetail['opt_status']));
        }

        $this->removeEmptyValues($objDetail);

        unset($objDetail['ad_create_time'], $objDetail['ad_modify_time']);

        $updateData = $objDetail;
        $updateData['advertiser_id'] = $data['adv_id'];

        $pattern = '/\(\.\d+_\d+\.\)/';
        $current_time = "(." . date('md_His') . ".)";

        if (preg_match($pattern, $objDetail['name'])) {
            $newName = $data['last_one']
                ? preg_replace($pattern, '', $objDetail['name'])
                : preg_replace($pattern, $current_time, $objDetail['name']);
        } else {
            $newName = $objDetail['name'] . $current_time;
        }

        $updateData['name'] = $newName;

        if (isset($updateData['delivery_setting']['schedule_time']) &&
            (preg_match('/^0+$/', $updateData['delivery_setting']['schedule_time']) ||
                preg_match('/^1+$/', $updateData['delivery_setting']['schedule_time']))) {
            unset($updateData['delivery_setting']['schedule_time']);
        }

        $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/ad/update/";
        $header = [
            'Access-Token:' . $token,
            'Content-Type:application/json',
        ];

        $res = \Requests::post($url, json_encode($updateData, JSON_UNESCAPED_UNICODE), $header);

        if ($res['code'] == 0 && $res['message'] == "OK") {
            return [true, '处理成功'];
        } else {
            if ($this->checkResultMsg($res)) {
                $this->deleteRedundantJob($queueData);
            }
            throw new Exception($res['message'] . json_encode($updateData));
        }
    }

    protected function removeEmptyValues(&$array)
    {
        if (isset($array['marketing_scene']) && $array['marketing_scene'] == "SEARCH") {
            unset($array['audience']['new_customer']);
            $programmatic = [
                'programmatic_creative_card',
                'multi_product_creative_list',
                'programmatic_creative_media_list',
                'programmatic_creative_title_list'
            ];
            foreach ($programmatic as $item) {
                if (empty($array[$item])) {
                    unset($array[$item]);
                }
            }
        }

        if (isset($array['audience']['new_customer']) && $array['audience']['new_customer'] == "NO_BUY_DOUYIN") {
            $array['audience']['new_customer'] = "NO_BUY";
        }

        foreach ($array as $key => &$value) {
            if (is_array($value)) {
                $this->removeEmptyValues($value);
            }

            if (empty($value)) {
                unset($array[$key]);
            }
        }
    }

    public function convertStatus($status)
    {
        switch ($status) {
            case 'DELETE':
                return "已删除";
            case "TIME_DONE":
                return "已终止";
            case "FROZEN":
                return "已冻结";
            default:
                return '';
        }
    }

    private function checkResultMsg($res)
    {
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

        foreach ($msg_arr as $msg) {
            if (strpos($res['message'], $msg) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function deleteRedundantJob($queueData)
    {
        $queue = new $this->queueModel();
        $where = [
            'job_name' => $queueData['job_name'],
            'queue_name' => $queueData['queue_name'],
            'status' => ['in', [0, 2]],
            'id' => ['neq', $queueData['id']]
        ];
        $queue->where($where)->delete();
    }
}
