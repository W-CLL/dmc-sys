<?php

namespace app\admin\controller\score;

use app\common\controller\Backend;
use app\common\model\AdvScore;
use jlqc\FundManagement;
use think\Db;

class Index extends Backend
{

    public $statusMap = [
        'STATUS_DISABLE' => '已禁用',
        'STATUS_PENDING_CONFIRM' => '审核中',
        'STATUS_PENDING_VERIFIED' => '待验证',
        'STATUS_CONFIRM_FAIL' => '审核失败/可再次申请',
        'STATUS_ENABLE' => '已审核',
        'STATUS_CONFIRM_FAIL_END' => '审核失败/最终状态',
        'STATUS_PENDING_CONFIRM_MODIFY' => '修改审核中',
        'STATUS_CONFIRM_MODIFY_FAIL' => '修改审核失败',
        'STATUS_PUNISH' => '惩罚',
        'STATUS_WAIT_FOR_BPM_AUDIT' => '等待CRM审核',
        'STATUS_SELF_SERVICE_UNAUDITED' => '待验证资质',
        'STATUS_ENABLE_AND_AVATAR_AUDITING' => 'SMB客户待合同归档',
        'STATUS_WAIT_FOR_BPM_FILE_CONTACT' => 'SMB客户待合同归档',
        'STATUS_WAIT_FOR_ACCOUNT_FEE' => 'SMB广告主待缴纳开户费',
        'STATUS_WAIT_FOR_PUBLIC_AUTH' => '待对公验证',
        'STATUS_LIMIT' => '永久封停'
    ];

    public function index()
    {
        $scoreModel = new AdvScore();
        if ($this->request->isAjax()) {
            $sort = input("sort", "id");
            $order = input("order", "desc");
            $offset = input("offset", 0);
            $limit = input("limit", 10);

            $where = [];

            $adv_id = input('advertiser_id');
            $com_name = input('com_name');
            $kahuna = input('kahuna');
            if ($adv_id) {
                $where['sco.adv_id'] = $adv_id;
            }
            if ($com_name) {
                $where['com.company_name'] = ['like', "%" . $com_name . "%"];
            }
            if ($kahuna) {
                $where['com.kahuna'] = ['like', "%" . $kahuna . "%"];
            }

            $list = $scoreModel
                ->alias('sco')
                ->join('company com', 'sco.adv_id=com.advertiser_id', 'left')
                ->field('sco.*,com.company_name,com.kahuna,com.name')
                ->where($where)
                ->where(['sco.status' => 1,'com.adv_status'=>1])
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            $adv_ids = array_column($list, 'adv_id');

            $info_list = FundManagement::get_adv_info($adv_ids);
            if ($info_list['code'] != 0) {
                $this->error('获取失败: ' . $info_list['message']);
            }
            foreach ($list as &$item) {
                foreach ($info_list['data'] as $info) {
                    if ($item['adv_id'] == $info['id']) {
                        $item['reason'] = $info['reason'];
                        $item['adv_status'] = $this->statusMap[$info['status']];
                    }
                }
            }

            $count = $scoreModel
                ->alias('sco')
                ->join('company com', 'sco.adv_id=com.advertiser_id', 'left')
                ->field('sco.*,com.company_name,com.kahuna,com.name')
                ->where($where)
                ->where(['sco.status' => 1])
                ->count();
            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        $account_data = Db::name("adv_score")
            ->field("id,adv_id")
            ->group("adv_id")
            ->select();

        $this->assign('account_data', $account_data ?: []);
        return $this->view->fetch();
    }

    public function score_list($ids)
    {
        $scoreModel = new AdvScore();
        if ($this->request->isAjax()) {
            $offset = input("offset", 0);
            $limit = input("limit", 10);
            $page = $offset / $limit;
            $page = $page + 1;
            $start_time = input("start_date", date('Y-01-01'));
            $end_time = input("end_date", date('Y-m-d'));
            $adv_id = $scoreModel->where(['id' => $ids])->value('adv_id');
            $base_params = [
                'advertiser_id' => (int)$adv_id,
                'business_line' => "QIANCHUAN",
                "page" => $page,
                "page_size" => $limit,
                "filtering" => json_encode([
                    'start_time' => $start_time . " 00:00:00",
                    'end_time' => $end_time . " 23:59:59",
                ])
            ];


//            $list = FundManagement::get_adv_score_list($base_params);
//            $total_page = $list['data']['page_info']['total_page'];
//            $last_page = ($total_page +1) - $page;
//            $base_params['page'] = $last_page;
            $list = FundManagement::get_adv_score_list($base_params);
            $result = FundManagement::get_adv_info([$adv_id]);

            if ($result['code'] == 40002) {
//                $scoreModel->where(['id' => $ids])->update(['status' => 0]);
                $this->error('账户已经注销！');
            }

            if ($list['code'] != 0) {
                $this->error($list['message'] . " 请联系管理员");
            }
            foreach ($list['data']['adv_score_event'] as &$item) {
                switch ($item['status']) {
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
                if ($item['illegal_type'] == "ONECLASS") {
                    $item['illegal_type_text'] = "一类违规";
                } elseif ($item['illegal_type'] == "TWOTHREECLASS") {
                    $item['illegal_type_text'] = "二三类违规";
                }
            }
            $result = array("total" => $list['data']['page_info']['total_number'], "rows" => $list['data']['adv_score_event']);

            return json($result);
        }
        $this->assign('score_id', $ids);
        return $this->view->fetch();
    }
}