<?php

namespace app\store\controller\tencent;

use app\common\controller\Store;
use app\common\model\txgg\TencentAccount;
use app\common\model\txgg\TencentStore;
use app\common\model\txgg\TencentRefund;
use app\common\model\txgg\TencentTransferLog as TencentTransfer;
use app\common\model\txgg\TencentTransactionLog;
use think\Exception;
use txgg\Fund;
use think\Db;


class Transfer extends Store
{
    public function _initialize()
    {
        parent::_initialize();
        $this->AccountModel = new TencentAccount();
        $this->TencentWalletModel = new TencentStore();
        $this->RefundModel = new TencentRefund();
        $this->TencentTransferModel = new TencentTransfer();
        $this->TencentTransactionModel = new TencentTransactionLog();
    }
    public function index()
    {
        if ($this->request->isAjax()) {
            $sort = input("sort","id");
            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);
            $filter = input("filter", '');

            if ($filter != '') {
                $filter = (array)json_decode($filter, true);
                $where = $this->screen_filter($filter);
            }

            $where['store_id'] = ['=',$this->auth->id];

            $list = $this->AccountModel
                ->with('store')
                ->where($where)
                ->order($sort, $order)
                ->limit($offset,$limit)
                ->select();
            $count = $this->AccountModel->where($where)->count();
            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        return $this->fetch();
    }


    public function Transfer($ids = null){
        $account = $this->AccountModel->where('id',$ids)->find();
        $store_info = $this->TencentWalletModel->where('store_id',$this->auth->id)->lock(true)->find();
        if(empty($store_info)){
            $this->error('尚未设置腾讯广告账户，请联系管理员设置');
        }
        $res = Fund::getFundAccountInfo([
            'account_id' => (int)$account['account_id'],
        ])['data'];
        if ($res['code'] != 0){
            $this->error('接口异常，请稍后再试');
        }
        $fund_info = [];
        foreach ($res['data']['list'] as $item){
            $fund_info[$item['fund_type']] = ($item['balance'] - (isset($item['bill_deposit_amount'])? $item['bill_deposit_amount'] :0)) / 100;
        }
        $agent_balance = Fund::getAgentFundInfo([
            'account_id' => 64568612,
        ])['data'];
        if ($agent_balance['code'] != 0){
            $this->error('腾讯广告接口异常，请稍后再试');
        }
        $agent_balance_info = [];
        foreach ($agent_balance['data']['list'] as $item){
            $agent_balance_info[$item['fund_type']] = $item['balance'] / 100;
        }
        $last_transfer_info = $this->RefundModel->getSingleItem([
            'account_type' => $account['account_type'],
            'store_id' => $account['store_id'],
            'account_id' => $account['account_id']
        ],1);
        if(!empty($last_transfer_info)){
            $maxTTO = $last_transfer_info['wallet'] + $last_transfer_info['credit'];
        }
        if ($this->request->isPost()) {
            $post = $this->request->post();
            if(isset($maxTTO) && $post['transfer_amount'] > $maxTTO && $post['transfer_direction'] == 'ADVERTISER_TO_AGENCY'){
                $this->error('超出本次同比可退最大值，本次转出金额最大值：'.$maxTTO);
            }
            $wallet_info = $this->checkParam($post, $account, $store_info, $fund_info);
            $array_data = [];
            Db::startTrans();
            try{
                $insert_data = $this->buildData($account,$post,$wallet_info);
                $id = $this->TencentTransferModel->insertGetId($insert_data);
                if(!$id){
                    throw new Exception('添加转账记录失败');
                }
                $this->deductMoney($store_info,$insert_data);
                list($transfer_result, $array_data) = $this->initiateTransfer($post,$agent_balance_info, $fund_info);
                if ($transfer_result === false){
                    throw new Exception('发起转账失败');
                }
                Db::commit();
            }catch (\Exception $e){
                Db::rollback();
                $this->rollbackMoney($array_data);
                $this->error($e->getMessage());
            }
            $update = $this->TencentTransferModel->update($id,
                [
                    'order_uid' => '',
                    'record' => '',
                    'update_time' => time()
                ]
            );
            if (!$update){
                $this->error('更新转账编号失败');
            }
            $bool = $this->createTransactionLog($id, $insert_data, $store_info);
            if($bool){
                //添加同步转账记录任务
                if($post['transfer_direction'] == 'AGENCY_TO_ADVERTISER' ){
                    $name = "同步腾讯广告充值记录";
                }else{
                    $name = "同步腾讯广告退款记录";
                }
                $queueModel = new \app\common\model\Queue();
                $queueModel->addQueue($name,"app\job\SyncCharge",
                    "syncCharge",
                    ["log_id" => $id, 'data'=>$insert_data],
                    "tencent_transfer_log"
                );
                $this->success('转账成功');
            }else{
                $this->error('转账异常');
            }
        }
        $this->view->assign('totalFund',$fund_info['FUND_TYPE_CASH'] + $fund_info['FUND_TYPE_GIFT']);
        $this->view->assign('account', $account);
        $this->view->assign('storeInfo', $store_info);
        $this->view->assign('agentBalanceInfo', $agent_balance_info);
        return $this->fetch();
    }

