<?php

namespace app\store\controller\tencent;

use app\common\controller\Store;
use app\common\model\txgg\TencentShareWallet;
use app\common\model\txgg\TencentStore;
use app\common\model\txgg\TencentRefund;
use app\common\model\txgg\TencentWalletTransferLog;
use app\common\model\txgg\TencentTransactionLog;
use think\Env;
use think\Exception;
use txgg\Fund;

use think\Db;

class TransferToWallet extends Store
{
    protected $fund_type = [
        'FUND_TYPE_CASH'                    => '现金账户',
        'FUND_TYPE_GIFT'                    => '赠送账户',
        'FUND_TYPE_SHARED'                  => '分成账户',
        'FUND_TYPE_BANK'                    => '专用现金账户',
        'FUND_TYPE_CREDIT_ROLL'             => '竞价信用账户',
        'FUND_TYPE_CREDIT_TEMPORARY'        => '竞价临时信用账户',
        'FUND_TYPE_COMPENSATE_VIRTUAL'      => '补偿虚拟金账户',
        'FUND_TYPE_INTERNAL_QUOTA'          => '内部领用金',
        'FUND_TYPE_TEST_VIRTUAL'            => '测试虚拟金账户'
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->WalletModel = new TencentShareWallet();
        $this->TencentWalletModel = new TencentStore();
        $this->RefundModel = new TencentRefund();
        $this->TransferModel = new TencentWalletTransferLog();
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

            $where['store_id'] = ['=', $this->auth->id];

            $list = $this->WalletModel
                ->with('store')
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();
            $count = $this->WalletModel->where($where)->count();
            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        return $this->fetch();
    }


    public function transfer($ids = null)
    {
        $wallet = $this->WalletModel->where('id', $ids)->find();
        $store_info = $this->TencentWalletModel->where('store_id', $this->auth->id)->lock(true)->find();
        if(empty($store_info)){
            $this->error('尚未设置腾讯广告账户，请联系管理员设置');
        }
        $res = Fund::getWalletBasicInfo([
            'account_id' => (int)Env::get('txgg.agency_'.$wallet['agency']),
            'wallet_id' => (int)$wallet['sub_wallet_id'],
        ])['data'];
        if ($res['code'] != 0){
            $this->error('腾讯广告接口异常，请稍后再试');
        }
        $agent_balance = Fund::getAgentFundInfo([
            'account_id' => (int)Env::get('txgg.agency_'.$wallet['agency']),
        ])['data'];
        if ($agent_balance['code'] != 0){
            $this->error('腾讯广告接口异常，请稍后再试');
        }
        $agent_balance_info = [];
        foreach ($agent_balance['data']['list'] as $item){
            $agent_balance_info[$item['fund_type']] = $item['balance'] / 100;
        }
        $balance = $res['data']['wallet_info']['balance_info_list'];
        foreach ($balance as &$item) {
            if (isset($this->fund_type[$item['fund_type']])) {
                $item['fund_type_text'] = $this->fund_type[$item['fund_type']];
            } else {
                $item['fund_type_text'] = $item['fund_type']; // 如果没有找到映射，保持原值
            }
        }
        unset($item); // 释放引用
        $last_transfer_info = $this->RefundModel->getSingleItem([
            'account_type' => $wallet['wallet_type'],
            'store_id' => $wallet['store_id'],
            'sub_wallet_id' => $wallet['sub_wallet_id']
        ],2);
        if(!empty($last_transfer_info)){
            $maxTTO = $last_transfer_info['wallet'] + $last_transfer_info['credit'];
        }
        if ($this->request->isPost()){
            $post = $this->request->post();
            if(isset($maxTTO) && $post['transfer_amount'] > $maxTTO && $post['transfer_direction'] == 'WALLET_TO_AGENCY'){
                $this->error('超出本次同比可退最大值，本次转出金额最大值：'.$maxTTO);
            }
            $wallet_info = $this->checkParam($post, $wallet, $store_info, $balance, $agent_balance_info);
            Db::startTrans();
            try{
                $ins = $this->buildData($post, $wallet_info, $wallet);
                $id = $this->TransferModel->insertGetId($ins);
                if (!$id){
                    throw new \Exception('转账失败');
                }
                $this->deductMoney($store_info,$ins);
                $transfer_result = $this->initiateTransfer($post,Env::get('txgg.agency_'.$wallet['agency']));
                if ($transfer_result['code'] != 0){
                    \think\Log::write($transfer_result, 'errorInfo');
                    throw new Exception('发起转账失败');
                }
                Db::commit();
            }catch (\Exception $e){
                Db::rollback();
                $this->error($e->getMessage());
            }
            $update = $this->TransferModel->update($id,
                [
                    'order_uid' => $transfer_result['data']['external_bill_no'],
                    'record' => json_encode($transfer_result['data'],JSON_UNESCAPED_UNICODE),
                    'update_time' => time()
                ]
            );
            if (!$update){
                $this->error('更新转账编号失败');
            }
            $bool = $this->createTransactionLog($id, $ins, $store_info);
            if($bool){
                //添加同步转账记录任务
                if($post['transfer_direction'] == 'AGENCY_TO_WALLET' ){
                    $name = "同步腾讯广告共享钱包充值记录";
                }else{
                    $name = "同步腾讯广告共享钱包退款记录";
                }
                $queueModel = new \app\common\model\Queue();
                $queueModel->addQueue($name,"app\job\SyncCharge",
                    "syncCharge",
                    ["log_id" => $id, 'data'=>$ins],
                    "tencent_wallet_transfer_log"
                );
                $this->success('转账成功');
            }else{
                $this->error('转账异常');
            }
        }
        $this->assign('store_info', $store_info);
        $this->assign('wallet_info', $wallet);
        $this->assign('balance', $balance);
        $this->assign('agent_balance_info', $agent_balance_info);
        return $this->fetch();
    }


