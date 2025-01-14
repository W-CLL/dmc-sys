<?php

namespace app\admin\controller\operate_monitor;


use app\admin\model\Company;
use app\admin\model\QcObjOptLog;
use app\admin\model\QcObj;
use app\common\controller\Backend;
use app\common\model\QcObjOptStats;
use think\Db;


class CompanyAdvObj extends Backend
{

    /**
     * @var QcObjOptStats
     */
    private $optStatsModel;
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
        $this->optStatsModel = new QcObjOptStats();
        $this->objModel = new QcObj();
        $this->optLogModel = new QcObjOptLog();
        $this->companyModel = new Company();
    }

    public function index($company_name = '')
    {
        if ($this->request->isAjax()) {
            $offset = input("offset", 0);
            $limit = input("limit", 10);

            $list = $this->optStatsModel // 主表 fa_qc_obj
            ->alias('obj')  // 别名 fqo
            ->join('company fco', 'obj.adv_id = fco.advertiser_id', 'LEFT')  // 左连接 fa_company 表
            ->join('company_setting fcs', 'fco.company_name = fcs.company_name', 'LEFT')  // 通过 company_name 字段连接
            ->field('fco.company_name,  
        fcs.is_white, 
        fcs.percentage,
        obj.*')  // 选择字段
//            ->where('obj.status', 1)  // 仅查询可操作的计划
                ->order('obj.total_opt_num', 'desc')
            ->limit($offset, $limit)
            ->select();  // 执行查询并获取结果

//dump($list);
//die;
//            $list =
//                $this->objModel
//                ->alias('o')
//                ->join('company_setting cs', 'c.company_name = cs.company_name', 'left')
//                ->field("c.company_name,cs.is_white,cs.percentage")
////            ->group('c.company_name')
//                ->where('c.company_name', $company_name)
//                ->limit($offset, $limit)
//                ->select();
            $count = $this->optStatsModel->count();//还有where条件
            $result = array("total" => $count, "rows" => $list);
            return json($result);
        }

        return $this->view->fetch();
    }
}