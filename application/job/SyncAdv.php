<?php


namespace app\job;

use app\admin\model\Company;
use app\common\library\Auth;
use app\common\model\Queue;
use app\store\model\SyncChargeRecord;
use fast\Random;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use jlqc\AccountRelationship;
use jlqc\UserInfo;
use think\Cache;
use think\Db;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\Env;
use think\Exception;
use think\exception\DbException;
use think\exception\PDOException;
use think\Log;
use think\queue\Job;

/**
 * 同步广告账户到数据库
 */
class SyncAdv
{

    /**
     * fire方法是消息队列默认调用的方法
     * @param Job $job 当前的任务对象
     * @param array|mixed $data 发布任务时自定义的数据
     */
    public function fire(Job $job, $data)
    {
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueModel = new \app\common\model\Queue();
        $queueData = $queueModel->where('job_id', $jobId)->find();
        if (!$queueData) {
            $job->delete();
            return '';
        }
        try {
            $isJobDone = $this->doJob($data, $queueData);
            if ($isJobDone) {
                $queueData->save(['id' => $queueData['id'], 'status' => 1, 'msg' => "处理完成"]);
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
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws DataNotFoundException
     * @throws Exception
     */
    protected function doJob($data, $queueData)
    {
        $access_token = Cache::get("qc_access_token");
        $advertiser_data = AccountRelationship::advertiser_select($access_token, $data['advertiser_id'], $data['cursor'], $data['count']);
        $public_info_data = UserInfo::public_info($access_token, json_encode($advertiser_data['data']['advertiser_ids']));
        if($public_info_data['code'] != 0){
            throw new Exception($public_info_data['message']);
        }
        $company_add_data = [];
        $companyModel = new Company();
        $auth = new Auth();
        foreach ($public_info_data['data'] as $item) {
            $info = $companyModel->where('advertiser_id', $item['id'])->find();
            if ($info) {
                if ($item['name'] != $info['name'] || $item['company'] != $info['company_name']) {
                    $companyModel->where(["advertiser_id" => $item["id"]])->update([
                        "name" => $item["name"],
                        "company_name" => $item["company"],
                        "update_time" => time()
                    ]);
                }
            } else {
                $salt = Random::alnum();
                $company_add_data[] = [
                    "advertiser_id" => $item["id"],
                    "company_name" => $item["company"],
                    "name" => $item["name"],
                    "first_industry_name" => $item["first_industry_name"],
                    "second_industry_name" => $item["second_industry_name"],
                    "salt" => $salt,
                    "password" => $auth->getEncryptPassword("123456", $salt),
                    "create_time" => time(),
                    "update_time" => time(),
                ];
            }
        }
        if($advertiser_data['data']['cursor_page_info']['has_more']){
            $queue = new Queue();
            $queue_data = [
                'advertiser_id' => $data['advertiser_id'],
                'count' => $data['count'],
                'cursor' => $advertiser_data['data']['cursor_page_info']['cursor'],
            ];
            $queue->addQueue('检查更新广告账户', 'app\job\SyncAdv', 'syncAdv', $queue_data);
        }
        if (!empty($company_add_data)) {
          $res =   $companyModel->saveAll($company_add_data);
          if(!$res){
              return false;
          }
        }
        return true;


    }

}