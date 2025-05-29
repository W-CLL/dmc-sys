<?php

namespace app\job\Base;

use app\common\model\Queue;
use think\Cache;
use think\Exception;
use think\queue\Job;

abstract class BaseUpdateWebJob
{
    protected abstract function getEditUrl($obj_info);
    protected abstract function getEditApiUrl();
    protected abstract function getQueueName();

    public function fire(Job $job, $data)
    {
        if (Cache::get('web_edit_too_many_res')) {
            echo "稍等";
            die;
        }

        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        $queueData = $queueModel->where('job_id', $jobId)->lock(true)->find();

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
                Cache::set('need_login', true, 300);
                $job->delete();
                return '';
            } else {
                if ($job->attempts() > 3) {
                    $job->delete();
                }
            }
        } catch (Exception $e) {
            if (preg_match("/Undefined index*/iu", $e->getMessage())) {
                $queueModel->rebootOne($queueData['id']);
//                Cache::set('web_edit_too_many_res', true, 600);
            } else {
                if (in_array($e->getMessage(), ['找不到广告主信息', '找不到计划信息'])) {
                    $this->delQueue($data['adv_id'], $data['obj_id'], $queueData['id'], "is_del_obj");
                }
                Cache::set('need_login', true, 300);
                $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => $e->getMessage()]);
            }
            $job->delete();
            return '';
        }
    }

    /**
     * @throws Exception
     */
    protected function doJob($data, $queueData)
    {
        list($com_info, $obj_info) = $this->getObjAndAdvInfo($data['adv_id'], $data['obj_id']);

        $target_url = $this->getEditUrl($obj_info);

        $url = $this->getEditApiUrl();

        $params = [
            'account_id' => $obj_info['adv_id'],
            'account_name' => $com_info['name'],
            'target_url' => $target_url,
            'last_one' => $data['last_one'],
            'need_login' => !Cache::get('need_login'),
            'need_change' => true,
        ];

        if (Cache::has('web_last_adv_id') && Cache::get('web_last_adv_id') == $data['adv_id']) {
            $params['need_change'] = false;
        }

        $res = sendApiRes($url, $params, "POST");

        if (isset($res['data']['status']) && $res['data']['status'] == 'fail') {
            list($key, $msg_res) = $this->skipIfContainsError($res['data']['msg']);
            if ($msg_res) {
                $this->delQueue($obj_info['adv_id'], $obj_info['obj_id'], $queueData['id'], $key);
            }
            Cache::set('web_last_adv_id', $data['adv_id'], 30);
            if ($res['data']['msg'] == "网络异常，请重新提交") {
                Cache::set('web_edit_too_many_res', true, 600);
            }
            throw new Exception($res['data']['msg']);
        }

        Cache::rm('web_edit_too_many_res');
        Cache::set('web_last_adv_id', $data['adv_id'], 30);
        return [$res['data']['status'], $res['data']['msg']];
    }

    public function skipIfContainsError($message): array
    {
        $keywords = [
            'not_found' => '/账号找不到.*/iu',
            'no_permission' => '/No permission to operate account/iu',
            "not_normal" => '/当前计划存在其他问.*/iu',
            "not_found_obj" => '/找不到计划信息.*/iu',
            "is_del_obj" => "/删除/iu",
            "not_support"=>"已不再支持商品标准推广的计划操作，请尽快迁移至全域推广",
            "not_dy"=>"/无此抖音号的使用权限/iu"
        ];
        foreach ($keywords as $key => $pattern) {
            if (preg_match($pattern, $message)) {
                return [$key, true];
            }
        }
        return [false, false];
    }

    private function delQueue($adv_id, $obj_id, $queue_record_id, $key): void
    {
        $queue = new Queue();
        if ($adv_id && in_array($key, ["not_found","not_support"])) {
            $queue->where([
                'job_data' => ['like', "%" . $adv_id . "%"],
                'status' => 0,
                'queue_name' => $this->getQueueName(),
                'id' => ['neq', $queue_record_id]
            ])->delete();
            return;
        }
        if ($obj_id && in_array($key, ["not_normal", "not_found_obj", 'is_del_obj','not_dy'])) {
            $queue->where([
                'job_name' => ['like', "%" . $obj_id . "%"],
                'status' => ['in', [0, 2]],
                'queue_name' => $this->getQueueName(),
                'id' => ['neq', $queue_record_id]
            ])->delete();
            return;
        }
    }

    /**
     * 获取广告主和计划信息
     * @throws Exception
     */
    protected function getObjAndAdvInfo($adv_id, $obj_id): array
    {
        $obj_url = API_BASE_URL . "/getObjInfo/";
        $adv_url = API_BASE_URL . "/getAdvInfo/";

        $adv_rep = sendApiRes($adv_url, [$adv_id]);
        if (!$adv_rep['data']) {
            throw new Exception("找不到广告主信息");
        }

        $obj_rep = sendApiRes($obj_url, $this->buildGetObjParams($adv_id, $obj_id));
        if (!$obj_rep['data']) {
            throw new Exception('找不到计划信息');
        }

        return [$adv_rep['data'], $obj_rep['data']];
    }

    protected function buildGetObjParams($adv_id, $obj_id): array
    {
        return [$adv_id, $obj_id];
    }
}
