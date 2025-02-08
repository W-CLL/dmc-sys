<?php

namespace app\admin\controller\operate_monitor;

use app\common\controller\Backend;
use app\admin\model\Company as CompanyModel;
use app\common\model\QcAdvDayCost;



class AdList extends Backend
{
    public function index()
    {
        $companyModel = new CompanyModel();
        if ($this->request->isAjax()) {
            $where = [];
            $sort = input("sort","mon_cost");
            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);
            $start_time = strtotime(input("start_date")?:date("Y-m-d",strtotime("-30 day")));
            $end_time = strtotime(input("end_date").' 23:59:59'?:date("Y-m-d",time()).' 23:59:59');
            $kahuna = input("kahuna");
            $advertiser_id = input("advertiser_id");
            if (!empty($kahuna)){
                $where['kahuna'] = ['like', "%$kahuna%"];
            }
            if (!empty($advertiser_id)){
                $where['advertiser_id'] = ['=', $advertiser_id];
            }
            $qcAdvModel = new QcAdvDayCost();
            $list = $qcAdvModel
                ->alias('adv_c')
                ->join('company com', 'adv_c.adv_id = com.advertiser_id', 'left')
                ->join(
                    "(SELECT adv_id, COUNT(*) AS total_num FROM fa_qc_obj_opt_log WHERE opt_time BETWEEN ".$start_time." AND ".$end_time." GROUP BY adv_id) AS total_stats",
                    'adv_c.adv_id = total_stats.adv_id',
                    'left'
                )
                ->join(
                    "(SELECT adv_id, COUNT(*) AS company_num FROM fa_qc_obj_opt_log WHERE opt_time BETWEEN ".$start_time." AND ".$end_time." AND operator IN (SELECT name FROM fa_ad_operator WHERE status = 1) GROUP BY adv_id) AS company_stats",
                    'adv_c.adv_id = company_stats.adv_id',
                    'left'
                )
                ->where(['adv_c.cost_date' => ['between', [$start_time, $end_time]]])
                ->where(function ($query) use ($advertiser_id,$kahuna){
                    $whereStr = [];
                    if($advertiser_id){
                        $whereStr['com.advertiser_id'] = $advertiser_id;
                    }
                    if($kahuna){
                        $whereStr['com.kahuna'] = ['like', "%$kahuna%"];
                    }
                    $query->where($whereStr);
                })
                ->field("adv_c.*, SUM(cost) AS mon_cost, com.company_name, com.kahuna, total_stats.total_num, company_stats.company_num")
                ->group('adv_c.adv_id')
                ->order($sort, $order)
                ->limit($offset, $limit)
//                ->fetchSql(true)
                ->select();
//            dump($list);
//            die;

            foreach ($list as &$item){
                $item['cus_num'] = $item['total_num'] - $item['company_num'];
                if($item['company_num'] > 0 && $item['cus_num'] > 0){
                    $item['percentage'] = number_format($item['company_num']/ $item['cus_num'], 2) * 100 ;
                    $item['percentage'] = $item['percentage'].'%';
                }else{
                    $item['percentage'] = "0%";
                }

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