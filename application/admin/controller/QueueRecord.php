<?php

namespace app\admin\controller;

use app\common\controller\Backend;
use app\common\model\Queue;
use think\Db;

class QueueRecord extends Backend
{
    public $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = model('Queue');
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
                ->order('id desc')
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

                $item['msg'] = substr($item['msg'], 0, 65);
            }

            $result = array("total" => $list->total(), "rows" => $list->items());

            return json($result);
        }
        $queue = new Queue();
        $class_list = $queue->group('class_name')->column('job_name', 'class_name');

//        $result = array_combine(
//            array_map(function($key) {
//                return basename($key);
//            }, array_keys($class_list)),
//            $class_list
//        );
        $result = array_combine(
            array_map(function ($key) {
                // 去除前缀部分
                return str_replace('app\\job\\', '', $key);
            }, array_keys($class_list)),
            $class_list
        );

        $select = build_select('class_name', $result, 0, ['class' => 'form-control selectpicker', 'data-rule' => 'required']);

        $this->assign('class_name_list', $select);
        return $this->view->fetch();
    }

    //重启任务的逻辑就是删掉原来失败的重新构建

    public function rebuildOne($ids)
    {
        $res = $this->model->rebuildOne($ids);
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
        $i=1;
        $ids = explode(',',$ids);
        foreach ($ids as $id){
            $info = $this->model->where('job_id',$id)->find();
            if($info['status'] == 1){
                continue;
            }
            $i++;
            $res = $this->model->rebuildOne($info['id']);
            if(!$res){
                $this->error('本次重启了'.$i.'条记录');
            }
        }
        $this->success('重启成功');
    }
}