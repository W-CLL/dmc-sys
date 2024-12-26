<?php

namespace app\index\controller;

use app\common\controller\Frontend;
use app\common\model\Queue;
use GuzzleHttp\Exception\GuzzleException;
use think\Db;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\DbException;

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
                    $res = buildCrmRequest($params);
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
                    $res = buildCrmRequest($params);
                    dump($res);
                }
            }
        }
    }

    public function getCrmWrongRefundMoneyData()
    {
        $params = [
            'app' => 'commonfix_controller_dmcapi',
            'act' => 'get',
            'account' => '20240919001',
        ];
        $res = buildCrmRequest($params);
        dump($res);
        die;
    }


    /**
     * 修复crm退款金额出错
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws GuzzleException
     */
    public function fixCrmRefundMoneyData()
    {
        $params = [
            'app' => 'commonfix_controller_dmcapi',
            'act' => 'get',
            'account' => '20240919001',
        ];
        //crm错误数据
        $res = buildCrmRequest($params);
        echo "共" . count($res['data']) . '条';
        $i = 0;
        $data = [];
        foreach ($res['data'] as $item) {
            if ($item['extra_type'] == 1) {
                $data = Db::name('transfer_records')->where('id', $item['extra_id'])->find();
            } else if ($item['extra_type'] == 2) {
                $data = Db::name('share_wallet_transfer_log')->where('id', $item['extra_id'])->find();
            }
            if ($data) {
                $realMoney = -($data['money'] - $data['rebate']);
                if (abs(round($item['eb_price'], 2)) == $data['money']) {
                    echo $item['id'] . '已经更新了';
                    continue;
                }
                $update['customer_back'] = (float)$this->convertValue($data['discount_percentage']);//计算客户返点 加入接口传过来是1.035 转换后则是3.5
//                //$data['account_type']账号类型 1 公 2私
                if ($data['account_type'] == 1 || !$data['account_type']) {
                    if ($data['discount_percentage'] < 0) {
                        $update['cost_back'] = -(abs($update['customer_back']) + 0.25);
                    } else {
                        $update['cost_back'] = $update['customer_back'] + 0.25;
                    }
                }
                if ($data['account_type'] == 2) {
                    if ($update['customer_back'] < 0) {
                        $update['cost_back'] = -(abs($update['customer_back']) + 0.5);
                    } else {
                        $update['cost_back'] = $update['customer_back'] + 0.5;
                    }
                }

                $update['eb_price'] = (1 + $update['customer_back'] * 0.01) * $realMoney;
                $update['ebfit_price'] = $realMoney * ($update['cost_back'] - $update['customer_back']) * 0.01;
                $update['rmbfit_price'] = $update['ebfit_price'] / (1 + $update['cost_back'] * 0.01);
                $update['customer_pay'] = $realMoney;
                $update['sales_price'] = $realMoney;
                $update['crm_id'] = $item['id'];
                bcscale(2);  // 设置精度为2位小数
                $update['eb_price'] = bcdiv($update['eb_price'], '1', 2); // 保留2位小数

                $params = [
                    'app' => 'commonfix_controller_dmcapi',
                    'act' => 'put',
                    'account' => '20240919001',
                    'update_data' => json_encode($update)
                ];
                //crm错误数据
                buildCrmRequest($params);
                $i++;
            }
        }
        echo "已处理了" . $i . "条数据";
    }

    protected function convertValue($value)
    {
        // 检查是否为负数
        $isNegative = $value < 0;
        // 获取绝对值的小数点后三位数
        $absValue = abs($value);
        $decimalPart = substr(strval($absValue), strpos(strval($absValue), '.') + 1, 3);
        // 将小数部分转换为整数
        $intValue = intval($decimalPart);
        // 计算结果
        $result = $intValue / 10;
        // 如果原值是负数，结果也应该是负数
        if ($isNegative) {
            $result = -$result;
        }
        // 返回转换后的值，保留两位小数
        return number_format($result, 2, '.', '');
    }
}