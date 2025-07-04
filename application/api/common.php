<?php

use app\common\model\Queue;
use think\Cache;
use think\Db;

if (!function_exists('checkQueueExecutionOver')) {

    /**
     * 检查队列执行情况，决定脚本重新生成
     * @param string $main_queue_name
     * @param string $chunk_queue_name
     * @return void
     */
    function checkQueueExecutionOver(string $main_queue_name, string $chunk_queue_name)
    {
        $main_queue_name_num = getQueueNumWithKey($main_queue_name);
        $chunk_queue_name_num = getQueueNumWithKey($chunk_queue_name);
        if($main_queue_name_num > 10 || $chunk_queue_name_num){
            echo "还有".$main_queue_name_num."条任务，没有执行完";
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

if(!function_exists("getQueueNumWithKey")){
    function getQueueNumWithKey($queue_key,$db=1)
    {
        $redis = Cache::store('redis')->handler();
        $redis->rawCommand('SELECT', $db);
        return $redis->llen('queues:'.$queue_key);
    }
}
