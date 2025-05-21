<?php

namespace app\api\controller;

use app\admin\model\Company;
use app\admin\model\CompanySetting;
use app\admin\model\QcObj as ObjModel;
use app\common\model\QcAdvDayCost;
use think\Controller;
use think\response\Json;

class ObjRelApiList extends Controller
{

    /**
     * 根据广告主账户获取操作次数列表（AutoUpdateObjName类调用）
     * @return Json
     */
    public function getOptCountCollectionApiAuto($start_time, $end_time, $advList)
    {
        $comModel = new Company();
        $list = $comModel
            ->alias('adv_c')
            ->join(
                "(SELECT adv_id, COUNT(*) AS total_num FROM fa_qc_obj_opt_log WHERE opt_time BETWEEN " . $start_time . " AND " . $end_time . " GROUP BY adv_id) AS total_stats",
                'adv_c.advertiser_id = total_stats.adv_id',
                'left'
            )
            ->join(
                "(SELECT adv_id, COUNT(*) AS company_num FROM fa_qc_obj_opt_log WHERE opt_time BETWEEN " . $start_time . " AND " . $end_time . " AND operator IN (SELECT name FROM fa_ad_operator WHERE status = 1) GROUP BY adv_id) AS company_stats",
                'adv_c.advertiser_id = company_stats.adv_id',
                'left'
            )
            ->where(['adv_c.advertiser_id' => ['in', $advList], 'total_stats.total_num' => ['>', 0]])
            ->field("adv_c.*, total_stats.total_num, company_stats.company_num")
            ->order('total_stats.total_num desc')
            ->select();

        return json($list);
    }


    /**
     * 获取计划列表（AutoUpdateObjName类调用）
     * @param $adv_id
     * @param $needComNum
     * @return Json
     */
    public function getObjListApiAuto($adv_id, $needComNum): Json
    {
        $objModel = new ObjModel();
        $list = $objModel->where([
            'obj_status' => ['not in', ['DELETE',  'FROZEN']],
            'lab_ad_type' => "LAB_AD",
            'opt_status' => ['not in', ['DELETE',  'FROZEN']],
            'adv_id' => $adv_id
        ])
            ->field('obj_id,adv_id')
            ->limit($needComNum)
            ->column('obj_id');
        return json($list);
    }

    /**
     * 获取指定负责人白名单公司
     * @param $charge_name
     * 负责人名字，如：王五
     * @return array|string
     */
    public function getNotWhiteComList($charge_name)
    {
        $comSettingModel = new CompanySetting();
        $companyModel = new Company();
        $ownerCompanyNames = $companyModel->where(['kahuna' => ['like', "%" . $charge_name . "%"]])->column('company_name');
        $where['company_name'] = ['in', $ownerCompanyNames];
        $where['is_white'] = 0;
        return $comSettingModel->where($where)->column('percentage', 'company_name');
    }


    public function get($page,$charge_name,$limit=100)
    {
       $white_list = $this->getNotWhiteComList($charge_name);
        $company_names = array_keys($white_list);
        $com_cost_model = new QcAdvDayCost();
        $adv_list = $com_cost_model
            ->alias('cc')
            ->join('company com', 'cc.adv_id=com.advertiser_id', 'left')
            ->where(['com.company_name' => ['in', $company_names], 'cc.cost_date' => ['between', [strtotime(date('Y-m-01')), time()]]])
            ->where(['com.adv_status'=>1])
            ->where(function ($query) use ($charge_name) {
                if ($charge_name) {
                    $query->where(['com.kahuna' => ['like', "%" . $charge_name . "%"]]);
                }
            })
            ->field('cc.*,sum(cc.cost) as mon_cost')
            ->group('cc.adv_id')
            ->order('mon_cost desc')
            ->page($page)
            ->limit($limit)
            ->select();
        return $adv_list;
    }


}