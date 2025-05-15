<?php

namespace app\admin\controller\score;

use app\common\controller\Backend;
use app\common\model\AdvScore;
use jlqc\FundManagement;
use think\Db;

class Index extends Backend
{
    public function index()
    {
        $scoreModel = new AdvScore();
        if ($this->request->isAjax()) {
            $sort = input("sort","id");
            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);
            $filter = input("filter", '');

            $where = [];
            if ($filter != '') {
                $filter = (array)json_decode($filter, true);
                $where = $this->screen_filter($filter);
            }

            $list = $scoreModel
//                ->where($where)
                ->order($sort, $order)
                ->limit($offset,$limit)
                ->select();
            $count = $scoreModel->where($where)->count();
            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        $account_data =Db::name("company")
            ->field("id,advertiser_id")
            ->group("advertiser_id")
            ->select();
        $this->assign('account_data',$account_data?:[]);
        return $this->view->fetch();
    }

    public function score_list($ids)
    {
        $scoreModel = new AdvScore();
        if ($this->request->isAjax()) {
            $offset = input("offset",0);
            $limit = input("limit",10);
            $page = $offset/$limit;
            $page = $page+1;
            $start_time = input("start_date",date('Y-01-01 00:00:00'));
            $end_time = input("end_date",date('Y-m-d 23:59:59'));
            $adv_id = $scoreModel->where(['id'=>$ids])->value('adv_id');
            $base_params = [
                'advertiser_id'=>(int)$adv_id,
                'business_line'=>"QIANCHUAN",
                "page"=>$page,
                "page_size"=>$limit,
                "filtering"=>[
                    'start_time'=>$start_time,
                    'end_time'=>$end_time,
                ]
            ];

//            $list = FundManagement::get_adv_score_list($base_params);
//            $total_page = $list['data']['page_info']['total_page'];
//            $last_page = ($total_page +1) - $page;
//            $base_params['page'] = $last_page;
            $list = FundManagement::get_adv_score_list($base_params);

            if($list['code'] != 0){
                $this->error($list['message']." 请联系管理员");
            }
            foreach ($list['data']['adv_score_event'] as &$item){
                switch ($item['status']){
                    case  "APPEAL":
                        $item['status_text'] = "已申诉（失效）";
                        break;
                    case  "FAILAPPEAL":
                        $item['status_text'] = "申诉失败";
                        break;
                    case  "ONAPPEAL":
                        $item['status_text'] = "申诉中";
                        break;
                    case  "VALID":
                        $item['status_text'] = " 生效";
                        break;
                }
                if($item['illegal_type'] == "ONECLASS"){
                    $item['illegal_type_text'] = "一类违规";
                }elseif($item['illegal_type'] == "TWOTHREECLASS"){
                    $item['illegal_type_text'] = "二三类违规";
                }
            }
            $result = array("total" => $list['data']['page_info']['total_number'], "rows" => $list['data']['adv_score_event']);

            return json($result);
        }
        $this->assign('score_id',$ids);
        return $this->view->fetch();
    }
}