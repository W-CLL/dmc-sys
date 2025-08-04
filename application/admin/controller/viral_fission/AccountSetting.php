<?php

namespace app\admin\controller\viral_fission;

use app\admin\model\Company;
use app\common\controller\Backend;
use think\Db;
use think\Exception;

/**
 * 爆款裂变-账户设置
 *
 * @icon fa fa-user-circle
 */
class AccountSetting extends Backend
{
    protected $relationSearch = true;
    protected $searchFields = 'name,advertiser_id,company.company_name';

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

    protected $daySetting = [
        '1'=>'当天',
        '2'=>'近2天',
        '3'=>'近3天',
        '4'=>'近5天',
        '5'=>'近7天',
    ];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = model('company');
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
            
            $list = $this->model
                ->where($where)
                ->where(['adv_status'=>1])
                ->order($sort, $order)
                ->paginate($limit);

            foreach ($list as $row) {
                // 获取账户裂变规则设置
                $fission_rules = $this->getAccountFissionRules($row['advertiser_id']);
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
            $adv_list = $this->model->whereIn('id', $ids)->column('advertiser_id');
            if (empty($setting['time_dimensions'])) {
                $this->error('至少填写一个时间维度');
            }
            try {
                Db::startTrans();
                foreach ($adv_list as $adv_id) {
                    $this->saveFissionRules($adv_id, $setting);
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
                // 将消耗规则合并到参数中
                $params['time_dimensions'] = $costRules;
                $this->saveFissionRules($row['advertiser_id'], $params);
                Db::commit();
                $this->success();
            } catch (Exception $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }
        }
        
        // 获取当前设置
        $fission_rules = $this->getAccountFissionRules($row['advertiser_id']);

        // 将cost_rules转换为JSON字符串供前端使用
        $fission_rules['cost_rules_json'] = json_encode($fission_rules['cost_rules'], JSON_UNESCAPED_UNICODE);

        $this->view->assign("row", $row);
        $this->view->assign("fission_rules", $fission_rules);
        $this->view->assign("fission_strategies", $this->fissionStrategies);
        $this->view->assign("day_setting", $this->daySetting);
        return $this->view->fetch();
    }

    /**
     * 获取账户裂变规则设置
     */
    private function getAccountFissionRules($adv_id)
    {
        $rules = Db::name('fission_account_rules')->where('adv_id', $adv_id)->find();
        if (!$rules || !$rules['rules_config']) {
            return [
                'cost_rules' => [],
                'fission_strategies' => [],
                'enabled' => 1
            ];
        }

        $config = json_decode($rules['rules_config'], true);
        if (!$config) {
            return [
                'cost_rules' => [],
                'fission_strategies' => [],
                'enabled' => 1
            ];
        }

        // 确保所有字段都存在
        return array_merge([
            'cost_rules' => [],
            'fission_strategies' => [],
            'enabled' => 1
        ], $config);
    }

    /**
     * 保存账户裂变规则设置
     */
    /**
     * 保存裂变规则设置
     */
    private function saveFissionRules($adv_id, $params)
    {
        // 处理消耗规则
        $costRules = [];
        $timeDimensions = $params['time_dimensions'];
        if (!empty($timeDimensions) && is_array($timeDimensions)) {
            foreach ($timeDimensions as $rule) {
                if(!$rule['dimension'] || !$rule['cost_data']){
                    $this->error('请填写正确的时间和消耗');
                }
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

        $exists = Db::name('fission_account_rules')->where('adv_id', $adv_id)->find();
        if ($exists) {
            Db::name('fission_account_rules')->where('adv_id', $adv_id)->update($data);
        } else {
            $data['adv_id'] = $adv_id;
            $data['create_time'] = time();
            Db::name('fission_account_rules')->insert($data);
        }
    }

}
