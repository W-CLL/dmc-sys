<?php

namespace app\store\controller\wallet;


use app\common\controller\Store;
use app\store\model\StoreRefund;
use jlqc\FundManagement;
use think\Cache;
use think\Db;
use think\Env;
use think\Exception;


class RechargeTransfer extends Store
{

    public function checkParam()
    {
        if (time() > strtotime(date('Y-m-d') . ' 23:50:00')) {
            $this->error("今日转账已停止");
        }

        $advertiser_id_initiate = input("advertiser_id_initiate");
        $advertiser_id_target = input("advertiser_id_target");
        $money = input("money");

        $account_info = $this->get_qc_money($advertiser_id_initiate);
        $account_data = $account_info->getData();

        if ($money > $account_data['data']['money']) {
            $this->error('发起账户余额不足，不能转账');
        }

        $initiate_company = Db::name("company")->where(['advertiser_id' => $advertiser_id_initiate, "store_id" => $this->auth->id])->field('id,account_type')->find();
        $target_company = Db::name("company")->where(['advertiser_id' => $advertiser_id_target, "store_id" => $this->auth->id])->field('id,account_type')->find();
        if ($initiate_company['id'] == $target_company['id'] || empty($target_company) || empty($initiate_company)) {
            $this->error("账户选择非法");
        }
        if (!is_numeric($money) || $money < 0) {
            $this->error("请输入正确金额");
        }

        $transfer_records_data = [
            "store_id" => $this->auth->id,
            "company_id" => $target_company['id'],
            "account_type" => $target_company['account_type'],
            "advertiser_id" => $advertiser_id_initiate,
            "transfer_direction" => 1,
            "money" => $money,
            "remark" => input("remark", ""),
            "create_time" => time()
        ];

        return [$money, $transfer_records_data, $advertiser_id_initiate, $advertiser_id_target];

    }

