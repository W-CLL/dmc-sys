<?php

namespace app\store\controller;

use app\common\controller\Store;
use jlqc\FundManagement;
use think\Cache;
use think\Exception;
use think\Db;
use app\store\model\QcShareWallet as WalletModel;
use app\store\model\QcConfig as QcConfigModel;
use app\store\model\Store as StoreModel;
use app\store\model\ShareWalletTransferLog as TransferLogModel;
use app\store\model\StoreRefund as RefundModel;
use app\store\model\StoreMoneyLog as StoreMoneyLogModel;
use think\Request;


class SubWallet extends Store
{
    public function _initialize()
    {
        parent::_initialize();
        $this->WalletModel = new WalletModel();
        $this->QcConfigModel = new QcConfigModel();
        $this->StoreModel = new StoreModel();
        $this->TransferLogModel = new TransferLogModel();
        $this->RefundModel = new RefundModel();
        $this->StoreMoneyLogModel = new StoreMoneyLogModel();
        $this->token = Cache::get("qc_access_token");
        $this->account_id = $this->QcConfigModel->where("id",1)->value("advertiser_id");
        $this->account_type = 'AGENT';
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

            $where['bind_store_id'] = ['=',$this->auth->id];


            $list = $this->WalletModel
                ->with('store')
                ->where($where)
                ->field("id,sub_wallet_id,bind_store_id,sub_wallet_type")
                ->order($sort, $order)
                ->limit($offset,$limit)
                ->select();
            $count = $this->WalletModel->where($where)->count();
            $list_info = array_column($list,'sub_wallet_id');
            $list_info = array_map(function($value) {
                return (int)$value;
            }, $list_info);
            $list_info = json_encode($list_info);
            if(!empty($list_info)){
                $res = FundManagement::get_wallet_info_list($this->token,$this->account_id,$list_info,$this->account_type);
                if($res['code'] == 0) {
                    foreach ($res['data']['wallet_info'] as $v) {
                        foreach ($list as $k => $item) {
                            if ($item['sub_wallet_id'] == $v['wallet_id']) {
                                $k = $k;
                                break;
                            }
                        }
                        $list[$k]['sub_wallet_name'] = $v['common_wallet_info']['wallet_name'];
                        $list[$k]['main_wallet_id'] = $v['sub_wallet_info']['main_wallet_id'];
                        $list[$k]['adv_cnt'] = $v['sub_wallet_info']['adv_cnt'];
                        $list[$k]['create_time'] = strtotime($v['common_wallet_info']['create_time']);
                    }
                }
            }
            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }


    /**
     * 子钱包转账
     */
    public function transfer_money($ids = null){
        $wallet = $this->WalletModel->where(['id' => $ids])->find();
        $store = $this->StoreModel->where(['id' => $this->auth->id])
            ->field('id,public_money,private_money,public_credit_limit,private_credit_limit,public_spending_credit_limit,private_spending_credit_limit,public_discount_percentage,private_discount_percentage')
            ->lock(true)
            ->find();
        $res = FundManagement::get_max_transfer($this->token,$this->account_id,$this->account_type,$this->generateRandomString(),$wallet['main_wallet_id'],json_encode([(int)$wallet['sub_wallet_id']]),'TRANSFER_OUT');
        $max_transfer_out = $res['data']['can_transfer_detail_list'][0]['non_brand_max_transfer_balance'] / 100;
        if ($this->request->isPost()) {
            $post = $this->request->post();
            $wallet_info = $this->checkParam($wallet,$store,$post,$max_transfer_out);
            Db::startTrans();
            try {
                $insert_data = $this->buildData($wallet,$post,$wallet_info);
                if(!$this->TransferLogModel->insert($insert_data)){
                    throw new Exception('添加转账记录失败');
                }
                $this->deductMoney($store,$insert_data);
                $result = $this->initiateTransfer($post);
                if($result['code'] != 0 && $result['message'] != 'OK'){
                    throw new Exception($result['message']);
                }
                $update_data = [
                    'record' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    'transfer_serial' => $result['data']['transfer_serial'],
                    'status' => 1,
                    'update_time' => time()
                ];
                $this->TransferLogModel->where(['id' => $this->TransferLogModel->getLastInsID()])->update($update_data);
                Db::commit();
            }catch (\Exception $e){
                Db::rollback();
                $this->error($e->getMessage());
            }
            $this->createStoreMoneyLog($this->TransferLogModel->getLastInsID(),$post,$insert_data,$store);
            $this->success('转账成功');
        }
        $this->view->assign('storeList', $store);
        $this->view->assign('walletList', $wallet);
        $this->view->assign('maxTransferOutNum', $max_transfer_out);
        return $this->view->fetch();
    }


