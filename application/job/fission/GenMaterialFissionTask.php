<?php

namespace app\job\fission;

use app\common\model\Queue;
use app\common\model\viral_fission\FissionMaterialTask;
use jlqc\FundManagement;
use PDOStatement;
use think\Collection;
use think\Db;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\Exception;
use think\exception\DbException;
use think\exception\PDOException;

class GenMaterialFissionTask extends BaseJob
{

    protected $daySetting = [
        '1' => '当天',
        '2' => '近2天',
        '3' => '近3天',
        '4' => '近5天',
        '5' => '近7天',
    ];

    public function __construct()
    {
        $this->queueRecordModelName = '\app\common\model\viral_fission\FissionQueue';
        $this->task_model = new FissionMaterialTask();
        $this->queue_model = new Queue();
    }

    protected function getJobName(): string
    {
        return "生成裂变任务";
    }

    protected function getQueueName(): string
    {
        return 'genMaterialFissionTask';
    }

    /**
     * @throws Exception
     */
    protected function doJob($data)
    {
        foreach ($data['adv_list'] as $adv_id => $company_name) {
            try {
                // 获取裂变规则
                $fission_rules = $this->getFissionRules($adv_id, $company_name);

                if (!$fission_rules) {
                    continue;
                }
                $fission_rules = json_decode($fission_rules, true) ?? [];
                $costRules = $fission_rules['cost_rules'];
                $materials = [];
                foreach ($costRules as $rule) {
                    $timeDimension = $rule['time_dimension'] ?? null;
                    if ($timeDimension) {
                        $res = $this->queryMaterialsByTimeDimension($adv_id, $timeDimension, $rule);
                        if (!empty($res)) {
                            $materials[] = $res;
                        }
                    }
                }

                if (!empty($materials)) {
                    $unique_ids = $this->mergeUniqueMaterial($materials);
                    $this->processMaterials($adv_id, (array)$unique_ids, $fission_rules);
                }

            } catch (\Exception $e) {
                throw new Exception($e->getMessage());
            }
        }
        return true;
    }

    /**
     * 获取裂变规则
     * @param string $adv_id 广告主ID
     * @param string $company_name 公司名称
     * @return array|bool|PDOStatement|string
     */
    private function getFissionRules($adv_id, $company_name)
    {
        $fission_rules = Db::name('fission_account_rules')->where(['adv_id' => $adv_id])->find();
        if (!$fission_rules) {
            $fission_rules = Db::name('fission_company_rules')->where(['company_name' => $company_name])->find();
        }
        return $fission_rules['rules_config'] ?? [];
    }

    /**
     * 根据时间维度查询素材
     * @param string $adv_id 广告主ID
     * @param string $timeDimension 时间维度
     * @param array $rule
     * @return bool|Collection|PDOStatement|string
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws DbException
     */
    private function queryMaterialsByTimeDimension(string $adv_id, string $timeDimension, array $rule)
    {
        [$startDate, $endDate] = $this->getTimeRange($timeDimension);

        return Db::name('fission_global_material')
            ->field([
                'material_id',
                'SUM(total_pay_order_count_for_roi2) AS total_order_count',
                'CASE WHEN SUM(stat_cost_for_roi2) > 0 
              THEN SUM(total_prepay_and_pay_order_roi2 * stat_cost_for_roi2) / SUM(stat_cost_for_roi2)
              ELSE 0 END AS weighted_roi',
                'SUM(stat_cost_for_roi2) AS total_cost',
                'adv_id'
            ])
            ->where([
                'adv_id' => $adv_id
            ])
            ->whereBetween('cost_date', [$startDate, $endDate])
            ->group('material_id')
            ->having('SUM(total_pay_order_count_for_roi2) >= ' . intval($rule['order_count'])
                . ' and SUM(stat_cost_for_roi2) >= ' . floatval($rule['threshold'])
                . ' and (CASE WHEN SUM(stat_cost_for_roi2) > 0 
                 THEN SUM(total_prepay_and_pay_order_roi2 * stat_cost_for_roi2) / SUM(stat_cost_for_roi2)
                 ELSE 0 END) >= ' . floatval($rule['roi']))
            ->select();


    }

    /**
     * 根据ROI和订单数查询素材
     * @param string $adv_id 广告主ID
     * @param array $fission_rules 裂变规则
     * @return bool|Collection|PDOStatement|string
     */
    private function queryMaterialsByRoiAndOrders(string $adv_id, array $fission_rules)
    {
        return Db::name('fission_global_material')
            ->where([
                'adv_id' => $adv_id,
                'total_prepay_and_pay_order_roi2' => ['>=', $fission_rules['roi_threshold']],
                'total_pay_order_count_for_roi2' => ['>=', $fission_rules['min_daily_orders']]
            ])
            ->select();
    }

