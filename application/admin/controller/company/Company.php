<?php

namespace app\admin\controller\company;

use app\common\controller\Backend;
use app\common\library\Auth;
use fast\Random;
use jlqc\AccountRelationship;
use jlqc\UserInfo;
use jlqc\FundManagement;
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
                    $store_id = Db::name("store")->where(["username" =>['like',"%". $filter_data["store_name"]."%"]])->value("id");
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
                ->field("id,advertiser_id,store_id,company_name,name,first_industry_name,second_industry_name,account_type,discount_percentage")
                ->limit($offset,$limit)
                ->select();

            $count = Db::name("company")
                ->where($where)
                ->whereOr($whereOr)
                ->count();

//            foreach ($list as $k => $v) {
//
//                $list[$k]['store'] = [];
//                if ($v['store_id']){
//                    $list[$k]['store'] = Db::name("store")->where("id",$v['store_id'])->field("id,username")->find();
//                }
//            }

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
            $data['discount_percentage'] = number_format(input("discount_percentage"), 4, '.', '');
            if (Db::name("company")->where("id",$id)->update($data)){
                $this->success();
            }
            $this->error();
        }


        $row = Db::name("company")
            ->where("id",$ids)
            ->field("id,store_id,account_type,discount_percentage")
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
            $data['discount_percentage'] = number_format(input("discount_percentage"), 4, '.', '');
            if (Db::name("company")->where(["id"=>["in",$company_ids]])->update($data)){
                $this->success();
            }
            $this->error();
        }
        $store_data = Db::name("store")->column('id as store_id,username');
        $store_data[0] = "不绑定";
        $this->view->assign('storeList', build_select('store_id', $store_data, 0, ['class' => 'form-control selectpicker' ,'data-live-search'=>'true']));

        return $this->view->fetch();
    }


    public function bind_by_qc_id(){
        if ($this->request->isPost()) {
            $err_num = 0;
            $err_id = '';
            $public_qc_id_list = [];
            $private_qc_id_list = [];
            $this->token();
            $post = $this->request->post();
            $post['discount_percentage'] = number_format($post['discount_percentage'], 4, '.', '');
            if(empty($post['store_id'])){
                $this->error('请选择绑定账号');
            }
            if(empty($post['public_qc_id']) && empty($post['private_qc_id'])){
                $this->error('空提交');
            }
            if(!empty($post['public_qc_id'])){
                $public_qc_id_list = array_filter(explode("\n",$post['public_qc_id']), function($value) {
                    return trim($value) !== '';
                });
                $public_qc_id_list = array_combine($public_qc_id_list,array_fill(0,count($public_qc_id_list),1));
            }
            if (!empty($post['private_qc_id'])){
                $private_qc_id_list = array_filter(explode("\n",$post['private_qc_id']), function($value) {
                    return trim($value) !== '';
                });
                $private_qc_id_list = array_combine($private_qc_id_list,array_fill(0,count($private_qc_id_list),2));
            }
            $qc_id_list = $public_qc_id_list + $private_qc_id_list;
            foreach ($qc_id_list as $k=>$v){
                $k = trim($k);
                if (!Db::name('company')->where(["advertiser_id"=>$k])->update(['store_id' => $post['store_id'], 'account_type' => $v, 'discount_percentage' => $post['discount_percentage']])){
                    $err_num++;
                    $err_id .= $k.",";
                }
            }
            if($err_num != 0){
                $this->error("部分成功，失败了".$err_num."次，绑定失败的ID为：".$err_id);
            }else{
                $this->success("批量绑定成功");
            }
        }
        $this->view->assign('storeList', Db::name("store")->field('id,username')->select());
        return $this->view->fetch();
    }

    public function query_grant($ids = null){
        if ($this->request->isPost()) {
            $no_grant_sum = 0;
            $grant_sum = 0;
            $this->token();
            // 判断
            $stare_time = strtotime($_POST['start_time']);
            $end_time = strtotime($_POST['end_time']);
            if($end_time < $stare_time){
                $this->error("开始日期不能大于结束日期");
            }
            if($end_time >= strtotime('today') || $stare_time >= strtotime('today')){
                $this->error("可选日期范围是今天以前");
            }
            $difference = ($end_time - $stare_time) / (60 * 60 * 24);
            if($difference > 365){
                $this->error("开始时间与结束时间的跨度不能超过365天。");
            }

            $access_token = Cache::get("qc_access_token");
            $advertiser_id = Db::name("company")->where("id",$ids)->value("advertiser_id");
            $agent_id = Db::name("qc_config")->where("id",1)->value("advertiser_id");
            $data = FundManagement::get_agent_statement($access_token,$agent_id, date("Y-m-d",$stare_time), date("Y-m-d",$end_time),1,100,(int)$advertiser_id);
            $total_page = ceil($data['data']['page_info']['total_number']/$data['data']['page_info']['page_size']);
            for($i=1;$i<=$total_page;$i++){
                $data = FundManagement::get_agent_statement($access_token,$agent_id, date("Y-m-d",$stare_time), date("Y-m-d",$end_time),$i,100,(int)$advertiser_id);
                foreach ($data['data']['list'] as $v){
                    $no_grant_sum += $v['no_grant_cost'] / 100000;
                    $grant_sum += $v['cost'] / 100000;
                }
            }
            $this->error("查询成功，该账号[".$advertiser_id."]<br>非赠款消耗为：".$no_grant_sum."元<br>总消耗为：".$grant_sum."元");
        }
        return $this->view->fetch();
    }





}
