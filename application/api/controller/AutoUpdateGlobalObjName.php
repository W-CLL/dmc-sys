<?php

namespace app\api\controller;
use app\common\controller\Api;
use app\common\model\Queue;
use GuzzleHttp\Exception\GuzzleException;
use think\Cache;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\DbException;

class AutoUpdateGlobalObjName extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';
    const GLOBAL_CACHE_KEY = 'global_handler_key';

    /**
     * 分割当天全域消耗下的广告计划
     * @param string $user_name
     * @param bool $is_special
     * @return void
     * @throws DataNotFoundException
     * @throws DbException
     * @throws GuzzleException
     * @throws ModelNotFoundException
     */
    public function chunkGlobalComAdv(string $user_name = '', bool $is_special = false)
    {
        $page = Cache::get('chunk_obj_global_page', 1);
        if (!$is_special && $page == 1) {
            checkQueueExecutionOver(self::GLOBAL_CACHE_KEY);
        }
        $redis = Cache::store('redis');
        list($advList, $notWhiteCom) = $this->getAdvList($page, $user_name, $is_special);
        list($start_time, $end_time) = getPersonStartTime($user_name);

        if (empty($advList)) {
            echo "全部处理完了";
            Cache::rm('chunk_obj_global_page');
            $redis->rm(self::GLOBAL_CACHE_KEY . '_over');
            Cache::set(self::GLOBAL_CACHE_KEY, strtotime(date('Y-m-d')));
            die;
        }

        $list = sendApiRes("https://dmc.zebranumber.cn/index.php/api/api/getGlobalOptCountCollectionApi/", [
            'start_time' => $start_time,
            'end_time' => $end_time,
            'advList' => $advList
        ], 'POST')['data'];

        if (empty($list)) {
            echo "全部处理完了";
            Cache::rm('chunk_obj_global_page');
            $redis->rm(self::GLOBAL_CACHE_KEY . '_over');
            Cache::set(self::GLOBAL_CACHE_KEY, strtotime(date('Y-m-d')));
            die;
        }
        $queue = new Queue();
        foreach ($list as $item) {
            if ($item['advertiser_id'] != '1775613163036679') {
                continue;
            }
            $totalNum = (int)$item['total_num'];
            $companyNum = (int)$item['company_num'];
            $cusNum = $totalNum - $companyNum;

            if ($cusNum <= 0 || ($companyNum > 0 && ($companyNum / $cusNum) * 100 >= ($notWhiteCom[$item['company_name']] * 2))) {
                $needComNum = 50;
            } else {
                $actualComNum = $cusNum + ($cusNum * ($notWhiteCom[$item['company_name']] / 100));
                $needComNum = $companyNum > 0 ? $actualComNum - $companyNum : $actualComNum;
                $needComNum = (int)ceil($needComNum);
            }
            $list = sendApiRes("https://dmc.zebranumber.cn/index.php/api/api/getGlobalObjListApi/", [
                $item['advertiser_id'], $needComNum
            ])['data'];

            if (!$list) {
                continue;
            }
            $queueData = [
                'need_opt_num' => $needComNum,
                'adv_id' => $item['advertiser_id'],
                'obj_list' => $list
            ];
            //一个广告主下的托管计划，总的操作次数，写入任务再平分次数到每个计划，进行延时修改
            $queue->addQueue('分块处理自动化【全域】', 'app\job\ChunkAutoGlobalObj', 'chunkAutoGlobalObj', $queueData);
        }
        if ($is_special) {
            echo "全部处理完了";
            die;
        }
        $page++;
        Cache::set('chunk_obj_global_page', $page);
        $this->chunkGlobalComAdv($user_name);
    }

    /**
     * 获取公司设置
     * @param $page
     * @param  $user_name
     * @param $is_special
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function getAdvList($page, $user_name, $is_special): array
    {
        $operator = [
            'zqp' => "张秋萍",
            'mmc' => "莫美春",
            'cxy' => "陈秀玉",
            'tyx' => "罗文静",
            'wyc' => "王倚澄",
        ];
        $charge_name = '';
        if ($user_name) {
            if (!$operator[$user_name]) {
                echo "名字不存在";
                die;
            } else {
                $charge_name = $operator[$user_name];
            }
        }
        //获取非白名单公司
        if ($charge_name) {
            $ownerCompanyNames = sendApiRes("https://dmc.zebranumber.cn/index.php/api/api/ownerCompanyNamesApi/", [
                $charge_name
            ])['data'];
            $name_where['company_name'] = ['in', $ownerCompanyNames];
        }
        $name_where['is_white'] = 0;
        $notWhiteCom = sendApiRes("https://dmc.zebranumber.cn/index.php/api/api/notWhiteComApi/", $name_where, 'POST')['data'];
        //提取公司名
        $companyNames = array_keys($notWhiteCom);
        $adv_list = sendApiRes("https://dmc.zebranumber.cn/index.php/api/api/getAdvListApi/", [
            "company_name" => $companyNames,
            "page" => $page,
            "charge_name" => $charge_name,
            "limit" => 1000
        ], 'POST')['data'];
        $adv_ids = array_column((array)$adv_list, 'adv_id');
        return [$adv_ids, $notWhiteCom];
    }

}