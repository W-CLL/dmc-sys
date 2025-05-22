<?php

namespace app\api\controller;

use app\admin\model\QcGlobalObj as ObjModel;
use app\common\controller\Api;
use app\common\model\Queue;
use GuzzleHttp\Psr7\Request;
use think\Cache;
use think\Db;
use think\Exception;


/**
 * 全域广告投放数据相关定时任务类
 */
class QcGlobalObj extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';

    public function getAllAdvIds()
    {
        $list = Cache::get('company_list_global_obj');
        if (!$list) {
            $list = Db::name('company')
//                ->where('adv_status', 1)
                ->order('advertiser_id', 'desc')
                ->column('advertiser_id');
            Cache::set('company_list_global_obj', $list, 21600);
        }
        return $list;
    }

    /**
     * 检测时间范围
     * @param $startDateStr
     * @param $endDateStr
     * @param string $dayNum
     * @return true|void
     * @throws \Exception
     */
  protected  function validateDateRange($startDateStr, $endDateStr,$dayNum='181') {
    // 创建日期对象
    $startDate = new \DateTime($startDateStr);
    $endDate = new \DateTime($endDateStr);

    // 确保起始日期在结束日期之前
    if ($startDate > $endDate) {
       $this->error("起始日期不能晚于结束日期");
    }
    // 计算日期间隔
    $interval = $startDate->diff($endDate);
    $days = $interval->days;

    // 判断是否超过181天
    if ($days > $dayNum) {
        $this->error("查询日期不能超过181天");
    } else {
        return true;
    }
}

// 示例用法


    /**
     * @param int $pageSize
     * @param string $type
     * @param string $otherKey
     * @param string $startTime
     * 不填，默认是2024-03-01
     * @param string $endTime
     * @return void
     * @throws \Exception
     */
    public function getNewObjRecursive($pageSize = 200, $type = "VIDEO_PROM_GOODS", $otherKey='SMART_BID_CUSTOM', $startTime='', $endTime='')
    {
        $this->validateDateRange($startTime,$endTime);
        $allAdvIds = $this->getAllAdvIds();
        $chunks = array_chunk($allAdvIds, $pageSize);
        $end = new \DateTime();
        $start = clone $end;
        $start->modify('-181 days');
        $start_time = $startTime?:$start->format('Y-m-d');
        $end_time = $endTime?:date('Y-m-d');
        foreach ($chunks as $chunk) {
            $job_data = [
                'params' => [
                    "start_time" => $start_time . ' 00:00:00',
                    "end_time" => $end_time." 23:59:59",
                    "marketing_goal" => $type,//LIVE_PROM_GOODS,VIDEO_PROM_GOODS
                    "order_type" => "DESC",
                    "page" => 1,
                    "fields" => ['stat_cost'],
                    "page_size" => 50,
                    "filtering" => [
                        "smart_bid_type" => $otherKey,//SMART_BID_CUSTOM(默认),SMART_BID_CONSERVATIVE
                    ]
                ],
                'adv_list' => $chunk
            ];
            if($type=='VIDEO_PROM_GOODS'){//当marketing_goal==VIDEO_PROM_GOODS 才生效
                $job_data['params']['filtering']['having_cost'] = "ALL";
                $job_data['params']['filtering']['status'] = "ALL_INCLUDE_DELETED";
                $job_data['params']['filtering']['create_start_date'] = $start_time;
                $job_data['params']['filtering']['create_end_date'] = $end_time;
            }

            \think\Queue::push('app\job\InsertDayGlobalObj', $job_data, "insertDayGlobalObj");
        }
        echo "全部处理完了";
    }

    /**
     * @throws \Exception
     */
    public function handlerVideo($start, $end)
    {
        $this->getNewObjRecursive(50, "VIDEO_PROM_GOODS",'SMART_BID_CUSTOM',$start,$end);
        $this->getNewObjRecursive(50, "VIDEO_PROM_GOODS",'SMART_BID_CONSERVATIVE',$start,$end);
    }

    /**
     * @throws \Exception
     */
    public function handlerLive($start, $end)
    {
        $this->getNewObjRecursive(50, "LIVE_PROM_GOODS",'SMART_BID_CUSTOM',$start,$end);
        $this->getNewObjRecursive(50, "LIVE_PROM_GOODS",'SMART_BID_CONSERVATIVE',$start,$end);
    }


    public function updateObjStatus()
    {

        $obj_model = new ObjModel();
        $adv_list = $obj_model
            ->alias('obj')
            ->join('company com', 'com.advertiser_id=obj.adv_id', 'left')
            ->where(['com.adv_status' => 1])
            ->group('adv_id')->column('adv_id');
        foreach ($adv_list as $item) {
            $list = $obj_model
                ->where(['opt_status' => ['NOT IN', ['DELETE']]])
                ->where(['adv_id' => $item])
                ->column('obj_id');
            if ($list) {
                $chunks = array_chunk($list, 300);
                foreach ($chunks as $chunk) {
                    $job_data = [
                        'adv_id' => $item,
                        'obj_list' => $chunk
                    ];
                    \think\Queue::push('app\job\UpdateGlobalObjStatus', $job_data, "updateGlobalObjStatus");
                }
            }

        }
        echo "全部处理完了";

    }


    /**
     * @throws \Exception
     */
    public function getGlobalObjEveryDay()
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $today = date('Y-m-d');
        $this->handlerVideo($yesterday,$today);
        $this->handlerLive($yesterday,$today);
    }


}