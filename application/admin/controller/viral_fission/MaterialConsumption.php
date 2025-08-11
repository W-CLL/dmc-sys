<?php

namespace app\admin\controller\viral_fission;

use app\common\model\viral_fission\AdvGlobalMaterial;
use app\common\model\viral_fission\FissionDeriveMaterial;
use app\admin\model\Company;
use app\common\model\MaterialConsumptionStats;
use app\common\model\MaterialDailyStats;
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

    private function __filter(&$where)
    {
        $params = input();

        // 固定条件，费用必须大于0
        $where['gm.stat_cost_for_roi2'] = ['>', 0];

        // 时间筛选
        $daterange = $params['daterange'] ?? '';

        if ($daterange) {
            $dates = explode(' - ', $daterange);
            if (count($dates) == 2) {
                $start_date = strtotime(trim($dates[0]));
                $end_date = strtotime(trim($dates[1]));
                $where['gm.cost_date'] = ['between', [$start_date, $end_date]];
            }
        } else {
            $where['gm.cost_date'] = ['between', [strtotime('-30 days'), time()]];
        }
        // 金额筛选
        $min_cost = $params['min_cost'] ?? '';
        $max_cost = $params['max_cost'] ?? '';
        if ($min_cost !== null && $min_cost !== '') {
            $where['gm.stat_cost_for_roi2'] = ['>=', $min_cost];
        }
        if ($max_cost !== null && $max_cost !== '') {
            $where['gm.stat_cost_for_roi2'] = ['<=', $max_cost];
        }
        // 广告主ID筛选
        $qc_id = $params['qc_id'] ?? '';
        if ($qc_id !== null && $qc_id !== '') {
            $where['gm.adv_id'] = $qc_id;
        }
        $company_name = $params['company_name'] ?? '';
        if ($company_name !== null && $company_name !== '') {
            $where['com.company_name'] = ['like', "%" . $company_name . "%"];
        }
        $material_id = $params['material_id'] ?? '';
        if ($material_id !== null && $material_id !== '') {
            $where['gm.material_id'] = $material_id;
        }
        $kahuna = $params['kahuna'] ?? '';
        if ($kahuna !== null && $kahuna !== '') {
            $where['com.kahuna'] = ['like', "%" . $kahuna . "%"];
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
                ->field('gm.adv_id,gm.material_id,gm.stat_cost_for_roi2,gm.cost_date,total_pay_order_count_for_roi2, IF(dm.adopt_material_id IS NULL, 0, 1) AS is_fission,com.company_name,com.kahuna,com.store_id')
                ->join('fission_derive_material dm', 'gm.material_id = dm.adopt_material_id', 'left')
                ->join('company com', 'gm.adv_id=com.advertiser_id', 'left');
            // 应用基础条件
            $list = $query->where($param_where)->order("is_fission desc,stat_cost_for_roi2 desc")->paginate($limit);
            foreach ($list as &$row) {
                $row['create_time_text'] = date('Y-m-d', $row['cost_date']);
                $row['is_fission'] = $row['is_fission'] ? '是' : '否';
                $row['fission_count'] = Db::name('fission_derive_material')->where(['old_material_id' => $row['material_id']])->count();
                $row['store_name'] = Db::name('store')->where(['id' => $row['store_id']])->column('username');
                if ($row['stat_cost_for_roi2'] >= 300) {
                    $msg_info = Db::name('fission_material_task')
                        ->where([
                            'adv_id' => $row['adv_id'],
                            'material_id' => $row['material_id'],
                        ])
//                        ->where(function($query) {
//                            $query->where('status_code', '>', 0)
//                                ->whereOr('fission_status', 'FAILED');
//                        })
                        ->find();
                    if (!empty($msg_info['fission_msg'])) {
                        $row['unfission_reason'] = $msg_info['fission_msg'];
                    } elseif (!empty($msg_info['status_code'])) {
                        $row['unfission_reason'] = $msg_info['status_message'];
                    }

                }
            }

            $result = [
                'total' => $list->total(),
                'rows' => $list->items(),
            ];

            return json($result);
        }
        return $this->view->fetch();
    }


    public function getStats()
    {
        $adopt = Db::name('fission_derive_material')->where(['adopt_status_message' => 'success'])->count();
        $generated = Db::name('fission_derive_material')->count();
        $total = Db::name('fission_global_material')->group('material_id')->count();

        $success_rate = $generated > 0 ? round(($adopt / $generated) * 100, 2) : 0;

        $data = [
            'data' => [
                'total' => $total,
                'generated' => $generated,
                'adopted' => $adopt,
                'success_rate' => $success_rate,
            ],
            'code' => 1
        ];
        return json($data);
    }

    /**
     * 获取折线图数据 - 按天显示消耗趋势
     */
    public function getLineChartData()
    {
        // 获取时间段参数
        $period = input('period', '15');

        // 缓存键
        $cache_key = "line_chart_data_{$period}";
        $cached_data = cache($cache_key);

        if ($cached_data !== false) {
            return json(['code' => 1, 'data' => $cached_data]);
        }

        // 根据时间段计算时间范围
        $timeRange = $this->calculateTimeRange($period);
        $start_timestamp = $timeRange['start'];
        $end_timestamp = $timeRange['end'];

        // 统计表没有数据，回退到实时查询（用于兼容性）
        $result = $this->getRealTimeLineData($start_timestamp, $end_timestamp);


        // 缓存结果5分钟
        cache($cache_key, $result, 300);

        return json(['code' => 1, 'data' => $result]);
    }

    /**
     * 获取饼状图数据 - 按公司主体显示消耗占比
     */
    public function getPieChartData()
    {
        // 获取时间段参数
        $period = input('period', '15');

        // 缓存键
        $cache_key = "pie_chart_data_{$period}";
        $cached_data = cache($cache_key);

        if ($cached_data !== false) {
            return json(['code' => 1, 'data' => $cached_data['data'], 'total_cost' => $cached_data['total_cost']]);
        }

        // 根据时间段计算时间范围
        $timeRange = $this->calculateTimeRange($period);
        $start_timestamp = $timeRange['start'];
        $end_timestamp = $timeRange['end'];

        // 计算日期范围
        $start_date = date('Y-m-d', $start_timestamp);
        $end_date = date('Y-m-d', $end_timestamp);

        // 优先从统计表查询数据

        // 统计表没有数据，回退到实时查询
        $company_data = AdvGlobalMaterial::getCompanyRankingData($start_timestamp, $end_timestamp, 20);

        // 获取公司名称列表用于查询裂变数据
        $company_names = [];
        foreach ($company_data as $item) {
            $company_names[] = $item['company_name'];
        }

        // 查询裂变数据
        $fission_result = AdvGlobalMaterial::getCompanyFissionData($start_timestamp, $end_timestamp, $company_names);

        // 转换为关联数组
        $fission_data = [];
        foreach ($fission_result as $item) {
            $fission_data[$item['company_name']] = $item;
        }


        // 计算总消耗用于计算百分比
        $total_cost = array_sum(array_column((array)$company_data, 'total_cost'));

        $result = [];
        foreach ($company_data as $item) {
            $company_name = $item['company_name'] ?: '未知公司';
            $percentage = $total_cost > 0 ? round(($item['total_cost'] / $total_cost) * 100, 2) : 0;

                // 实时查询的数据，需要获取裂变数据
                $fission_info = $fission_data[$company_name] ?? null;
                $result[] = [
                    'company_name' => $company_name,
                    'total_cost' => round($item['total_cost'], 2),
                    'percentage' => $percentage,
                    'material_count' => $item['material_count'],
                    'fission_cost' => $fission_info ? round($fission_info['fission_cost'], 2) : 0,
                    'fission_material_count' => $fission_info ? $fission_info['fission_material_count'] : 0
                ];

        }

        // 缓存结果5分钟
        $cache_data = [
            'data' => $result,
            'total_cost' => round($total_cost, 2)
        ];
        cache($cache_key, $cache_data, 300);

        return json(['code' => 1, 'data' => $result, 'total_cost' => round($total_cost, 2)]);
    }

    /**
     * 获取公司详细数据（分页）
     */
    public function getCompanyDetailData()
    {
        try {
            $page = input('page', 1);
            $limit = input('limit', 10);

            // 使用原生SQL避免GROUP BY问题
            $sql = "SELECT
                        c.company_name,
                        c.kahuna,
                        SUM(g.stat_cost_for_roi2) as total_cost,
                        COUNT(DISTINCT g.material_id) as material_count
                    FROM fa_fission_global_material g
                    LEFT JOIN fa_company c ON g.adv_id = c.advertiser_id
                    WHERE c.company_name IS NOT NULL
                    AND c.company_name != ''
                    GROUP BY c.company_name, c.kahuna
                    ORDER BY total_cost DESC
                    LIMIT " . (($page - 1) * $limit) . ", " . $limit;

            $list = Db::query($sql);

            // 获取总数
            $count_sql = "SELECT COUNT(DISTINCT c.company_name) as total
                         FROM fa_fission_global_material g
                         LEFT JOIN fa_company c ON g.adv_id = c.advertiser_id
                         WHERE c.company_name IS NOT NULL
                         AND c.company_name != ''";
            $count_result = Db::query($count_sql);
            $total = $count_result[0]['total'] ?? 0;

            // 计算总消耗
            $sum_sql = "SELECT SUM(g.stat_cost_for_roi2) as total_cost
                       FROM fa_fission_global_material g
                       LEFT JOIN fa_company c ON g.adv_id = c.advertiser_id
                       WHERE c.company_name IS NOT NULL
                       AND c.company_name != ''";
            $sum_result = Db::query($sum_sql);
            $total_cost_sum = $sum_result[0]['total_cost'] ?? 0;

            $result = [];
            foreach ($list as $item) {
                $percentage = $total_cost_sum > 0 ? round(($item['total_cost'] / $total_cost_sum) * 100, 2) : 0;
                $avg_cost = $item['material_count'] > 0 ? round($item['total_cost'] / $item['material_count'], 2) : 0;

                $result[] = [
                    'company_name' => $item['company_name'] ?: '未知公司',
                    'kahuna' => $item['kahuna'] ?: '',
                    'total_cost' => round($item['total_cost'], 2),
                    'fission_cost' => 0, // 暂时设为0
                    'non_fission_cost' => round($item['total_cost'], 2),
                    'percentage' => $percentage,
                    'material_count' => $item['material_count'],
                    'fission_material_count' => 0,
                    'non_fission_material_count' => $item['material_count'],
                    'avg_cost' => $avg_cost
                ];
            }

            return json([
                'code' => 1,
                'data' => $result,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_cost' => round($total_cost_sum ?: 0, 2)
            ]);
        } catch (\Exception $e) {
            // 返回错误信息用于调试
            return json([
                'code' => 0,
                'msg' => '数据查询失败: ' . $e->getMessage(),
                'data' => [],
                'total' => 0
            ]);
        }
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
            case 'last_3_months':
                // 前三个月：从3个月前的1号00:00:00到今天23:59:59
                $start_timestamp = strtotime('-3 months', strtotime(date('Y-m-01 00:00:00')));
                $end_timestamp = $today_end;
                break;
            default:
                // 默认近15天
                $start_timestamp = strtotime('-14 days', $today_start);
                $end_timestamp = $today_end;
                break;
        }

        // 调试输出时间范围
        $start_date = date('Y-m-d H:i:s', $start_timestamp);
        $end_date = date('Y-m-d H:i:s', $end_timestamp);
        error_log("Period: {$period}, Range: {$start_date} to {$end_date}");

        return [
            'start' => $start_timestamp,
            'end' => $end_timestamp
        ];
    }

    /**
     * 实时查询折线图数据（回退方案）
     */
    private function getRealTimeLineData($start_timestamp, $end_timestamp)
    {
        // 使用原有的实时查询逻辑
        $consumption_data = AdvGlobalMaterial::getConsumptionData($start_timestamp, $end_timestamp);
        $fission_data = AdvGlobalMaterial::getFissionConsumptionData($start_timestamp, $end_timestamp);

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
                    'fission_material_count' => 0,
                    'materials' => []
                ];
            }
            $fission_stats[$date]['fission_cost'] += $item['stat_cost_for_roi2'];
            $fission_stats[$date]['materials'][] = $item['material_id'];
        }

        // 计算去重后的裂变素材数量
        foreach ($fission_stats as &$stat) {
            $stat['fission_material_count'] = count(array_unique($stat['materials']));
            unset($stat['materials']);
        }

        // 合并数据
        $result = [];
        foreach ($daily_stats as $date => $stat) {
            $result[] = [
                'date' => $date,
                'total_cost' => round($stat['total_cost'], 2),
                'material_count' => $stat['material_count'],
                'fission_cost' => isset($fission_stats[$date]) ? round($fission_stats[$date]['fission_cost'], 2) : 0,
                'fission_material_count' => isset($fission_stats[$date]) ? $fission_stats[$date]['fission_material_count'] : 0
            ];
        }

        // 按日期排序
        usort($result, function ($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });

        return $result;
    }

    /**
     * 清除缓存
     */
    public function clearCache()
    {
        $periods = ['7', '15', 'current_month', 'last_month', 'last_3_months'];

        foreach ($periods as $period) {
            cache("line_chart_data_{$period}", null);
            cache("pie_chart_data_{$period}", null);
        }

        return json(['code' => 1, 'msg' => '缓存已清除']);
    }

    /**
     * 数据看板页面
     */
    public function dashboard()
    {
        return $this->view->fetch();
    }

    /**
     * 导出数据
     */
    public function exportData()
    {
        $days = input('days', 15);
        $start_date = input('start_date', '');
        $end_date = input('end_date', '');

        // 如果没有指定日期范围，使用默认天数
        if (empty($start_date) || empty($end_date)) {
            $end_timestamp = strtotime('today');
            $start_timestamp = strtotime("-{$days} days", $end_timestamp);
        } else {
            $start_timestamp = strtotime($start_date);
            $end_timestamp = strtotime($end_date);
        }

        // 查询数据
        $data = Db::name('fission_global_material')
            ->alias('gm')
            ->join('company com', 'gm.adv_id=com.advertiser_id', 'left')
            ->field('com.company_name, com.kahuna, gm.adv_id, gm.material_id, gm.roi2_material_video_name, gm.stat_cost_for_roi2, gm.total_pay_order_count_for_roi2, FROM_UNIXTIME(gm.cost_date, "%Y-%m-%d") as cost_date')
            ->where('gm.cost_date', 'between', [$start_timestamp, $end_timestamp])
            ->where('com.company_name', 'neq', '')
            ->where('com.company_name', 'not null')
            ->order('gm.cost_date DESC, gm.stat_cost_for_roi2 DESC')
            ->select();

        // 设置响应头
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment;filename="material_consumption_' . date('Y-m-d') . '.csv"');
        header('Cache-Control: max-age=0');

        // 输出CSV内容
        $output = fopen('php://output', 'w');

        // 输出BOM以支持中文
        fwrite($output, "\xEF\xBB\xBF");

        // 输出表头
        fputcsv($output, ['公司名称', '负责人', '千川ID', '素材ID', '素材名称', '消耗金额', '订单数', '消耗日期']);

        // 输出数据
        foreach ($data as $row) {
            fputcsv($output, [
                $row['company_name'] ?: '未知公司',
                $row['kahuna'] ?: '',
                $row['adv_id'],
                $row['material_id'],
                $row['roi2_material_video_name'] ?: '',
                $row['stat_cost_for_roi2'],
                $row['total_pay_order_count_for_roi2'],
                $row['cost_date']
            ]);
        }

        fclose($output);
        exit;
    }


}
