<?php

namespace app\admin\controller\operate_monitor;


use app\admin\model\Company;
use app\admin\model\QcObjOptLog;
use app\admin\model\QcObj;
use app\common\controller\Backend;

use think\Db;


class CompanyAdvObj extends Backend
{
    /**
     * @var QcObj
     */
    private $objModel;
    /**
     * @var QcObjOptLog
     */
    private $optLogModel;

    public function _initialize()
    {
        parent::_initialize();
        $this->objModel = new QcObj();
        $this->optLogModel = new QcObjOptLog();
        $this->companyModel = new Company();
    }

    public function index($company_name = '')
    {
        if ($this->request->isAjax()) {
            $offset = input("offset", 0);
            $limit = input("limit", 10);
            $filter = input("filter");
            $inputData =  json_decode($filter,true);
            $list = $this->objModel
                ->alias('obj')
                ->join('company cs', 'obj.adv_id = cs.advertiser_id', 'left')
                ->field("cs.company_name,obj.adv_id,cs.is_white,cs.monitor_percentage,obj.obj_id")
                ->where(function ($query) use ($inputData){
                    if(isset($inputData['adv_id'])){
                        $query->where('obj.adv_id',$inputData['adv_id']);
                    }
                })
                ->order('cs.company_name', 'asc')
                ->limit($offset, $limit)
                ->select();


            $count = $this->objModel->where(function ($query) use ($inputData){
                if(isset($inputData['adv_id'])){
                    $query->where('adv_id',$inputData['adv_id']);
                }
            })->count();
            $result = array("total" => $count, "rows" => $list);
            return json($result);
        }

        return $this->view->fetch();
    }
}