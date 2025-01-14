<?php

namespace app\admin\controller\operate_monitor;


use app\admin\model\Company;
use app\admin\model\QcObjOptLog;
use app\admin\model\QcObj;
use app\common\controller\Backend;
use app\common\model\QcObjOptStats;
use think\Db;


class CompanyAdv extends Backend
{

    /**
     * @var Company
     */
    private $companyModel;

    public function _initialize()
    {
        parent::_initialize();
        $this->optStatsModel = new QcObjOptStats();
        $this->objModel = new QcObj();
        $this->optLogModel = new QcObjOptLog();
        $this->companyModel = new Company();
    }
    public function index()
    {
        if ($this->request->isAjax()) {
            $offset = input("offset", 0);
            $limit = input("limit", 10);
            $filter = input("filter");
            $inputData =  json_decode($filter,true);

            $list = $this->companyModel
                ->alias('c')
                ->join('company_setting cs', 'c.company_name = cs.company_name', 'left')
                ->field("c.company_name,c.advertiser_id,cs.is_white,cs.percentage")
                ->where(function ($query) use ($inputData){
                    if(isset($inputData['company_name'])){
                        $query->where('c.company_name',$inputData['company_name']);
                    }
                })
                ->order('c.company_name', 'asc')
                ->limit($offset, $limit)

                ->select();
            $count = $this->companyModel->where(function ($query) use ($inputData){
                if(isset($inputData['company_name'])){
                    $query->where('company_name',$inputData['company_name']);
                }
            })->count();
            $result = array("total" => $count, "rows" => $list);
            return json($result);
        }

        return $this->view->fetch();
    }

    /**
     * 设置白名单，百分比
     * @param $advId
     */
    public function setting($advId = '')
    {
    }
}