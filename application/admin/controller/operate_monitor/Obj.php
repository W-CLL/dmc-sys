<?php

namespace app\admin\controller\operate_monitor;

use app\common\controller\Backend;
use app\admin\model\QcObj as ObjModel;
use app\admin\model\Operator as OperatorModel;
use app\admin\model\QcObjOptLog as PlanOptLogModel;

class Obj extends Backend
{
    public function index()
    {
        $objModel = new ObjModel();
        $operatorModel = new OperatorModel();
        $planOptLogModel = new PlanOptLogModel();
        $bm_username = $operatorModel->field('name')->select();
        $bm_username = array_column($bm_username, 'name');
        if ($this->request->isAjax()) {
            $sort = input("sort","id");
            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);



            $list = $objModel
                ->alias('o')
                ->join('company c', 'o.company_id = c.id')
                ->field("o.id,o.company_id,o.advertiser_id,o.object_id,o.status,o.create_time,c.kahuna")
                ->order($sort, $order)
                ->limit($offset,$limit);
            $list = $list->select();
            foreach ($list as $k => $v){
                $list[$k]['this_month_opt_sum'] = $planOptLogModel
                    ->where([
                        'obj_id' => $v['object_id'],
                        'opt_time' => ['between', [strtotime("first day of this month 00:00:00"), strtotime("last day of this month 23:59:59")]]
                    ])
                    ->count();
                $list[$k]['this_month_bmopt_sum'] = $planOptLogModel
                    ->where([
                        'obj_id' => $v['object_id'],
                        'operator' => ['in', $bm_username],
                        'opt_time' => ['between', [strtotime("first day of this month 00:00:00"), strtotime("last day of this month 23:59:59")]]
                    ])
                    ->count();
            }
            // 查询总数
            $countQuery = $objModel;
            if (!empty($ad_id)) {
                $countQuery = $countQuery->where('advertiser_id', $ad_id);
            }
            $count = $countQuery->count();
            $result = array("total" => $count, "rows" => $list);
            return json($result);
        }
        return $this->view->fetch();
    }

    public function details()
    {
        $objModel = new ObjModel();
        $operatorModel = new OperatorModel();
        $planOptLogModel = new PlanOptLogModel();
        $bm_username = $operatorModel->field('name')->select();
        $bm_username = array_column($bm_username, 'name');
        $ad_id = input("ad_id");
        if ($this->request->isAjax()) {
            $sort = input("sort","id");
            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);



            $list = $objModel
                ->alias('o')
                ->join('company c', 'o.company_id = c.id')
                ->field("o.id,o.company_id,o.advertiser_id,o.object_id,o.status,o.create_time,c.kahuna")
                ->order($sort, $order)
                ->limit($offset,$limit);
            if(!empty($ad_id)){
                $list = $list->where('o.advertiser_id', $ad_id);
            }
            $list = $list->select();
            foreach ($list as $k => $v){
                $list[$k]['this_month_opt_sum'] = $planOptLogModel
                    ->where([
                        'obj_id' => $v['object_id'],
                        'opt_time' => ['between', [strtotime("first day of this month 00:00:00"), strtotime("last day of this month 23:59:59")]]
                    ])
                    ->count();
                $list[$k]['this_month_bmopt_sum'] = $planOptLogModel
                    ->where([
                        'obj_id' => $v['object_id'],
                        'operator' => ['in', $bm_username],
                        'opt_time' => ['between', [strtotime("first day of this month 00:00:00"), strtotime("last day of this month 23:59:59")]]
                    ])
                    ->count();
            }
            // 查询总数
            $countQuery = $objModel;
            if (!empty($ad_id)) {
                $countQuery = $countQuery->where('advertiser_id', $ad_id);
            }
            $count = $countQuery->count();
            $result = array("total" => $count, "rows" => $list);
            return json($result);
        }
        $this->assign("ad_id",$ad_id);
        return $this->view->fetch();
    }
}