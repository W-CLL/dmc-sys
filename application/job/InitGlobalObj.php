<?php

namespace app\job;

use app\job\Base\BaseInitObjJob;
use jlqc\FundManagement;

class InitGlobalObj extends BaseInitObjJob
{
    public function getJobName(): string
    {
        return '获取第n页全域计划';
    }

    public function getQueueName(): string
    {
        return 'initGlobalObj';
    }

    protected function getModelClass(): string
    {
        return '\app\admin\model\QcGlobalObj';
    }

    protected function fetchData(array $data): array
    {
        return FundManagement::get_global_obj_list($data);
    }

    protected function buildInsertData(int $advId, array $item): array
    {
        return [
            'adv_id' => $advId,
            'obj_id' => $item['id'],
            'name' => $item['name'],
            'obj_status' => $item['status'],
            'opt_status' => $item['opt_status'],
            'marketing_goal' => $item['marketing_goal'],
            'smart_bid_type' => $item['smart_bid_type'],
            'obj_create_time' => strtotime($item['create_time']),
            'obj_modify_time' => strtotime($item['modify_time']),
            'start_time' => strtotime($item['start_time']),
            'end_time' => strtotime($item['end_time']),
            'product_info' => json_encode($item['product_info']),
            'room_info' => json_encode($item['room_info']),
            'stats_info' => json_encode($item['stats_info']),
        ];
    }
}
