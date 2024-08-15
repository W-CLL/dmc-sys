<?php

namespace app\admin\controller;

use app\common\controller\Backend;
use qywx\Api;
use think\Db;

class FinancialStaff extends Backend
{


    public function index()
    {
        if (request()->isAjax()){
            $sort = input("sort","id");
            $order = input("order","desc");
            $offset = input("offset",0);
            $limit = input("limit",10);

            $list = Db::name("financial_staff")
                ->order($sort, $order)
                ->limit($offset,$limit)
                ->select();
            $count = Db::name("financial_staff")->count();
            $result = array("total" => $count, "rows" => $list);
            return json($result);
        }
        return $this->view->fetch();
    }

    public function list_save(){
        if (request()->isAjax()){

            $member_data = Api::get_finance_department_member();
            $data = [];
            foreach ($member_data as $k=>$v){
                $data[] = [
                    "name" => $v["name"],
                    "user_id" => $v["userid"]
                ];
            }

            Db::query('truncate table fa_financial_staff');
            Db::name("financial_staff")->insertAll($data);
            return json(["code"=>1,"msg"=>"更新成功"]);
        }
    }

    public function multi($ids = null)
    {
        if (request()->isAjax()){
            $params = input("params");
            list($key, $value) = explode('=', $params); // 分割字符串
            $data = [$key => intval($value)];
            Db::name("financial_staff")->where("id",$ids)->update($data);
            $this->success();

        }
    }
}