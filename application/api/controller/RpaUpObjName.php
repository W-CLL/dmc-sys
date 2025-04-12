<?php

namespace app\api\controller;

use app\admin\model\Company;
use app\admin\model\QcObj as ObjModel;
use app\common\controller\Api;
use app\common\model\Queue;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use think\Cache;
use think\Exception;


/**
 * 根据元素操作计划
 */
class RpaUpObjName extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';

    const CACHE_TYPE_NOT_LAB = "not_lab";
    const CACHE_TYPE_LAB = "lab";

    public function index()
    {
        $company = new Company();
        $obj = new ObjModel();
        $com_info = $company->where(['advertiser_id' => 1818196468347980])->find()->getData();
        $obj_info = $obj->where(['adv_id' => 1818196468347980, 'obj_status' => ['not in', ['DELETE']], 'obj_id' => 1828951472728074])->find()->getData();
        $base_edit_url = "https://qianchuan.jinritemai.com/creation/";
        $marketing_scene = strtolower($obj_info['marketing_scene']);
        if ($obj_info['marketing_goal'] == "LIVE_PROM_GOODS") {
            $type = "live";
        } else {
            $type = 'video';
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
            'need_login' => false,
            'need_change' => false,
        ];
        $res = $this->sendApiRes($url, $params, "POST");
        dump($res);
        die;
    }

    /**
     * 获取有操作日志且全都是非托管计划(自定义)的
     * @return void
     */
    public function getNotLabObjList($user_name)
    {
        $page = Cache::get(self::CACHE_TYPE_NOT_LAB . '_page', 1);
        $autoClass = new AutoUpdateObjName();
        //分页获取负责人下的广告主账户列表
        list($advList, $notWhiteCom) = $autoClass->getAdvList($page, $user_name, false);
        //获取消耗范围（一般为当月1号到当天）
        list($start_time, $end_time) = $autoClass->getPersonStartTime($user_name);

        if (empty($advList)) {
            Cache::rm(self::CACHE_TYPE_NOT_LAB . '_page');
            echo "全部处理完了";
            die;
        }

//        $url = "http://dmc-new.com.cn:8084/index.php/api/rpa_up_obj_name/getOptCountCollectionApi/";
        $url = "https://dmc.zebranumber.cn/index.php/api/rpa_up_obj_name/getOptCountCollectionApi/";
        $params = [
            'start_time' => $start_time,
            'end_time' => $end_time,
            'adv_list' => $advList
        ];
        $rep = $this->sendApiRes($url, $params, "POST");
        if (isset($rep['msg'])) {
            echo $rep['msg'];
            die;
        }

        if (empty($list)) {
            echo "全部处理完了";
            Cache::rm(self::CACHE_TYPE_NOT_LAB . '_page');
            die;
        }
        $queue = new Queue();
        foreach ($list as $item) {
            $totalNum = (int)$item['total_num'];
            $companyNum = (int)$item['company_num'];
            $cusNum = $totalNum - $companyNum;

            if ($cusNum <= 0 || ($companyNum > 0 && ($companyNum / $cusNum) * 100 >= ($notWhiteCom[$item['company_name']] * 2))) {
                continue;
            }

            $actualComNum = $cusNum + ($cusNum * ($notWhiteCom[$item['company_name']] / 100));
            $needComNum = $companyNum > 0 ? $actualComNum - $companyNum : $actualComNum;
            $needComNum = (int)ceil($needComNum);

//            $url = "http://dmc-new.com.cn:8084/index.php/api/rpa_up_obj_name/getObjListApi/";
            $url = "https://dmc.zebranumber.cn/index.php/api/rpa_up_obj_name/getObjListApi/";
            $params = [$item['advertiser_id'], $needComNum,'NOT_LAB_AD'];
            $rep = $this->sendApiRes($url, $params);
            if (isset($rep['msg'])) {
                echo $rep['msg'];
                die;
            }
            if (!$rep['data']) {
                continue;
            }
            $queueData = [
                'need_opt_num' => $needComNum,
                'adv_id' => $item['advertiser_id'],
                'obj_list' => $list
            ];
            //一个广告主下的托管计划，总的操作次数，写入任务再平分次数到每个计划，进行延时修改
            $queue->addQueue('web计划分块处理', 'app\job\ChunkAutoObjWeb', 'chunkAutoObjWeb', $queueData);
        }

        $page++;
        Cache::set(self::CACHE_TYPE_NOT_LAB . '_page', $page);
        $this->index($user_name);

    }

    public function getOptCountCollectionApi()
    {
        $params = input();
        $start_time = $params['start_time'];
        $end_time = $params['end_time'];
        $adv_list = $params['adv_list'];
        if (!is_array($adv_list)) {
            // 处理解码失败的情况（如返回错误信息）
            return json(['status' => -1, 'msg' => '参数格式错误']);
        }
        $comModel = new Company();
        $list = $comModel
            ->alias('adv_c')
            ->join(
                "(SELECT adv_id, COUNT(*) AS total_num FROM fa_qc_obj_opt_log WHERE opt_time BETWEEN " . $start_time . " AND " . $end_time . " GROUP BY adv_id) AS total_stats",
                'adv_c.advertiser_id = total_stats.adv_id',
                'left'
            )
            ->join(
                "(SELECT adv_id, COUNT(*) AS company_num FROM fa_qc_obj_opt_log WHERE opt_time BETWEEN " . $start_time . " AND " . $end_time . " AND operator IN (SELECT name FROM fa_ad_operator WHERE status = 1) GROUP BY adv_id) AS company_stats",
                'adv_c.advertiser_id = company_stats.adv_id',
                'left'
            )
            ->where(['adv_c.advertiser_id' => ['in', $adv_list], 'total_stats.total_num' => ['>', 0]])
            ->field("adv_c.*, total_stats.total_num, company_stats.company_num")
            ->order('total_stats.total_num desc')
            ->select();

        return json($list);
    }

    public function getObjListApi($adv_id, $needComNum, $lab_type = 'NOT_LAB_AD')
    {

        $objModel = new ObjModel();
        $count = $objModel->where([
                'obj_status' => ['not in', ['DELETE', "TIME_DONE", 'FROZEN']],
                'lab_ad_type' => $lab_type,
                'opt_status' => ['not in', ['DELETE', "TIME_DONE", 'FROZEN']],
                'adv_id' => $adv_id]
        )->count('id');
        //托管计划少于4个才去执行
        $list = [];
        if ($count < 4) {
            $list = $objModel->where([
                'obj_status' => ['not in', ['DELETE', "TIME_DONE", 'FROZEN']],
                'lab_ad_type' => $lab_type,
                'opt_status' => ['not in', ['DELETE', "TIME_DONE", 'FROZEN']],
                'adv_id' => $adv_id
            ])
                ->field('obj_id,adv_id')
                ->limit($needComNum)
                ->column('obj_id');
        }


        return json($list);
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
            return ['data' => $contents, 'status' => 0];
        } catch (Exception|GuzzleException $e) {
            return ['data' => [], 'status' => -1, 'msg' => $e->getMessage()];
        }
    }

}