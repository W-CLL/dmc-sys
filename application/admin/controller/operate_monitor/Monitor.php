<?php

namespace app\admin\controller\operate_monitor;


use app\common\controller\Backend;


class Monitor extends Backend
{

    protected $relationSearch = true;
    protected $searchFields = 'id,username';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = model('Monitor');
    }

    /**
     * 查看
     */
    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $list = $this->model
                ->where($where)
                ->paginate($limit);
            $result = array("total" => $list->total(), "rows" => $list->items());

            return json($result);
        }
        return $this->view->fetch();
    }

    /**
     * 添加
     */
    public function add()
    {

//        parent::add();
        $this->token();
        if ($this->request->isPost()) {
            $params = $this->request->post();
            $nameArr = explode(';', trim($params['name'], ';'));
            $insertData = array_map(function ($value) use ($params) {
                return [
                    'name' => $value,
                    'type' => $params['type'],
                ];
            }, $nameArr);

            if (!empty($insertData)) {
                foreach ($insertData as $key => $item) {
                    $data = $this->model->where(['name' => $item['name'], 'type' => $item['type']])->find();
                    if ($data) {
                        unset($insertData[$key]);
                    }
                }
                if (empty($insertData)) {
                    $this->error('所填数据已存在，请检查！');
                }
            } else {
                $this->error('所填数据为空，请检查！');
            }


            $res = $this->model->saveAll($insertData);
            if (!$res) {
                $this->error('添加失败！');
            }
            $this->success('添加成功！');
        }
        return $this->view->fetch();
    }


    /**
     * 编辑
     */
    public function edit($ids = null)
    {
        if ($this->request->isPost()) {
            $this->token();
            $data['id'] = input("id");
            $data['name'] = input("name");
            $data['type'] = input("type");

            if (empty($data['id'])) {
                $this->error("数据异常，请刷新后重试");
            }

            $res = $this->model->where(['name' => $data['name'], 'type' => $data['type']])->find();
            if ($res) {
                $this->error('该角色已存在名称，请检查！');
            }

            $store = $this->model->update($data);
            if (!$store) {
                $this->error("更新失败");
            }
            $this->success('修改成功');
        }
        $row = $this->model->where('id', $ids)->find();
        $this->modelValidate = true;
        $this->view->assign("row", $row);

        return $this->view->fetch();
    }


}