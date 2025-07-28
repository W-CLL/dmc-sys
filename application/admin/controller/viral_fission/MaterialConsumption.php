<?php

namespace app\admin\controller\viral_fission;

use app\common\model\viral_fission\AdvGlobalMaterial;
use app\common\controller\Backend;
use think\Db;
use think\Exception;

/**
 * 爆款裂变-素材消耗列表
 *
 * @icon fa fa-video-camera
 */
class MaterialConsumption extends Backend
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

            // 解析基础条件（$where是数组或字符串）
            $query = $this->model
                ->alias('gm')
                ->field('gm.*, IF(dm.adopt_material_id IS NULL, 0, 1) AS is_fission')
                ->join('fission_derive_material dm', 'gm.material_id = dm.adopt_material_id','left');

            // 应用基础条件
            if ($where) {
                if (is_array($where)) {
                    foreach ($where as $cond) {
                        $query = $query->where($cond);
                    }
                } else {
                    $query = $query->where($where);
                }
            }

            // 固定条件，费用必须大于0
            $query = $query->where('gm.stat_cost_for_roi2', '>', 0);

            // 时间筛选
            $daterange = $this->request->get('daterange');
            if ($daterange) {
                $dates = explode(' - ', $daterange);
                if (count($dates) == 2) {
                    $start_date = strtotime(trim($dates[0]));
                    $end_date = strtotime(trim($dates[1]));
                    $query = $query->whereBetween('gm.cost_date', [$start_date, $end_date]);
                }
            } else{
                $query = $query->whereBetween('gm.cost_date',[strtotime('-5 days'),time()]);
            }

            // 金额筛选
            $min_cost = $this->request->get('min_cost');
            $max_cost = $this->request->get('max_cost');
            if ($min_cost !== null && $min_cost !== '') {
                $query = $query->where('gm.stat_cost_for_roi2', '>=', $min_cost);
            }
            if ($max_cost !== null && $max_cost !== '') {
                $query = $query->where('gm.stat_cost_for_roi2', '<=', $max_cost);
            }

            // 广告主ID筛选
            $qc_id = $this->request->get('qc_id');
            if ($qc_id !== null && $qc_id !== '') {
                $query = $query->where('gm.adv_id', '=', $qc_id);
            }

            $list = $query->order("is_fission desc,stat_cost_for_roi2 desc")->paginate($limit);
            foreach ($list as &$row) {

                $row['is_fission'] = $row['is_fission'] ? '是' : '否';
            }

            $result = [
                'total' => $list->total(),
                'rows'  => $list->items(),
            ];

            return json($result);
        }
        return $this->view->fetch();
    }

}
