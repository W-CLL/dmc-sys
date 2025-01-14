<?php

namespace app\store\model;

use think\Collection;
use think\Db;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\Error;
use think\Exception;
use think\exception\DbException;
use think\exception\PDOException;
use think\Model;
use think\Session;

class StoreRefund extends Model
{

//    // 开启自动写入时间戳字段
//    protected $autoWriteTimestamp = 'int';
//    // 定义时间戳字段名
//    protected $createTime = 'create_time';
//    protected $updateTime = 'update_time';

    /**
     * 获取多条商户归属下的千川折扣百分比扣除记录
     * @param $transfer_records_data
     * @param $wallet_type
     * @return bool|string|Collection
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function getStoreRefundRecordList($transfer_records_data,$wallet_type)
    {
        $where = [
            'type' => $transfer_records_data['account_type'],
            'store_id' => $transfer_records_data['store_id'],
            'wallet_type' => $wallet_type,
        ];
        if($wallet_type == 1){
            $where['platform_id'] = $transfer_records_data['advertiser_id'];
        }else{
            $where['platform_id'] = isset($transfer_records_data['swtl_id']) ? Db::name('share_wallet_transfer_log')->where('id',$transfer_records_data['swtl_id'])->value('sub_wallet_id') : $transfer_records_data['sub_wallet_id'];
        }
        //千川账户的充值记录
        return self::where($where)
            ->where(function ($query) {
                $query->where('credit > 0 OR wallet > 0');
            })
            ->order('id desc')
//            ->fetchSql(true)
            ->select();
    }

    /**
     * 获取单条商户归属下的千川折扣百分比扣除记录
     * @param $transfer_records_data
     * @param $wallet_type
     * @return array|bool|string|Model|null
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function getStoreRefundRecord($transfer_records_data,$wallet_type)
    {
        $where = [
            'type' => $transfer_records_data['account_type'],
            'store_id' => $transfer_records_data['store_id'],
//            'company_id' => $transfer_records_data['company_id'],
            'discount_percentage' => $transfer_records_data['discount_percentage'],
            'wallet_type' => $wallet_type,
        ];
        if($wallet_type == 1){
            $where['platform_id'] = $transfer_records_data['platform_id'] ?? $transfer_records_data['advertiser_id'];
        }else{
            $where['platform_id'] = $transfer_records_data['platform_id'] ?? $transfer_records_data['sub_wallet_id'];
        }
        //千川账户的充值记录
        return self::where($where)->order('id desc')->find();
    }

    /**
     * 更新退款关联表数据
     * @param $id
     * @param $wallet
     * @param $credit
     * @return StoreRefund
     */
    public function updateRefundMoney($id, $wallet, $credit)
    {
        $update_data = [
            'wallet' => $wallet,
            'credit' => $credit,
            'update_time' => time()
        ];
        return self::where('id', $id)->update($update_data);
    }

