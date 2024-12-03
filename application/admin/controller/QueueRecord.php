<?php

namespace app\admin\controller;

use app\common\controller\Backend;
use think\Db;

Class QueueRecord extends Backend{
    public $model = null;
    public function _initialize()
    {
        parent::_initialize();
        $this->model = model('Queue');
    }

    public function  index()
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
//                ->where($where_str)
                ->order('id desc')
                ->paginate($limit);
            foreach ($list as $item){
                switch ($item['status']){
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

                $item['msg'] = substr( $item['msg'], 0, 65);
            }

            $result = array("total" => $list->total(), "rows" => $list->items());

            return json($result);
//            return json($result);
        }

        return $this->view->fetch();
    }
    //重启任务的逻辑就是删掉原来失败的重新构建

    public function rebuildOne($ids){
       $res =  $this->model->rebuildOne($ids);
       if($res){
        $this->success('重启成功');
       }else{
           $this->error('重启失败');
       }
    }
    public function rebuildAll(){
    }
}