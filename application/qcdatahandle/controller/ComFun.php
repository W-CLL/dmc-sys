<?php


namespace app\qcdatahandle\controller;

use think\Controller;

class ComFun extends Controller
{
    /**
     * 返回日期
     * @param $month
     * 1表示返回最近30，2表示返回最近30天的前30天
     * @return array
     */
    public function getSearchDate($month = 2)
    {
        $currentDate = new \DateTime();
        // 设置时间为当天的最后时间（23:59:59）
        $currentDate->setTime(23, 59, 59);
        // 计算前30天的时间
        $thirtyDaysAgo = clone $currentDate;
        $thirtyDaysAgo->modify('-29 days');
        // 计算前60天的时间
        if ($month == 1) {
//            return ["2024-12-09", "2025-01-07"];
            return [$thirtyDaysAgo->format('Y-m-d'),$currentDate->format('Y-m-d')];
        }
        $thirtyDaysAgo = clone $currentDate;
        $thirtyDaysAgo->modify('-30 days');
        $sixtyDaysAgo = clone $currentDate;
        $sixtyDaysAgo->modify('-59 days');
        if ($month == 2) {
//            return ["2024-11-09", "2024-12-08"];
            return [$sixtyDaysAgo->format('Y-m-d'), $thirtyDaysAgo->format('Y-m-d')];
        }
        return ['',''];
    }
}