<?php

namespace app\admin\controller\viral_fission;

use app\admin\model\Company;
use app\common\controller\Backend;
use app\common\model\FissionCostRule;
use think\Db;
use think\Exception;
use \app\common\model\viral_fission\CompanySetting as setting_model;

/**
 * 爆款裂变-公司主体设置
 *
 * @icon fa fa-building
 */
class CompanySetting extends Backend
{
    protected $relationSearch = true;
    protected $searchFields = 'company_name,store.username';

    /**
     * @var Company
     */
    protected $model = null;

    /**
     * 裂变策略配置
     */
    private $fissionStrategies = [
        'CLIP_REPLACE' => '分镜替换',
        'ROBOT_REPLACE' => '人物替换',
        'HOT_PRE_VIDEO' => '爆款开头',
        'MIX_CUT' => '重新混剪',
        'PRE_VIDEO_CLIP_REPLACE' => '前贴扩写',
        'DERIVE_FROM_CHOSEN_HOT_MID' => '自有爆款套路',
        'DERIVE_FROM_INDUSTRY_HOT_PATTERN' => '行业爆款套路'
    ];

    /**
     * 时间维度配置
     */
    protected $daySetting = [
        '1' => '当天',
        '2' => '近2天',
        '3' => '近3天',
        '4' => '近5天',
        '5' => '近7天',
    ];
    /**
     * @var setting_model
     */
    private $setting_model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = model('Company');
        $this->setting_model = new setting_model();
    }

    /**
     * 查看
     */
    public function index()
    {
        //设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }

            list($where, $sort, $order, $offset, $limit) = $this->buildparams();

            $list = $this->model
                ->join('store', 'store.id = company.store_id','left')
                ->where($where)
                ->field('company.id,company.company_name,store.username')
                ->group('company_name')
                ->order($sort, $order)
                ->paginate($limit);

            foreach ($list as $row) {
                $qc_count = Db::name('company')->where('company_name', $row['company_name'])->count();
                $row['qc_account_count'] = $qc_count;
                $fission_rules = $this->getFissionRules($row['company_name']);
                if(!$fission_rules['fission_strategies']){
                    $fission_rules['fission_strategies'] = array_values($this->fissionStrategies);
                }else{
                    $fission_rules['fission_strategies']=  array_values(array_intersect_key($this->fissionStrategies, array_flip($fission_rules['fission_strategies'])));
                }
                $row['fission_rules'] = $fission_rules;
            }

            $result = array("total" => $list->total(), "rows" => $list->items());
            return json($result);
        }
        $this->view->assign("fission_strategies", $this->fissionStrategies);
        $this->view->assign("day_setting", $this->daySetting);
        return $this->view->fetch();
    }

    /**
     * 批量设置
     */
    public function batch_setting(): string
    {
        if ($this->request->isPost()) {
            $ids = $this->request->post("ids");
            $formData = input('post.cost_rules');
            parse_str($formData, $setting);
            if (empty($ids)) {
                $this->error(__('Parameter %s can not be empty', 'ids'));
            }

            $ids = explode(',', $ids);
            $company_list = $this->model->whereIn('id', $ids)->column('company_name');

            if (empty($setting['time_dimensions'])) {
                $this->error('至少填写一个时间维度');
            }
            try {
                Db::startTrans();
                foreach ($company_list as $company_name) {
                    $this->saveFissionRules($company_name, $setting);
                }
                Db::commit();
                $this->success();
            } catch (Exception $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }
        }
        $this->view->assign("fission_strategies", $this->fissionStrategies);
        $this->view->assign("day_setting", $this->daySetting);
        return $this->view->fetch();
    }

    /**
     * 单个设置
     */
    public function setting($ids = null)
    {

        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if ($this->request->isPost()) {
            $params = $this->request->post("row/a");
            $costRules = $this->request->post("time_dimensions/a", []);
            try {
                Db::startTrans();
                $params['time_dimensions'] = $costRules;
                $this->saveFissionRules($row['company_name'], $params);
                Db::commit();
                $this->success();
            } catch (Exception $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }
        }


        $fission_rules = $this->getFissionRules($row['company_name']);

        $fission_rules['cost_rules_json'] = json_encode($fission_rules['cost_rules'], JSON_UNESCAPED_UNICODE);
        $this->view->assign("row", $row);
        $this->view->assign("fission_rules", $fission_rules);
        $this->view->assign("fission_strategies", $this->fissionStrategies);
        $this->view->assign("day_setting", $this->daySetting);
        return $this->view->fetch();
    }

    /**
     * 获取裂变规则设置
     */
    private function getFissionRules($company_name)
    {
        $rules = Db::name('fission_company_rules')->where('company_name', $company_name)->find();
        if (!$rules || !$rules['rules_config']) {
            return [
                'cost_rules' => [],
                'roi_threshold' => 0,
                'min_daily_orders' => 0,
                'fission_strategies' => [],
                'enabled' => 1
            ];
        }
        $config = json_decode($rules['rules_config'], true);
        if (!$config) {
            return [
                'fission_strategies' => [],
                'min_daily_orders' => 0,
                'roi_threshold' => 0,
                'cost_rules' => [],

            ];
        }
        // 确保所有字段都存在
        return array_merge([
            'cost_rules' => [],
            'roi_threshold' => 0,
            'min_daily_orders' => 0,
            'fission_strategies' => [],
        ], $config);
    }
    /**
     * 保存裂变规则设置
     */
    private function saveFissionRules($company_name, $params)
    {
        // 处理消耗规则

        $costRules = [];
        $timeDimensions = $params['time_dimensions'];
        if (!empty($timeDimensions) && is_array($timeDimensions)) {
            foreach ($timeDimensions as $rule) {
//                if(!$rule['dimension'] || !$rule['cost_data']){
//                    $this->error('请填写正确的时间和消耗');
//                }
                if (!empty($rule['dimension']) && isset($rule['cost_data']) && $rule['cost_data'] >= 0) {
                    $costRules[] = [
                        'time_dimension' => $rule['dimension'],
                        'threshold' => floatval($rule['cost_data']),
                        'roi'=>$rule['roi']?:0,
                        'order_count'=>$rule['order_count']?:0,
                    ];
                }
            }
        }
        // 构建规则配置JSON
        $rules_config = [
            'cost_rules' => $costRules,
            'fission_strategies' => $params['fission_strategies'] ?? array_keys($this->fissionStrategies),
            'update_time' => date('Y-m-d H:i:s')
        ];
        $data = [
            'rules_config' => json_encode($rules_config, JSON_UNESCAPED_UNICODE),
            'update_time' => time()
        ];

        $exists = Db::name('fission_company_rules')->where('company_name', $company_name)->find();

        if ($exists) {

            Db::name('fission_company_rules')->where('company_name', $company_name)->update($data);
        } else {
            $data['company_name'] = $company_name;
            $data['create_time'] = time();
            Db::name('fission_company_rules')->insert($data);
        }
    }

}
