<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\model\Queue;
use DateTime;
use fast\Date;
use think\Cache;

/**
 * 每天12：00：00，晚上23：59：50 获取一下当天区间操作日志
 */
class QcObjOptLog extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';


    /**
     * 获取当天操作日志，定时任务每小时的第二分钟执行一次
     * 但有判断隔四个小时才会执行一次，从凌晨开始算，00：02、04：02、08：02。。。。
     * @param string $start_date 时间戳
     * @param string $end_date 时间戳
     * @return void
     */
    public function index($start_date ='',$end_date='')
    {
//        Cache::rm('first_execution_done');
        list($e,$s)=$this->checkAndGetExecutionTime();
        if(!$s&&!$e && !$start_date){
            echo "时间不对不执行".date('Y-m-d H:i:s');
            die;
        }
        $objModel = new \app\admin\model\QcObj();
        $queue = new Queue();
        $advIds = $objModel->group('adv_id')->order('adv_id')->column('adv_id');
        if ($start_date&&$end_date) {
            $s = date("Y-m-d H:i:s",$start_date);
            $e = date("Y-m-d H:i:s",$end_date);
        }
        foreach ($advIds as $id) {
            $where['adv_id'] = $id;
            $objIds = $objModel->where($where)->order('obj_id')->column('obj_id');
            $count = count($objIds);
            if ($count == 0) {
                continue;
            }
            $count = ceil($count / 20); // 计算分页数
            $startTime = $s;
            $endTime = $e;
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

    /**
     * 检查定时任务时间是否匹配
     * 每四个小时执行一次,不符合则返回null
     * @return array|null[]
     */
    public function checkAndGetExecutionTime(): array
    {
        $now = new \DateTime(); // 获取当前时间
        $currentHour = (int)$now->format('H'); // 当前小时
        $currentMinute = (int)$now->format('i'); // 当前分钟

        // 检查是否在每小时的第 2 分钟
        if ($currentMinute != 2) {
            return [null, null]; // 不满足条件，返回空
        }
        // 获取今天的凌晨时间（00:00:00）
        $midnight = new \DateTime('today');

        // 读取缓存，判断是否已经执行过第一次
        $isFirstExecution = true; // 默认是第一次执行
        if (Cache::has('first_execution_done')) {
            $isFirstExecution = false; // 如果缓存标记为已执行，则不是第一次执行
        }

        // 如果是第一次执行（即当前时间不是 4 小时的倍数，且没有执行过）
        if ($isFirstExecution && $currentHour % 4 != 0) {
            // 标记为已执行
            Cache::set('first_execution_done', true);
            // 返回从凌晨到当前时间的时间范围
            return [
                $now->format('Y-m-d H:i:s'),      // 当前时间
                $midnight->format('Y-m-d H:i:s') // 凌晨时间
            ];
        }

        // 检查是否满足从零点开始每隔 4 小时的条件
        if ($currentHour % 4 == 0) {
            // 计算上一次执行时间（当前时间减去 4 小时）
            $lastExecutionTime = clone $now;
            $lastExecutionTime->modify('-4 hours');
            // 每4点拉一次前半天的数据
            if ($currentHour == 4 || $currentHour == 16){
                $lastExecutionTime->modify('-13 hours'); // 此处包含上面-的4小时，故需要再-13小时
            }
            // 返回当前时间和上一次执行时间
            return [
                $now->format('Y-m-d H:i:s'),              // 当前时间
                $lastExecutionTime->format('Y-m-d H:i:s') // 上一次执行时间
            ];
        }

        // 如果当前时间不是 4 小时的倍数，且不是第一次执行，则不执行逻辑
        return [null, null];
    }
}