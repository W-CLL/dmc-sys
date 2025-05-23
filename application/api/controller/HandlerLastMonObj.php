<?php

namespace app\api\controller;

use app\admin\model\Company;
use app\admin\model\QcObj as ObjModel;
use app\common\controller\Api;
use app\common\model\QueueAvg;
use GuzzleHttp\Exception\GuzzleException;
use think\Cache;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\DbException;
use app\common\model\QcAdvDayCost;


/**
 * 根据上个月的次数，平均分到每个人，每个户，平均一天要做改多少
 */
class HandlerLastMonObj extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';
    const GLOBAL_CACHE_KEY = 'last_mon_global_handler_key';//全域
    const NORMAL_CACHE_KEY = 'last_mon_normal_handler_key';//标准

    /**
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws GuzzleException
     */
    public function index($user_name)
    {
        //检查当天是不是已经执行了
        $page = Cache::get(self::NORMAL_CACHE_KEY.'_page', 1);
        if($page == 1){
            $this->checkDayIsHandler(self::NORMAL_CACHE_KEY);
        }
        //分页获取负责人的广告账号
        if(Cache::has('last_mon_adv_list')){
            $advList = unserialize(Cache::get('last_mon_adv_list'));
        }else{
            $advList= $this->getOwnerAdvList($page, $user_name);
            Cache::set('last_mon_adv_list',serialize($advList));
        }
        //获取上个月整月的时间范围，如当月是4月，返回则是3.1-3.31的时间戳
        list($start_time, $end_time) = $this->getTimeRange();
        $url = API_BASE_URL."/getAvOptCountCollectionApi/";
        $params = [
            'table' => 'fa_qc_obj_opt_log',
            'start_time' => $start_time,
            'end_time' => $end_time,
            'adv_list' =>$advList,
            'page' =>$page,
        ];
       $rep =  sendApiRes($url,$params,"POST");
       if(isset($rep['msg'])){
           echo $rep['msg'];
           die;
       }
       $list = $rep['data'];
        if (empty($list)) {
            echo "全部处理完了";
            Cache::rm(self::NORMAL_CACHE_KEY.'_page');
            Cache::rm("last_mon_adv_list");
            Cache::set(self::NORMAL_CACHE_KEY, strtotime(date('Y-m-d')));
            die;
        }
        $queue = new QueueAvg();
        $cost_model = new QcAdvDayCost();
        foreach ($list as $item) {
            //本月没有标准消耗就跳过
            $has_cost = $cost_model->where([
                'adv_id' => $item['advertiser_id'],
                'cost_date' => ['between', [strtotime(date('Y-m-01')), time()]],
                'type' => 1,
            ])
                ->field('sum(cost) as total_cost')
                ->group('adv_id')
                ->find();
            if ($has_cost && $has_cost['total_cost'] < 100) {
                continue;
            }
            $cusNum = (int)$item['cus_num'];
            $needComNum = $cusNum / 27;
            if($needComNum < 50){
                $needComNum = $needComNum + 20;
            }elseif($needComNum<200 && $needComNum>50){
                $needComNum = ($needComNum * 0.3) + $needComNum;
            }
            $needComNum = (int)ceil($needComNum);

            $url = API_BASE_URL."/getObjListApi/";
            $params = [
                $item['advertiser_id'], $needComNum
            ];
            $rep =  sendApiRes($url,$params);
            if(isset($rep['msg'])){
                echo $rep['msg'];
                die;
            }

            if (!$rep['data']) {
                continue;
            }
            $queueData = [
                'need_opt_num' => $needComNum,
                'adv_id' => $item['advertiser_id'],
                'obj_list' => $rep['data']
            ];
            //一个广告主下的托管计划，总的操作次数，写入任务再平分次数到每个计划，进行延时修改
            $queue->addQueue('平均分块处理自动化', 'app\job\ChunkAutoObjAvg', 'chunkAutoObjAvg', $queueData);
        }
        $page++;
        Cache::set(self::NORMAL_CACHE_KEY.'_page', $page);
        $this->index($user_name);
    }


    public function globalHandler($user_name)
    {
        //检查当天是不是已经执行了
        $page = Cache::get(self::GLOBAL_CACHE_KEY.'_page', 1);
        if($page == 1){
            $this->checkDayIsHandler(self::GLOBAL_CACHE_KEY);
        }
        //分页获取负责人的广告账号
        if(Cache::has('last_mon_global_adv_list')){
            $advList = unserialize(Cache::get('last_mon_global_adv_list'));
        }else{
            $advList= $this->getOwnerAdvList($page, $user_name);
            Cache::set('last_mon_global_adv_list',serialize($advList));
        }
        //获取上个月整月的时间范围，如当月是4月，返回则是3.1-3.31的时间戳
        list($start_time, $end_time) = $this->getTimeRange();
        $url = API_BASE_URL."/getAvOptCountCollectionApi/";
        $params = [
            'table' => 'fa_qc_global_obj_opt_log',
            'start_time' => $start_time,
            'end_time' => $end_time,
            'adv_list' =>$advList,
            'page' =>$page,
        ];
        $rep =  sendApiRes($url,$params,"POST");
        if(isset($rep['msg'])){
            echo $rep['msg'];
            die;
        }
        $list = $rep['data'];
        if (empty($list)) {
            echo "全部处理完了";
            Cache::rm(self::GLOBAL_CACHE_KEY.'_page');
            Cache::rm("last_mon_global_adv_list");
            Cache::set(self::GLOBAL_CACHE_KEY, strtotime(date('Y-m-d')));
            die;
        }
        $queue = new QueueAvg();
        $cost_model = new QcAdvDayCost();
        foreach ($list as $item) {
            //本月没有标准消耗就跳过
            $has_cost = $cost_model->where([
                'adv_id' => $item['advertiser_id'],
                'cost_date' => ['between', [strtotime(date('Y-m-01')), time()]],
                'type' => 2,
            ])
                ->field('sum(cost) as total_cost')
                ->group('adv_id')
                ->find();
            if ($has_cost && $has_cost['total_cost'] < 100) {
                continue;
            }
            $cusNum = (int)$item['cus_num'];
            $needComNum = $cusNum / 27;
            if($needComNum < 50){
                $needComNum = $needComNum + 20;
            }elseif($needComNum<200 && $needComNum>50){
                $needComNum = ($needComNum * 0.3) + $needComNum;
            }
            $needComNum = (int)ceil($needComNum);

            $url = API_BASE_URL."/getGlobalObjListApi/";
            $params = [
                $item['advertiser_id'], $needComNum
            ];
            $rep =  sendApiRes($url,$params);
            if(isset($rep['msg'])){
                echo $rep['msg'];
                die;
            }

            if (!$rep['data']) {
                continue;
            }
            $queueData = [
                'need_opt_num' => $needComNum,
                'adv_id' => $item['advertiser_id'],
                'obj_list' => $rep['data']
            ];
            //一个广告主下的托管计划，总的操作次数，写入任务再平分次数到每个计划，进行延时修改
            $queue->addQueue('平均分块处理自动化【全域】', 'app\job\ChunkAutoGlobalObjAvg', 'chunkAutoGlobalObjAvg', $queueData);
        }
        $page++;
        Cache::set(self::GLOBAL_CACHE_KEY.'_page', $page);
        $this->globalHandler($user_name);
    }






    /**
     * 获取公司设置
     * @param $page
     * @param  $user_name
     * @return array
     */
    protected function getOwnerAdvList($page, $user_name): array
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

        //获取公司下的广告主账户，每页1000条
        return sendApiRes(API_BASE_URL."/getOwnerAdvListApi/",[$page,$charge_name])['data'];
    }

    public function checkDayIsHandler($key)
    {
        // 获取当前日期的时间戳（只保留日期部分）
        $currentDateTimestamp = strtotime(date('Y-m-d'));
        // 从缓存中获取上次记录的时间戳
        $lastTimestamp = Cache::get($key);
        if ($lastTimestamp && $lastTimestamp == $currentDateTimestamp) {
            // 判断是否是同一天
            echo "今天已经处理了";
            die;
        }
    }

    /**
     * 获取上一个月的时间戳范围如:2-1 —— 3-1的时间戳
     * @return array
     */
    protected function getTimeRange(): array
    {
        // 获取上个月1号凌晨时间戳
        $startDate = new \DateTime('first day of last month');
        $startDate->setTime(0, 0, 0);
        $start_time = $startDate->getTimestamp();

        // 获取本月1号凌晨时间戳
        $endDate = new \DateTime('first day of this month');
        $endDate->setTime(0, 0, 0);
        $end_time = $endDate->getTimestamp();
        return [$start_time, $end_time];
    }


}