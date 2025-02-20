<?php

namespace app\admin\controller\fund_log;

use app\common\controller\Backend;
use DateTime;
use jlqc\FundManagement;
use think\Cache;
use think\Db;
use think\Env;
use think\Exception;


class CapitalFlow extends Backend
{


    /**
     * @return array|string
     * @throws Exception
     * @throws \Exception
     * 消耗流水
     */
    public function index()
    {
        if (false === $this->request->isAjax()) {

            return $this->view->fetch();
        }
//        $advertiser_id = Db::name("qc_config")->where("id",1)->value('advertiser_id');
        $advertiser_id = Env::get('dmc_ad_config.advertiser_id');
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
        $return_code = FundManagement::$auth_return_code;

        if(in_array($finance_data['code'],$return_code)){
            send_work_wx_msg('千川授权已失效!请尽快更新');
            $this->error('千川授权已失效，请联系管理员');
        }

        $data['rows'] = $finance_data['data']['list'];
        $data['total'] = $finance_data['data']['page_info']['total_number'];
        return $data;
    }

}