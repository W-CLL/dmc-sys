<?php

namespace app\admin\controller\wechat_group;
use app\robotapi\model\QueueRobot;
use app\common\controller\Backend;
use think\Db;

class RobotQueue extends Backend
{
    public $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new QueueRobot();
    }

    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {

            //如果发送的来源是Selectpage，则转发到Selectpage
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }

            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $list = $this->model
                ->where($where)
                ->order('create_time desc')
                ->paginate($limit);
            foreach ($list as $item) {
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
                $item['msg'] = $item['msg'] ?? '-';
            }

            $result = array("total" => $list->total(), "rows" => $list->items());

            return json($result);
        }
        $class_list = [
            '千川账户【转账】'=>'千川账户【转账】',
            '千川账户【查询转账信息】'=>'千川账户【查询转账信息】',
            '共享钱包【转账】'=>'共享钱包【转账】',
            '共享钱包【查询转账信息】'=>'共享钱包【查询转账信息】',
            '腾讯广告【转账】' => '腾讯广告【转账】',
            '腾讯广告【全额转出】' => '腾讯广告【全额转出】',
            '腾讯广告【共享钱包转账】' => '腾讯广告【共享钱包转账】',
            '腾讯广告【转账后续操作】' => '腾讯广告【转账后续操作】',
            '回调请求'=>'回调请求'
        ];

        $select = build_select('job_name', $class_list, 0, ['class' => 'form-control selectpicker', 'data-rule' => 'required']);

        $this->assign('class_name_list', $select);
        return $this->view->fetch();
    }

    //重启任务的逻辑就是删掉原来失败的重新构建

    public function rebuildOne($ids)
    {
        $res = $this->model->rebootOne($ids);
        if ($res) {
            $this->success('重启成功');
        } else {
            $this->error('重启失败');
        }
    }

    //一键重启选中任务
    public function rebuildAll($ids = '')
    {
        if (!$ids) {
            $this->error('请选中一条记录');
        }
        $i = 1;
        $ids = explode(',', $ids);
        foreach ($ids as $id) {
            $info = $this->model->where('job_id', $id)->find();
            if ($info['status'] == 1) {
                continue;
            }
            $i++;
            $res = $this->model->rebootOne($info['id']);
            if (!$res) {
                $this->error('本次重启了' . $i . '条记录');
            }
        }
        $this->success('重启成功');
    }
}