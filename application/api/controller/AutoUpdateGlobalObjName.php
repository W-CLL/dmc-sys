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
            checkQueueExecutionOver('autoUpdateGlobalObjName','chunkAutoGlobalObj');
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

        $list = sendApiRes(API_BASE_URL."/getGlobalOptCountCollectionApi/", [
            'start_time' => $start_time,
            'end_time' => $end_time,
            'adv_list' => $advList
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
            $totalNum = (int)$item['total_num'];
            $companyNum = (int)$item['company_num'];
            $cusNum = $totalNum - $companyNum;

            if ($cusNum <= 0 || ($companyNum > 0 && ($companyNum / $cusNum) * 100 >= ($notWhiteCom[$item['company_name']] * 2))) {
                $needComNum = 50;
                continue;
            } else {
                $actualComNum = $cusNum + ($cusNum * ($notWhiteCom[$item['company_name']] / 100));
                $needComNum = $companyNum > 0 ? $actualComNum - $companyNum : $actualComNum;
                $needComNum = (int)ceil($needComNum);
            }
            $list = sendApiRes(API_BASE_URL."/getGlobalObjListApi/", [
                $item['advertiser_id'], $needComNum
            ])['data'];

            if (!$list) {
                continue;
            }
            if($this->specialAdvObj($item['advertiser_id'],$user_name)){
                $list = $this->specialAdvObj($item['advertiser_id'],$user_name);
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
     * @return array
     */
    public function getAdvList($page, $user_name): array
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
            $ownerCompanyNames = sendApiRes(API_BASE_URL."/ownerCompanyNamesApi/", [
                $charge_name
            ])['data'];
            $name_where['company_name'] = ['in', $ownerCompanyNames];
        }
        $name_where['is_white'] = 0;
        $notWhiteCom = sendApiRes(API_BASE_URL."/notWhiteComApi/", $name_where, 'POST')['data'];
        //提取公司名
        $companyNames = array_keys($notWhiteCom);
        $adv_list = sendApiRes(API_BASE_URL."/getAdvListApi/", [
            "company_name" => $companyNames,
            "page" => $page,
            "charge_name" => $charge_name,
            "limit" => 1000,
            "type" => 2
        ], 'POST')['data'];
        $adv_ids = array_column((array)$adv_list, 'adv_id');
        return [$adv_ids, $notWhiteCom];
    }


    // 某些客户指定了一条计划用于修改
    private function specialAdvObj($adv_id,$user_name){
        $special = [
            'mmc' => [
                '1829163931608537' => ["1832152428880217"] // 比如这个户只允许刷1832152428880217这个计划，就放在数组里，如果涉及两条或者以上，直接在数组上继续加即可，返回去方法内顶替掉$list
            ]
        ];
        if(!isset($special[$user_name])){
            return false;
        }
        if(isset($special[$user_name][$adv_id])){
            return $special[$user_name][$adv_id];
        }
        return false;
    }

}