    /**
     * 获取实际退款返点
     * @param $transfer_records_data
     * ['money'=>'充值金额','transfer_direction'=>'充值方向',
     * 'remark'=>'备注','account_type'=>'账户类型','store_id'=>'商户id',
     * 'company_id'=>'千川商户id','advertiser_id'=>'千川代理商id','discount_percentage'=>'折扣百分比']
     * @param $wallet_type
     * 目标充值钱包类型：1千川[默认]   2共享
     * @param $is_update
     * 是否执行更新操作
     * @return  float
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws DbException
     */
    public function getRealRefundRebate($transfer_records_data,$wallet_type = 1,$is_update = true)
    {
        $list = $this->getStoreRefundRecordList($transfer_records_data,$wallet_type);
        $totalRefundPoints = 0;
        if (!$list) {
            return $totalRefundPoints;
        }

        //需要退款的金额
        $remainingRefundAmount = $transfer_records_data['money'];
        $recordCount = count($list);
        foreach ($list as $key => $item) {
            $wallet = $item['wallet'];
            $credit = $item['credit'];
            $percentage = $item['discount_percentage'];
            $currentTotal = $wallet + $credit;//最新一笔充值的 钱包+授信总额度
            //如果需要退款的金额大于或等于最新一笔充值的钱包+授信总额度
            if ($remainingRefundAmount > 0 && $remainingRefundAmount >= $currentTotal) {
                $rebate = round($currentTotal - ($currentTotal * 100) / ($percentage * 100), 2);
                $totalRefundPoints += $rebate;
                $remainingRefundAmount -= $currentTotal;
                $wallet = 0;
                $credit = 0;
                if($is_update){
                    $this->updateRefundMoney($item['id'], $wallet, $credit);     // 扣除钱包和授信额度
                }
                // 如果处理金额超出了最近充值的所有金额，超出部分则按照最新百分比进行退款
                if ($remainingRefundAmount > 0 && $key == ($recordCount - 1)) {
                    $rebate = round($remainingRefundAmount - ($remainingRefundAmount * 100) / ($percentage * 100), 2);
                    $totalRefundPoints += $rebate;
                }
            } else if ($remainingRefundAmount > 0) {
                // 处理超出部分金额
                $rebate = round($remainingRefundAmount - ($remainingRefundAmount * 100) / ($percentage * 100), 2);
                $totalRefundPoints += $rebate;
                // 扣除钱包和授信额度
                if ($remainingRefundAmount <= $wallet) {
                    $wallet -= $remainingRefundAmount;
                } else {
                    $credit -= $remainingRefundAmount - $wallet;
                }
                if($is_update){
                    $this->updateRefundMoney($item['id'], $wallet, $credit);     // 扣除钱包和授信额度
                }
                break;
            }
        }
        return $totalRefundPoints;
    }


    /**
     * 添加千川充值记录,与最新一条记录比较百分比字段，有变化则添加一条记录，没有则更新
     * @param $money
     * ['wallet'=>'充值使用的钱包数额','credit'=>'充值使用的授信额度']
     * @param $transfer_records_data
     * ['money'=>'充值金额'[非计算后的金额],
     * 'account_type'=>'账户类型',
     * 'store_id'=>'商户id',
     * 'discount_percentage'=>'折扣百分比']
     * @param $wallet_type
     * 目标充值钱包类型：1千川[默认]   2共享
     * @return int|string
     * @throws Exception
     */
    public function addStoreRefundRecord($money, $transfer_records_data,$wallet_type = 1)
    {
        try {
            $record = $this->getStoreRefundRecord($transfer_records_data,$wallet_type);
            $res = '';
            if (!$record || $record['discount_percentage'] != $transfer_records_data['discount_percentage']) {
                $ins = [
                    'type' => $transfer_records_data['account_type'],
                    'wallet' => $money['wallet'],
                    'credit' => $money['credit'],
                    'store_id' => $transfer_records_data['store_id'],
//                    'company_id' => $transfer_records_data['company_id'],
                    'discount_percentage' => $transfer_records_data['discount_percentage'],
                    'wallet_type' => $wallet_type,
                    'create_time' => time()];
                if($wallet_type == 1){
                    $ins['platform_id'] = $transfer_records_data['platform_id'] ?? $transfer_records_data['advertiser_id'];
                }else{
                    $ins['platform_id'] = $transfer_records_data['platform_id'] ?? $transfer_records_data['sub_wallet_id'];
                }
                $res = self::insertGetId($ins);
            } elseif ($record['discount_percentage'] == $transfer_records_data['discount_percentage']) {
                $res = self::where('id', $record['id'])
                    ->inc('wallet', $money['wallet'])
                    ->inc('credit', $money['credit'])
                    ->update(['update_time' => time()]);
            }
        }catch (Exception $e){
            throw new Exception($e->getMessage());
        }


        return $res;
    }


}