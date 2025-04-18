<?php

namespace app\admin\controller\operate_monitor;


use app\admin\model\Company;
use app\admin\model\QcObjOptLog;
use app\admin\model\QcObj;
use app\common\controller\Backend;
use think\Db;
use think\Exception;


class CompanyAdv extends Backend
{

    /**
     * @var Company
     */
    private $companyModel;

    public function _initialize()
    {
        parent::_initialize();
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
            $is_white = input('is_white');
            $inputData =  json_decode($filter,true);

            $list = $this->companyModel
                ->field("company_name,advertiser_id,is_white,monitor_percentage")
                ->where(function ($query) use ($inputData){
                    if(isset($inputData['company_name'])){
                        $query->where('company_name',$inputData['company_name']);
                    }
                })
                ->order('monitor_percentage', 'asc')
                ->limit($offset, $limit);
            if($is_white === '1' || $is_white === '0'){
                $list = $list->where(['is_white' => $is_white]);
            }
            $list = $list->select();
            $count = $this->companyModel->where(function ($query) use ($inputData){
                if(isset($inputData['company_name'])){
                    $query->where('company_name',$inputData['company_name']);
                }
            });
            if($is_white === '1' || $is_white === '0'){
                $count = $count->where(['is_white' => $is_white]);
            }
            $count = $count->count();
            foreach ($list as &$item){
                $item['obj_num'] = $this->objModel->where(['adv_id'=>$item['advertiser_id']])->count();
            }
            $result = array("total" => $count, "rows" => $list);
            return json($result);
        }

        return $this->view->fetch();
    }

    /**
     * 设置白名单，百分比
     * @param $advId
     */
    public function checkSettingParams($data)
    {
        if(!isset($data['is_white'])){
            $this->error('请选择是否加入白名单');
        }
        if(!isset($data['percentage'])){
            $this->error('请输入百分比');
        }else{
            if(!is_numeric($data['percentage']) || $data['percentage'] < 0 || $data['percentage'] > 100){
                $this->error('百分比填写有误, 请填写0-100之间的数字');
            }
        }
        if(empty($data['ids'])){
            $this->error('请选择要设置的数据');
        }
    }
//    /**
//     * 设置公司下广告计划监测百分比、是否加入白名单
//     */
//    public function edit($ids='')
//    {
//        $companySetting = new \app\admin\model\CompanySetting();
//        if ($this->request->isPost()) {
//            $data = $this->request->post();
//            $this->checkSettingParams($data);
//            $ids = explode(',',$data['ids']);
//            $comName= $companySetting->where(['id'=>['in',$ids]])->column('company_name');
//            Db::startTrans();
//            try {
//                $companySetting->where(['id'=>['in',$ids]])->update(['is_white'=>$data['is_white']]);
//                //设置公司下的广告主为白名单
//                $this->companyModel->where(['company_name'=>['in',$comName]])->update(['is_white'=>$data['is_white']]);
//                Db::commit();
//                $this->success('设置成功!');
//            }catch (Exception $e){
//                Db::rollback();
//                $this->error('设置失败，联系管理员');
//            }
//
//        }
//        $this->assign('info',$this->companyModel->where('advertiser_id',$ids)->find());
//        return $this->view->fetch('edit');
//
//    }

    public function setting($ids='')
    {
        if ($this->request->isPost()) {
            $data = $this->request->post();
            $this->checkSettingParams($data);
            $advInfo = $this->companyModel->where('id',$data['ids'])->find();
            if($advInfo){
                $this->companyModel->where('id',$data['ids'])->update(['is_white'=>$data['is_white'],'monitor_percentage'=>$data['percentage']]);
                $this->success('设置成功!');
            }else{
                $this->error('未查到该广告主信息');
            }

        }
        $this->assign('info',$this->companyModel->where('advertiser_id',$ids)->find());
        return $this->view->fetch('');
    }


}