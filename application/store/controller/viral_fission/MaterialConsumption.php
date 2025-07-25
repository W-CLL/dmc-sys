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

    /**
     * 查看
     */
    public function index()
    {
        //设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            //如果发送的来源是Selectpage，则转发到Selectpage
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $param_where = [];
            $param_where['adv_id'] = ['in',Db::name('company')->where(['store_id'=>$this->auth->id])->column('advertiser_id')];

            // 添加时间筛选
            $daterange = $this->request->get('daterange');
            if ($daterange) {
                $dates = explode(' - ', $daterange);
                if (count($dates) == 2) {
                    $start_date = strtotime(trim($dates[0]));
                    $end_date = strtotime(trim($dates[1]));
                    $param_where['cost_date'] = [ 'between', [$start_date, $end_date]];
                }
            }

            // 添加金额筛选
            $min_cost = $this->request->get('min_cost');
            $max_cost = $this->request->get('max_cost');
            $param_where['stat_cost_for_roi2'] = ['>',0];
            if ($min_cost !== null && $min_cost !== '') {
                $param_where['stat_cost_for_roi2'] = ['>=', $min_cost];
                if ($max_cost !== null && $max_cost !== '') {
                    if(isset($param_where['stat_cost_for_roi2'])){
                        $param_where['stat_cost_for_roi2'] = ['between',[$min_cost,$max_cost]];
                    }
                }
            }


            // 添加千川ID筛选
            $qc_id = $this->request->get('qc_id');
            if ($qc_id !== null && $qc_id !== '') {
                $param_where['adv_id'] =  $qc_id;
            }
            $list = $this->model
                ->where($where)
                ->where($param_where)
                ->order('stat_cost_for_roi2', 'desc')
                ->paginate($limit);

            foreach ($list as $row) {
                // 获取裂变状态和链接
//                $fission_info = $this->getFissionInfo($row['id']);
//                $row['fission_status'] = $fission_info['status'];
//                $row['fission_url'] = $fission_info['url'];
//                $row['fission_remark'] = $fission_info['remark'];
            }

            $result = array("total" => $list->total(), "rows" => $list->items());
            return json($result);
        }
        return $this->view->fetch();
    }

}
