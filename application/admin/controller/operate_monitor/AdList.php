<?php

namespace app\admin\controller\operate_monitor;

use app\common\controller\Backend;
use app\admin\model\Operator as OperatorModel;
use app\admin\model\PlanOptLog as PlanOptLogModel;
use app\admin\model\QcObj as ObjModel;
use app\admin\model\Company as CompanyModel;
use jlqc\FundManagement;
use think\Cache;
use think\Db;


class AdList extends Backend
{
    public function index()
    {
        $companyModel = new CompanyModel();
        $access_token = Cache::get("qc_access_token");
        if ($this->request->isAjax()) {
            $where = [];
//            $sort = input("sort","this_month_opt_sum");
//            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);
            $agent_id = Db::name("qc_config")->where("id",1)->value("advertiser_id");
            $start_time = input("start_date")?:date("Y-m-d",strtotime("-30 day"));
            $end_time = input("end_date")?:date("Y-m-d",strtotime("-1 day"));
            $kahuna = input("kahuna");
            $advertiser_id = input("advertiser_id");
            if (!empty($kahuna)){
                $where['kahuna'] = ['like', "%$kahuna%"];
            }
            if (!empty($advertiser_id)){
                $where['advertiser_id'] = ['=', $advertiser_id];
            }


            $list = $companyModel
                ->where($where)
                ->field("id,advertiser_id,company_name,name,kahuna,this_month_opt_sum,this_month_bmopt_sum")
                ->order('this_month_opt_sum', 'desc')
                ->limit($offset,$limit);
            $list = $list->select();
            foreach ($list as $k => $v){
                $no_grant_sum = 0;
                $grant_sum = 0;
//                $data = FundManagement::get_agent_statement($access_token,$agent_id, $start_time, $end_time,1,100,(int)$v['advertiser_id']);
                $data = FundManagement::get_flow_info($access_token,$v['advertiser_id'],1,100,$start_time,$end_time);
                $total_page = ceil($data['data']['page_info']['total_number']/$data['data']['page_info']['page_size']);
                if($total_page == 1){
                    foreach ($data['data']['list'] as $value){
//                        $no_grant_sum += $value['no_grant_cost'] / 100000;
                        $no_grant_sum += $value['cash_cost'] / 100000;
                        $grant_sum += $value['cost'] / 100000;
                    }
                }else{
                    for($i=1;$i<=$total_page;$i++){
//                        $data = FundManagement::get_agent_statement($access_token,$agent_id, $start_time, $end_time,$i,100,(int)$v['advertiser_id']);
                        $data = FundManagement::get_flow_info($access_token,$v['advertiser_id'],$i,100,$start_time,$end_time);
                        foreach ($data['data']['list'] as $value){
//                            $no_grant_sum += $value['no_grant_cost'] / 100000;
                            $no_grant_sum += $value['cash_cost'] / 100000;
                            $grant_sum += $value['cost'] / 100000;
                        }
                    }
                }
                $list[$k]['no_grant_sum'] = floor(floatval($no_grant_sum) * 100) / 100;
                $list[$k]['grant_sum'] = floor(floatval($grant_sum) * 100) / 100;
            }
            // 查询总数
            $countQuery = $companyModel->where($where);
            $count = $countQuery->count();
            $result = array("total" => $count, "rows" => $list);
            return json($result);
        }
        return $this->view->fetch();
    }


    public function sub_page()
    {
        $companyModel = new CompanyModel();
        $access_token = Cache::get("qc_access_token");
        $admin_name = $this->auth->getUserInfo()['nickname'];
        if ($this->request->isAjax()) {
            $where = [];
//            $sort = input("sort","id");
//            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);
            $agent_id = Db::name("qc_config")->where("id",1)->value("advertiser_id");
            $start_time = input("start_date")?:date("Y-m-d",strtotime("-30 day"));
            $end_time = input("end_date")?:date("Y-m-d",strtotime("-1 day"));
            $where['kahuna'] = ['=', $admin_name];
            $advertiser_id = input("advertiser_id");
            if (!empty($advertiser_id)){
                $where['advertiser_id'] = ['=', $advertiser_id];
            }


            $list = $companyModel
                ->where($where)
                ->field("id,advertiser_id,company_name,name,kahuna,this_month_opt_sum,this_month_bmopt_sum")
                ->order('this_month_opt_sum', 'desc')
                ->limit($offset,$limit);
            $list = $list->select();
            foreach ($list as $k => $v){
                $no_grant_sum = 0;
                $grant_sum = 0;
                $data = FundManagement::get_agent_statement($access_token,$agent_id, $start_time, $end_time,1,100,(int)$v['advertiser_id']);
                $total_page = ceil($data['data']['page_info']['total_number']/$data['data']['page_info']['page_size']);
                for($i=1;$i<=$total_page;$i++){
                    $data = FundManagement::get_agent_statement($access_token,$agent_id, $start_time, $end_time,$i,100,(int)$v['advertiser_id']);
                    foreach ($data['data']['list'] as $value){
                        $no_grant_sum += $value['no_grant_cost'] / 100000;
                        $grant_sum += $value['cost'] / 100000;
                    }
                }
                $list[$k]['no_grant_sum'] = floor(floatval($no_grant_sum) * 100) / 100;
                $list[$k]['grant_sum'] = floor(floatval($grant_sum) * 100) / 100;
            }
            // 查询总数
            $countQuery = $companyModel->where($where);
            $count = $countQuery->count();
            $result = array("total" => $count, "rows" => $list);
            return json($result);
        }
        return $this->view->fetch();
    }

}