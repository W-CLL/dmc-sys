<?php

namespace app\admin\controller\operate_monitor;

use app\common\controller\Backend;
use app\admin\model\Company as CompanyModel;
use app\common\model\QcAdvDayCost;
use think\Db;


class AdList extends Backend
{
    protected $username = [
        '王跟' => ['谭玉霞'],
        '张跟' => ['张秋萍'],
        '陈跟' => ['陈秀玉'],
        '莫跟' => ['莫美春'],
        '罗跟' => ['罗文静'],
        '谭玉霞' => ['罗文静'],
    ];
    public function index($user_name = '')
    {
        $companyModel = new CompanyModel();
        if ($this->request->isAjax()) {
            $where = [];
            $sort = input("sort", "mon_cost");
            $order = input("order", "desc");
            $offset = input("offset", 0);
            $limit = input("limit", 10);
            $start_time = strtotime(input("start_date") ?: date("Y-m-01"));
            $end_time = strtotime(input("end_date") . ' 23:59:59' ?: date("Y-m-d", time()) . ' 23:59:59');
            $kahuna = input("kahuna");
            $advertiser_id = input("advertiser_id");
            $list_where = [];
            if (!empty($kahuna) || $user_name) {
                $kahuna = $this->check($kahuna, $user_name);
                if(is_array($kahuna)){
                    $where['kahuna'] = ['in', $kahuna];
                    $list_where['com.kahuna'] = ['in', $kahuna];
                }else{
                    $where['kahuna'] = ['like', "%$kahuna%"];
                    $list_where['com.kahuna'] = ['like', "%$kahuna%"];
                }
            }
            if (!empty($advertiser_id)) {
                $where['advertiser_id'] = ['=', $advertiser_id];
                $list_where['com.advertiser_id'] = $advertiser_id;
            }

            $qcAdvModel = new QcAdvDayCost();
            $list = $qcAdvModel
                ->alias('adv_c')
                ->join('company com', 'adv_c.adv_id = com.advertiser_id', 'left')
                ->join(
                    "(SELECT adv_id, COUNT(*) AS total_num FROM fa_qc_obj_opt_log WHERE opt_time BETWEEN " . $start_time . " AND " . $end_time . " GROUP BY adv_id) AS total_stats",
                    'adv_c.adv_id = total_stats.adv_id',
                    'left'
                )
                ->join(
                    "(SELECT adv_id, COUNT(*) AS company_num FROM fa_qc_obj_opt_log WHERE opt_time BETWEEN " . $start_time . " AND " . $end_time . " AND operator IN (SELECT name FROM fa_ad_operator WHERE status = 1) GROUP BY adv_id) AS company_stats",
                    'adv_c.adv_id = company_stats.adv_id',
                    'left'
                )
                ->join(
                    "(SELECT adv_id, COUNT(*) AS global_total_num FROM fa_qc_global_obj_opt_log WHERE opt_time BETWEEN " . $start_time . " AND " . $end_time . " GROUP BY adv_id) AS global_total_stats",
                    'adv_c.adv_id = global_total_stats.adv_id',
                    'left'
                )
                ->join(
                    "(SELECT adv_id, COUNT(*) AS global_company_num FROM fa_qc_global_obj_opt_log WHERE opt_time BETWEEN " . $start_time . " AND " . $end_time . " AND operator IN (SELECT name FROM fa_ad_operator WHERE status = 1) GROUP BY adv_id) AS global_company_stats",
                    'adv_c.adv_id = global_company_stats.adv_id',
                    'left'
                )
                // 统计标准推商品计划数
                ->join(
                    "(SELECT ol.adv_id, COUNT(*) AS product_promotion_count FROM fa_qc_obj o 
                      INNER JOIN fa_qc_obj_opt_log ol ON o.obj_id = ol.obj_id 
                      WHERE ol.opt_time BETWEEN " . $start_time . " AND " . $end_time . " 
                      AND o.marketing_goal = 'VIDEO_PROM_GOODS' 
                      GROUP BY ol.adv_id) AS product_promotion_stats",
                    'adv_c.adv_id = product_promotion_stats.adv_id',
                    'left'
                )
                // 统计标准推直播间计划数
                ->join(
                    "(SELECT ol.adv_id, COUNT(*) AS live_promotion_count FROM fa_qc_obj o 
                      INNER JOIN fa_qc_obj_opt_log ol ON o.obj_id = ol.obj_id 
                      WHERE ol.opt_time BETWEEN " . $start_time . " AND " . $end_time . " 
                      AND o.marketing_goal = 'LIVE_PROM_GOODS' 
                      GROUP BY ol.adv_id) AS live_promotion_stats",
                    'adv_c.adv_id = live_promotion_stats.adv_id',
                    'left'
                )
                // 统计全域推商品计划数
                ->join(
                    "(SELECT ol.adv_id, COUNT(*) AS global_product_promotion_count FROM fa_qc_global_obj o 
                      INNER JOIN fa_qc_global_obj_opt_log ol ON o.obj_id = ol.obj_id 
                      WHERE ol.opt_time BETWEEN " . $start_time . " AND " . $end_time . " 
                      AND o.marketing_goal = 'VIDEO_PROM_GOODS' 
                      GROUP BY ol.adv_id) AS global_product_promotion_stats",
                    'adv_c.adv_id = global_product_promotion_stats.adv_id',
                    'left'
                )
                // 统计全域推直播间计划数
                ->join(
                    "(SELECT ol.adv_id, COUNT(*) AS global_live_promotion_count FROM fa_qc_global_obj o 
                      INNER JOIN fa_qc_global_obj_opt_log ol ON o.obj_id = ol.obj_id 
                      WHERE ol.opt_time BETWEEN " . $start_time . " AND " . $end_time . " 
                      AND o.marketing_goal = 'LIVE_PROM_GOODS' 
                      GROUP BY ol.adv_id) AS global_live_promotion_stats",
                    'adv_c.adv_id = global_live_promotion_stats.adv_id',
                    'left'
                )
                ->where(['adv_c.cost_date' => ['between', [$start_time, $end_time]]])
                ->where('adv_c.cost','>', '0')
                ->where($list_where)
                ->field("adv_c.*, SUM(cost) AS mon_cost,
                 SUM(CASE WHEN adv_c.type = 1 THEN cost ELSE 0 END) AS stand_cost,
                  SUM(CASE WHEN adv_c.type = 2 THEN cost ELSE 0 END) AS global_cost,
                 com.company_name, com.kahuna, total_stats.total_num, company_stats.company_num, global_total_stats.global_total_num, global_company_stats.global_company_num,
                (total_num - IFNULL(company_num, 0)) as cus_num,
                (CASE 
                    WHEN (total_num - IFNULL(company_num, 0)) > 0 THEN (IFNULL(company_num, 0) / (total_num - IFNULL(company_num, 0))) * 100
                ELSE 0
                END) as percentage,
                (global_total_num - IFNULL(global_company_num, 0)) as global_cus_num,
                (CASE 
                    WHEN (global_total_num - IFNULL(global_company_num, 0)) > 0 THEN (IFNULL(global_company_num, 0) / (global_total_num - IFNULL(global_company_num, 0))) * 100
                ELSE 0
                END) as global_percentage,
                IFNULL(product_promotion_stats.product_promotion_count, 0) as product_promotion_count,
                IFNULL(live_promotion_stats.live_promotion_count, 0) as live_promotion_count,
                IFNULL(global_product_promotion_stats.global_product_promotion_count, 0) as global_product_promotion_count,
                IFNULL(global_live_promotion_stats.global_live_promotion_count, 0) as global_live_promotion_count
                 ")
                ->cache(true,3600)
                ->group('adv_c.adv_id')
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            foreach ($list as &$item) {
//                $item['cus_num'] = $item['total_num'] - $item['company_num'];
//
//                if ($item['company_num'] > 0 && $item['cus_num'] > 0) {
//                    $item['percentage'] = number_format($item['company_num'] / $item['cus_num'], 2) * 100;
//                    $item['percentage'] = $item['percentage'] . '%';
//                } else if ($item['company_num'] > 0 && $item['cus_num'] == 0) {
//                    $item['percentage'] = number_format($item['company_num'], 2) * 100;
//                    $item['percentage'] = $item['percentage'] . '%';
//                } else {
//                    $item['percentage'] = "0%";
//                }
                $cus_num = $item['total_num'] - $item['company_num'];
                if($cus_num == 0){
                    $item['percentage'] = $item['company_num'] * 100;
                }
                $item['percentage'] = number_format($item['percentage'], 2) . "%";
                $global_cus_num = $item['global_total_num'] - $item['global_company_num'];
                if($global_cus_num == 0){
                    $item['global_percentage'] = $item['global_company_num'] * 100;
                }
                $item['global_percentage'] = number_format($item['global_percentage'], 2) . "%";
            }

            // 查询总数
            $countQuery=  $qcAdvModel
                ->alias('adv_c')
                ->join('company com', 'adv_c.adv_id = com.advertiser_id', 'left')
                ->where(['adv_c.cost_date' => ['between', [$start_time, $end_time]]])
                ->where('adv_c.cost','>', '0')
                ->where($list_where) ->group('adv_c.adv_id');

//            $countQuery = $companyModel->where($where);
            $count = $countQuery->count();
            $result = array("total" => $count, "rows" => $list);
            return json($result);
        }
        return $this->view->fetch();
    }

    public function charge_page()
    {
        $user_name = $this->auth->getUserInfo()['nickname'];
        if(isset($this->username[$user_name])){
            $user_name=$this->username[$user_name];
        }
        return $this->index($user_name);
    }

    // 优先级判断
    public function check($kahuna, $user_name)
    {
        if(empty($kahuna)){
            return $user_name;
        }
        if (empty($user_name)){
            return $kahuna;
        }
        foreach ($user_name as $value){
            if(strpos($value,$kahuna) !== false){
                return $kahuna;
            }
        }
        return $user_name;
    }

}