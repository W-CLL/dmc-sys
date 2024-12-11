<?php

namespace app\admin\controller\operate_monitor;


use app\common\controller\Backend;
use app\admin\model\Operator as OperatorModel;
use app\admin\model\PlanOptLog as PlanOptLogModel;


class Monitor extends Backend
{
    public function _initialize()
    {
        parent::_initialize();
        $this->model = model('Monitor');
    }

    /**
     * 查看
     */
    public function index()
    {
        $obj_id = input("obj_id");
        $ad_id = input("ad_id");
        $details = input("details");
        $operatorModel = new OperatorModel();
        $planOptLogModel = new PlanOptLogModel();
        if ($this->request->isAjax()) {
            $sort = input("sort","id");
            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);
            $start_date = input("start_date");
            $end_date = input("end_date");
            $is_bm = input("is_bm");

            $startDate =strtotime($start_date);
            $endDate = strtotime($end_date." 23:59:59");

            if($startDate && $endDate){
                $where['opt_time'] = ["between",[$startDate,$endDate]];
            }
            if($is_bm === '0'){
                $where['operator'] = ["not in",array_column($operatorModel->get_operator_name(), 'name')];
            }elseif ($is_bm === '1'){
                $where['operator'] = ["in",array_column($operatorModel->get_operator_name(), 'name')];
            }



            $list = $planOptLogModel
                ->alias('p')
                ->join('company c', 'p.advertiser_id = c.advertiser_id')
                ->join('qc_obj o', 'p.obj_id = o.object_id')
                ->field("p.id,p.advertiser_id,p.obj_id,p.content_log,p.content_title,p.object_name,p.object_type,p.operator,p.opt_ip,p.opt_time,c.kahuna")
                ->order($sort, $order)
                ->limit($offset,$limit)
                ->where($where);
            if(!empty($obj_id)){
                $list = $list->where('o.object_id', $obj_id);
            }
            $list = $list->select();
            // 查询总数
            $countQuery = $planOptLogModel->where($where);
            if (!empty($obj_id)) {
                $countQuery = $countQuery->where('obj_id', $obj_id);
            }
            $count = $countQuery->count();
            $result = array("total" => $count, "rows" => $list);
            return json($result);
        }
        $this->assign("ad_id",$ad_id);
        $this->assign("details",$details);
        return $this->view->fetch();
    }


}