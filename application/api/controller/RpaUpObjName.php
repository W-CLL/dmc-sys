<?php

namespace app\api\controller;

use app\admin\model\Company;
use app\admin\model\QcObj as ObjModel;
use app\common\controller\Api;
use app\common\model\Queue;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use think\Cache;
use think\Db;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\Exception;
use think\exception\DbException;
use think\response\Json;


/**
 * 根据元素操作计划
 */
class RpaUpObjName extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';

    const CACHE_TYPE_NOT_LAB = "not_lab";
    const CACHE_TYPE_LAB = "lab";

    const WEB_GLOBAL_KEY = 'web_global_key';

    const WEB_STRAND_KEY = 'web_strand_key';

    /**
     * 获取有操作日志且全都是非托管计划(自定义)的
     * @param $user_name
     * @return void
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function getNotLabObjList($user_name)
    {
        $page = Cache::get(self::CACHE_TYPE_NOT_LAB . '_page', 1);
        $autoClass = new AutoUpdateObjName();
        //分页获取负责人下的广告主账户列表
        list($advList, $notWhiteCom) = $autoClass->getAdvList($page, $user_name, false);
        //获取消耗范围（一般为当月1号到当天）
        list($start_time, $end_time) = getPersonStartTime($user_name);

        if (empty($advList)) {
            Cache::rm(self::CACHE_TYPE_NOT_LAB . '_page');
            echo "全部处理完了";
            die;
        }
        $url = "https://dmc.zebranumber.cn/index.php/api/api/getRpaOptCountCollectionApi/";
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
        $list = $rep['data'];
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

            $url = "https://dmc.zebranumber.cn/index.php/api/api/getRpaObjListApi/";
            $params = [$item['advertiser_id'], $needComNum];
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
                'obj_list' => $rep['data'],
            ];

            //一个广告主下的托管计划，总的操作次数，写入任务再平分次数到每个计划，进行延时修改
            $queue->addQueue('web计划分块处理', 'app\job\ChunkAutoObjWeb', 'chunkAutoObjWeb', $queueData);
        }

        $page++;
        Cache::set(self::CACHE_TYPE_NOT_LAB . '_page', $page);
        $this->getNotLabObjList($user_name);

    }

    /**
     * 移除登录缓存
     * @return void
     */
    public function rmWebCache()
    {
        dump(Cache::rm('need_login'));
        dump(Cache::rm('web_last_adv_id'));
        die;
    }

    public function checkQueueExecutionOver($fun_name)
    {
//         生成时间参数
//        $todayStart = strtotime('today');
        $todayStart = strtotime(date('Y-m-01'));
        $todayEnd = strtotime('tomorrow') - 1;

        // 构造原生SQL（使用命名占位符）
        $sql = "SELECT COUNT(*) AS count 
            FROM fa_queue_record 
            WHERE (
                (queue_name = :queue1 
                AND status = 0 
                AND create_time BETWEEN :start1 AND :end1)
                OR 
                (queue_name = :queue2 
                AND status = 0 
                AND create_time BETWEEN :start2 AND :end2)
            )
            AND TIME(FROM_UNIXTIME(create_time)) NOT BETWEEN '09:00:00' AND '09:02:00'";

        // 执行查询（使用ThinkPHP的数据库组件）
        $count = Db::query($sql, [
            'queue1' => 'autoUpdateObjNameWeb',
            'queue2' => 'chunkAutoObjWeb',
            'start1' => $todayStart,
            'end1' => $todayEnd,
            'start2' => $todayStart,
            'end2' => $todayEnd
        ])[0]['count'];

        if ($count <= 50) {
            Cache::store('redis')->set(self::WEB_STRAND_KEY . '_over', 1);
            Cache::store('redis')->set(self::WEB_GLOBAL_KEY . '_over', 1);
        }
        $canRun = Cache::store('redis')->get($fun_name . '_over');
        if ($canRun != 1) {
            echo "时辰未到";
            die;
        }
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
            return ['data' => json_decode($contents, true), 'status' => 0];
        } catch (Exception|GuzzleException $e) {
            return ['data' => [], 'status' => -1, 'msg' => $e->getMessage()];
        }
    }

}