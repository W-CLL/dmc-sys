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
        // 获取最新的黑名单列表，确保实时过滤
        $blackCompanyList = $this->getBlackCompanyList();

        foreach ($data['adv_list'] as $adv_id => $company_name) {
            try {
                // 检查公司是否在黑名单中
                if (in_array($company_name, $blackCompanyList)) {
                    echo "跳过黑名单公司: {$company_name} (广告主ID: {$adv_id})\n";
                    continue;
                }

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
        // 提取素材ID
        $materialIds = array_column($materials, 'material_id');

        // 过滤今天已处理过的素材（排除包含"请重试"字眼的失败记录）
        $processedMaterialIds = $this->isMaterialProcessedBatch($adv_id, $materialIds);
        $unprocessedMaterialIds = $processedMaterialIds
            ? array_diff($materialIds, $processedMaterialIds)
            : $materialIds;

        // 获取不能裂变的素材ID（有错误原因、状态FAILED或status_code>0，但排除"请重试"类型）
        $refusedMaterialIds = $this->getRefusedMaterialId($adv_id, $unprocessedMaterialIds);

        if ($refusedMaterialIds) {
            echo "----------有限制的----------";
            dump($refusedMaterialIds);
        }

        // 最终可用素材ID = 未处理 ∩ 未拒绝
        $finalMaterialIds = array_diff($unprocessedMaterialIds, $refusedMaterialIds);
        $finalMaterialIds = array_map('intval', $finalMaterialIds);

        if (!$finalMaterialIds) {
            echo "无可用素材，结束\n";
            return;
        }

        // 分批提交，每批最多 50
        $batches = array_chunk($finalMaterialIds, 50);
        foreach ($batches as $batch) {
            $this->submitToThirdPartyPlatform($adv_id, $batch, $fission_rules['fission_strategies']);
        }
    }

    /**
     * 根据素材 
     * 过滤逻辑：
     * 1. 今天已有记录且不包含"请重试"字眼的素材
     * 2. 今天已提交但还未返回结果的素材（有task_id但无fission_msg）
     * @param string $adv_id 广告主ID
     * @param array $materialIds 素材ID数组
     * @return array 已处理的素材ID数组
     */
    private function isMaterialProcessedBatch(string $adv_id, array $materialIds): array
    {
        $todayStart = strtotime(date('Y-m-d'));
        $todayEnd = strtotime(date('Y-m-d') . ' 23:59:59');

        return Db::name('fission_material_task')
            ->where('adv_id', $adv_id)
            ->whereIn('material_id', $materialIds)
            ->whereBetween('create_time', [$todayStart, $todayEnd])
            ->where(function ($query) {
                // 今天已经有记录的素材，但排除包含"请重试"字眼的失败记录
                $query->where(function ($subQuery) {
                    // 有成功记录或非重试类型的失败记录
                    $subQuery->whereRaw('fission_msg IS NOT NULL')
                        ->where('fission_msg', 'not like', '%请重试%');
                })->whereOr(function ($subQuery) {
                    // 或者有task_id但还没有fission_msg的记录（刚提交的任务）
                    $subQuery->where('task_id', '>', 0)
                        ->whereRaw('fission_msg IS NULL');
                });
            })
            ->column('material_id');
    }
    /**
     * 获取不能裂变的素材id
     * 过滤逻辑：排除以下素材
     * 1. 有错误原因且不包含"请重试"字眼的素材
     * 2. status_code > 0 且不包含"请重试"字眼的素材
     * 3. fission_status = 'FAILED' 且不包含"请重试"字眼的素材
     * @param string $adv_id 广告主ID
     * @param array $materialIds 素材ID数组
     * @return array 需要过滤掉的素材ID数组
     */
    private function getRefusedMaterialId(string $adv_id, array $materialIds): array
    {
        return Db::name('fission_material_task')
            ->field('material_id')
            ->where('material_id', 'in', $materialIds)
            ->where('adv_id', $adv_id)
            ->where(function ($query) {
                // 过滤条件：有错误原因、状态是FAILED或status_code大于0的素材
                $query->where(function ($subQuery) {
                    // 有fission_msg且不包含"请重试"字眼的失败记录
                    $subQuery->whereRaw('fission_msg IS NOT NULL')
                        ->where('fission_msg', 'not like', '%请重试%')
                        ->where(function ($innerQuery) {
                            $innerQuery->where('status_code', '>', 0)
                                ->whereOr('fission_status', 'FAILED');
                        });
                })->whereOr(function ($subQuery) {
                    // 或者status_code大于0但没有"请重试"字眼的记录
                    $subQuery->where('status_code', '>', 0)
                        ->where(function ($innerQuery) {
                            $innerQuery->whereRaw('status_message IS NULL')
                                ->whereOr('status_message', 'not like', '%请重试%');
                        });
                })->whereOr(function ($subQuery) {
                    // 或者fission_status为FAILED但没有"请重试"字眼的记录
                    $subQuery->where('fission_status', 'FAILED')
                        ->where(function ($innerQuery) {
                            $innerQuery->whereRaw('fission_msg IS NULL')
                                ->whereOr('fission_msg', 'not like', '%请重试%');
                        });
                });
            })
            ->group('material_id')
            ->column('material_id');
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

    /**
     * 获取黑名单公司列表
     * @return array
     */
    private function getBlackCompanyList(): array
    {
        $config_file_path = APP_PATH . 'api/controller/fission/black_company_config_fission.php';

        // 尝试从PHP配置文件读取
        if (file_exists($config_file_path)) {
            try {
                $black_company_list = include $config_file_path;
                if (is_array($black_company_list) && !empty($black_company_list)) {
                    echo "从黑名单配置文件读取到 " . count($black_company_list) . " 个黑名单公司\n";
                    return $black_company_list;
                }
            } catch (\Exception $e) {
                echo "读取黑名单配置文件失败: " . $e->getMessage() . "\n";
            }
        }

        echo "未找到黑名单配置文件或配置为空\n";
        return [];
    }


}
