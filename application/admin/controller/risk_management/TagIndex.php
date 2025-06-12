<?php

namespace app\admin\controller\risk_management;

use app\admin\model\Keyword;
use app\admin\model\Tag;
use app\common\controller\Backend;

class TagIndex extends Backend
{
    public function index()
    {
        $TagModel = new Tag();
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();


            $list = $TagModel
                ->where($where)
                ->order($sort, $order)
                ->limit($offset,$limit)
                ->select();

            $count = $TagModel->count();
            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }

    public function add()
    {
        $this->token();
        if ($this->request->isPost()) {
            $TagModel = new Tag();
            $params = $this->request->post();
            $cleaned = str_replace(["\r"], '', $params['name']);  // 移除所有换行符残留
            $tagArr = array_filter(array_map('trim', explode("\n", $cleaned)));

            $insertData = array_map(function ($value) use ($params) {
                return [
                    'name' => $value,
                ];
            }, $tagArr);
            // 去重
            $uniqueNames = [];
            foreach ($insertData as $item) {
                if (!isset($uniqueNames[$item['name']])) {
                    $uniqueNames[$item['name']] = $item;
                }
            }
            $insertData = array_values($uniqueNames);
            if (!empty($insertData)) {
                foreach ($insertData as $key => $item) {
                    $data = $TagModel->where(['name' => $item['name']])->find();
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



            $res = $TagModel->saveAll($insertData);
            if (!$res) {
                $this->error('添加失败！');
            }
            $this->success('添加成功！');
        }
        return $this->view->fetch();
    }


    public function edit($ids = null)
    {
        $TagModel = new Tag();
        if ($this->request->isPost()) {
            $this->token();
            $data['id'] = input("id");
            $data['name'] = input("name");

            if (empty($data['id'])) {
                $this->error("数据异常，请刷新后重试");
            }

            $res = $TagModel->where(['name' => $data['name']])->find();
            if ($res) {
                $this->error('该关键词已存在，请检查！');
            }

            $result = $TagModel->update($data);
            if (!$result) {
                $this->error("更新失败");
            }
            $this->success('修改成功');
        }
        $row = $TagModel->where('id', $ids)->find();
        $this->view->assign("row", $row);

        return $this->view->fetch();
    }


    public function del($ids = "")
    {
        if ($ids) {
            $KeywordModel = new Keyword();
            $result = $KeywordModel->where('tag_id', $ids)->find();
            if ($result) {
                $this->error("删除失败，该标签已被使用，请勿删除！");
            } else {
                Tag::destroy($ids);
                $this->success("删除成功");
            }
        }
    }

}