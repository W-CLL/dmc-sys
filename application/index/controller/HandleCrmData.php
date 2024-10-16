<?php

namespace app\index\controller;

use app\common\controller\Frontend;
use app\common\model\Queue;
use think\Db;

class HandleCrmData extends Frontend
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';
    protected $layout = '';

    public function fixUpdateCrmAddtimeData()
    {
        echo "禁止访问!";
        exit;
        $queueModel = new Queue();
        $list = $queueModel->select();

        foreach ($list as $item) {
            $pattern = '/log_id:(\d+),crm_id:(\d+)/';
            if (preg_match($pattern, $item['msg'], $matches)) {

                $logId = $matches[1];
                $crmId = $matches[2];
                $logData = Db::name($item['relation_table'])->where('id', $logId)->find();

                if ($logData) {
                    $params = [
                        'app' => 'charge_controller_dmcapi',
                        'act' => 'put',
                        'crm_id' => $crmId,
                        'account' => '20240919001',
                        'add_time' => $logData['create_time']
                    ];
                    $res =  buildCrmRequest($params);
                    dump($res);
                }
            }
        }
    }

    public function fixCrmMoneyData()
    {
        echo "禁止访问!";
        exit;
        $queueModel = new Queue();
        $list = $queueModel->select();

        foreach ($list as $item) {
            $pattern = '/log_id:(\d+),crm_id:(\d+)/';
            if (preg_match($pattern, $item['msg'], $matches)) {
                $logId = $matches[1];
                $crmId = $matches[2];
                $logData = Db::name($item['relation_table'])->where('id', $logId)->find();

                if ($logData) {
                    $params = [
                        'app' => 'charge_controller_dmcapi',
                        'act' => 'put',
                        'crm_id' => $crmId,
                        'account' => '20240919001',
                        'actual_money' => $logData['actual_money']
                    ];
                    $res =  buildCrmRequest($params);
                    dump($res);
                }
            }
        }
    }
}