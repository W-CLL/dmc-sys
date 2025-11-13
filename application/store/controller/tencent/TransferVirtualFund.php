<?php

namespace app\store\controller\tencent;

use app\common\controller\Store;
use app\common\model\txgg\TencentRefund;
use think\Db;
use think\Exception;
use txgg\Fund;

class TransferVirtualFund extends Store
{

    public function index()
    {
        if (request()->isAjax()){
            list($money, $transfer_records_data, $account_id, $to_account_id) = $this->checkParam();
            try {
                Db::startTrans();
                $transfer_records_id = Db::name("tencent_transfer_log")->insertGetId($transfer_records_data);
                if (!$transfer_records_id) {
                    throw new Exception("生成转账记录失败");
                }
                $this->inheritanceRatio($account_id, $to_account_id, $money); // 继承返点比例

                $res = Fund::accountToAccountTransfer([
                    'account_id' => (int)$account_id,
                    'to_account_id' => (int)$to_account_id,
                    'fund_type' => 'FUND_TYPE_COMPENSATE_VIRTUAL',
                    'amount' => (float)$money * 100,
                    'external_bill_no' => uniqid('hx-'),
                    'pre_fetch_amount' => 0,
                ])['data'];

                if ($res['code'] != 0) {
                    throw new Exception("发起转账失败");
                }
                Db::commit();
            }catch (\Exception $e){
                Db::rollback();
                $this->error($e->getMessage());
            }
            $update['order_uid'] = $res['data']['external_bill_no'];
            $update['record'] = json_encode($res, JSON_UNESCAPED_UNICODE);
            $update['update_time'] = time();
            Db::name("tencent_transfer_log")->where(["id" => $transfer_records_id])->update($update);
            $this->success("转账成功");
        }
        $account_data = Db::name("tencent_account")
            ->where("store_id", $this->auth->id)
            ->where("status", 1)
            ->field("account_id,name")->select();
        $this->assign("account_data", $account_data);
        return $this->view->fetch();
    }


    private function checkParam(){
        $account_id = input("account_id_initiate");
        $to_account_id = input("to_account_id");
        $money = input("money");
        $account_info = $this->get_amount($account_id);
        $account_data = $account_info->getData();
        if ($money > $account_data['data']['money']) {
            $this->error('发起账户余额不足，不能转账');
        }

        $initiate_account = Db::name("tencent_account")->where(['account_id' => $account_id, "store_id" => $this->auth->id])->field('id,account_type')->find();
        $target_account = Db::name("tencent_account")->where(['account_id' => $to_account_id, "store_id" => $this->auth->id])->field('id,account_type')->find();
        if ($initiate_account['id'] == $target_account['id'] || empty($initiate_account) || empty($target_account)) {
            $this->error("账户选择非法");
        }
        if (!is_numeric($money) || $money < 0) {
            $this->error("请输入正确金额");
        }

        $transfer_records_data = [
            "store_id" => $this->auth->id,
            "tencent_account_id" => $target_account['id'],
            "account_type" => $target_account['account_type'],
            "account_id" => $account_id,
            "transfer_direction" => 1,
            "money" => $money,
            "remark" => input("remark", ""),
            "create_time" => time()
        ];

        return [$money, $transfer_records_data, $account_id, $to_account_id];
    }


    private function inheritanceRatio($advertiser_id_initiate, $advertiser_id_target, $money)
    {
        $refund_model = new TencentRefund();
        $info = $refund_model->getOneRefundInfo($advertiser_id_initiate);
        if ($info) {
            $wallet = [];
            if($info['wallet']+$info['credit'] < $money){
                throw new Exception("本次同级互转最大金额不得超过：".($info['wallet']+$info['credit']));
            }
            if ($info['credit'] >= $money){
                $wallet['credit'] = $money;
                $wallet['wallet'] = 0;
            }else{
                $wallet['credit'] = $info['credit'];
                $wallet['wallet'] = $money - $info['credit'];
            }
            $data = [
                'money' => $money,
                'store_id' => $info['store_id'],
                'discount_percentage' => $info['discount_percentage'],
                'platform_id' => $advertiser_id_target,
                'account_type' => $info['type'],
            ];
            $refund_model->addStoreRefundRecord($wallet, $data);
            $info->wallet -= $wallet['wallet'];
            $info->credit -= $wallet['credit'];
            $info->save(); // 扣除原本号的金额记录
        }
    }


    public function get_amount($account_id = ''){
        if (empty($account_id)) {
            $account_id = input("account_id");
        }
        if (!$account_id) {
            $this->error('请选择账户');
        }
        $account = Db::name("tencent_account")->where(['account_id' => $account_id, "store_id" => $this->auth->id])->find();
        if ($account) {
            $res = Fund::accountToAccountTransfer([
                'account_id' => (int)$account_id,
                'to_account_id' => (int)$account_id,
                'fund_type' => 'FUND_TYPE_COMPENSATE_VIRTUAL',
                'amount' => 0,
                'pre_fetch_amount' => 1,
            ])['data'];

            if ($res['code'] != 0) {
                return json(["code" => 0, "msg" => "请求异常"]);
            }
            // 此处取两位小数
            $actual_money = number_format($res['data']['recommend_amount'] / 100, 2);
            $data = [
                "money" => $actual_money,
            ];
            return json(["code" => 1, "data" => $data, "msg" => "请求成功"]);
        }
        return json(["code" => 0, "msg" => "请求失败，请刷新后重新请求"]);
    }



}