    private function checkParam($post, $account, $store_info, $fund_info){
        if (!is_numeric($post['transfer_amount']) || $post['transfer_amount'] < 0) {
            $this->error("请输入正确金额");
        }
        $wallet_info['account_type']  = $account['account_type']; // 账户类型
        if($account['account_type'] == 1){
            $wallet_info['wallet_money'] = $store_info['public_money_tencent'];
            $wallet_info['wallet_limit'] = $store_info['public_credit_limit_tencent'];
            $wallet_info['wallet_discount'] = $store_info['public_discount_percentage_tencent'];
        }
        elseif ($account['account_type'] == 2){
            $wallet_info['wallet_money'] = $store_info['private_money_tencent'];
            $wallet_info['wallet_limit'] = $store_info['private_credit_limit_tencent'];
            $wallet_info['wallet_discount'] = $store_info['private_discount_percentage_tencent'];
        }
        else{
            $this->error('账户类型异常，请刷新重试');
        }
        // 如果账户设置自定义折扣比例，则取自定义的比例
        if(!empty(floatval($account['discount_percentage']))){
            $wallet_info['wallet_discount'] = $account['discount_percentage'];
        }
        // 余额检查
        if ($post['transfer_direction'] == 'AGENCY_TO_ADVERTISER'){
            if ($post['transfer_amount'] < 50) {
                $this->error('最小转入金额为50');
            }elseif ($post['transfer_amount'] > 20000000){
                $this->error('最大转入金额20000000');
            }
            if(!empty(floatval($wallet_info['wallet_discount']))){
                $rebate = round($post['transfer_amount'] - ($post['transfer_amount'] * 100) / ($wallet_info['wallet_discount'] * 100), 2);
            }else{
                $rebate = 0;
            }
            if (($post['transfer_amount'] - $rebate) > ($wallet_info['wallet_money'] + $wallet_info['wallet_limit'])) {
                $this->error('转入余额超出上限');
            }
        }elseif ($post['transfer_direction'] == 'ADVERTISER_TO_AGENCY'){
            if ($post['transfer_amount'] > $fund_info['FUND_TYPE_CASH'] + $fund_info['FUND_TYPE_GIFT']) {
                $this->error('转出余额超出上限');
            }
        }else{
            $this->error('转账方向参数异常');
        }
        return $wallet_info;
    }


