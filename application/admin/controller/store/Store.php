<?php

namespace app\admin\controller\store;

use app\admin\model\Admin;
use app\common\controller\Backend;
use app\common\library\Auth;
use fast\Random;

use qywx\Api;
use think\Db;


use app\admin\model\ZhSubAccount;
use think\Exception;

/**
 * 商户管理
 *
 * @icon fa fa-user
 */
class Store extends Backend
{

    protected $relationSearch = true;
    protected $searchFields = 'id,username';

    /**
     * @var \app\admin\model\User
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = model('Store');
    }

    /**
     * 查看
     */
    public function index()
    {
        //设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            //如果发送的来源是Selectpage，则转发到Selectpage
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            $sort = input("sort","id");
            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);
            $search = input("search");
            $group_id = input("group_id");
            $where = [];
            if ($search){
                $where['username'] = ['like', "%{$search}%"];
            }
            if (!empty($group_id)){
                $where['group_id'] = $group_id;
            }


            $store_ids = $this->get_store_ids();
            if (is_array($store_ids)){
                if (empty($store_ids)){
                    return json(["total" => 0, "rows" => []]);
                }
                $where["id"] = ["in",$store_ids];
            }

            $list = Db::name("store")
                ->where($where)
                ->order($sort, $order)
                ->field("id,username,group_id,login_time,loginip,status,public_money,private_money,public_discount_percentage,private_discount_percentage,public_credit_limit,private_credit_limit,public_spending_credit_limit,private_spending_credit_limit,bank")
                ->limit($offset,$limit)
                ->select();

            $count = Db::name("store")->where($where)->count();

