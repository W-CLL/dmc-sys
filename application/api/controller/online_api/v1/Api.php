<?php

namespace app\api\controller\online_api\v1;

use app\admin\model\Company;
use app\admin\model\CompanySetting;
use app\admin\model\QcObj as ObjModel;
use app\admin\model\QcGlobalObj as GlobalObjModel;
use app\common\model\QcAdvDayCost;
use think\Cache;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\Exception;
use think\exception\DbException;
use think\response\Json;
use think\Db;

class Api
{
    // 标准、全域共用
    public function ownerCompanyNamesApi($charge_name): Json
    {
        $companyModel = new Company();
        return json($companyModel->where(['kahuna' => ['like', "%" . $charge_name . "%"]])->column('company_name'));
    }

    //标准调用
    public function getOptCountCollectionApi(): Json
    {
//        return json($advList);
        $comModel = new Company();
        // 获取当前请求对象
        $request = \think\Request::instance();
        // 获取并解析JSON数据
        $jsonData = $request->getContent();

        $data = json_decode($jsonData, true);
        $start_time = $data['start_time'];
        $end_time = $data['end_time'];
        $adv_list = $data['adv_list'];
        $rule_message = [
            'start_time' => ["require" => 'start_time 必填'],
            'end_time' => ["require" => 'end_time 必填'],
            'adv_list' => ["require" => 'adv_list 必填'],
        ];
        $error = apiFieldValidate($rule_message, $data);
        if ($error) {
            return json(['status' => -1, 'msg' => $error]);
        }

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
            ->where(['adv_c.advertiser_id' => ['in', $adv_list], 'total_stats.total_num' => ['>', 0], 'is_white' => 0])
            ->field("adv_c.*, total_stats.total_num, company_stats.company_num")
            ->order('total_stats.total_num desc')
            ->select();

        return json($list);
    }


    //平均调用
    public function getAvOptCountCollectionApi(): Json
    {
        $params = input();
        $table = $params['table'] ;
        $start_time = $params['start_time'];
        $end_time = $params['end_time'];
        $adv_list = $params['adv_list'];
        $page = $params['page'];
        $rule_message = [
            'start_time' => ["require" => 'start_time 必填'],
            'end_time' => ["require" => 'end_time 必填'],
            'adv_list' => ["require" => 'adv_list 必填'],
            'page' => ["require" => 'adv_list 必填'],
        ];
        $error = apiFieldValidate($rule_message, $params);
        if ($error) {
            return json(['status' => -1, 'msg' => $error]);
        }
        if (!is_array($adv_list)) {
            // 处理解码失败的情况（如返回错误信息）
            return json(['status' => -1, 'msg' => '参数格式错误']);
        }
        $comModel = new Company();
        $list = $comModel
            ->alias('adv_c')
            ->join(
                "(SELECT adv_id, COUNT(*) AS cus_num FROM ". $table ." WHERE opt_time BETWEEN " . $start_time . " AND " . $end_time . " AND operator NOT IN (SELECT name FROM fa_ad_operator WHERE status = 1) GROUP BY adv_id) AS cus_stats",
                'adv_c.advertiser_id = cus_stats.adv_id',
                'left'
            )
            ->where(['adv_c.advertiser_id' => ['in', $adv_list], 'cus_stats.cus_num' => ['>', 0], 'is_white' => 0])
            ->field("adv_c.*, cus_stats.cus_num")
            ->order('cus_stats.cus_num desc')
            ->page($page, 100)
            ->select();

        return json($list);
    }


    // 标准、平均共用
    public function getObjListApi($adv_id, $needComNum): Json
    {
        $objModel = new ObjModel();
        $list = $objModel->where([
            'obj_status' => ['not in', ['DELETE', 'FROZEN']],
            'lab_ad_type' => "LAB_AD",
            "marketing_goal" => "LIVE_PROM_GOODS",//2025-5-22号标准商品全下线了，只有推直播间的了
            'opt_status' => ['not in', ['DELETE', 'FROZEN']],
            'adv_id' => $adv_id
        ])
            ->field('obj_id,adv_id')
            ->limit($needComNum)
//              ->fetchSql(true)
            ->column('obj_id');

        return json($list);
    }



    // 全域、标准共用
    public function notWhiteComApi(): Json
    {
        // 获取当前请求对象
        $request = \think\Request::instance();
        // 获取并解析JSON数据
        $jsonData = $request->getContent();
        $where = json_decode($jsonData, true);
        $rule_message = [
            'is_white' => [
                "require" => 'is_white 必填',
            ],
            "company_name"=>[
                "array" => "company_name必须是数组"
            ],
        ];
        $error = apiFieldValidate($rule_message, $where);
        if ($error) {
            return json(['status' => -1, 'msg' => $error]);
        }

        $comSettingModel = new CompanySetting();
        return json($comSettingModel->where($where)->column('percentage', 'company_name'));
    }


