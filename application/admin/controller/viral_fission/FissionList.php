<?php

namespace app\admin\controller\viral_fission;

use app\common\controller\Backend;
use app\common\model\viral_fission\FissionDeriveMaterial;
use jlqc\FundManagement;
use think\Db;
use think\Env;
use think\Exception;

/**
 * 爆款裂变-裂变列表
 *
 * @icon fa fa-list
 */
class FissionList extends Backend
{
    protected $relationSearch = true;
    protected $searchFields = 'adv_id,old_material_id,title';

    /**
     * @var FissionDeriveMaterial
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        // 使用爆款裂变后的素材表
        $this->model = new FissionDeriveMaterial();
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
            // 只显示有视频URL的裂变素材
//            $param_where['video_url'] = [ '<>', ''];
//            $param_where['video_url'] = 'not null';

            $list = $this->model
                ->where($where)
                ->where($param_where)
                ->order('create_time', 'desc')
                ->paginate($limit);

            foreach ($list as $row) {
//              $res =   FundManagement::get_adv_material_info([
//                    'advertiser_id' => (int)$row['adv_id'],
//                    'filtering' => json_encode([
//                        'material_ids' => [7477591584448839706]
//                    ])
//                ]);

                // 获取投前检测状态
//                $pre_check_info = $this->getPreCheckInfo($row['id']);
//                $row['pre_check_status'] = $pre_check_info['status'];
//                $row['pre_check_result'] = $pre_check_info['result'];


                // 获取原始素材信息
                $original_material = $this->getOriginalMaterialInfo($row['adv_id'], $row['old_material_id']);
                $row['original_material'] = $original_material;
            }

            $result = array("total" => $list->total(), "rows" => $list->items());
            return json($result);
        }
        return $this->view->fetch();
    }

    /**
     * 投前检测
     */
    public function preCheck()
    {
        if ($this->request->isPost()) {
            $ids = $this->request->post("ids");

            if (empty($ids)) {
                $this->error(__('Parameter %s can not be empty', 'ids'));
            }

            $ids = explode(',', $ids);

            try {
                Db::startTrans();

                foreach ($ids as $fission_id) {
                    // 创建投前检测任务
                    $this->createPreCheckTask($fission_id);
                }

                Db::commit();
                $this->success('投前检测任务已创建，正在检测中...');
            } catch (Exception $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }
        }
    }

    /**
     * 批量投前检测
     */
    public function batchPreCheck()
    {
        if ($this->request->isPost()) {
            $ids = $this->request->post("ids");

            if (empty($ids)) {
                $this->error(__('Parameter %s can not be empty', 'ids'));
            }

            return $this->preCheck();
        }
    }

    /**
     * 一键采纳
     */
    public function batchAdopt()
    {
        if ($this->request->isPost()) {
            $ids = $this->request->post("ids");
            $skip_check = $this->request->post("skip_check", 0);

            if (empty($ids)) {
                $this->error(__('Parameter %s can not be empty', 'ids'));
            }

            $ids = explode(',', $ids);

            try {
                Db::startTrans();

                foreach ($ids as $fission_id) {
                    // 采纳裂变素材
                    $this->adoptFissionFromList($fission_id, $skip_check);
                }

                Db::commit();
                $this->success('采纳成功');
            } catch (Exception $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }
        }
    }

    /**
     * 单个投前检测
     */
    public function singlePreCheck()
    {
        if ($this->request->isPost()) {
            $id = $this->request->post("id");

            if (empty($id)) {
                $this->error(__('Parameter %s can not be empty', 'id'));
            }

            try {
                Db::startTrans();

                // 创建投前检测任务
                $this->createPreCheckTask($id);

                Db::commit();
                $this->success('投前检测任务已创建，正在检测中...');
            } catch (Exception $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }
        }
    }

    /**
     * 获取投前检测信息
     */
    private function getPreCheckInfo($fission_id)
    {
        $check = Db::name('fission_pre_check')->where('fission_id', $fission_id)->find();
        if (!$check) {
            return [
                'status' => '未检测',
                'result' => '无'
            ];
        }

        $status_map = [
            0 => '未检测',
            1 => '检测中',
            2 => '通过',
            3 => '不通过'
        ];

        return [
            'status' => $status_map[$check['status']] ?? '未知',
            'result' => $check['result'] ?: '无'
        ];
    }

    /**
     * 获取原始素材信息
     */
    private function getOriginalMaterialInfo($adv_id, $material_id)
    {
        $material = $this->model
            ->where('adv_id', $adv_id)
            ->where('old_material_id', $material_id)
            ->find();

        if (!$material) {
            return [
                'material_name' => '未知素材',
                'material_cost' => 0,
                'orders_count' => 0
            ];
        }

        return [
            'material_name' => $material['strategy_name'] ?: '未知素材',
            'material_cost' => $material['stat_cost_for_roi2'] ?? 0,
            'orders_count' => $material['total_pay_order_count_for_roi2'] ?? 0
        ];
    }

    /**
     * 创建投前检测任务
     */
    private function createPreCheckTask($fission_id)
    {
        // 检查是否已存在检测任务
        $exists = Db::name('fission_pre_check')->where('fission_id', $fission_id)->find();
        if ($exists) {
            // 更新状态为检测中
            Db::name('fission_pre_check')->where('fission_id', $fission_id)->update([
                'status' => 1,
                'update_time' => time()
            ]);
        } else {
            // 创建新的检测任务
            Db::name('fission_pre_check')->insert([
                'fission_id' => $fission_id,
                'status' => 1,
                'create_time' => time(),
                'update_time' => time()
            ]);
        }

        // 这里应该调用API接口进行投前检测
        // TODO: 调用投前检测API
    }

    /**
     * 从裂变列表采纳素材
     */
    private function adoptFissionFromList($fission_id, $skip_check = 0)
    {
        // 获取裂变素材信息
        $fission = $this->model->where('id', $fission_id)->find();
        if (!$fission || empty($fission['video_url'])) {
            throw new Exception('裂变素材不存在或视频未生成，无法采纳');
        }

        // 如果不跳过检测，检查投前检测状态
        if (!$skip_check) {
            $check = Db::name('fission_pre_check')->where('fission_id', $fission_id)->find();
            if (!$check || $check['status'] != 2) {
                throw new Exception('投前检测未通过，无法采纳');
            }
        }

        // 记录采纳状态（可以在表中添加字段或创建新表记录采纳状态）
        $adopt_data = [
            'fission_id' => $fission_id,
            'adv_id' => $fission['adv_id'],
            'old_material_id' => $fission['old_material_id'],
            'video_id' => $fission['video_id'],
            'video_url' => $fission['video_url'],
            'skip_check' => $skip_check,
            'adopt_time' => time(),
            'create_time' => time(),
            'update_time' => time()
        ];

        // 这里可以插入到采纳记录表或更新原表状态
        // Db::name('fission_adopt_record')->insert($adopt_data);

        // 这里应该调用API接口进行采纳操作
        // TODO: 调用采纳API，将裂变素材提交到广告平台
    }
}