            foreach ($list as $k => $v) {
                $list[$k]['group'] = [];
                if ($v['group_id']){
                    $list[$k]['group'] = Db::name("store_group")->where("id",$v['group_id'])->find();
                }
                $list[$k]['sub_account'] = [];
                if($v['bank'] == 1){
                    $list[$k]['sub_account'] = Db::name("zh_sub_account")->where("store_id",$v['id'])->find();
                }
            }

            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        $group_list = Db::name("store_group")->field("id,name")->select();
        $this->assign('user_group_list',$group_list);
        return $this->view->fetch();
    }

    /**
     * 添加
     */
    public function add()
    {
        if ($this->request->isPost()) {
            $this->token();
            $data['username'] = input("username");
            $data['group_id'] = input("group_id",0);
            $password = input("password");
            if (empty($password)){
                $password = '123456';
            }
            $data['status'] = input("status",1);
            $data['public_discount_percentage'] = input("public_discount_percentage",1) < 1 ? 1 : input("public_discount_percentage",1);
            $data['private_discount_percentage'] = input("private_discount_percentage",1) < 1 ? 1 : input("private_discount_percentage",1);
            $data['salt'] = Random::alnum();
            $data['public_credit_limit'] = input("public_credit_limit",0);
            $data['private_credit_limit'] = input("private_credit_limit",0);
            $data['password'] = $this->auth->getEncryptPassword($password,$data['salt']);
            $data['create_time'] = time();
            $data['update_time'] = time();
            $admin_list = input("adminList/a");
            if (empty($admin_list)){
                $this->error("请选择绑定业务员");
            }
            $store_id = Db::name("store")->insertGetId($data);
            if ($store_id){
                $dataset = [];
                foreach ($admin_list as $value) {
                    $dataset[] = ['store_id' => $store_id, 'admin_id' => $value];
                }
                Db::name("store_admin_access")->insertAll($dataset);
                $this->success();
            }
            $this->error("添加失败，请检查用户名是否已存在");
        }

        $admin_model = model('Admin');

//        $sales_data = $admin_model
//            ->alias('a')
//            ->join('AuthGroupAccess aga', 'a.id = aga.uid')
//            ->join('AuthGroup ag', 'aga.group_id = ag.id')
//            ->where('ag.name', 'like', '%销售%')
//            ->column('a.id, a.nickname');

        $admin_data =$admin_model::admin_nickname();
        $this->view->assign('groupList', build_select('group_id', Db::name("store_group")->column('id,name'), 0, ['class' => 'form-control selectpicker','data-rule'=>'required']));
        $this->view->assign('adminList', build_select('adminList[]', $admin_data, 0, ['class' => 'form-control selectpicker','data-live-search'=>'true','data-rule'=>'required']));
//        $this->view->assign('salesList', build_select('sale_id', $sales_data, 0, ['class' => 'form-control selectpicker','data-live-search'=>'true', 'data-rule'=>'required','deselectAll'=>'']));

        return $this->view->fetch();
    }




    /**
     * 编辑
     */
    public function edit($ids = null)
    {
        if ($this->request->isPost()) {
            $this->token();
            $data['id'] = input("id");

            $data['username'] = input('username');
            $data['group_id'] = input("group_id",0);
            $data['public_discount_percentage'] = input("public_discount_percentage",1) < 1 ? 1 : input("public_discount_percentage",1);
            $data['private_discount_percentage'] = input("private_discount_percentage",1) < 1 ? 1 : input("private_discount_percentage",1);
            $data['status'] = input("status",0);
//            $data['public_credit_limit'] = input("public_credit_limit",0);
//            $data['private_credit_limit'] = input("private_credit_limit",0);
            if (empty($data['id'])){
                $this->error("数据异常，请刷新后重试");
            }
            $store = Db::name("store")->where("id",$data['id'])->find();
            if (empty($store)){
                $this->error("数据异常，请刷新后重试");
            }
            //加余额
            $public_add_money = input("public_add_money",0);
            $private_add_money = input("private_add_money",0);
            //扣余额
            $public_deduct_money = input("public_deduct_money",0);
            $private_deduct_money = input("private_deduct_money",0);
            //加额度（包含负数）
            $public_add_credit_money = input("public_add_credit_money",0);
            $private_add_credit_money= input("private_add_credit_money",0);
            //手动清账（得大于零）
            $public_clear_money = input("public_clear_money",0);
            $private_clear_money = input("private_clear_money",0);
            $clear_image = input("clear_image");
            if($private_clear_money<0 || $public_clear_money<0 ){
                $this->error("手动清账金额不能小于0");
            }
            if(($private_clear_money>0 || $public_clear_money>0) && empty($clear_image)){
                $this->error("请上传转账截图");
            }
            $admin_list = input("adminList/a");

            $money_log = [];

            // 启动事务
            Db::startTrans();
            try{
                if (!$this->add_money($public_add_money,$store,1,$money_log)){
                    throw new \Exception('赠款失败(公账)');
                }
                if (!$this->add_money($private_add_money,$store,2,$money_log)){
                    throw new \Exception('赠款失败(私账)');
                }

                if (!$this->deduct_money($public_deduct_money,$store,1,$money_log)){
                    throw new \Exception('扣款失败,余额不足(公账)');
                }

                if (!$this->deduct_money($private_deduct_money,$store,2,$money_log)){
                    throw new \Exception('扣款失败,余额不足(私账)');
                }

                if (!$this->editCreditMoney($public_add_credit_money,$store,1,$money_log)){
                    throw new \Exception('修改额度失败(公账)');
                }

                if (!$this->editCreditMoney($private_add_credit_money,$store,2,$money_log)){
                    throw new \Exception('修改额度失败(私账)');
                }

                if(!$this->manualClearMoney($public_clear_money,$store,1,$clear_image)){
                    throw new \Exception('手动清账失败(公账)');
                }

                if(!$this->manualClearMoney($private_clear_money,$store,2,$clear_image)){
                    throw new \Exception('手动清账失败(私账)');
                }

                Db::name("store")->update($data);
                if (!empty($money_log)){
                    if (!Db::name('store_money_log')->insertAll($money_log)){
                        throw new \Exception("日志写入失败");
                    }
                }
                Db::name("store_admin_access")->where("store_id",$data["id"])->delete();
                if (!empty($admin_list)){
                    $dataset = [];
                    foreach ($admin_list as $value) {
                        $dataset[] = ['store_id' => $data["id"], 'admin_id' => $value];
                    }
                    Db::name("store_admin_access")->insertAll($dataset);
                }else{
                    throw new \Exception("请选择绑定业务员");
                }

                // 提交事务
                Db::commit();
            } catch (\Exception $e) {
                // 回滚事务
                Db::rollback();
                $this->error($e->getMessage());
            }
            $this->success();
        }
        $row = Db::name("store")
            ->where("id",$ids)->find();
        $this->modelValidate = true;
        if (!$row) {
            $this->error(__('No Results were found'));
        }

        $admin_model = model('Admin');

        $admin_data = $admin_model::admin_nickname();
        $admin_ids = Db::name("store_admin_access")->where("store_id",$ids)->column("admin_id");
        $this->view->assign('adminList', build_select('adminList[]', $admin_data, $admin_ids, ['class'=>'form-control selectpicker', 'data-rule'=>'required','data-live-search'=>'true']));
        $this->view->assign("row",$row);
        $this->view->assign('groupList', build_select('group_id', Db::name("store_group")->column('id,name'), $row['group_id'], ['class' => 'form-control selectpicker']));

        return $this->view->fetch();
    }

    public function add_money($add_money,$store,$account_type,&$money_log){
        if (is_numeric($add_money) && $add_money > 0){
            $data = [
                'admin_id'=>$this->auth->id,
                'admin_username'=>$this->auth->username,
                'store_id' => $store['id'],
                'money' => $add_money,
                "account_type" => $account_type,
                'type' => 1,
                'explain' => '管理员'.$this->auth->username .'赠送'. $add_money .'元' . ($account_type == 1?'(公账钱包)':'(私账钱包)'),
                'create_time' => time(),
            ];

            if ($account_type == 1){
                if (!Db::name("store")->where('id',$store['id'])->setInc('public_money',$add_money)){
                    return false;
                }
                $data['before_money'] = $store['public_money'];
                $data['today_money'] = round($store['public_money'] + $add_money,2);
                $data['balance_surplus'] = round($store['public_money'] + $add_money,2);
            }else{
                if (!Db::name("store")->where('id',$store['id'])->setInc('private_money',$add_money)){
                    return false;
                }
                $data['before_money'] = $store['private_money'];
                $data['today_money'] = round($store['private_money'] + $add_money,2);
                $data['balance_surplus'] = round($store['private_money'] + $add_money,2);
            }

            $money_log[] = $data;
        }
        return true;
    }

    private function editCreditMoney($money, $store, $account_type, &$money_log)
    {
        if (is_numeric($money) ){
            // 判断额度是否足够扣
            $account_type == 1?$updateField = 'public_credit_limit':$updateField='private_credit_limit';
            if($money < 0 && (abs($money) > $store[$updateField]) ){
                return false;
            }
            $data = [
                'admin_id'=>$this->auth->id,
                'admin_username'=>$this->auth->username,
                'store_id' => $store['id'],
                'money' => abs($money),
                "account_type" => $account_type,
                'type' => 2,
                'explain' => '管理员'.$this->auth->username .($money>0?'增加':'扣除'). '额度'. abs($money) .'元' . ($account_type == 1?'(公账额度)':'(私账额度)'),
                'create_time' => time(),
            ];

            $updateMoney = $money > 0 ? $store[$updateField] + $money : $store[$updateField] - abs($money);

            if (!Db::name("store")->where('id',$store['id'])->update([$updateField=>$updateMoney])) {
                return false;
            }
            if($money>0){
                $data['credit_limit_surplus'] = round($store[$updateField] + $updateMoney,2);
            }else{
                $data['credit_limit_surplus'] = round($store[$updateField] - abs($money),2);
            }

            $money_log[] = $data;
        }
        return true;
    }


    /**
     * 手动清账
     * @param $money
     * @param $store
     * @param $account_type
     * @param string $clear_image
     * @return true
     * @throws Exception
     */
    private function manualClearMoney($money, $store, $account_type,$clear_image=''){
        if($money>0) {
            Db::startTrans();
            try {
                $actual_money = $money;
                $deduction_credit_limit = 0;
                if ($account_type == 1) {
                    $before_money_field = 'public_money';
                    $before_limit_field = 'public_credit_limit';
                    $handler_spending_field = 'public_spending_credit_limit';
                    $explain_field = "公账";
                } else {
                    $before_money_field = 'private_money';
                    $before_limit_field = 'private_credit_limit';
                    $handler_spending_field = 'private_spending_credit_limit';
                    $explain_field = "私账";
                }

                $before_money = $store[$before_money_field];
                $before_limit = $store[$before_limit_field];
                $explain = "充值" . $explain_field . "钱包" . $money . "元";
                if ($store[$handler_spending_field] > 0) {
                    $explain .= ",已使用" . $explain_field . "授信额度" . $store[$handler_spending_field] . "元,";
                    if ($store[$handler_spending_field] >= $money) {
                        if (!Db::name("store")->where(['id' => ["=", $store['id']], $handler_spending_field => ['>=', $money]])->setDec($handler_spending_field, $money)) {
                            throw new \Exception('扣除授信额度失败');
                        }
                        $actual_money = 0;
                        $deduction_credit_limit = $money;
                        $explain .= "扣除" . $money . "元";
                    } else {
                        $actual_money = $money - $store[$handler_spending_field];
                        $deduction_credit_limit = $store[$handler_spending_field];

                        Db::name("store")->where('id', $store['id'])->update([$handler_spending_field => 0]);
                        $explain .= "扣除" . $store[$handler_spending_field] . "元";
                    }
                    $explain .= "实际到账" . $actual_money . "元";
                    Db::name("store")->where("id", $store['id'])->setInc($before_limit_field, $deduction_credit_limit);
                }
                if ($actual_money > 0) {
                    Db::name("store")->where(['id' => $store['id']])->setInc($before_money_field, $actual_money);
                }

                Db::name("store_money_log")->insert([
                    "admin_id" => $this->auth->id,
                    "admin_username" => $this->auth->username,
                    "store_id" => $store["id"],
                    "username" => $store["username"],
                    "money" => $money,
                    "actual_money" => $actual_money,
                    "account_type" => $account_type,
                    "deduction_credit_limit" => $deduction_credit_limit,
                    "receipt_image" => $clear_image,
                    "before_money" => $before_money,
                    "today_money" => floatval($before_money) + floatval($actual_money),
                    "order_number" => "manual" . round(microtime(true) * 1000),
                    "type" => 3,
                    "explain" => $explain,
                    "create_time" => time(),
                    "balance_surplus" => (float)$before_money + (float)$actual_money,
                    "credit_limit_surplus" => (float)$before_limit + (float)$deduction_credit_limit
                ]);

                // 提交事务
                Db::commit();

            } catch (\Exception $e) {
                // 回滚事务
                Db::rollback();
                throw  new Exception($e->getMessage());
            }

            $user_ids = Db::name("financial_staff")->where(["state" => 1])->column("user_id");
            if (!empty($user_ids)) {
                $media_id = Api::media_upload(ROOT_PATH . "public".$clear_image);

                if (!empty($media_id)) {
                    $user_ids = implode("|", $user_ids);
                    Api::send_image_messages($user_ids, $media_id);
                }
            }
        }
        return true;
    }

    public function deduct_money($deduct_money,$store,$account_type,&$money_log){
        if (is_numeric($deduct_money) && $deduct_money > 0){
            if (($account_type == 1?$store['public_money']:$store['private_money']) - $deduct_money  < 0){
                return false;
            }
            $data = [
                'admin_id'=>$this->auth->id,
                'admin_username'=>$this->auth->username,
                'store_id' => $store['id'],
                'money' => $deduct_money,
                "account_type" => $account_type,
                'type' => 2,
                'explain' => '管理员'.$this->auth->username .'扣除余额'. $deduct_money .'元' . ($account_type == 1?'(公账钱包)':'(私账钱包)'),
                'create_time' => time(),
            ];
            $where['id'] = ['=',$store['id']];
            if ($account_type == 1){
                $where['public_money'] = ['>=',$deduct_money];
                if (!Db::name("store")->where($where)->setDec('public_money',$deduct_money)) {
                    return false;
                }
                $data['before_money'] = $store['public_money'];
                $data['today_money'] = round($store['public_money'] - $deduct_money,2);
                $data['balance_surplus'] = round($store['public_money'] - $deduct_money,2);
            }else{
                $where['private_money'] = ['>=',$deduct_money];
                if (!Db::name("store")->where($where)->setDec('private_money',$deduct_money)){
                    return false;
                }
                $data['before_money'] = $store['private_money'];
                $data['today_money'] = round($store['private_money'] - $deduct_money,2);
                $data['balance_surplus'] = round($store['private_money'] - $deduct_money,2);
            }
            $money_log[] = $data;
        }
        return true;
    }

    /**
     * 绑定银行子账户
     */
    public function bind_bank_sub_account(){
        if ($this->request->isPost()) {
            $this->token();
            $bank = input("bank");
            $data = input();
            switch ($bank){
                case 1:
                    $result = $this->validate($data, 'BindBankSubAccount.zhaohang');
                    if (true !== $result) {
                        $this->error($result);
                    }
                    $result1 = $this->bind_zh_sub_account($data);
                    if (!$result1){
                        $this->error('绑定失败');
                    }else{
                        Db::name('store')->where(['id' => $data['ids']])->update(['bank' => $bank]);
                    }
                    break;
            }
            $this->success('成功');
        }
        $zhApi = new \zhaohang\Api();
        $res = $zhApi->getOperationModel('N36090');     // 此处为交易管家编号，要传什么去查招行文档
        if($res){
            $res = json_decode($res,TRUE);
            $list = $res['response']['body']['ntqmdlstz'];
            $this->view->assign('busModList',$list);
        }else{
            $this->error('网络出现问题，请稍后再试！');
        }

        return $this->view->fetch('bind');
    }


    /**
     * 绑定招行子账户
     * @param $data
     * @return bool
     */
    public function bind_zh_sub_account($data){
        $zhApi = new \zhaohang\Api();
        $res = $zhApi->addChildAccount($data);
        $res = json_decode($res,TRUE);
        if($res['response']['head']['resultcode'] == 'SUC0000'){
            $insert['store_id'] = $data['ids'];
            $insert['bus_mod'] = $data['busmod'];
            $insert['branch_num'] = $data['bbknbr'];
            $insert['settle_account'] = $data['accnbr'];
            $insert['sub_account'] = $res['response']['body']['ntdmabadz1'][0]['dmanbr'];
            $insert['sub_name'] = $data['dmanam'];
            $insert['can_overdraw'] = $data['ovrctl'];
            $insert['return_method'] = $data['bcktyp'];
            $insert['can_off'] = $data['clstyp'];
            $insert['whether_limit'] = $data['lmtflg'];
            $insert['max_limit'] = $data['lmtflg'] == 'Y'? $data['ballmt'] : 0;
            $ZhSubAccountModel = new ZhSubAccount;
            $result = $ZhSubAccountModel->insert($insert);
            if($result){
                return true;
            }else{
                return false;
            }
        }else{
            return false;
        }
    }

    /**
     * 绑定子账号修改
     */
    public function edit_sub_account($ids=null){
        $ZhSubAccountModel = new ZhSubAccount;
        if ($this->request->isPost()) {
            $this->token();
            $bank = input("bank");
            $data = input();
            switch ($bank){
                case 1:
                    $result = $this->validate($data, 'BindBankSubAccount.zhaohang');
                    if (true !== $result) {
                        $this->error($result);
                    }
                    $result1 = $this->edit_zh_sub_account($data);
                    if (!$result1){
                        $this->error('修改失败');
                    }else{
                        Db::name('store')->where(['id' => $data['ids']])->update(['bank' => $bank]);
                    }
                    break;
            }
            $this->success('成功');
        }
        $zhApi = new \zhaohang\Api();
        $res = $zhApi->getOperationModel('N36090');     // 此处为交易管家编号，要传什么去查招行文档
        if($res){
            $res = json_decode($res,TRUE);
            $this->view->assign('busModList',$res['response']['body']['ntqmdlstz']);
        }else{
            $this->error('网络出现问题，请稍后再试！');
        }
        $bind_bank = Db::name('store')->where(['id' => $ids])->value('bank');
        $this->view->assign('bind_bank',$bind_bank);
        switch ($bind_bank){
            case 1:
                $info = $ZhSubAccountModel->where(['store_id' => $ids])->find();
                $this->view->assign('info',$info);
                break;
        }
        return $this->view->fetch();
    }

    /**
     * 招行修改子账户接口
     * @param $data
     * @return bool
     */
    public function edit_zh_sub_account($data){
        $zhApi = new \zhaohang\Api();
        $res = $zhApi->updateChildAccount($data);
        $res = json_decode($res,TRUE);
        if($res['response']['head']['resultcode'] == 'SUC0000'){
            $update['sub_name'] = $data['dmanam'];
            $update['can_overdraw'] = $data['ovrctl'];
            $update['return_method'] = $data['bcktyp'];
            $update['can_off'] = $data['clstyp'];
            $update['whether_limit'] = $data['lmtflg'];
            $update['max_limit'] = $data['lmtflg'] == 'Y'? $data['ballmt'] : 0;
            $ZhSubAccountModel = new ZhSubAccount;
            $result = $ZhSubAccountModel->where(['store_id' => $data['ids']])->update($update);
            if($result){
                return true;
            }else{
                return false;
            }
        }else{
            return false;
        }
    }





    /**
     * 关闭子账户（招行）
     */
    public function off_zh_sub_account($ids=Null){
        $ZhSubAccountModel = new ZhSubAccount;
        $info = $ZhSubAccountModel->where(['store_id' => $ids])->find();
        $zhApi = new \zhaohang\Api();
        $res = $zhApi->delChildAccount($info);
        $res = json_decode($res,TRUE);
        if($res['response']['head']['resultcode'] == 'SUC0000'){
            $result = $ZhSubAccountModel->where(['store_id' => $ids])->delete();
            if($result){
                Db::name('store')->where(['id' => $ids])->update(['bank' => 0]);
                $this->success('成功');
            }else{
                $this->error('删除子账户记录失败');
            }
        }else{
            $this->error('关闭子账户失败');
        }
    }


    /**
     * 重置密码
     */
    public function reset_pwd($ids = null){
        if ($this->request->isPost()) {
            $this->token();
            $password = input("password");
            // 验证密码长度最少6位
            if (strlen($password) < 6){
                $this->error('密码长度最少6位');
            }
            $data['salt'] = Random::alnum();
            $data['password'] = $this->auth->getEncryptPassword($password,$data['salt']);
            $row = Db::name("store")->where("id",$ids)->update($data);
            if ($row){
                $this->success("修改成功");
            }else{
                $this->error("修改失败");
            }
        }
        return $this->view->fetch();
    }





}
