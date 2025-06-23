<?php

namespace app\admin\controller\risk_management;

use app\admin\model\MarkLog;
use app\admin\model\Tag;
use app\common\controller\Backend;
use app\common\model\AdvStats;
use think\Db;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\Exception;
use think\exception\DbException;

class RiskAdv extends Backend
{
    protected $handle_status = ['-1' => '无', '正常', '跟进中', '已注销', '已处理'];
    protected $obj_handle_status = [ '正常', '跟进中', '已删除', '已完成'];

    private function _filter(&$where, &$advIdFilter = null)
    {
        $params = json_decode(input('filter'), true);

        if (isset($params['keyword'])) {
            // 先用keyword过滤出符合的adv_id列表
            $advIds = (new \app\common\model\ObjProduct)->where('name', 'like', '%' . $params['keyword'] . '%')
                ->group('adv_id')
                ->column('adv_id');

            if (!empty($advIds)) {
                $advIdFilter = $advIds;
            } else {
                $advIdFilter = [0];
            }
        }
        if (isset($params['company_name'])) {
            $where['c.company_name'] = ['like', '%' . $params['company_name'] . '%'];
        }
        if (!empty($params['staff'])) {
            $where['ra.staff'] = $params['staff'];
        }

        if (isset($params['handle_status'])) {
            if ($params['handle_status'] != '-1') {
                $where['ra.handle_status'] = $params['handle_status'];
            }
        }
        if (!empty($params['sys_tag'])) {
            $where['ra.sys_tag'] = $params['sys_tag'];
        }
    }


    public function index()
    {
        $risk_adv_model = new AdvStats();
        $tag_model = new Tag();
        $tag = $tag_model->column('name', 'id');
        if ($this->request->isAjax()) {
            $where = [];
            $sort = input("sort", "adv_id");
            $order = input("order", "desc");
            $offset = input("offset", 0);
            $limit = input("limit", 10);
            $this->_filter($where, $advIdFilter);
            $staff = '';
            if (!empty($where['ra.staff'])) {
                $staff = $where['ra.staff'];
                unset($where['ra.staff']);
            }
            $order_filed = $sort.' '.$order.',adv_id desc';

            if ($advIdFilter !== null) {
                $where['ra.adv_id'] = ['in', $advIdFilter];
            }
            $base_field = [
                'ra.adv_id' => 'adv_id',
                'ra.id' => 'id',
                'ra.check_staff' => 'check_staff',
                'ra.business_staff' => 'business_staff',
                'ra.sys_tag' => 'sys_tag',
                'ra.keywords' => 'keywords',
                'ra.handle_status' => 'handle_status',
                'ra.tag' => 'tag',
                'ra.remark' => 'remark',
                'c.company_name' => 'company_name',
                'c.kahuna' => 'kahuna',
                's.one_class_score' => 'one_class_score',
                's.two_three_class_score' => 'two_three_class_score',
                'COUNT(rop.obj_id)' => 'total_obj',
            ];

//            foreach ($tag as $id => $name) {
//                $field['SUM(CASE WHEN rop.sys_tag = ' . $id . ' THEN 1 ELSE 0 END)'] = 'sys_tag' . $id . '_count';
//            }
//            $fields = array_merge($base_field, $field);
            $list = $risk_adv_model
                ->alias('ra')
                ->field($base_field)
                ->join((new \app\admin\model\Company)->getTable() . ' c', 'ra.adv_id = c.advertiser_id', 'LEFT')
                ->join((new \app\common\model\AdvScore)->getTable() . ' s', 'ra.adv_id = s.adv_id', 'LEFT')
                ->join((new \app\common\model\ObjProduct)->getTable() . ' rop', 'ra.adv_id = rop.adv_id', 'LEFT')
                ->where($where)
                ->where(['c.adv_status'=>1])//直接过滤已注销的账户
                ->where(function ($query) use ($staff) {
                    $query->whereOr(['ra.check_staff' => ['like', "%" . $staff . "%"]])
                        ->whereOr(['c.kahuna' => ['like', "%" . $staff . "%"]])
                        ->whereOr(['ra.business_staff' => ['like', "%" . $staff . "%"]]);
                })
                ->group('ra.adv_id, c.company_name, s.one_class_score')
                ->order($order_filed)
                ->limit($offset, $limit)
                ->select();

            foreach ($list as &$item) {
                $item['sys_tag_text'] = $item['sys_tag'] ? $tag[$item['sys_tag']] : '正常';
                $item['tag_text'] = $item['tag'] ? $tag[$item['tag']] : '-';
                $item['tag_obj_count'] = '总计划数：' . $item['total_obj'] . '条;';

                $item['status_text'] = $this->handle_status[(int)$item['handle_status']];
                foreach ($tag as $id => $name) {
                    $true_num = Db::name('risk_obj_product')->where(['adv_id'=>$item['adv_id'],'handle_status'=>['in',[0,1]],'sys_tag'=>$id])->count();
                    $item['tag_obj_count'] = $item['tag_obj_count'] . $name . "数：" . $true_num . "条;";
                }
            }
            // 查询总数
            $countQuery = $risk_adv_model
                ->alias('ra')
                ->field($base_field)
                ->join((new \app\admin\model\Company)->getTable() . ' c', 'ra.adv_id = c.advertiser_id', 'LEFT')
                ->join((new \app\common\model\AdvScore)->getTable() . ' s', 'ra.adv_id = s.adv_id', 'LEFT')
                ->join((new \app\common\model\ObjProduct)->getTable() . ' rop', 'ra.adv_id = rop.adv_id', 'LEFT')
                ->where($where)
                ->where(['c.adv_status'=>1])//直接过滤已注销的账户,不统计已经完成的计划
                ->where(function ($query) use ($staff) {
                    $query->whereOr(['ra.check_staff' => ['like', "%" . $staff . "%"]])
                        ->whereOr(['c.kahuna' => ['like', "%" . $staff . "%"]])
                        ->whereOr(['ra.business_staff' => ['like', "%" . $staff . "%"]]);
                })
                ->group('ra.adv_id, c.company_name, s.one_class_score')->count();
            $result = array("total" => $countQuery, "rows" => $list);
            return json($result);
        }
        $this->assign('handle_status_list', $this->handle_status);

        $this->assign('tag_list', $tag);
        return $this->view->fetch();
    }

