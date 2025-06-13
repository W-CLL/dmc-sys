<?php

namespace app\admin\controller\risk_management;

use app\admin\model\MarkLog;
use app\common\controller\Backend;

class MarkLogIndex extends Backend
{
    public function index()
    {
        $MarkLogModel = new MarkLog();
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();

            $list = $MarkLogModel
                ->where($where)
                ->order($sort, $order)
                ->limit($offset,$limit)
                ->select();

            $count = $MarkLogModel->count();
            $result = array("total" => $count, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }

}