    /**
     * 构建存储数据(计算扣款)
     * @param $walletList
     * @param $storeList
     * @param $post
     * @param $max_transfer_out
     * @return array
     */
    private function buildData($account,$post,$wallet_info){
        $insert_data = [
            'store_id' => $this->auth->id,
            'tencent_account_id' => $account['id'],
            'account_id' => $account['account_id'],
            'money' => $post['transfer_amount'],
            'deduction_credit_limit' => 0,
            'deduction_balance' => 0,
            "remark" => $post["remark"],
            'account_type' => $wallet_info['account_type'],
            'discount_percentage' => $wallet_info['wallet_discount'],
            'create_time' => time(),
        ];
        if($post['transfer_direction'] == 'ADVERTISER_TO_AGENCY'){
            $insert_data['transfer_direction'] = 2;
            if(!empty(floatval($wallet_info['wallet_discount']))) {
                list($real_rebate,$actualPer) = $this->RefundModel->getRealRefundRebate($insert_data);
                if (empty($real_rebate)) {
                    $real_rebate = round($insert_data["money"] - ($insert_data["money"] * 100) / ($insert_data['discount_percentage'] * 100), 2);
                }
                $insert_data['discount_percentage'] = $actualPer; // 获取实际退款比例
            }else{
                $real_rebate = 0;
            }
            $insert_data["rebate"] = $real_rebate;
            $insert_data['actual_money'] = $insert_data["money"];
        }
        elseif ($post['transfer_direction'] == 'AGENCY_TO_ADVERTISER'){
            $insert_data['transfer_direction'] = 1;
            if(!empty(floatval($wallet_info['wallet_discount']))){
                $insert_data['rebate'] = round($post['transfer_amount'] - ($post['transfer_amount'] * 100) / ($wallet_info['wallet_discount'] * 100), 2);
            }else{
                $insert_data['rebate'] = 0;
            }
            $insert_data['actual_money'] = $insert_data['money'] - $insert_data['rebate'];
            if($insert_data['actual_money'] > $wallet_info['wallet_money']){
                $insert_data['deduction_balance'] = $wallet_info['wallet_money'];
                $insert_data['deduction_credit_limit'] = $insert_data['actual_money'] - $wallet_info['wallet_money'];
                // 计算商户使用钱包、额度的返点记录
                $wallet_money = $wallet_info['wallet_money'];
                $credit_limit = $insert_data['money'] - $wallet_info['wallet_money'];
            }else{
                $insert_data['deduction_balance'] = $insert_data['actual_money'];
                // 计算商户使用钱包、额度的返点记录
                $wallet_money = $insert_data['money'];
                $credit_limit = 0;
            }
            $money = [
                'wallet' => $wallet_money,
                'credit' => $credit_limit,
            ];
            if(!empty(floatval($wallet_info['wallet_discount']))){
                $this->RefundModel->addStoreRefundRecord($money, $insert_data);
            }
        }else{
            $this->error('转账方向参数异常');
        }
        return $insert_data;
    }


    /**
     * 扣除余额
     * @param $store
     * @param $transfer_data
     * @return void
     * @throws Exception
     */
    private function deductMoney($store,$transfer_data){
        if($transfer_data['transfer_direction'] == 1){
            if($transfer_data['account_type'] == 1){
                $res = $this->TencentWalletModel->where([
                    'id'=>['=',$store['id']],
                    'public_money_tencent'=>['>=',$transfer_data['deduction_balance']],
                    'public_credit_limit_tencent'=>['>=',$transfer_data['deduction_credit_limit']]
                ])
                    ->dec('public_money_tencent',(float)$transfer_data['deduction_balance'])
                    ->dec('public_credit_limit_tencent',(float)$transfer_data['deduction_credit_limit'])
                    ->inc('public_spending_credit_limit_tencent',(float)$transfer_data['deduction_credit_limit']);
            }else{
                $res = $this->TencentWalletModel->where([
                    'id'=>['=',$store['id']],
                    'private_money_tencent'=>['>=',$transfer_data['deduction_balance']],
                    'private_credit_limit_tencent'=>['>=',$transfer_data['deduction_credit_limit']]
                ])
                    ->dec('private_money_tencent',(float)$transfer_data['deduction_balance'])
                    ->dec('private_credit_limit_tencent',(float)$transfer_data['deduction_credit_limit'])
                    ->inc('private_spending_credit_limit_tencent',(float)$transfer_data['deduction_credit_limit']);
            }
            if(!$res->update(["update_time" => time()])){
                throw new Exception('扣款失败');
            }
        }
    }