    public function edit($ids = null)
    {
        $model = new AdvStats();
        $info = $model->where(['id' => $ids])->find();
        if (!$info) {
            $this->error('记录不存在');
        }
        $before = $info->toArray();
        if ($this->request->isPost()) {
            $data['id'] = input("id");
            $data['handle_status'] = input("handle_status");
            $data['check_staff'] = input("check_staff");
            $data['business_staff'] = input("business_staff");
            $data['remark'] = input("remark");
            if (empty($data['id'])) {
                $this->error("数据异常，请刷新后重试");
            }
            try {
                $info->save($data);
                $this->saveLog($before, $data);
            } catch (Exception $exception) {
                $this->error("保存失败:" . $exception->getMessage());
            }
            $this->success('保存成功');
        }

        $tag_list = Db::name('tag')->column('name', 'id');
        $this->assign('handle_status_list', $this->handle_status);
        $this->assign('tag_list', $tag_list);
        $this->assign('row', $info);
        return $this->view->fetch();
    }


    /**
     * 插入修改日志
     * @throws Exception
     */
    public function saveLog($info, $update_data)
    {
        if (!$info) {
            throw new Exception('源数据不存在');
        }
        $content = '';
        foreach ($update_data as $key => $item) {
            if ($info[$key] != $item) {
                $content .= $key . ": " . $info[$key] . " -> " . $item . ";";
            }
        }
        if (!$content) {
            throw new Exception('无修改数据');
        }
        $log = [
            'admin_id' => $this->auth->id,
            'operator' => $this->auth->username,
            'adv_id' => $info['adv_id'],  // $info里的如果跟表设置的字段不一致，请自行修改
            'content' => $content,
        ];
        return MarkLog::create($log);
    }

    /**
     * 获取操作日志列表
     * @param $ids
     * @return mixed
     * @throws Exception
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws DbException
     */
    public function get_log_list($ids = null)
    {

        $model = new AdvStats();
        $adv_id = $model->where(['id' => $ids])->value('adv_id');
        if (!$adv_id) {
            $this->error('千川记录不存在');
        }
        $log_model = new MarkLog();
        if ($this->request->isAjax()) {
            $fields_map = [
                'handle_status' => "处理状态",
                'check_staff' => '巡查人员',
                'business_staff' => '商务',
                'remark' => '备注',
                'tag' => '人工标签',
            ];
            $list = $log_model->where(['adv_id' => $adv_id])->order('create_time desc')->select();
            foreach ($list as &$item) {
                $content = explode(';', rtrim($item['content'], ';'));
                $result = [];
                $item['type'] = '处理账户';
                $handle_status = $this->handle_status;
                if($item['obj_id']){
                    $item['type'] = '处理计划';
                    $handle_status = $this->obj_handle_status;
                }
                foreach ($content as $value) {
                    if (preg_match('/([^:]+):\s*(.+)\s*->\s*(.+)/', $value, $matches)) {
                        $field = trim($matches[1]);
                        $old_value = (isset($matches[2]) && trim($matches[2], " \n\r\t\v") !== '') ? trim($matches[2], " \n\r\t\v") : "-";
                        $new_value = (isset($matches[3]) && trim($matches[3], " \n\r\t\v") !== '') ? trim($matches[3], " \n\r\t\v") : "-";
                        // 获取中文字段名
                        $chinese_field = $fields_map[$field] ?? $field;
                        // 处理 handle_status 的值映射
                        if ($field === 'handle_status') {
                            $old_value =$handle_status[$old_value] ?? $old_value;
                            $new_value = $handle_status[$new_value] ?? $new_value;
                        }
                        // 拼接结果
                        $result[] = $chinese_field."：".$old_value." 修改为：".$new_value;
                    }
                }

                $item['contents'] = implode(';',$result);

            }


            $count = $log_model->where(['adv_id' => $adv_id])->order('create_time desc')->count();
            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        $this->assign('ids', $ids);
        return $this->view->fetch();
    }


}