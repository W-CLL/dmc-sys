<?php

namespace app\admin\controller\risk_management;

use app\admin\model\Keyword;
use app\admin\model\Tag;
use app\common\controller\Backend;

class KeywordIndex extends Backend
{
    public function index()
    {
        $KeywordModel = new Keyword();
        $TagModel = new Tag();
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();


            $list = $KeywordModel
                ->with('tag')
                ->where($where)
                ->order($sort, $order)
                ->limit($offset,$limit)
                ->select();

            $count = $KeywordModel->count();
            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        $tag_list = $TagModel->select();
        $this->view->assign("tag_list", $tag_list);
        return $this->view->fetch();
    }


    public function add()
    {
        $TagModel = new Tag();
        $this->token();
        if ($this->request->isPost()) {
            $KeywordModel = new Keyword();
            $params = $this->request->post();
            if(empty($params['tag_id'])){
                $this->error('请选择标签！');
            }
            $cleaned = str_replace(["\r"], '', $params['keyword']);  // 移除所有换行符残留
            $keywordArr = array_filter(array_map('trim', explode("\n", $cleaned)));

            $insertData = array_map(function ($value) use ($params) {
                return [
                    'keyword' => $value,
                    'tag_id' => $params['tag_id'],
                ];
            }, $keywordArr);

            // 传入keyword去重
            $uniqueNames = [];
            foreach ($insertData as $item) {
                if (!isset($uniqueNames[$item['keyword']])) {
                    $uniqueNames[$item['keyword']] = $item;
                }
            }
            $insertData = array_values($uniqueNames);

            if (!empty($insertData)) {
                foreach ($insertData as $key => $item) {
                    $data = $KeywordModel->where(['keyword' => $item['keyword']])->find();
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


            $res = $KeywordModel->saveAll($insertData);
            if (!$res) {
                $this->error('添加失败！');
            }
            $this->success('添加成功！');
        }
        $tag_list = $TagModel->select();
        $this->view->assign("tag_list", $tag_list);
        return $this->view->fetch();
    }


    public function edit($ids = null)
    {
        $KeywordModel = new Keyword();
        $TagModel = new Tag();
        if ($this->request->isPost()) {
            $this->token();
            $data['id'] = input("id");
            $data['keyword'] = input("keyword");
            $data['tag_id'] = input("tag_id");

            if (empty($data['id'])) {
                $this->error("数据异常，请刷新后重试");
            }

            $res = $KeywordModel->where(['keyword' => $data['keyword']])->find();
            if ($res) {
                $this->error('该关键词已存在，请检查！');
            }

            $result = $KeywordModel->update($data);
            if (!$result) {
                $this->error("更新失败");
            }
            $this->success('修改成功');
        }
        $row = $KeywordModel->where('id', $ids)->find();
        $this->view->assign("row", $row);
        $tag_list = $TagModel->select();
        $this->view->assign("tag_list", $tag_list);

        return $this->view->fetch();
    }

    public function del($ids = "")
    {
        if ($ids) {
            $KeywordModel = new Keyword();
            $result = $KeywordModel->where('id', $ids)->delete();
            if ($result) {
                $this->success("删除成功");
            } else {
                $this->error("删除失败");
            }
        }
    }

}