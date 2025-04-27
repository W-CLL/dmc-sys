<?php

namespace app\job;

use app\admin\model\Company;
use app\admin\model\QcObj as ObjModel;
use app\common\model\Queue;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use think\Cache;
use think\Exception;
use think\queue\Job;

/**
 * 每天早上八点，下午三点自动跑刷名称
 */
class AutoUpdateObjNameWeb
{

    public function fire(Job $job, $data)
    {
        if( Cache::get('web_edit_too_many_res')){
//            echo "请求太快了，等五分钟";
            echo "稍等";
            die;
        }
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        $queueData = $queueModel->where('job_id', $jobId)->find();
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
            $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => $e->getMessage()]);
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
//        sleep(3);
//        dump(Cache::rm('need_login'));
//        dump(Cache::rm('web_last_adv_id'));
//        die;
        list($com_info, $obj_info) = $this->getObjAndAdvInfo($data['adv_id'], $data['obj_id']);
        $base_edit_url = "https://qianchuan.jinritemai.com/creation/";

        $marketing_scene = strtolower($obj_info['marketing_scene']);
        if ($obj_info['marketing_goal'] == "LIVE_PROM_GOODS") {
            $type = "live";
        } else {
            $type = 'video';
        }

        if ($marketing_scene == "shopping_mall") {
            $marketing_scene = 'mall';
            $type = "product";
        }

        if ($obj_info['lab_ad_type'] == "NOT_LAB_AD") {
            $lab_type = "standard";
        } else {
            $lab_type = "auto";
        }

        $target_url = sprintf(
            "%s%s-%s-%s?type=edit&adId=%s&aavid=%s",
            $base_edit_url,
            $marketing_scene,
            $type,
            $lab_type,
            $obj_info['obj_id'],
            $obj_info['adv_id']
        );
        $url = "http://localhost:2025/edit_plan/";
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

        $res = $this->sendApiRes($url, $params, "POST");

        if (isset($res['data']['status']) && $res['data']['status'] == 'fail') {
            Cache::set('need_login', true, 300);
            list($key, $msg_res) = $this->skipIfContainsError($res['data']['msg']);
            if ($msg_res) {
                $this->delQueue($obj_info['adv_id'], $obj_info['obj_id'], $queueData['id'], $key);
            } else {
                Cache::set('web_last_adv_id', $data['adv_id'], 30);
            }
            if($res['data']['msg'] == "网络异常，请重新提交"){
                Cache::set('web_edit_too_many_res',true,600);
            }
            throw new Exception($res['data']['msg']);
        }
        Cache::rm('web_edit_too_many_res');
        Cache::set('web_last_adv_id', $data['adv_id'], 30);
        return [$res['status'], $res['data']['msg']];

    }

    /**
     * 向正式服发送请求
     * @param $url
     * @param array $params
     * @param string $method
     * @return array
     */
    private function sendApiRes($url, array $params, string $method = 'GET'): array
    {
        try {
            $client = new Client(['verify' => false]);
            if ($method === 'POST') {
                $response = $client->post($url, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $params // 自动将数组转为 JSON 字符串
                ]);
            } else {
                $response = $client->get($url, [
                    'query' => $params
                ]);
            }
            $contents = $response->getBody()->getContents();
            return ['data' => json_decode($contents, true), 'status' => 1];
        } catch (Exception|GuzzleException $e) {
            return ['data' => [], 'status' => 0, 'msg' => $e->getMessage()];
        }
    }


    public function skipIfContainsError($message): array
    {
        // 定义需要匹配的关键词列表（支持中英文）
        $keywords = [
            'not_found' => '/账号找不到.*/iu',  // 中文关键词（忽略大小写）
            'no_permission' => '/No permission to operate account/iu',  // 英文关键词（忽略大小写）
            "not_normal" => '/当前计划存在其他问.*/iu',
            "not_found_obj" => '/找不到计划信息.*/iu',
            "is_del_obj"=>"/删除/iu"

        ];
        // 检查是否匹配其中一个关键词
        foreach ($keywords as $key => $pattern) {
            if (preg_match($pattern, $message)) {
                return [$key, true];
            }
        }
        return [false, false];
    }

    private function delQueue($adv_id, $obj_id, $queue_record_id, $key)
    {
        $queue = new Queue();
        if ($adv_id && $key == "not_found") {
            return $queue->where(['job_data' => ['like', "%" . $adv_id . "%"], 'status' => 0, 'queue_name' => "autoUpdateObjNameWeb", 'id' => ['neq', $queue_record_id]])->delete();
        }
        if ($obj_id &&  in_array($key, ["not_normal","not_found_obj",'is_del_obj'])) {
            return $queue->where(['job_name' => ['like', "%" . $obj_id . "%"], 'status' => ['in', [0, 2]], 'queue_name' => "autoUpdateObjNameWeb", 'id' => ['neq', $queue_record_id]])->delete();
        }
        return true;
    }

    /**
     * 获取广告主和计划信息
     * @param $adv_id
     * @param $obj_id
     * @return array
     * @throws Exception
     */
    private function getObjAndAdvInfo($adv_id, $obj_id)
    {
        $obj_url = "https://dmc.zebranumber.cn/index.php/api/rpa_up_obj_name/getObjInfo/";
        $adv_url = "https://dmc.zebranumber.cn/index.php/api/rpa_up_obj_name/getAdvInfo/";

        $adv_rep = $this->sendApiRes($adv_url, [$adv_id]);
        if (!$adv_rep['data']) {
            throw new Exception("找不到广告主信息");
        }
        $obj_rep = $this->sendApiRes($obj_url, [$adv_id, $obj_id]);
        if (!$obj_rep['data']) {
            throw new Exception('找不到计划信息');
        }

        return [$adv_rep['data'], $obj_rep['data']];
    }
}