    /**
     * 检查参数
     */
    private function checkParam($walletList,$storeList,$post,$max_transfer_out){
        if (!is_numeric($post['transfer_amount']) || $post['transfer_amount'] < 0) {
            $this->error("请输入正确金额");
        }
        if($walletList['sub_wallet_type'] == 1){
            $wallet_info['wallet_money'] = $storeList['public_money'];
            $wallet_info['wallet_limit'] = $storeList['public_credit_limit'];
            $wallet_info['wallet_discount'] = $storeList['public_discount_percentage'];
        }
        elseif ($walletList['sub_wallet_type'] == 2){
            $wallet_info['wallet_money'] = $storeList['private_money'];
            $wallet_info['wallet_limit'] = $storeList['private_credit_limit'];
            $wallet_info['wallet_discount'] = $storeList['private_discount_percentage'];
        }
        else{
            $this->error('该子钱包类型不支持转账');
        }
        if($post['transfer_direction'] == 'TRANSFER_OUT'){
            if($max_transfer_out < $post['transfer_amount']){
                $this->error('转出金额超出上限');
            }
        }
        elseif ($post['transfer_direction'] == 'TRANSFER_IN'){
            $rebate = round($post['transfer_amount'] - ($post['transfer_amount'] * 100) / ($wallet_info['wallet_discount'] * 100), 2);
            if (($post['transfer_amount'] - $rebate) > ($wallet_info['wallet_money'] + $wallet_info['wallet_limit'])) {
                $this->error('转入余额超出上限');
            }
        }
        else{
            $this->error('转账类型参数错误');
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
    private function buildData($walletList,$post,$wallet_info){
        $insert_data = [
            'store_id' => $this->auth->id,
            'sub_wallet_id' => $walletList['sub_wallet_id'],
            'main_wallet_id' => $walletList['main_wallet_id'],
            'money' => $post['transfer_amount'],
            'deduction_credit_limit' => 0,
            'deduction_balance' => 0,
            'status' => 0,
            'account_type' => $walletList['sub_wallet_type'],
            'discount_percentage' => $wallet_info['wallet_discount'],
            'create_time' => time(),
        ];
        if($post['transfer_direction'] == 'TRANSFER_OUT'){
            $insert_data['transfer_direction'] = 2;
            $real_rebate = $this->RefundModel->getRealRefundRebate($insert_data,2);
            if (empty($real_rebate)) {
                $real_rebate = round($insert_data["money"] - ($insert_data["money"] * 100) / ($insert_data['discount_percentage'] * 100), 2);
            }
            $insert_data["rebate"] = $real_rebate;
            $insert_data['actual_money'] = $insert_data["money"];
        }
        else{
            $insert_data['transfer_direction'] = 1;
            $insert_data['rebate'] = round($post['transfer_amount'] - ($post['transfer_amount'] * 100) / ($wallet_info['wallet_discount'] * 100), 2);
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
            $this->RefundModel->addStoreRefundRecord($money, $insert_data, 2);
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
                $res = $this->StoreModel->where([
                        'id'=>['=',$store['id']],
                        'public_money'=>['>=',$transfer_data['deduction_balance']],
                        'public_credit_limit'=>['>=',$transfer_data['deduction_credit_limit']]
                    ])
                    ->dec('public_money',(float)$transfer_data['deduction_balance'])
                    ->dec('public_credit_limit',(float)$transfer_data['deduction_credit_limit'])
                    ->inc('public_spending_credit_limit',(float)$transfer_data['deduction_credit_limit']);
            }else{
                $res = $this->StoreModel->where([
                        'id'=>['=',$store['id']],
                        'private_money'=>['>=',$transfer_data['deduction_balance']],
                        'private_credit_limit'=>['>=',$transfer_data['deduction_credit_limit']]
                    ])
                    ->dec('private_money',(float)$transfer_data['deduction_balance'])
                    ->dec('private_credit_limit',(float)$transfer_data['deduction_credit_limit'])
                    ->inc('private_spending_credit_limit',(float)$transfer_data['deduction_credit_limit']);
            }
            if(!$res->update(["update_time" => time()])){
                throw new Exception('扣款失败');
            }
        }
    }


    /**
     * 发起转账
     * @param $post
     * @return mixed
     */
    private function initiateTransfer($post){
        $access_token = $this->token;
        $account_id = $this->account_id;
        $account_type = $this->account_type;
        $biz_request_no = $this->generateRandomString();
        $main_wallet_id = $post['main_wallet_id'];
        $target_wallet_detail_list = [
            [
                'sub_wallet_id' => (int)$post['sub_wallet_id'],
                'transfer_capital_detail_list' => [
                    [
                        'capital_type' => 'PREPAY_GENERAL',
                        'platform' => 'QIANCHUAN',
                        'transfer_amount' => (float)$post['transfer_amount'] * 100
                    ]
                ]
            ]
        ];
        $transfer_direction = $post['transfer_direction'];
        $remark = $post['remark'];
        return FundManagement::wallet_transfer($access_token, $account_id, $account_type, $biz_request_no, $main_wallet_id, $target_wallet_detail_list, $transfer_direction, $remark);
    }


    /**
     * 创建用户动账记录
     * @param $swtl_id
     * @param $post
     * @param $transfer_data
     * @param $store_data
     * @return void
     */
    private function createStoreMoneyLog($swtl_id,$post,$transfer_data,$store_data){
        $money_log_data = [
            'store_id' => $store_data['id'],
            'swtl_id' => $swtl_id,
            'money' => $post['transfer_amount'],
            'rebate' => $transfer_data['rebate'],
            'discount_percentage' => $transfer_data['discount_percentage'],
            'create_time' => time()
        ];
        Db::startTrans();
        try{
            if($transfer_data['transfer_direction'] == 1){
                $money_log_data['actual_money'] = $transfer_data['actual_money'];
                $money_log_data["deduction_balance"] = $transfer_data["deduction_balance"];
                $money_log_data['deduction_credit_limit'] = $transfer_data["deduction_credit_limit"];
                $money_log_data['type'] = 8;
                $money_log_data['explain'] = "转入子钱包[".$transfer_data['sub_wallet_id']."]，返点：".$transfer_data['rebate']."，扣除余额：".$transfer_data['deduction_balance']."，扣除授信额度：".$transfer_data['deduction_credit_limit']."，实际扣除金额：".$transfer_data['actual_money']."【单位：元】";
            }else{
                $money_log_data['type'] = 9;
                $money_log_data["actual_money"] = $transfer_data["actual_money"] - $transfer_data["rebate"];
                $money_log_data['explain'] = "子钱包[".$transfer_data['sub_wallet_id']."]转出，转出金额：".$transfer_data['money']."，扣除返点：".$transfer_data['rebate']."，预计到账金额：".$transfer_data['actual_money'];
                if($transfer_data['account_type'] == 1){
                    if($store_data['public_spending_credit_limit'] >= $money_log_data['actual_money']){
                        $public_money = 0.00;
                        $public_credit_limit = (float)$money_log_data['actual_money'];
                        $public_spending_credit_limit = (float)$money_log_data['actual_money'];
                    }else{
                        $public_money = (float)$money_log_data['actual_money'] - (float)$store_data['public_spending_credit_limit'];
                        $public_credit_limit = (float)$store_data['public_spending_credit_limit'];
                        $public_spending_credit_limit = (float)$store_data['public_spending_credit_limit'];
                    }
                    $res = $this->StoreModel->where([
                            'id'=>['=',$store_data['id']]
                        ])
                        ->inc('public_money',$public_money)
                        ->inc('public_credit_limit',$public_credit_limit)
                        ->dec('public_spending_credit_limit',$public_spending_credit_limit);
                    $money_log_data["deduction_credit_limit"] = $public_spending_credit_limit;
                    $money_log_data['explain'] .= "，归还已使用授信额度：".$public_spending_credit_limit."，实际到账金额：".$public_money."【单位：元】";
                }else{
                    if($store_data['private_spending_credit_limit'] >= $money_log_data['actual_money']){
                        $private_money = 0;
                        $private_credit_limit = (float)$money_log_data['actual_money'];
                        $private_spending_credit_limit = (float)$money_log_data['actual_money'];
                    }else{
                        $private_money = (float)$money_log_data['actual_money'] - (float)$store_data['private_spending_credit_limit'];
                        $private_credit_limit = (float)$store_data['private_spending_credit_limit'];
                        $private_spending_credit_limit = (float)$store_data['private_spending_credit_limit'];
                    }
                    $res = $this->StoreModel->where([
                            'id'=>['=',$store_data['id']]
                        ])
                        ->inc('private_money',$private_money)
                        ->inc('private_credit_limit',$private_credit_limit)
                        ->dec('private_spending_credit_limit',$private_spending_credit_limit);
                    $money_log_data["deduction_credit_limit"] = $private_spending_credit_limit;
                    $money_log_data['explain'] .= "，归还已使用授信额度：".$private_spending_credit_limit."，实际到账金额：".$private_money."【单位：元】";
                }
                if(!$res->update(["update_time" => time()])){
                    throw new Exception('金额变更失败');
                }
            }
            if(!$this->StoreMoneyLogModel->insert($money_log_data)){
                throw new Exception('金额变更记录失败');
            }
            Db::commit();
        }catch (\Exception $e){
            Db::rollback();
            $this->error($e->getMessage());
        }
    }


    /**
     * 生成随机字符串
     * @param $length
     * @param $type (0:数字字母混合 1:数字 2：字母)
     * @return string
     */
    protected function generateRandomString($length = 10, $type = 0)
    {
        if ($type == 0) {
            $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        } elseif ($type == 1) {
            $characters = '0123456789';
        } elseif ($type == 2) {
            $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        }
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }


    /**
     * 获取实际动账金额
     * @param Request $request
     * @return \think\response\Json
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function get_actual_money(Request $request){
        $res_msg = '';
        $direction = $request->param('direction');
        $amount = $request->param('amount');
        $wallet_info = $this->WalletModel
            ->where(['id'=>$request->param('sub_wallet_id')])
            ->find();
        $store_info = $this->StoreModel
            ->where(['id'=>$this->auth->id])
            ->field('id,public_discount_percentage,private_discount_percentage')
            ->find();
        if($wallet_info['sub_wallet_type'] == 1){
            $wallet['wallet_discount'] = $store_info['public_discount_percentage'];
        }else{
            $wallet['wallet_discount'] = $store_info['private_discount_percentage'];
        }
        if($direction == 'TRANSFER_IN'){
            $rebate = round($amount - ($amount * 100) / ($wallet['wallet_discount'] * 100), 2);
            $actual_money = $amount - $rebate;
            $res_msg = '预计从您钱包扣除金额: '.$actual_money.' 元';
        }
        elseif($direction == 'TRANSFER_OUT'){
            $data = [
                'money' => $amount,
                'transfer_direction' => 2,
                'discount_percentage' => $wallet['wallet_discount'],
                'store_id' => $this->auth->id,
                'account_type' => $wallet_info['sub_wallet_type']
            ];
            $rebate = $this->RefundModel->getRealRefundRebate($data,2,false);
            if (empty($rebate)) {
                $rebate = round($amount - ($amount * 100) / ($wallet['wallet_discount'] * 100), 2);
            }
            $actual_money = $amount - $rebate;
            $res_msg = '预计给您钱包增加金额: '.$actual_money.' 元';
        }
        if(!empty($res_msg)){
            return json(['code' => 1,'msg'=> '成功', 'data' => $res_msg]);
        }else{
            return json(['code' => 0,'msg'=> '失败', 'data' => '']);
        }

    }
}