    // 全域、标准共用
    public function getAdvListApi(): Json
    {
        // 获取当前请求对象
        $request = \think\Request::instance();
        // 获取并解析JSON数据
        $jsonData = $request->getContent();

        $data = json_decode($jsonData, true);
        $companyNames = $data['company_name'];
        $page = $data['page'];
        $charge_name = $data['charge_name'];
        $limit = $data['limit'];
        $min_cost = $data['min_cost'] ?? 0;//最少消耗
        $type = $data['type'];
        $rule_message = [
            'company_name' => ["require" => 'company_name 必填'],
            'page' => ["require" => 'page 必填'],
            'charge_name' => ["require" => 'charge_name 必填'],
            'limit' => ["require" => 'limit 必填'],
            'type' => ["require" => 'type 必填'],
        ];
        $error = apiFieldValidate($rule_message, $data);
        if ($error) {
            return json(['status' => -1, 'msg' => $error]);
        }

        $comCostModel = new QcAdvDayCost();
        //获取公司下的广告主账户，每页1000条
        return json($comCostModel
            ->alias('cc')
            ->join('company com', 'cc.adv_id=com.advertiser_id', 'left')
            ->where(['com.company_name' => ['in', $companyNames], 'cc.cost_date' => ['between', [strtotime(date('Y-m-01')), time()]], 'cc.type' => $type])
            ->where(function ($query) use ($charge_name) {
                if ($charge_name) {
                    $query->where(['com.kahuna' => ['like', "%" . $charge_name . "%"]]);
                }
            })
            ->having('mon_cost > ' . $min_cost)
            ->field('cc.*,sum(cc.cost) as mon_cost')
            ->group('cc.adv_id')
            ->order('mon_cost desc')
            ->page($page)
            ->limit($limit)
            ->select());
    }


    // 全域调用

    /**
     * @throws ModelNotFoundException
     * @throws DbException
     * @throws DataNotFoundException
     */
    public function getGlobalOptCountCollectionApi(): Json
    {
//        return json($advList);
        $comModel = new Company();
        // 获取当前请求对象
        $request = \think\Request::instance();
        // 获取并解析JSON数据
        $jsonData = $request->getContent();

        $data = json_decode($jsonData, true);
        $start_time = $data['start_time'];
        $end_time = $data['end_time'];
        $adv_list = $data['adv_list'];
        $rule_message = [
            'start_time' => ["require" => 'start_time 必填'],
            'end_time' => ["require" => 'end_time 必填'],
            'adv_list' => ["require" => 'adv_list 必填'],
        ];
        $error = apiFieldValidate($rule_message, $data);
        if ($error) {
            return json(['status' => -1, 'msg' => $error]);
        }
        $list = $comModel
            ->alias('adv_c')
            ->join(
                "(SELECT adv_id, COUNT(*) AS total_num FROM fa_qc_global_obj_opt_log WHERE opt_time BETWEEN " . $start_time . " AND " . $end_time . " GROUP BY adv_id) AS total_stats",
                'adv_c.advertiser_id = total_stats.adv_id',
                'left'
            )
            ->join(
                "(SELECT adv_id, COUNT(*) AS company_num FROM fa_qc_global_obj_opt_log WHERE opt_time BETWEEN " . $start_time . " AND " . $end_time . " AND operator IN (SELECT name FROM fa_ad_operator WHERE status = 1) GROUP BY adv_id) AS company_stats",
                'adv_c.advertiser_id = company_stats.adv_id',
                'left'
            )
            ->where(['adv_c.advertiser_id' => ['in', $adv_list], 'total_stats.total_num' => ['>', 0], 'is_white' => 0])
            ->field("adv_c.*, total_stats.total_num, company_stats.company_num")
            ->order('total_stats.total_num desc')
            ->select();

        return json($list);
    }


    // 全域、平均,元素调用
    public function getGlobalObjListApi($adv_id, $needComNum): Json
    {
        $objModel = new GlobalObjModel();
        $list = $objModel->where([
            'obj_status' => ['not in', ['DELETE', 'FROZEN']],
            'opt_status' => ['not in', ['DELETE']],
            'marketing_goal' => 'VIDEO_PROM_GOODS',    // 只获取推商品的计划【推直播暂不支持修改】
            'adv_id' => $adv_id
        ])
            ->field('obj_id,adv_id')
            ->limit($needComNum)
            ->column('obj_id');

        return json($list);
    }


