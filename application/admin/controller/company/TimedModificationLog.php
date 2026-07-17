<?php

namespace app\admin\controller\company;

use app\common\controller\Backend;
use think\Db;

/**
 * 定时修改主体政策记录表
 *
 * @icon fa fa-list
 */
class TimedModificationLog extends Backend
{

    /**
     * 查看
     */
    public function index()
    {
        if ($this->request->isAjax()) {
            $sort   = input("sort", "id");
            $order  = input("order", "desc");
            $offset = input("offset", 0);
            $limit  = input("limit", 10);
            $filter = input("filter");
            $filter_data = json_decode($filter, true);

            $where = [];

            if (!empty($filter_data)) {
                if (isset($filter_data['subject_name'])) {
                    $where['subject_name'] = ['like', "%" . $filter_data['subject_name'] . "%"];
                }
                if (isset($filter_data['status'])) {
                    $where['status'] = $filter_data['status'];
                }
                if (isset($filter_data['subject_type'])) {
                    $where['subject_type'] = $filter_data['subject_type'];
                }
            }

            $list = Db::table("fa_subject_percentage_change")
                ->field("id, status, subject_name, discount_percentage, subject_type, effective_time, msg, create_time, update_time")
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            $count = Db::table("fa_subject_percentage_change")
                ->where($where)
                ->count();

            $result = ["total" => $count, "rows" => $list];

            return json($result);
        }
        return $this->view->fetch();
    }


    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post("row/a");
            if (empty($params['subject_name'])) {
                $this->error('请填写主体名');
            }
            if ($params['discount_percentage'] === '' || $params['discount_percentage'] === null) {
                $this->error('请填写折扣百分比');
            }
            if (empty($params['effective_time'])) {
                $this->error('请选择生效时间');
            }

            $params['discount_percentage'] = number_format($params['discount_percentage'], 4, '.', '');
            $params['status'] = isset($params['status']) ? $params['status'] : 0;
            $params['subject_type'] = isset($params['subject_type']) ? $params['subject_type'] : 0;
            $params['create_time'] = time();
            $params['update_time'] = time();

            if (!empty($params['effective_time']) && !is_numeric($params['effective_time'])) {
                $params['effective_time'] = strtotime($params['effective_time']);
            }

            $result = Db::table("fa_subject_percentage_change")->insert($params);
            if ($result) {
                $this->success();
            }
            $this->error("添加失败");
        }
        return $this->view->fetch();
    }


    public function del($ids = "")
    {
        if (!$this->request->isPost()) {
            $this->error(__("Invalid parameters"));
        }

        $ids = $ids ? $ids : $this->request->post("ids");
        if ($ids) {
            $where = ['id' => ['in', $ids]];
            $count = Db::table("fa_subject_percentage_change")->where($where)->delete();
            if ($count) {
                $this->success();
            }
        }
        $this->error(__('Parameter %s can not be empty', 'ids'));
    }

    /**
     * 执行定时修改政策
     */
    public function execute()
    {
        if (!$this->request->isPost()) {
            $this->error(__("Invalid parameters"));
        }

        $list = Db::table("fa_subject_percentage_change")
            ->where('status', 0)
            ->where('effective_time', '<=', time())
            ->select();

        if (empty($list)) {
            $this->success('没有待执行的任务');
        }

        $success = 0;
        $fail = 0;

        foreach ($list as $item) {
            $company = Db::table("fa_company")->where('company_name', $item['subject_name'])->find();
            if (!$company) {
                Db::table("fa_subject_percentage_change")->where('id', $item['id'])->update([
                    'status' => 2,
                    'msg' => '未找到对应主体',
                    'update_time' => time()
                ]);
                $fail++;
                continue;
            }

            $accountType = $item['subject_type'] == 1 ? 2 : 1;

            $result = Db::table("fa_company")->where('id', $company['id'])->update([
                'discount_percentage' => $item['discount_percentage'],
                'account_type' => $accountType,
                'update_time' => time()
            ]);

            if ($result) {
                Db::table("fa_subject_percentage_change")->where('id', $item['id'])->update([
                    'status' => 1,
                    'msg' => '修改成功',
                    'update_time' => time()
                ]);
                $success++;
            } else {
                Db::table("fa_subject_percentage_change")->where('id', $item['id'])->update([
                    'status' => 2,
                    'msg' => '修改失败',
                    'update_time' => time()
                ]);
                $fail++;
            }
        }

        $this->success("执行完成，成功{$success}条，失败{$fail}条");
    }

}
