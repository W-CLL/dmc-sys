<?php

namespace app\company\controller\fund_log;

use app\common\controller\Company;
use DateTime;
use jlqc\FundManagement;
use think\Cache;


class CapitalFlow extends Company
{



    public function index()
    {
        if (false === $this->request->isAjax()) {

            return $this->view->fetch();
        }
        $advertiser_id = $this->auth->advertiser_id;
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

}