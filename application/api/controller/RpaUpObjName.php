<?php

namespace app\api\controller;


use app\common\controller\Api;
use app\common\model\Queue;
use think\Cache;
use think\Db;


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

    private function processObjList($user_name, $cacheKey, $apiUrl, $listApiUrl, $type,$modelName,$main_queue)
    {
        $page = Cache::get($cacheKey . '_page', 1);
        if ($page == 1) {
            checkQueueExecutionOver($main_queue,"chunkAutoObjWeb");
        }

        $autoClass = new $modelName();
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
            'stand',
            "app\api\controller\AutoUpdateObjName",
            "autoUpdateObjNameWeb"
        );
    }

    public function getGlobalObjList($user_name)
    {
        $this->processObjList(
            $user_name,
            self::WEB_GLOBAL_KEY,
            API_BASE_URL."/getGlobalOptCountCollectionApi/",
            API_BASE_URL."/getGlobalObjListApi/",
            'global',
            "app\api\controller\AutoUpdateGlobalObjName",
            "autoUpdateObjNameGlobalWeb"
        );
    }



}