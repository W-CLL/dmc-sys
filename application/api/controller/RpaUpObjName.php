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

    private function processObjList($user_name, $cacheKey, $apiUrl, $listApiUrl, $type)
    {
        $page = Cache::get($cacheKey . '_page', 1);
        $autoClass = new AutoUpdateObjName();
        list($advList, $notWhiteCom) = $autoClass->getAdvList($page, $user_name, false);
        list($start_time, $end_time) = getPersonStartTime($user_name);
        if (empty($advList)) {
            Cache::rm($cacheKey . '_page');
            echo "全部处理完了";
            die;
        }
        // 请求统计 API
        $rep = sendApiRes($apiUrl, [
            'start_time' => $start_time,
            'end_time' => $end_time,
            'adv_list' => $advList
        ], "POST");

        if (isset($rep['msg'])) {
            echo $rep['msg'];
            die;
        }

        $list = $rep['data'];
        if (empty($list)) {
            echo "全部处理完了";
            Cache::rm($cacheKey . '_page');
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
            // 获取对象列表
            $rep = sendApiRes($listApiUrl, [$item['advertiser_id'], $needComNum]);
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
                'type' => $type
            ];

            $queue->addQueue('web计划分块处理', 'app\job\ChunkAutoObjWeb', 'chunkAutoObjWeb', $queueData);
        }

        $page++;
        Cache::set($cacheKey . '_page', $page);
        $this->{$this->getCallingFunctionName($cacheKey)}($user_name);
    }

    private function getCallingFunctionName($cacheKey): string
    {
        return $cacheKey == self::CACHE_TYPE_NOT_LAB ? 'getNotLabObjList' : 'getGlobalObjList';
    }

    public function getNotLabObjList($user_name)
    {
        $this->processObjList(
            $user_name,
            self::CACHE_TYPE_NOT_LAB,
            API_BASE_URL."/getOptCountCollectionApi/",
            API_BASE_URL."/getRpaObjListApi/",
            'stand'
        );
    }

    public function getGlobalObjList($user_name)
    {
        $this->processObjList(
            $user_name,
            self::WEB_GLOBAL_KEY,
            API_BASE_URL."/getGlobalOptCountCollectionApi/",
            API_BASE_URL."/getGlobalObjListApi/",
            'global'
        );
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

}