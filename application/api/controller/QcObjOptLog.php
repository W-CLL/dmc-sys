<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\model\Queue;

/**
 * 每天12：00：00，晚上23：59：50 获取一下当天区间操作日志
 */
class QcObjOptLog extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';

    /**
     * @param $date
     * 格式2025-01-15,处理特殊情况，一般不传
     * @return void
     */
    public function index($date='')
    {
        $objModel = new \app\admin\model\QcObj();
        $queue = new Queue();
        $advIds = $objModel->group('adv_id')->column('adv_id');
        $where['obj_status'] = ['not in', ["DELETE", "TIME_DONE","FROZEN"]];

        if($date){
            $startTime = $date.' 00:00:00';
            $endTime = $date.' 23:59:59';
            $where['obj_create_time'] = ['between',[strtotime($startTime),strtotime($endTime)]];
        }
        foreach ($advIds as $id) {
            $where['adv_id'] = $id;
            $objIds = $objModel->where($where)->column('obj_id');
            $count = count($objIds);
            if ($count == 0) {
                continue;
            }
            $count = ceil($count / 20); // 计算分页数
            // 分页处理
            $currDay = date('Y-m-d',time()) ;
            if($date){
                $currDay = $date;
            }
            $currTime = time();
            $dayHalfTime = strtotime($currDay.' 11:59:00');
            $dayEndTime = strtotime($currDay.' 23:49:00');
            if($currTime > $dayHalfTime && $currTime<$dayEndTime){
                $startTime = $currDay . ' 00:00:00';
                $endTime = $currDay.' 11:59:59';
            }else if($currTime > $dayEndTime){
                $startTime = $currDay . ' 12:00:00';
                $endTime = $currDay.' 23:59:59';
            }else{
                $startTime = $currDay.' 00:00:00';
                $endTime = $currDay.' 23:59:59';
            }

            if($date){
                $startTime = $currDay.' 00:00:00';
                $endTime = $currDay.' 23:59:59';
            }
            for ($i = 0; $i < $count; $i++) {
                $start = $i * 20;
                $object_ids = array_slice($objIds, $start, 20);
                $params = [
                    "advertiser_id" => (int)$id,
                    'object_id' => $object_ids,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    "page" => 1,
                    "page_size" => 20,
                ];
                $queue->addQueue('插入当天新增日志', 'app\job\InsertDayOptLog', 'insertDayOptLog', $params);
            }
        }
        echo "已经全部处理完了";
    }


}