    public function index()
    {

        if (request()->isAjax()) {
            list($money, $transfer_records_data, $advertiser_id_initiate, $advertiser_id_target) = $this->checkParam();

            $access_token = Cache::get("qc_access_token");
//            $advertiser_id = Db::name("qc_config")->where("id", 1)->value("advertiser_id");
            $advertiser_id = Env::get('dmc_ad_config.advertiser_id');
            $transfer_direction = 'TRANSFER_IN';
            $remark = "抖秒冲转账";
            $target_account_detail_list[] = [
                'account_id' => (int)$advertiser_id_target,
                'transfer_capital_detail_list' => [[
                    'capital_type' => 'PREPAY_GENERAL',
                    'transfer_amount' => (int)($money * 100),
                ]]
            ];

            $transfer_records_id = "";
            $data = [];
            Db::startTrans();
            try {
                $transfer_records_id = Db::name("transfer_records")->insertGetId($transfer_records_data);
                if (!$transfer_records_id) {
                    throw new Exception("生成转账记录失败");
                }
                $this->inheritanceRatio($advertiser_id_initiate, $advertiser_id_target, $money); // 继承返点比例

                //发起转账
                list($data, $biz_request_no) = FundManagement::create_transfer($access_token, $transfer_records_id, $advertiser_id, $advertiser_id_initiate, $target_account_detail_list, $transfer_direction, $remark);
                if (!isset($data['code']) || !isset($data['message']) || $data['code'] != 0 || $data['message'] != "OK") {
                    throw new Exception("发起转账失败");
                }

                $transfer_records_data['transfer_serial'] = $data['data']['transfer_serial'];
                $transfer_records_data['record'] = json_encode($data, JSON_UNESCAPED_UNICODE);
                $transfer_records_data['update_time'] = time();
                Db::name("transfer_records")->where(["id" => $transfer_records_id])->update($transfer_records_data);
                Db::commit();
            } catch (\Exception $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }

            $explain_record = [];
            //查询转账状态
            for ($i = 1; $i <= 3; $i++) {
                $transfer_detail_data = FundManagement::transfer_detail($access_token, $biz_request_no, $advertiser_id, $data['data']['transfer_serial']);
                if (isset($transfer_detail_data['code']) && isset($transfer_detail_data['message']) && $transfer_detail_data['code'] == 0 && $transfer_detail_data['message'] == "OK") {
                    if ($transfer_detail_data['data']['transfer_status'] === 'TRANSFER_SUCCESS') {
                        //转账成功
                        if (!Db::name("transfer_records")->where(["id" => $transfer_records_id])->update(['status' => 1])) {
                            $msg = '转账成功，状态更新失败';
                            $this->error($msg);
                        }
                        $this->success('转账成功');
                    } else if ($transfer_detail_data['data']['transfer_status'] == 'NO_TRANSFER') {
                        //未转账
                        Db::name("transfer_records")->where(["id" => $transfer_records_id])->update(['status' => 3]);
                        usleep(500000 * $i);
                    } else if ($transfer_detail_data['data']['transfer_status'] == 'TRANSFER_ING') {
                        //转账中
                        Db::name("transfer_records")->where(["id" => $transfer_records_id])->update(['status' => 4]);
                        if ($i == 3) {
                            $this->error("转账中，请稍后刷新");
                        }
                        usleep(500000 * $i);
                    } else if ($transfer_detail_data['data']['transfer_status'] == 'TRANSFER_FAILED') {
                        Db::name("transfer_records")->where(["id" => $transfer_records_id])->update(['status' => 2, 'explain' => $transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason']]);
                        //转账失败
                        $this->error("转账失败," . $transfer_detail_data['data']['transfer_target_record_list'][0]['transfer_capital_record_list'][0]['fail_reason']);
                    }
                } else {
                    $explain_record[] = $transfer_records_data;
                    if ($i == 3) {
                        Db::name("transfer_records")->where(["id" => $transfer_records_id])->update(['status' => 5, 'explain_record' => json_encode($explain_record, JSON_UNESCAPED_UNICODE), 'explain' => "查询转账状态失败", 'update_time' => time()]);
                        $this->error("查询转账状态失败");
                    }
                    usleep(500000 * $i);
                }
            }
        }
        $company_data = Db::name("company")->where("store_id", $this->auth->id)->field("advertiser_id,name")->select();
        $this->assign("company_data", $company_data);
        return $this->view->fetch();
    }


    /**
     * @param $advertiser_id_initiate
     * @param $advertiser_id_target
     * @param $money
     * @return void
     * @throws Exception
     * 同级互转继承返点比例
     */
    private function inheritanceRatio($advertiser_id_initiate, $advertiser_id_target, $money)
    {
        $store_refund_model = new StoreRefund();
        $info = $store_refund_model->getOneRefundInfo($advertiser_id_initiate);
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
            $store_refund_model->addStoreRefundRecord($wallet, $data);
            $info->wallet -= $wallet['wallet'];
            $info->credit -= $wallet['credit'];
            $info->save(); // 扣除原本号的金额记录
        }
    }


    public function get_qc_money($advertiser_id = '')
    {
        if (empty($advertiser_id)) {
            $advertiser_id = input("advertiser_id");
        }
        if (!$advertiser_id) {
            $this->error('请选择账户');
        }
        $company = Db::name("company")->where(['advertiser_id' => $advertiser_id, "store_id" => $this->auth->id])->find();
        if ($company) {
            $access_token = Cache::get("qc_access_token");
//            $qc_money = FundManagement::account_balance($access_token, $advertiser_id);//获取不到赠送余额
            $qc_money = FundManagement::account_balance_wallet($access_token, $advertiser_id);//获取钱包详细信息
            $return_code = FundManagement::$auth_return_code;

            if (in_array($qc_money['code'], $return_code)) {
                send_work_wx_msg('千川授权已失效，请尽快更新!');
                return json(["code" => 0, "msg" => "千川授权已失效，请联系管理员"]);
//                $this->error('千川授权已失效，请联系管理员');
            }
            $total_money = $qc_money['data']['total_balance_abs'];
            $grant_balance = $qc_money['data']['grant_balance'];
            $actual_money = $total_money - $grant_balance;
            $data = [
                "money" => $actual_money / 100000,
            ];
            return json(["code" => 1, "data" => $data, "msg" => "请求成功"]);
        }
        return json(["code" => 0, "msg" => "请求失败，请刷新后重新请求"]);
    }

}









