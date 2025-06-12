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
        $KeywordModel = new Keyword();
        $this->token();
        if ($this->request->isPost()) {
            $params = $this->request->post();
            if(empty($params['tag_id'])){
                $this->error('请选择标签！');
            }
            $cleaned = str_replace('，', ',', $params['keyword']); // 把中文的，转换成英文的,
            $keywordArr = array_filter(array_map('trim', explode(",", $cleaned))); // 转数组


            // 传入keyword去重
            $uniqueNames = [];
            foreach ($keywordArr as $item) {
                if (!isset($uniqueNames[$item])) {
                    $uniqueNames[$item] = $item;
                }
            }
            $insertData = array_values($uniqueNames);

            if (!empty($insertData)) {
                foreach ($insertData as $key => $item) {
                    $data = $KeywordModel->where("FIND_IN_SET(:word, keyword)", ['word' => $item])->find();
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
            $insert['keyword'] = implode(',', $insertData);
            $insert['tag_id'] =  $params['tag_id'];
            $res = $KeywordModel->save($insert);
            if (!$res) {
                $this->error('添加失败！');
            }
            $this->success('添加成功！');
        }
        $use_tab = $KeywordModel->distinct(true)->column('tag_id');
        $tag_list = $TagModel->where(['id' => ['not in', $use_tab]])->select();
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

            if (empty($data['tag_id'])){
                $this->error('标签不允许为空！');
            }

            $cleaned = str_replace('，', ',', $data['keyword']); // 把中文的，转换成英文的,
            $keywordArr = array_filter(array_map('trim', explode(",", $cleaned))); // 转数组


            // 传入keyword去重
            $uniqueNames = [];
            foreach ($keywordArr as $item) {
                if (!isset($uniqueNames[$item])) {
                    $uniqueNames[$item] = $item;
                }
            }
            $insertData = array_values($uniqueNames);

            if (!empty($insertData)) {
                foreach ($insertData as $key => $item) {
                    $check = $KeywordModel->where("FIND_IN_SET(:word, keyword)", ['word' => $item])->where(['id' => ['neq', $data['id']]])->find();
                    if ($check) {
                        unset($insertData[$key]);
                    }
                }
                if (empty($insertData)) {
                    $this->error('所填数据已存在，请检查！');
                }
            } else {
                $this->error('所填数据为空，请检查！');
            }
            $data['keyword'] = implode(',', $insertData);

            $result = $KeywordModel->update($data);
            if (!$result) {
                $this->error("更新失败");
            }
            $this->success('修改成功');
        }
        $row = $KeywordModel->where('id', $ids)->find();
        $this->view->assign("row", $row);
        $use_tab = $KeywordModel->distinct(true)->column('tag_id');
        $tag_list = $TagModel->where(['id' => ['not in', $use_tab]])->whereOr('id', $row['tag_id'])->select();
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