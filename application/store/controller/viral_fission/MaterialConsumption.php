<?php

namespace app\store\controller\viral_fission;

use app\common\controller\Store;
use app\common\model\viral_fission\AdvGlobalMaterial;
use think\Db;

/**
 * 爆款裂变-素材消耗列表
 *
 * @icon fa fa-video-camera
 */
class MaterialConsumption extends Store
{
    protected $relationSearch = true;
    protected $searchFields = 'adv_id,cost_date,material_id,roi2_material_video_name';

    /**
     * @var AdvGlobalMaterial
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        // 这里假设有一个素材消耗的模型，实际需要根据数据库表结构调整
        $this->model = new AdvGlobalMaterial();
    }

    private function __filter(&$where)
    {
        $params = input();

        // 固定条件，费用必须大于0
        $where['gm.stat_cost_for_roi2'] = ['>', 0];
        $where['gm.adv_id'] = ['in',Db::name('company')->where(['store_id'=>$this->auth->id])->column('advertiser_id')];

        // 时间筛选
        $daterange = $params['daterange']??'';

        if ($daterange) {
            $dates = explode(' - ', $daterange);
            if (count($dates) == 2) {
                $start_date = strtotime(trim($dates[0]));
                $end_date = strtotime(trim($dates[1]));
                $where['gm.cost_date'] = ['between', [$start_date, $end_date]];
            }
        } else {
            $where['gm.cost_date']=['between', [strtotime('-5 days'), time()]];
        }
        // 金额筛选
        $min_cost = $params['min_cost'] ?? '';
        $max_cost = $params['max_cost']??'';
        if ($min_cost !== null && $min_cost !== '') {
            $where['gm.stat_cost_for_roi2']=[ '>=', $min_cost];
        }
        if ($max_cost !== null && $max_cost !== '') {
            $where['gm.stat_cost_for_roi2']=[ '<=', $max_cost];
        }
        // 广告主ID筛选
        $qc_id = $params['qc_id']??'';
        if ($qc_id !== null && $qc_id !== '') {
            $where['gm.adv_id']= $qc_id;
        }
        $company_name = $params['company_name']??'';
        if ($company_name !== null && $company_name !== '') {
            $where['com.company_name']=['like',"%".$company_name."%"];
        }

        $kahuna= $params['kahuna']??'';
        if ($kahuna !== null && $kahuna !== '') {
            $where['com.kahuna']= ['like',"%". $kahuna."%"];
        }

    }


    /**
     * 查看
     */
    public function index()
    {
        // 输入过滤
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {

            // Selectpage 转发
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            // 获取基础查询参数
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $this->__filter($param_where);


            // 解析基础条件（$where是数组或字符串）
            $query = $this->model
                ->alias('gm')
                ->field('gm.*, IF(dm.adopt_material_id IS NULL, 0, 1) AS is_fission,com.company_name,com.kahuna,com.store_id')
                ->join('fission_derive_material dm', 'gm.material_id = dm.adopt_material_id', 'left')
                ->join('company com','gm.adv_id=com.advertiser_id','left');
            // 应用基础条件
            $list = $query->where($param_where)->order("is_fission desc,stat_cost_for_roi2 desc")->paginate($limit);
            foreach ($list as &$row) {
                $row['is_fission'] = $row['is_fission'] ? '是' : '否';
                $row['fission_count'] = Db::name('fission_derive_material')->where(['old_material_id'=>$row['material_id']])->count();
                if($row['stat_cost_for_roi2'] >=300){
                    $msg_info= Db::name('fission_material_task')
                        ->where([
                            'adv_id' => $row['adv_id'],
                            'material_id' => $row['material_id'],
                            'fission_msg' => ['<>', '裂变生成超时，请重试']
                        ])
                        ->where(function($query) {
                            $query->where('status_code', '>', 0)
                                ->whereOr('fission_status', 'FAILED');
                        })
                        ->find();
                    if(!empty($msg_info['fission_msg'])){
                        $row['unfission_reason'] = $msg_info['fission_msg'];
                    }elseif(!empty($msg_info['status_message'])){
                        $row['unfission_reason'] = $msg_info['status_message'];
                    }

                }
//                $row['store_name'] = Db::name('store')->where(['id'=>$row['store_id']])->column('username');
            }

            $result = [
                'total' => $list->total(),
                'rows' => $list->items(),
            ];

            return json($result);
        }
        return $this->view->fetch();
    }


    public function get_stats()
    {
        $where['adv_id'] = ['in',Db::name('company')->where(['store_id'=>$this->auth->id])->column('advertiser_id')];

        $adopt = Db::name('fission_derive_material')->where(['adopt_status_message' => 'success'])->where($where)->count();
        $generated = Db::name('fission_derive_material')->where($where)->count();
        $data = ['data' => [
            'total' => Db::name('fission_global_material')->group('material_id')->where($where)->count(),
            'generated' => $generated,
            'adopted' => $adopt,
            'success_rate' => ($adopt / $generated) * 100,
        ],
            'code' => 1
        ];
        return json($data);
    }


}
