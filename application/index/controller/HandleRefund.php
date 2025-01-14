<?php

namespace app\index\controller;

use app\common\controller\Frontend;
use app\store\model\StoreMoneyLog;
use app\store\model\StoreRefund;
use app\store\model\TransferRecords;
use think\Db;
use think\Exception;


class HandleRefund extends Frontend
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';
    protected $layout = '';


    public function handleMoneyLogData()
    {
//        echo "禁止访问";
//        die;
//        $records = Db::name('store_money_log')
//            ->where('type', '=', 4)
//            ->whereOr('type', '=', 5)
//            ->order('store_id','asc')
//            ->order('id', 'asc')
//            ->select();
//        $res = $this->updateRebateAndPercentage($records);
        $records = Db::name('store_money_log')
            ->where('type', '=', 4)
            ->whereOr('type', '=', 5)
            ->order('store_id','asc')
            ->order('id', 'asc')
            ->select();
        $res1 = $this->initRefundData($records,1);
        $records1 = Db::name('store_money_log')
            ->where('type', '=', 8)
            ->whereOr('type', '=', 9)
            ->order('store_id','asc')
            ->order('id', 'asc')
            ->select();
        $res2 = $this->initRefundData($records1,2);
        echo $res1;
        echo $res2;
        die;
    }

    public function handleTransferRecordData()
    {
        echo "禁止访问";
        die;
        $records = Db::name('transfer_records')
            ->where('status', '=', 1)
            ->where('store_id', '>', 0)
            ->order('id', 'asc')
            ->select();
        $res = $this->updateRebateAndPercentage($records, 'transfer_records');
        echo "转换成功!".$res;
        $records = Db::name('transfer_records')
            ->where('status', '=', 1)
            ->where('store_id', '>', 0)
            ->order('id', 'asc')
            ->select();
        $res1 = $this->initRefundData($records,'transfer_records');

        echo "初始化成功！".$res1;
        die;
    }


    public function initRefundData($records, $wallet_type = 1, $handleType = 'store_money_log')
    {
        $newRecords = [];
        $tempGroup = [];
        $currentStoreId = null;
        $currentPercentage = null;
        $currentAccountType = null;

        foreach ($records as $record) {
            if ($currentStoreId == null || $currentStoreId != $record['store_id']
                || $currentPercentage != $record['discount_percentage']
                || $currentAccountType != $record['account_type']
            ) {
                if (!empty($tempGroup)) {
                    $newRecords[] = $tempGroup;
                }
                $tempGroup = [];
            }
            $tempGroup[] = $record;
            $currentStoreId = $record['store_id'];
            $currentPercentage = $record['discount_percentage'];
            $currentAccountType = $record['account_type'];
        }


        if (!empty($tempGroup)) {
            $newRecords[] = $tempGroup;
        }

        $storeRefundModel = new StoreRefund();
        foreach ($newRecords as $items) {
            foreach ($items as $item) {
                $rechargeWallet = 0;
                $rechargeCredit = 0;
                if ($handleType == 'store_money_log') {
                    if($wallet_type == 1){
                        $transferType = $item['type'] == 4 ? 1 : 2;
                    }else{
                        $transferType = $item['type'] == 8 ? 1 : 2;
                    }

                } else {
                    $transferType = $item['transfer_direction'];
                }

                if ($transferType == 1) {//转入千川（充值）
                    if ($item['deduction_credit_limit'] > 0) {
                        $rechargeWallet += $item['deduction_balance'];
                        $rechargeCredit += $item['deduction_credit_limit'] + $item['rebate'];
                    } else {
                        $rechargeWallet += $item['money'];
                        $rechargeCredit = 0;
                    }
                    $realWallet = $rechargeWallet;
                    $realCredit = $rechargeCredit;
                    $realWallet < 0 ? $realWallet = 0 : '';
                    $realCredit < 0 ? $realCredit = 0 : '';

                    $money = [
                        'wallet' => round($realWallet, 2),
                        'credit' => round($realCredit, 2),
                    ];
                    $transferRecordsData = [
                        'store_id' => $item['store_id'],
                        'account_type' => $item['account_type'],
                        'discount_percentage' => $item['discount_percentage'],
                    ];
                    if($wallet_type == 1){
                        $transferRecordsData['platform_id'] = $item['advertiser_id'];
                    }else{
                        $transferRecordsData['platform_id'] = Db::name('share_wallet_transfer_log')->where('id',$item['swtl_id'])->value('sub_wallet_id');
                    }
                    try {
                        $storeRefundModel->addStoreRefundRecord($money, $transferRecordsData,$wallet_type);
                    }catch (Exception $e){
                        dump($e->getMessage());
                    }
                }
                if ($transferType == 2) {//转出（退款）
                    try {
                        $storeRefundModel->getRealRefundRebate($item, $wallet_type);
                    }catch (Exception $e){
                        dump($e->getMessage());
                    }
                }
            }




//            if (!$res) {
//                $this->error('更新失败');
//            }
//            $totalMoney[] = [
//                'store_id' => $items[0]['store_id'],
//                'type' => $items[0]['account_type'],
//                'wallet' => round($realWallet, 2),
//                'credit' => round($realCredit, 2),
//                'discount_percentage' => $items[0]['discount_percentage'],
//            ];
        }


        return true;

        // 返回分组后的记录
    }

    public function updateRebateAndPercentage($records, $handleType = 'store_money_log')
    {

        foreach ($records as $record) {
            preg_match_all('/\d+(\.\d+)?/', $record['explain'], $matches);
            if ($handleType == "store_money_log") {
                $rebate = $matches[0][1];
                $model = new StoreMoneyLog();
            } else {
                $rebate = $record['rebate'];
                $model = new TransferRecords();
            }

            $record['money'] = number_format($record['money'], 2, '.', '');
            $percentage = round($record['money'] / ($record['money'] - $rebate), 3);
            $percentage = $this->handlePercentage($percentage);
            $updateData[] = [
                'id' => $record['id'],
                'rebate' => $rebate,
                'discount_percentage' => $percentage,
            ];
        }

        $res = $model->saveAll($updateData);
        if (!$res) {
            dump("处理失败，请联系管理员!");
            die;
        }
        return true;

    }

    public function handlePercentage($percentage)
    {
        // 将数字转换为字符串
        $numberStr = (string)$percentage;
        // 检查是否有小数点
        if (strpos($numberStr, '.') === false) {
            return $percentage; // 如果没有小数点，直接返回原数
        }
        // 分离整数部分和小数部分
        list($integerPart, $decimalPart) = explode('.', $numberStr);
        // 获取小数点后倒数第二位和最后一位
        $lastTwoDigits = substr($decimalPart, -2);
        // 判断最后两位是否大于55
        if ((int)$lastTwoDigits >= 55) {
            $roundedNumber = round($percentage, 2);
        } else {
            $roundedNumber = $percentage;
        }

        // 返回最终结果
        return $roundedNumber;

    }

}