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
        $material_id = $params['material_id']??'';
        if ($material_id !== null && $material_id !== '') {
            $where['gm.material_id']= $material_id;
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
                ->field('gm.adv_id,gm.material_id,MIN(gm.cost_date) as min_cost_date,MAX(gm.cost_date) as max_cost_date,SUM(gm.stat_cost_for_roi2) as stat_cost_for_roi2,SUM(gm.total_pay_order_count_for_roi2) as total_pay_order_count_for_roi2,SUM(gm.total_prepay_and_pay_order_roi2) as total_prepay_and_pay_order_roi2,COUNT(DISTINCT gm.cost_date) as date_count, IF(dm.adopt_material_id IS NULL, 0, 1) AS is_fission,com.company_name,com.kahuna,com.store_id,com.first_industry_name,com.second_industry_name')
                ->join('fission_derive_material dm', 'gm.material_id = dm.adopt_material_id', 'left')
                ->join('company com','gm.adv_id=com.advertiser_id','left');
            // 应用基础条件
            // 修改查询方式，按 adv_id 和 material_id 分组并合并数据
            $list = $query->where($param_where)
                ->group('gm.adv_id, gm.material_id')
                ->order("is_fission desc,stat_cost_for_roi2 desc")
                ->paginate($limit);

            foreach ($list as &$row) {
                // 显示日期范围格式
                if ($row['min_cost_date'] == $row['max_cost_date']) {
                    $row['create_time_text'] = date('Y-m-d', $row['min_cost_date']);
                } else {
                    $row['create_time_text'] = date('Y-m-d', $row['min_cost_date']) . ' —— ' . date('Y-m-d', $row['max_cost_date']);
                }
                
                // 根据日期范围决定是否显示ROI值
                if ($row['date_count'] == 1) {
                    // 只有一天数据时显示ROI值
                    $row['roi_display'] = $row['total_prepay_and_pay_order_roi2'];
                } else {
                    // 多天数据时显示"-"
                    $row['roi_display'] = '-';
                }
                
                $row['is_fission'] = $row['is_fission'] ? '是' : '否';
                $row['fission_count'] = Db::name('fission_derive_material')->where(['old_material_id'=>$row['material_id']])->count();
//                if($row['stat_cost_for_roi2'] >=300){
                    $msg_info=Db::name('fission_material_task')
                        ->where([
                            'adv_id' => $row['adv_id'],
                            'material_id' => $row['material_id']
                        ])
//                        ->where(function($query) {
//                            $query->where('fission_msg', '<>', '裂变生成超时，请重试')
//                                ->whereOr('fission_msg', 'NULL');
//                        })
//                        ->where(function($query) {
//                            $query->where('status_code', '>', 0)
//                                ->whereOr('fission_status', 'FAILED');
//                        })
                        ->order('update_time desc')
                        ->find();

                    if(!empty($msg_info['fission_msg'])){
                        $row['unfission_reason'] = $msg_info['fission_msg'];
                    }elseif(!empty($msg_info['status_code'])){
                        $row['unfission_reason'] = $msg_info['status_message'];
                    }

//                }
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


    /**
     * 获取图表数据
     */
    public function get_chart()
    {
        // 获取时间段参数
        $period = input('period', '15');

        // 根据时间段计算时间范围
        $timeRange = $this->calculateTimeRange($period);
        $start_timestamp = $timeRange['start'];
        $end_timestamp = $timeRange['end'];

        // 实时查询折线图数据
        $result = $this->getRealTimeLineData($start_timestamp, $end_timestamp);

        return json(['code' => 1, 'data' => $result]);
    }

    /**
     * 计算时间范围
     */
    private function calculateTimeRange($period)
    {
        // 获取今天的开始和结束时间戳
        $today_start = strtotime(date('Y-m-d 00:00:00'));
        $today_end = strtotime(date('Y-m-d 23:59:59'));

        switch ($period) {
            case '7':
                // 近7天：从今天往回算7天 (今天-6天到今天)
                $start_timestamp = strtotime('-6 days', $today_start);
                $end_timestamp = $today_end;
                break;
            case '15':
                // 近15天：从今天往回算15天 (今天-14天到今天)
                // 例如：今天8月2日，往回15天就是7月19日-8月2日
                $start_timestamp = strtotime('-14 days', $today_start);
                $end_timestamp = $today_end;
                break;
            case 'current_month':
                // 本月：从本月1号00:00:00到今天23:59:59
                $start_timestamp = strtotime(date('Y-m-01 00:00:00'));
                $end_timestamp = $today_end;
                break;
            case 'last_month':
                // 上月：从上月1号00:00:00到上月最后一天23:59:59
                $start_timestamp = strtotime(date('Y-m-01 00:00:00', strtotime('first day of last month')));
                $end_timestamp = strtotime(date('Y-m-t 23:59:59', strtotime('last day of last month')));
                break;
            default:
                // 默认近15天
                $start_timestamp = strtotime('-14 days', $today_start);
                $end_timestamp = $today_end;
                break;
        }

        return [
            'start' => $start_timestamp,
            'end' => $end_timestamp
        ];
    }

    /**
     * 实时查询折线图数据
     */
    private function getRealTimeLineData($start_timestamp, $end_timestamp)
    {
        // 获取当前商户关联的广告主ID
        $adv_ids = Db::name('company')->where(['store_id'=>$this->auth->id])->column('advertiser_id');
        
        if (empty($adv_ids)) {
            return [];
        }

        // 查询基础消耗数据
        $consumption_data = Db::name('fission_global_material')
            ->where('adv_id', 'in', $adv_ids)
            ->where('cost_date', 'between', [$start_timestamp, $end_timestamp])
            ->where('stat_cost_for_roi2', '>', 0)
            ->field([
                'cost_date',
                'stat_cost_for_roi2',
                'material_id'
            ])
            ->select();

        // 查询裂变消耗数据
        $fission_data = Db::name('fission_global_material') 
            ->alias('g')
            ->join('fission_derive_material d', 'g.material_id = d.adopt_material_id AND d.adopt_status_message = "success"', 'INNER')
            ->where('g.adv_id', 'in', $adv_ids)
            ->where('g.cost_date', 'between', [$start_timestamp, $end_timestamp])
            ->where('g.stat_cost_for_roi2', '>', 0)
            ->field([
                'g.cost_date',
                'g.stat_cost_for_roi2',
                'g.material_id'
            ])
            ->select();

        // 按日期分组统计基础数据
        $daily_stats = [];
        foreach ($consumption_data as $item) {
            $date = date('Y-m-d', $item['cost_date']);
            if (!isset($daily_stats[$date])) {
                $daily_stats[$date] = [
                    'date' => $date,
                    'total_cost' => 0,
                    'material_count' => 0,
                    'materials' => []
                ];
            }
            $daily_stats[$date]['total_cost'] += $item['stat_cost_for_roi2'];
            $daily_stats[$date]['materials'][] = $item['material_id'];
        }

        // 计算去重后的素材数量
        foreach ($daily_stats as &$stat) {
            $stat['material_count'] = count(array_unique($stat['materials']));
            unset($stat['materials']);
        }

        // 按日期分组统计裂变数据
        $fission_stats = [];
        foreach ($fission_data as $item) {
            $date = date('Y-m-d', $item['cost_date']);
            if (!isset($fission_stats[$date])) {
                $fission_stats[$date] = [
                    'fission_cost' => 0,
                    'fission_materials' => []
                ];
            }
            $fission_stats[$date]['fission_cost'] += $item['stat_cost_for_roi2'];
            $fission_stats[$date]['fission_materials'][] = $item['material_id'];
        }

        // 计算裂变素材数量
        foreach ($fission_stats as &$stat) {
            $stat['fission_material_count'] = count(array_unique($stat['fission_materials']));
            unset($stat['fission_materials']);
        }

        // 查询裂变素材创建数据（基于fission_derive_material表的创建时间）
        $fission_creation_data = Db::name('fission_derive_material')
            ->alias('d')
            ->join('fission_global_material g', 'd.old_material_id = g.material_id', 'INNER')
            ->where('g.adv_id', 'in', $adv_ids)
            ->where('d.create_time', 'between', [$start_timestamp, $end_timestamp])
            ->field([
                'd.create_time',
                'd.adopt_material_id'
            ])
            ->select();

        // 按日期分组统计裂变素材创建数量
        $fission_creation_stats = [];
        foreach ($fission_creation_data as $item) {
            $date = date('Y-m-d', $item['create_time']);
            if (!isset($fission_creation_stats[$date])) {
                $fission_creation_stats[$date] = [
                    'fission_created_materials' => []
                ];
            }
            $fission_creation_stats[$date]['fission_created_materials'][] = $item['adopt_material_id'];
        }

        // 计算裂变素材创建数量
        foreach ($fission_creation_stats as &$stat) {
            $stat['fission_material_count'] = count(array_unique($stat['fission_created_materials']));
            unset($stat['fission_created_materials']);
        }

        // 补充缺失日期的数据
        $current = $start_timestamp;
        while ($current <= $end_timestamp) {
            $date = date('Y-m-d', $current);
            if (!isset($daily_stats[$date])) {
                $daily_stats[$date] = [
                    'date' => $date,
                    'total_cost' => 0,
                    'material_count' => 0
                ];
            }
            if (!isset($fission_stats[$date])) {
                $fission_stats[$date] = [
                    'fission_cost' => 0,
                    'fission_material_count' => 0
                ];
            }
            if (!isset($fission_creation_stats[$date])) {
                $fission_creation_stats[$date] = [
                    'fission_material_count' => 0
                ];
            }
            $current = strtotime('+1 day', $current);
        }

        // 合并数据
        $result = [];
        foreach ($daily_stats as $date => $key) {
            $result[] = [
                'date' => $date,
                'total_cost' => round($key['total_cost'], 2),
                'material_count' => $key['material_count'],
                'fission_cost' => isset($fission_stats[$date]) ? round($fission_stats[$date]['fission_cost'], 2) : 0,
                'fission_material_count' => isset($fission_creation_stats[$date]) ? $fission_creation_stats[$date]['fission_material_count'] : 0
            ];
        }

        // 按日期排序
        usort($result, function ($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });

        return $result;
    }
}