    /**
     * 提交到第三方平台
     * @param string $adv_id 广告主ID
     * @param array $materialIds 素材ID数组
     * @param array $strategies 素材ID数组
     * @throws \Exception
     */
    private function submitToThirdPartyPlatform($adv_id, $materialIds, $strategies)
    {

        $params = ['advertiser_id' => (int)$adv_id, 'material_ids' => $materialIds, 'strategies' => $strategies];
        $res = FundManagement::gen_material_derive_task($params);
        $insert = [];
        if ($res['code'] == 0) {
            foreach ($res['data']['tasks'] as $item) {
                if (in_array($item['status_code'], [41010, 41001])) {
                    dump($item);
                    continue;
                }
                $insert[] = [
                    'task_id' => $item['task_id'] ?? 0,
                    'adv_id' => $adv_id,
                    'material_id' => $item['material_id'],
                    'status_code' => $item['status_code'],
                    'status_message' => $item['status_message'],
                    'request_id' => $res['request_id']
                ];
            }
            if ($insert) {
                $res = $this->task_model->saveAll($insert);
                if (!$res) {
                    $this->queue_model->addQueue('处理裂变任务插入异常', 'app\job\fission\HandleFissionException', 'handleFissionException', $insert);
                }
            }
        }
    }

    /**
     * 处理查询到的素材
     * @param string $adv_id 广告主ID
     * @param array $materials 素材列表
     * @param array $fission_rules 裂变规则
     * @throws \Exception
     */
    private function processMaterials(string $adv_id, array $materials, array $fission_rules): void
    {
        $materialIds = array_column($materials, 'material_id');
        $processedMaterialIds = $this->isMaterialProcessedBatch($adv_id, $materialIds);
        if(!$processedMaterialIds){
            $unprocessedMaterialIds = $materialIds;
        }else{
            $unprocessedMaterialIds = array_diff($materialIds,$processedMaterialIds);
        }
        $refusedMaterialIds =$this->getRefusedMaterialId($adv_id,$unprocessedMaterialIds);
        if($refusedMaterialIds){
            echo "----------有限制的----------";
            dump($refusedMaterialIds);
        }
        $finalMaterialIds = array_diff($unprocessedMaterialIds, $refusedMaterialIds);
        // 最终能处理裂变的素材
        $unprocessedMaterialIds = array_map('intval', $finalMaterialIds);
        if(!$unprocessedMaterialIds){
            return;
        }
        // 分批提交，每批最多 50 个素材
        $batches = array_chunk($unprocessedMaterialIds, 50);
        foreach ($batches as $batch) {
            $this->submitToThirdPartyPlatform($adv_id, $batch, $fission_rules['fission_strategies']);
        }
    }

    /**
     * 合并多维分组数组，按 adv_id + material_id 去重，返回平铺后的唯一结果
     *
     * @param array $data 多组二维数组
     * @return array 去重后的结果
     */
    private function mergeUniqueMaterial(array $data): array
    {
        $unique = [];
        $result = [];

        foreach ($data as $group) {
            foreach ($group as $row) {
                $key = $row['adv_id'] . '_' . $row['material_id'];
                if (!isset($unique[$key])) {
                    $unique[$key] = true;
                    $result[] = $row;
                }
            }
        }

        return $result;
    }

    /**
     * 根据素材id去掉今天已经处理过的素材id
     * @param string $adv_id 广告主ID
     * @param array $materialIds 素材ID数组
     * @return array 已处理的素材ID数组
     */
    private function isMaterialProcessedBatch(string $adv_id, array $materialIds)
    {
        $todayStart = strtotime(date('Y-m-d'));
        $todayEnd = strtotime(date('Y-m-d') . ' 23:59:59');
        return Db::name('fission_material_task')
            ->where('adv_id', $adv_id)
            ->whereIn('material_id', $materialIds)
            ->whereBetween('create_time',[$todayStart, $todayEnd])
            ->column('material_id');
    }

    /**
     * 获取不能裂变的素材id
     * @param string $adv_id 广告主ID
     * @param array $materialIds 素材ID数组
     * @return array 符合条件的素材ID数组
     */
    private function getRefusedMaterialId(string $adv_id, array $materialIds): array
    {
        //完善一下
        return Db::name('fission_material_task')
            ->field('material_id')
            ->where('material_id', 'in', $materialIds)
            ->where('adv_id', $adv_id)
            ->where('status_code','>',0)
            ->group('material_id')
            ->column('material_id');
    }

    /**
     * 获取时间范围
     * @param string $timeDimension 时间维度
     * @return array [开始时间, 结束时间]
     */
    private function getTimeRange($timeDimension)
    {

        $now = time();
        $dayDescription = $this->daySetting[$timeDimension] ?? '当天';
        preg_match('/近(\d+)天/', $dayDescription, $matches);
        $dayCount = isset($matches[1]) ? (int)$matches[1] : 1;

        // 结束时间始终是今天 00:00:00
        $endDate = strtotime('today', $now);

        if ($dayDescription === '当天' || $dayCount === 1) {
            $startDate = $endDate;
        } else {
            // 近N天 => 从 N-1 天前 00:00:00
            $startDate = strtotime('-' . ($dayCount - 1) . ' days', $endDate);
        }

        return [$startDate, $endDate];
    }


}