    // 平均调用
    public function getOwnerAdvListApi($page, $charge_name): Json
    {
        $companyModel = new Company();
        return json($companyModel
            ->where(function ($query) use ($charge_name) {
                if ($charge_name) {
                    $query->where(['kahuna' => ['like', "%" . $charge_name . "%"]]);
                }
            })
            ->where(['is_white' => 0, 'adv_status' => 1])
            ->order('advertiser_id desc')
//            ->page($page)
//            ->limit(100)
            ->column('advertiser_id'));
    }


    // 元素调用 弃用
    public function getRpaOptCountCollectionApi(): Json
    {
        $params = input();
        $start_time = $params['start_time'];
        $end_time = $params['end_time'];
        $adv_list = $params['adv_list'];
        $rule_message = [
            'start_time' => ["require" => 'start_time 必填'],
            'end_time' => ["require" => 'end_time 必填'],
            'adv_list' => ["require" => 'adv_list 必填'],
        ];
        $error = apiFieldValidate($rule_message, $params);
        if ($error) {
            return json(['status' => -1, 'msg' => $error]);
        }
        if (!is_array($adv_list)) {
            // 处理解码失败的情况（如返回错误信息）
            return json(['status' => -1, 'msg' => '参数格式错误']);
        }
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
            ->where(['adv_c.advertiser_id' => ['in', $adv_list], 'total_stats.total_num' => ['>', 0], 'is_white' => 0])
            ->field("adv_c.*, total_stats.total_num, company_stats.company_num")
            ->order('total_stats.total_num desc')
            ->select();

        return json($list);
    }


    // 元素调用

    /**
     * @throws Exception
     */
    public function getRpaObjListApi($adv_id, $needComNum): Json
    {

        $objModel = new ObjModel();
        $count = $objModel->where([
                'obj_status' => ['not in', ['DELETE', 'FROZEN']],
                'lab_ad_type' => "LAB_AD",
                'opt_status' => ['not in', ['DELETE', 'FROZEN']],
                'adv_id' => $adv_id]
        )->count('id');
        //托管计划少于4个才去执行
        $list = [];
        if ($count < 4) {
            $list = $objModel->where([
                'obj_status' => ['not in', ['DELETE', 'FROZEN']],
//                'lab_ad_type' => $lab_type,
                'opt_status' => ['not in', ['DELETE', 'FROZEN']],
                'adv_id' => $adv_id
            ])
                ->field('obj_id,adv_id')
                ->limit($needComNum)
                ->column('obj_id');
        }
        return json($list);
    }


    /**
     * 获取计划信息
     * @param $adv_id
     * @param $obj_id
     * @param string $type
     * @return Json
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function getObjInfo($adv_id, $obj_id, string $type = 'stand'): Json
    {
        if ($type == "stand") {
            $obj = new ObjModel();
        } else {
            $obj = new GlobalObjModel();
        }

        $obj_info = $obj->where(['adv_id' => $adv_id, 'obj_status' => ['not in', ['DELETE']], 'obj_id' => $obj_id])->find();
        if (!$obj_info) {
            return json([]);
        }
        return json($obj_info->getData());
    }

    /**
     * 获取广告主信息
     * @param $adv_id
     * @return Json
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws DbException
     */
    public function getAdvInfo($adv_id): Json
    {
        $company = new Company();
        $com_info = $company->where(['advertiser_id' => $adv_id])->find();
        if (!$com_info) {
            return json([]);
        }
        return json($com_info->getData());
    }


    // 插入线上redis的API。value为数组时，调用前需要用json_encode()处理
    public function pushRedisApi(string $key_name, $value)
    {
        return Cache::store('redis')->handler()->rPush($key_name, $value);
    }

    public function getIdApi(): Json
    {
        $POST = input();
        $table_name = $POST['table_name'];
        $where = $POST['where'];
        $rule_message = [
            'table_name' => ["require" => 'table_name 必填'],
            'where' => [
                "require" => 'where 必填',
                "array" => 'where 必须是数组',
            ],
        ];
        $error = apiFieldValidate($rule_message, $POST);
        if ($error) {
            return json(['status' => -1, 'msg' => $error]);
        }
        return json(Db::name($table_name)->where($where)->value('id'));
    }


    public function getHasCost(){
        $cost_model = new QcAdvDayCost();
        $POST = input();
        $rule_message = [
            'where' => [
                "require" => 'where 必填',
                "array" => 'where 必须是数组',
            ],
        ];
        $error = apiFieldValidate($rule_message, $POST);
        if ($error) {
            return json(['status' => -1, 'msg' => $error]);
        }
        return json(
            $cost_model->where($POST['where'])
            ->field('sum(cost) as total_cost')
            ->group('adv_id')
            ->find()->toArray()
        );
    }
}