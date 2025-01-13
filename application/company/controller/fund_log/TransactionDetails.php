<?php

namespace app\company\controller\fund_log;

use app\common\controller\Company;
use DateTime;
use jlqc\FundManagement;
use think\Cache;


class TransactionDetails extends Company
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
        $transaction_type = input("transaction_type",1);
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

        if ($interval->m > 1 || ($interval->m == 1 && $interval->d > 0)) {
            $this->error("查询相隔时间不能超过一个月");
        }
        $transaction_data = FundManagement::fund_transaction($access_token,$advertiser_id,$start_date,$end_date,$offset,$limit,$transaction_type);
        $return_code = FundManagement::$auth_return_code;

        if(in_array($transaction_data['code'],$return_code)){
            send_work_wx_msg('千川授权已失效，请尽快更新!');
            $this->error('千川授权已失效，请联系管理员');
        }
        $data['rows'] = $transaction_data['data']['list'];
        $data['total'] = $transaction_data['data']['page_info']['total_number'];
        return $data;
    }

}

