<?php

namespace app\admin\controller\store;

use app\admin\model\Admin;
use app\common\controller\Backend;
use app\common\library\Auth;
use fast\Random;
use jlqc\AccountRelationship;
use jlqc\UserInfo;
use think\Cache;
use think\Db;
use think\Validate;

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
            $where = [];
            if ($search){
                $where['username'] = ['like', "%{$search}%"];
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
                ->field("id,username,group_id,login_time,loginip,status,public_money,private_money,public_discount_percentage,private_discount_percentage,public_credit_limit,private_credit_limit,public_spending_credit_limit,private_spending_credit_limit")
                ->limit($offset,$limit)
                ->select();

            $count = Db::name("store")->where($where)->count();

            foreach ($list as $k => $v) {
                $list[$k]['group'] = [];
                if ($v['group_id']){
                    $list[$k]['group'] = Db::name("store_group")->where("id",$v['group_id'])->find();
                }
            }

            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
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
            $data['public_discount_percentage'] = input("public_discount_percentage",0);
            $data['private_discount_percentage'] = input("private_discount_percentage",0);
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
        $admin_data = Admin::admin_nickname();
        $this->view->assign('groupList', build_select('group_id', Db::name("store_group")->column('id,name'), 0, ['class' => 'form-control selectpicker']));
        $this->view->assign('adminList', build_select('adminList[]', $admin_data, 0, ['class' => 'form-control selectpicker']));

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

            $data['group_id'] = input("group_id",0);
            $data['public_discount_percentage'] = input("public_discount_percentage",0);
            $data['private_discount_percentage'] = input("private_discount_percentage",0);
            $data['status'] = input("status",0);
            $data['public_credit_limit'] = input("public_credit_limit",0);
            $data['private_credit_limit'] = input("private_credit_limit",0);
            if (empty($data['id'])){
                $this->error("数据异常，请刷新后重试");
            }
            $store = Db::name("store")->where("id",$data['id'])->find();
            if (empty($store)){
                $this->error("数据异常，请刷新后重试");
            }

            $public_add_money = input("public_add_money",0);
            $private_add_money = input("private_add_money",0);
            $public_deduct_money = input("public_deduct_money",0);
            $private_deduct_money = input("private_deduct_money",0);
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
            ->where("id",$ids)
            ->field("id,admin_id,group_id,username,login_time,loginip,status,public_money,private_money,public_discount_percentage,private_discount_percentage,public_credit_limit,private_credit_limit")->find();
        $this->modelValidate = true;
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $admin_data = Admin::admin_nickname();
        $admin_ids = Db::name("store_admin_access")->where("store_id",$ids)->column("admin_id");
        $this->view->assign('adminList', build_select('adminList[]', $admin_data, $admin_ids, ['class'=>'form-control selectpicker', 'multiple'=>'', 'data-rule'=>'required','data-live-search'=>'true']));
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
            }else{
                if (!Db::name("store")->where('id',$store['id'])->setInc('private_money',$add_money)){
                    return false;
                }
                $data['before_money'] = $store['private_money'];
                $data['today_money'] = round($store['private_money'] + $add_money,2);
            }

            $money_log[] = $data;
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
            }else{
                $where['private_money'] = ['>=',$deduct_money];
                if (!Db::name("store")->where($where)->setDec('private_money',$deduct_money)){
                    return false;
                }
                $data['before_money'] = $store['private_money'];
                $data['today_money'] = round($store['private_money'] - $deduct_money,2);
            }
            $money_log[] = $data;
        }
        return true;
    }



}
