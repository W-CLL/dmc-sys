<?php

namespace app\admin\controller\risk_management;

use app\admin\model\Tag;
use app\common\controller\Backend;
use app\common\model\ObjProduct;


class RiskObj extends Backend
{
    protected $handle_status = ['正常','跟进中','已注销','已处理'   ];
    private function _filter(&$where)
    {
        $params = input();
        if ($params['keyword']) {
            $where['keyword'] = '';
        }
        $start_time = strtotime(input("start_date") ?: date("Y-m-01"));
        $end_time = strtotime(input("end_date") . ' 23:59:59' ?: date("Y-m-d", time()) . ' 23:59:59');
        $kahuna = input("kahuna");
        $advertiser_id = input("advertiser_id");
//        if (!empty($kahuna) || $user_name) {
//            $kahuna = $this->check($kahuna, $user_name);
//            if(is_array($kahuna)){
//                $where['kahuna'] = ['in', $kahuna];
//                $list_where['com.kahuna'] = ['in', $kahuna];
//            }else{
//                $where['kahuna'] = ['like', "%$kahuna%"];
//                $list_where['com.kahuna'] = ['like', "%$kahuna%"];
//            }
//        }
        if (!empty($advertiser_id)) {
            $where['advertiser_id'] = ['=', $advertiser_id];
            $list_where['com.advertiser_id'] = $advertiser_id;
        }
    }

    public function index($adv_id='')
    {
        $risk_obj_model = new ObjProduct();
        if ($this->request->isAjax()) {
            $where = [];
            $sort = input("sort", "mon_cost");
            $order = input("order", "desc");
            $offset = input("offset", 0);
            $limit = input("limit", 10);
            $list_where = [];

            $list = $risk_obj_model
                ->where(['adv_id'=>$adv_id])
                ->group('obj_id')
//                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            foreach ($list as &$item) {
                $product_ids = $risk_obj_model->where(['obj_id'=>$item['obj_id']])->limit(10)->column('product_id');
            }
            // 查询总数
            $countQuery = $risk_obj_model->count();
            $result = array("total" => $countQuery, "rows" => $list);
            return json($result);
        }
        $this->assign('handle_status_list',$this->handle_status);
        $this->assign('adv_id',$adv_id);
        return $this->view->fetch();
    }
}