    /**
     * 发起转账
     * @param $post
     * @param $agent_balance_info
     * @param $balance_info
     * @return mixed
     */
    private function initiateTransfer($post, $agent_balance_info, $balance_info){
        switch ($post['transfer_direction']) {
            case 'AGENCY_TO_ADVERTISER':
                if ($agent_balance_info['FUND_TYPE_GIFT'] == 0){
                    $transfer = $this->sendRequest($post, $post['transfer_amount'], 'FUND_TYPE_CASH');
                    if ($transfer['code'] != 0){
                        return [false,[]];
                    }
                }
                else if ($post['transfer_amount'] > $agent_balance_info['FUND_TYPE_GIFT']){
                    $remaining_amount = $post['transfer_amount'] - $agent_balance_info['FUND_TYPE_GIFT'];
                    $first = $this->sendRequest($post, $agent_balance_info['FUND_TYPE_GIFT'], 'FUND_TYPE_GIFT');
                    if ($first['code'] != 0){
                        return [false,[]];
                    }
                    $second = $this->sendRequest($post, $remaining_amount, 'FUND_TYPE_CASH');
                    if ($second['data']['code'] != 0){
                        return [false, ['money' => $agent_balance_info['FUND_TYPE_GIFT'], 'transfer_type' => 1]];
                    }
                }elseif ($post['transfer_amount'] <= $agent_balance_info['FUND_TYPE_GIFT']){
                    $transfer = $this->sendRequest($post, $post['transfer_amount'], 'FUND_TYPE_GIFT');
                    if ($transfer['code'] != 0){
                        return [false,[]];
                    }
                }
                break;
            case 'ADVERTISER_TO_AGENCY':
                if ($balance_info['FUND_TYPE_CASH'] == 0){
                    $transfer = $this->sendRequest($post, $post['transfer_amount'], 'FUND_TYPE_GIFT');
                    if ($transfer['code'] != 0){
                        return [false,[]];
                    }
                }
                else if ($post['transfer_amount'] <= $balance_info['FUND_TYPE_CASH']){
                    $transfer = $this->sendRequest($post, $post['transfer_amount'], 'FUND_TYPE_CASH');
                    if ($transfer['code'] != 0){
                        return [false,[]];
                    }
                }
                elseif ($post['transfer_amount'] > $balance_info['FUND_TYPE_CASH']){
                    $remaining_amount = $post['transfer_amount'] - $balance_info['FUND_TYPE_CASH'];
                    $first = $this->sendRequest($post, $agent_balance_info['FUND_TYPE_CASH'], 'FUND_TYPE_CASH');
                    if ($first['code'] != 0){
                        return [false,[]];
                    }
                    $second = $this->sendRequest($post, $remaining_amount, 'FUND_TYPE_GIFT');
                    if ($second['data']['code'] != 0){
                        return [false, ['money' => $agent_balance_info['FUND_TYPE_CASH'], 'transfer_type' => 2, 'account_id' => $post['account_id']]];
                    }
                }
                break;
        }
        return true;
    }


    private function sendRequest($post, $money, $fund_type){
        return Fund::transfer([
            'account_id' => $post['account_id'],
            'fund_type' => $fund_type,
            'amount' => $money * 100,
            'transfer_type' => $post['transfer_direction'],
            'external_bill_no' => uniqid('hxsz-zz-'),
            'memo' => $post['remark'],
            'transfer_try_best' => 0,
            'high_frequency_transfer' => 0,
        ])['data'];
    }