    private function checkParam($post, $sub_wallet_info, $store_info, $balance, $agent_balance_info){
        if (!is_numeric($post['transfer_amount']) || $post['transfer_amount'] < 0) {
            $this->error("请输入正确金额");
        }
        $wallet_info['account_type']  = $sub_wallet_info['wallet_type']; // 账户类型
        if($sub_wallet_info['wallet_type'] == 1){
            $wallet_info['wallet_money'] = $store_info['public_money_tencent'];
            $wallet_info['wallet_limit'] = $store_info['public_credit_limit_tencent'];
            $wallet_info['wallet_discount'] = $store_info['public_discount_percentage_tencent'];
        }
        elseif ($sub_wallet_info['wallet_type'] == 2){
            $wallet_info['wallet_money'] = $store_info['private_money_tencent'];
            $wallet_info['wallet_limit'] = $store_info['private_credit_limit_tencent'];
            $wallet_info['wallet_discount'] = $store_info['private_discount_percentage_tencent'];
        }
        else{
            $this->error('账户类型异常，请刷新重试');
        }
        // 如果账户设置自定义折扣比例，则取自定义的比例
        if(!empty(floatval($sub_wallet_info['discount_percentage']))){
            $wallet_info['wallet_discount'] = $sub_wallet_info['discount_percentage'];
        }
        // 余额检查
        if ($post['transfer_direction'] == 'AGENCY_TO_WALLET'){
            if ($post['transfer_amount'] < 5000) {
                $this->error('最小转入金额为5000');
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
            if ($agent_balance_info[$post['fund_type']] < $post['transfer_amount']){
                $this->error('公用钱包备款不足，请联系管理员');
            }
        }elseif ($post['transfer_direction'] == 'WALLET_TO_AGENCY'){
            $can_transfer_out_money = 0;
            foreach ($balance as $item){
                if ($item['fund_type'] == $post['fund_type']){
                    $can_transfer_out_money = $item['balance'] / 100;
                }
            }
            if ($post['transfer_amount'] > $can_transfer_out_money) {
                $this->error('转出余额超出上限');
            }
        }else{
            $this->error('转账方向参数异常');
        }
        return $wallet_info;
    }



    private function buildData($post, $wallet_info, $wallet){
        $insert_data = [
            'store_id' => $this->auth->id,
            'tencent_wallet_id' => $wallet['id'],
            'sub_wallet_id' => $wallet['sub_wallet_id'],
            'money' => $post['transfer_amount'],
            'deduction_credit_limit' => 0,
            'deduction_balance' => 0,
            "remark" => $post["remark"],
            'account_type' => $wallet_info['account_type'],
            'discount_percentage' => $wallet_info['wallet_discount'],
            'create_time' => time(),
            'agency' => $wallet['agency']
        ];
        if ($post['transfer_direction'] == 'AGENCY_TO_WALLET'){
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
                $this->RefundModel->addStoreRefundRecord($money, $insert_data, 2);
            }
        }
        elseif ($post['transfer_direction'] == 'WALLET_TO_AGENCY'){
            $insert_data['transfer_direction'] = 2;
            if(!empty(floatval($wallet_info['wallet_discount']))) {
                list($real_rebate,$actualPer) = $this->RefundModel->getRealRefundRebate($insert_data, 2);
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
        else{
            $this->error('转账方向参数异常');
        }
        return $insert_data;
    }



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



    private function initiateTransfer($post,$account_id){
        return Fund::transferToShareWallet([
            'account_id' => (int)$account_id,
            'to_account_id' => $post['wallet_id'],
            'fund_type' => $post['fund_type'],
            'amount' => $post['transfer_amount'] * 100,
            'transfer_type' => $post['transfer_direction'],
            'external_bill_no' => uniqid('hxsz-gx-'),
            'memo' => $post['remark'],
            'transfer_try_best' => 0,
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
            'swtl_id' => $transfer_id,
            'sub_wallet_id' => $insert_data['sub_wallet_id'],
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
                $money_log_data['type'] = 8;
                $money_log_data['explain'] = "转入腾讯广告子钱包[".$insert_data['sub_wallet_id']."]，返点：".$insert_data['rebate']."，扣除余额：".$insert_data['deduction_balance']."，扣除授信额度：".$insert_data['deduction_credit_limit']."，实际扣除金额：".$insert_data['actual_money']."【单位：元】";
                $money_log_data['balance_surplus'] = $store_info[$prefix.'money_tencent'] - $insert_data['deduction_balance'];
                $money_log_data['credit_limit_surplus'] = $store_info[$prefix.'credit_limit_tencent'] - $insert_data['deduction_credit_limit'];
            }else{
                $money_log_data['type'] = 9;
                $money_log_data["actual_money"] = $insert_data["actual_money"] - $insert_data["rebate"];
                $money_log_data['explain'] = "腾讯广告子钱包[".$insert_data['sub_wallet_id']."]转出，转出金额：".$insert_data['money']."，扣除返点：".$insert_data['rebate']."，预计到账金额：".$insert_data['actual_money'];
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
}