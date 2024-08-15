<?php

namespace app\admin\controller\company;

use app\common\controller\Backend;
use app\common\library\Auth;
use fast\Random;
use jlqc\AccountRelationship;
use jlqc\UserInfo;
use think\Cache;
use think\Db;
use app\admin\model\Company as CompanyModel;

/**
 * 商户管理
 *
 * @icon fa fa-user
 */
class Company extends Backend
{

    protected $relationSearch = true;
    protected $searchFields = 'id,company_name,name';

    /**
     * @var \app\admin\model\User
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = model('Company');
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
            $filter = input("filter");

            $filter_data = json_decode($filter,true);


            $whereOr = [];
            $is_binding = input("is_binding");
            if (!empty($is_binding)){
                if ($is_binding == 1){
                    $whereOr['store_id'] = ['>', 0];
                }else{
                    $whereOr['store_id'] = ['=', 0];
                }
            }
            if (!empty($filter_data)){
                if (isset($filter_data["advertiser_id"])){
                    $advertiser_ids = explode(" ",$filter_data["advertiser_id"]);
                    $advertiser_ids = array_filter($advertiser_ids, function($value) {
                        return trim($value) !== "";
                    });
                    if (count($advertiser_ids) > 1){
                        $whereOr['advertiser_id'] = ['in',$advertiser_ids];
                    }else{
                        $whereOr['advertiser_id'] = ['=', $advertiser_ids[0]];
                    }
                }

                if (isset($filter_data["company_name"])){
                    $whereOr['company_name'] = ['like', "%" . $filter_data["company_name"] . "%"];
                }

                if (isset($filter_data["store_name"])){
                    $store_id = Db::name("store")->where(["username" => $filter_data["store_name"]])->value("id");
                    $whereOr['store_id'] = ['=', $store_id];
                }
            }

            $where = [];
            $store_ids = $this->get_store_ids();
            if (is_array($store_ids)){
                if (empty($store_ids)){
                    return json(["total" => 0, "rows" => []]);
                }
                $where["store_id"] = ["in",$store_ids];
            }

            $list = CompanyModel::with(['store'])
                ->where($where)
                ->whereOr($whereOr)
                ->order($sort, $order)
                ->field("id,advertiser_id,store_id,company_name,name,first_industry_name,second_industry_name,account_type")
                ->limit($offset,$limit)
                ->select();

            $count = Db::name("company")
                ->where($where)
                ->whereOr($whereOr)
                ->count();


            foreach ($list as $k => $v) {
                $list[$k]['store'] = [];
                if ($v['store_id']){
                    $list[$k]['store'] = Db::name("store")->where("id",$v['store_id'])->field("id,username")->find();
                }
            }

            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }



    /**
     * 编辑
     */
    public function edit($ids = null)
    {
        if ($this->request->isPost()) {
            $this->token();
            $id = input("id");
            $data['account_type'] = input("account_type");
            $data['store_id'] = input("store_id");
            if (Db::name("company")->where("id",$id)->update($data)){
                $this->success();
            }
            $this->error();
        }


        $row = Db::name("company")
            ->where("id",$ids)
            ->field("id,store_id,account_type")
            ->find();
        $this->modelValidate = true;
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $this->view->assign("row",$row);

        $this->view->assign('storeList', build_select('store_id', [0=>"不绑定"]+Db::name("store")->column('id,username'), $row['store_id'], ['class' => 'form-control selectpicker']));

        return $this->view->fetch();
    }

    public function batch_binding(){
        if ($this->request->isPost()) {
            $this->token();
            $company_ids = input("company_ids");
            $data['store_id'] = input("store_id");
            $data['account_type'] = input("account_type");
            if (Db::name("company")->where(["id"=>["in",$company_ids]])->update($data)){
                $this->success();
            }
            $this->error();
        }
        $store_data = Db::name("store")->column('id as store_id,username');
        $store_data[0] = "不绑定";
        $this->view->assign('storeList', build_select('store_id', $store_data, 0, ['class' => 'form-control selectpicker']));

        return $this->view->fetch();
    }





}
