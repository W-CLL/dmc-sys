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
        $adv_id = trim(input("adv_id"));
        $job_name = trim(input("job_name"));
        $code_type = input('code_type','');

        $startDate = strtotime($start_date);
        $endDate = strtotime($end_date . " 23:59:59");
        if($startDate&&$endDate){
            $where['create_time'] = [ 'between', [$startDate, $endDate]];
        }
        // 公共字段处理
        if ($status !== '') {
            $where['status'] = $status;
        }
        if ($adv_id !== '') {
            $where['job_data'] = ['like', "%{$adv_id}%"];
        }
        if ($job_name !== '') {
            $where['job_name'] = ['like', "%{$job_name}%"];
        }
        $where['code_type'] = $code_type;
        // code_type 类型判断
        switch ($code_type) {
            case "1":
            case '0':
                $where['queue_name'] = 'autoUpdateObjName';
                break;
            case '2':
                $where['queue_name'] = 'autoUpdateObjNameWeb';
                break;
            case '3':
                $where['queue_name'] = 'autoUpdateGlobalObjName';
                break;
            default:
                $where['queue_name'] = ['like', '%autoUpdateObjName%'];
                break;
        }

        // 非 code_type=1，统一补充 create_time 查询范围


    }

    public function index()
    {
        if ($this->request->isAjax()) {
            $sort = input("sort", "id");
            $order = input("order", "desc");
            $offset = input("offset", 0);
            $limit = input("limit", 10);
            $cs = input("cs");
            if (!$cs) {
                $this->error('请选择客服');
            }
            $url = $cs . ".frp.zebranumber.cn/index.php/api/index/getQueueStatusList";
            $where = [];
            $this->_search($where);

            $data = [
                'sort' => $sort,
                'order' => $order,
                'offset' => $offset,
                'limit' => $limit,
                'where' => $where,
                'kefu' => $cs,
            ];

            $data['validate'] = md5(json_encode($data, JSON_UNESCAPED_UNICODE));
            $res = Requests::post($url, json_encode($data, JSON_UNESCAPED_UNICODE), [
                'Content-Type' => 'application/json'
            ]);

            if (is_null($res)) {
                $this->error('请求异常');
            }
            if ($res['code'] != 0) {
                $this->error($res['msg']);
            }
            foreach ($res['list'] as $k => $v) {
                $dataArray = json_decode($v['job_data'], true);
                $res['list'][$k]['company_id'] = $dataArray['adv_id'] ?? null;
            }
            $result = array("total" => $res['count'], "rows" => $res['list']);

            return json($result);
        }
        return $this->view->fetch();
    }


}