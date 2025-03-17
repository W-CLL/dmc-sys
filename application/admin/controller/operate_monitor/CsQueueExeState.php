<?php

namespace app\admin\controller\operate_monitor;

use app\common\controller\Backend;
use Requests;
use think\Db;

class CsQueueExeState extends Backend
{
    public function _search(&$where)
    {

        $start_date = input("start_date");
        $end_date = input("end_date");
        $status = input("status");

        $startDate =strtotime($start_date);
        $endDate = strtotime($end_date." 23:59:59");

        if($startDate && $endDate){
            $where['create_time'] = ["between",[$startDate,$endDate]];
        }

        if($status != ''){
            $where['status'] = $status;
        }

        $where['queue_name'] = 'autoUpdateObjName';
    }
    public function index()
    {
        if ($this->request->isAjax()) {
            $sort = input("sort", "id");
            $order = input("order", "desc");
            $offset = input("offset", 0);
            $limit = input("limit", 10);
            $cs = input("cs");
            $name = '';
            switch ($cs) {
                case 'cxy':
//                    $Db = Db::connect('cxy');
                    $name = 'cxy';
                    break;
                case 'wyc':
//                    $Db = Db::connect('wyc');
                    $name = 'wyc';
                    break;
                case 'mmc':
//                    $Db = Db::connect('mmc');
                    $name = 'mmc';
                    break;
                case 'zqp':
//                    $Db = Db::connect('zqp');
                    $name = 'zqp';
                    break;
                case 'tyx':
//                    $Db = Db::connect('tyx');
                    $name = 'tyx';
                    break;
            }
//            if(!$Db){
//                $this->error('请选择数据库');
//            }
            if(!$name){
                $this->error('请选择客服');
            }
            $url = $name.".frp.zebranumber.cn/index.php/api/index/getQueueStatusList";
            $where = [];
            $this->_search($where);
            $data = [
                'sort' => $sort,
                'order' => $order,
                'offset' => $offset,
                'limit' => $limit,
                'where' => $where,
            ];
            $data['validate'] = md5(json_encode($data,JSON_UNESCAPED_UNICODE));

//            $list = $Db->name("queue_record")
//                ->where($where)
//                ->order($sort, $order)
//                ->limit($offset,$limit)
//                ->select();

            $res = Requests::post($url, json_encode($data, JSON_UNESCAPED_UNICODE), [
                'Content-Type' => 'application/json'
            ]);
            if(is_null($res)){
                $this->error('请求异常');
            }
            if($res['code'] != 0){
                $this->error($res['msg']);
            }
            foreach ($res['list'] as $item) {
                switch ($item['status']) {
                    case 0:
                        $item['status_text'] = '等待中';
                        break;
                    case 1:
                        $item['status_text'] = '已完成';
                        break;
                    case 2:
                        $item['status_text'] = '失败';
                        break;
                }
//                $res = mb_convert_encoding($item['msg'], 'UTF-8', 'UTF-8');
//
                $item['msg']=  $item['msg']??'-';
            }
//            $count = $Db->name("queue_record")->where($where)->count();
            $result = array("total" => $res['count'], "rows" => $res['list']);

            return json($result);
        }
        return $this->view->fetch();
    }


}