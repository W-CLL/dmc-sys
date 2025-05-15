<?php

use app\common\model\Queue;
use think\Cache;
use think\Db;

if (!function_exists('checkQueueExecutionOver')) {

    /**
     * @param $fun_name
     * @return void
     */
    function checkQueueExecutionOver($fun_name, $queue_name1 = 'autoUpdateObjName', $queue_name2 = 'chunkAutoObj')
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
            'queue1' => $queue_name1,
            'queue2' => $queue_name2,
            'start1' => $todayStart,
            'end1' => $todayEnd,
            'start2' => $todayStart,
            'end2' => $todayEnd
        ])[0]['count'];

        if ($count <= 50) {
            Cache::store('redis')->set($fun_name . '_over', 1);
        }
        $canRun = Cache::store('redis')->get($fun_name . '_over');
        if ($canRun != 1) {
            echo "时辰未到";
            die;
        }
    }
}

if (!function_exists('getPersonStartTime')) {
    function getPersonStartTime($user_name = '')
    {
        $mon = date('d');
        switch ($user_name) {
            case 'mmc':
            case 'wyc':
            case 'tyx':
            case 'cxy':
            case 'zqp':
                $day_before = $mon;
                break;
            default:
                $day_before = 1;
                break;
        }
        $currentDate = new \DateTime();
        $currentDate->modify('-' . $day_before . ' days');
        $start_time = $currentDate->getTimestamp();
        $end_time = time();
        return [$start_time, $end_time];
    }
}



if(!function_exists('delNoPermission')){
    /**
     * 删除队列表中无权限操作的任务
     * @param $str
     * @return true
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    function delNoPermission($str = "No permission")
    {
        $queue = new Queue();
        $result = Db::table('fa_queue_record')
            ->field([
                'SUBSTRING_INDEX(SUBSTRING_INDEX(msg, \'"adv_id":"\', -1), \'"\', 1)' => 'adv_id',
                'GROUP_CONCAT(id)' => 'id_list',
                'id',
                'job_data'
            ])
            ->where('status', 2)
            ->where('msg', 'like', '%' . $str . '%')
            ->group('adv_id')
            ->select();

        foreach ($result as $value) {
            if ((string)$value['id'] == $value['id_list']) {
                continue;
            }
            $idListArray = explode(',', $value['id_list']);
            if (count($idListArray) > 1) {
                $idListArray = array_filter($idListArray, function ($item) use ($value) {
                    return $item != $value['id'];
                });
                $queue->where(['id' => ['in', $idListArray]])->delete();
            }
            $number = json_decode($value['job_data'], true)['adv_id'];
            if ($number) {
                $queue->where(['job_data' => ['like', "%" . $number . "%"], 'id' => ['neq', $value['id']]])->delete();
            }
        }
        return true;
    }
}