    /**
     * 创建转账记录
     * @param $transfer_id
     * @param $insert_data
     * @param $store_info
     * @return bool
     * @throws Exception
     */
    private function createTransactionLog($transfer_id, $insert_data, $store_info){
        $money_log_data = [
            'store_id' => $store_info['store_id'],
            'tencent_account_id' => $insert_data['tencent_account_id'],
            'account_id' => $insert_data['account_id'],
            'transfer_log_id' => $transfer_id,
            'money' => $insert_data['money'],
            'account_type' =>$insert_data['account_type'],
            'rebate' => $insert_data['rebate'],
            'discount_percentage' => $insert_data['discount_percentage'],
            'create_time' => time()
        ];
        Db::startTrans();
        try {
            if ($insert_data['account_type'] == 1){
                $prefix = 'public_';
            }else{
                $prefix = 'private_';
            }
            if($insert_data['transfer_direction'] == 1){
                $money_log_data['actual_money'] = $insert_data['actual_money'];
                $money_log_data["deduction_balance"] = $insert_data["deduction_balance"];
                $money_log_data['deduction_credit_limit'] = $insert_data["deduction_credit_limit"];
                $money_log_data['type'] = 4;
                $money_log_data['explain'] = "转入腾讯广告账户[".$insert_data['account_id']."]，返点：".$insert_data['rebate']."，扣除余额：".$insert_data['deduction_balance']."，扣除授信额度：".$insert_data['deduction_credit_limit']."，实际扣除金额：".$insert_data['actual_money']."【单位：元】";
                $money_log_data['balance_surplus'] = $store_info[$prefix.'money_tencent'] - $insert_data['deduction_balance'];
                $money_log_data['credit_limit_surplus'] = $store_info[$prefix.'credit_limit_tencent'] - $insert_data['deduction_credit_limit'];
            }else{
                $money_log_data['type'] = 5;
                $money_log_data["actual_money"] = $insert_data["actual_money"] - $insert_data["rebate"];
                $money_log_data['explain'] = "腾讯广告账户[".$insert_data['account_id']."]转出，转出金额：".$insert_data['money']."，扣除返点：".$insert_data['rebate']."，预计到账金额：".$insert_data['actual_money'];
                if($store_info[$prefix.'spending_credit_limit_tencent'] >= $money_log_data['actual_money']){
                    $money = 0.00;
                    $credit_limit = (float)$money_log_data['actual_money'];
                    $spending_credit_limit = (float)$money_log_data['actual_money'];
                }else{
                    $money = (float)$money_log_data['actual_money'] - (float)$store_info[$prefix.'spending_credit_limit_tencent'];
                    $credit_limit = (float)$store_info[$prefix.'spending_credit_limit_tencent'];
                    $spending_credit_limit = (float)$store_info[$prefix.'spending_credit_limit_tencent'];
                }
                $sql = $this->TencentWalletModel->where([
                    'id'=>['=',$store_info['id']]
                ])
                    ->inc($prefix.'money_tencent',$money)
                    ->inc($prefix.'credit_limit_tencent',$credit_limit)
                    ->dec($prefix.'spending_credit_limit_tencent',$spending_credit_limit);
                $money_log_data["deduction_credit_limit"] = $spending_credit_limit;
                $money_log_data['explain'] .= "，归还已使用授信额度：".$spending_credit_limit."，实际到账金额：".$money."【单位：元】";
                $money_log_data['balance_surplus'] = $store_info[$prefix.'money_tencent'] + $money;
                $money_log_data['credit_limit_surplus'] = $store_info[$prefix.'credit_limit_tencent'] + $credit_limit;
                if(!$sql->update(["update_time" => time()])){
                    throw new Exception('金额变更失败');
                }
            }
            $logId = $this->TencentTransactionModel->insertGetId($money_log_data);
            if(!$logId){
                throw new Exception('金额变更记录失败');
            }
            Db::commit();
            return true;
        }catch (Exception $e){
            \think\Log::write('金额变更失败：'.$e->getMessage(),'error');
            Db::rollback();
            return false;
        }
    }


    private function rollbackMoney($array){
        if (!empty($array)){
            $bool = true;
            $i = 0;
            do {
                if ($array['account_type'] == 1){
                    // 发起转入就回滚转出
                    $res = Fund::transfer([
                        'account_id' => $array['account_id'],
                        'fund_type' => "FUND_TYPE_GIFT",
                        'amount' => $array['money'] * 100,
                        'transfer_type' => "ADVERTISER_TO_AGENCY",
                        'external_bill_no' => uniqid('hxsz-zz-'),
                        'memo' => "失败回退",
                        'transfer_try_best' => 0,
                        'high_frequency_transfer' => 0,
                    ])['data'];
                }else{
                    // 发起转出就回滚转入
                    $res = Fund::transfer([
                        'account_id' => $array['account_id'],
                        'fund_type' => "FUND_TYPE_CASH",
                        'amount' => $array['money'] * 100,
                        'transfer_type' => "AGENCY_TO_ADVERTISER",
                        'external_bill_no' => uniqid('hxsz-zz-'),
                        'memo' => "失败回退",
                        'transfer_try_best' => 0,
                        'high_frequency_transfer' => 0,
                    ])['data'];
                }
                if($res['code'] == 0 && $i < 3){
                    $bool = false;
                }else{
                    $i++;
                }
            } while ($bool);
        }
    }


}