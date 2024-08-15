<?php

namespace app\store\controller\fund_log;

use app\common\controller\Store;
use DateTime;
use jlqc\FundManagement;
use think\Cache;
use think\Db;


class CapitalFlow extends Store
{
    //消耗流水
    public function index()
    {
        if (false === $this->request->isAjax()) {
            $company_data = Db::name("company")->where(["store_id"=>$this->auth->id])->field("advertiser_id,company_name")->select();
            $this->assign("company_data",$company_data);
            return $this->view->fetch();
        }
        $advertiser_id = input("advertiser_id");
        $is_company = Db::name("company")->where(["advertiser_id"=>$advertiser_id,'store_id'=>$this->auth->id])->count();
        if ($advertiser_id && $is_company){
            $access_token = Cache::get("qc_access_token");
            $start_date = input("start_date");
            $end_date = input("end_date");
            $limit = input("limit",10);
            $offset = input("offset",0);
            if ($offset>0){
                $offset = $offset / 10 + 1;
            }else{
                $offset = 1;
            }
            $startDate = new DateTime($start_date);
            $endDate = new DateTime($end_date);
            $interval = $startDate->diff($endDate);

            if ($interval->y > 0) {
                $this->error("查询相隔时间不能超过一年");
            }

            $finance_data = FundManagement::finance($access_token,$advertiser_id,$start_date,$end_date,$offset,$limit);
            $data['rows'] = $finance_data['data']['list'];
            $data['total'] = $finance_data['data']['page_info']['total_number'];
            return $data;
        }

        return ["rows"=>[],"total"=>0];
    }

}