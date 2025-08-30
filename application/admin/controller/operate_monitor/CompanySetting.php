<?php

namespace app\admin\controller\operate_monitor;


use app\admin\model\Company;
use app\admin\model\QcObjOptLog;
use app\admin\model\QcObj;
use app\common\controller\Backend;
use app\common\model\QcObjOptStats;
use think\Db;
use think\Exception;


class CompanySetting extends Backend
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


    /**
     * 公司列表
     */
    public function index()
    {
        if ($this->request->isAjax()) {
            $offset = input("offset", 0);
            $limit = input("limit", 10);
            $input = json_decode(input('filter'),true);
            $is_white = input('is_white');
//dump($input);
//die;
            $list = $this->companyModel
                ->alias('c')
                ->join('company_setting cs', 'c.company_name = cs.company_name', 'left')
                ->where(function ($query) use ($input,$is_white){
                    $where = [];
                    if(isset($input['company_name'])){
                        $where['c.company_name'] = ['like',"%".$input['company_name']."%"];
//                        $query->where(['c.company_name'=>['like',"%".$input['company_name']."%"]]);
                    }
                    if(strlen($is_white)>0){
                        $where['cs.is_white'] = $is_white;
//                        $query->where(['cs.is_white'=>$input['is_white']]);
                    }
                    $query->where($where);
                })
                ->field("c.company_name,cs.is_white,cs.percentage,cs.id,count(c.id) as adv_num")
                ->group('c.company_name')
                ->order('adv_num desc')
                ->limit($offset, $limit)
//                ->fetchSql(true)
                ->select();
            foreach ($list as &$item){
                $item['percentage'] = $item['percentage']."%";
            }

            $count = $this->companyModel
                ->alias('c')
                ->join('company_setting cs', 'c.company_name = cs.company_name', 'left')
                ->where(function ($query) use ($input,$is_white){
                    $where = [];
                    if(isset($input['company_name'])){
                        $where['c.company_name'] = ['like',"%".$input['company_name']."%"];
//                        $query->where(['c.company_name'=>['like',"%".$input['company_name']."%"]]);
                    }
                    if(strlen($is_white)>0){
                        $where['cs.is_white'] = $is_white;
//                        $query->where(['cs.is_white'=>$input['is_white']]);
                    }
                    $query->where($where);
                })
                ->group('c.company_name')
                ->count();
            $result = array("total" => $count, "rows" => $list);
            return json($result);
        }
        return $this->view->fetch('index');
    }


    public function checkSettingParams($data,$is_id=true)
    {
        if(!isset($data['is_white'])){
            $this->error('请选择是否加入白名单');
        }
        if(!isset($data['percentage'])){
            $this->error('请输入百分比');
        }else{
            if(!is_numeric($data['percentage']) || $data['percentage'] < 0 || $data['percentage'] > 200){
                $this->error('百分比填写有误, 请填写0-200之间的数字');
            }
        }
        if(empty($data['ids']) && $is_id){
            $this->error('请选择要设置的数据');
        }
    }
    /**
     * 设置公司下广告计划监测百分比、是否加入白名单
     */
    public function edit($ids='')
    {
        $companySetting = new \app\admin\model\CompanySetting();
        if ($this->request->isPost()) {
            $data = $this->request->post();
            $this->checkSettingParams($data);
            $ids = explode(',',$data['ids']);
            $comName= $companySetting->where(['id'=>['in',$ids]])->column('company_name');
            Db::startTrans();
            try {
                $companySetting->where(['id'=>['in',$ids]])->update(['is_white'=>$data['is_white'],'percentage'=>$data['percentage']]);
                //设置公司下的广告主为白名单
                $this->companyModel->where(['company_name'=>['in',$comName]])->update(['is_white'=>$data['is_white'],'monitor_percentage'=>$data['percentage']]);
                Db::commit();
                $this->success('设置成功!');
            }catch (Exception $e){
                Db::rollback();
                $this->error('设置失败，联系管理员');
            }

        }
        $this->assign('info',$companySetting->where('id',$ids)->find());
        return $this->view->fetch('edit');

    }

    public function setting($ids='')
    {
        $companySetting = new \app\admin\model\CompanySetting();
        if ($this->request->isPost()) {
            $data = $this->request->post();
            $this->checkSettingParams($data);
            $comInfo = $companySetting->where('id',$data['ids'])->find();
            Db::startTrans();
            try {
                $companySetting->where('id',$data['ids'])->update(['is_white'=>$data['is_white'],'percentage'=>$data['percentage']]);
                //设置公司下的广告主为白名单
                $this->companyModel->where(['company_name'=>$comInfo['company_name']])->update(['is_white'=>$data['is_white'],'monitor_percentage'=>$data['percentage']]);
                Db::commit();
                $this->success('设置成功!');
            }catch (Exception $e){
                Db::rollback();
                $this->error('设置失败，联系管理员'.$e->getMessage());
            }

        }
        $this->assign('info',$companySetting->where('id',$ids)->find());
        return $this->view->fetch('');
    }

    public function edit_text($ids='')
    {
        $companySetting = new \app\admin\model\CompanySetting();
        if ($this->request->isPost()) {
            $data = $this->request->post();
            $this->checkSettingParams($data,false);
            $company_list= explode(',',rtrim(str_replace(["\n", "\r", "\t", " "], "", $data['company_text']),','));
//            $company_list = array_map(function($item) {
//                return trim($item, " ,"); // 清除空格和逗号
//            }, explode(',', $data['company_text']));
//
//// 过滤空值
//            $company_list = array_filter($company_list);
//            dump($company_list);
//            die;
            Db::startTrans();
            try {
                $companySetting->where(['company_name'=>['in',$company_list]])->update(['is_white'=>$data['is_white'],'percentage'=>$data['percentage']]);
                //设置公司下的广告主为白名单
                $this->companyModel->where(['company_name'=>['in',$company_list]])->update(['is_white'=>$data['is_white'],'monitor_percentage'=>$data['percentage']]);
                Db::commit();
                $this->success('设置成功!');
            }catch (Exception $e){
                Db::rollback();
                $this->error('设置失败，联系管理员');
            }

        }
        $this->assign('info',$companySetting->where('id',$ids)->find());
        return $this->view->fetch('edit_text');

    }

}