<?php

namespace app\common\model\viral_fission;

use think\Model;
use think\Db;
use think\Exception;

/**
 * 全域计划的素材数据
 */
class AdvGlobalObjMaterial extends Model
{
    /**
     * @var mixed|string[]
     */
    protected $name = 'fission_global_obj_material_202508';
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    /**
     * 模型初始化
     */
    protected static function init()
    {
        // 在模型初始化时自动设置当前月份的表名
        static::event('before_write', function ($model) {
            $model->autoSetMonthlyTable();
        });
    }

    /**
     * 构造函数 - 自动初始化月份表
     * @throws Exception
     */
    public function __construct($data = [])
    {
        parent::__construct($data);
        $this->autoSetMonthlyTable();
    }

    /**
     * 自动设置月份表名并创建表（如果不存在）
     *
     * @param string|null $month 月份，格式：YYYYMM，默认为当前月份
     * @return $this
     * @throws Exception
     */
    private function autoSetMonthlyTable(string $month = null): AdvGlobalObjMaterial
    {
        // 如果未指定月份，使用当前月份
        if ($month === null) {
            $month = date('Ym');
        }

        // 设置表名
        $tableName = 'fission_global_obj_material_' . $month;
        $this->name = $tableName;

        // 检查表是否存在，不存在则创建
        if (!$this->checkTableExists($tableName)) {
            $this->createMonthlyTable($tableName);
        }

        return $this;
    }

    /**
     * 手动设置指定月份的表（可选使用）
     *
     * @param string $month 月份，格式：YYYYMM
     * @return $this
     * @throws Exception
     */
    public function setMonth(string $month): AdvGlobalObjMaterial
    {
        // 验证月份格式
        if (!preg_match('/^\d{6}$/', $month)) {
            throw new Exception("月份格式错误，应为YYYYMM格式，如：202508");
        }

        return $this->autoSetMonthlyTable($month);
    }

    /**
     * 检查表是否存在
     *
     * @param string $tableName 表名
     * @return bool
     */
    private function checkTableExists(string $tableName): bool
    {
        try {
            $prefix = config('database.prefix');
            $fullTableName = $prefix . $tableName;

            $result = Db::query("SHOW TABLES LIKE '{$fullTableName}'");
            return !empty($result);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 创建月度表
     *
     * @param string $tableName 表名
     * @throws Exception
     */
    private function createMonthlyTable(string $tableName)
    {
        try {
            $prefix = config('database.prefix');
            $fullTableName = $prefix . $tableName;

            $sql = "CREATE TABLE `{$fullTableName}` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `adv_id` varchar(30) NOT NULL COMMENT '广告id',
                `obj_id` varchar(30) NOT NULL COMMENT '计划id',
                `material_id` varchar(30) NOT NULL,
                `product_show_count_for_roi2` decimal(10,2) DEFAULT NULL COMMENT '整体展示次数',
                `product_click_count_for_roi2` decimal(10,2) DEFAULT NULL COMMENT '整体点击次数',
                `product_cvr_rate_for_roi2` decimal(10,2) DEFAULT NULL COMMENT '整体点击率',
                `product_convert_rate_for_roi2` decimal(10,2) DEFAULT NULL COMMENT '整体转化率',
                `stat_cost_for_roi2` decimal(10,2) DEFAULT NULL COMMENT '整体消耗，单位元',
                `total_prepay_and_pay_order_roi2` decimal(10,2) DEFAULT NULL COMMENT '整体支付ROI',
                `total_pay_order_gmv_for_roi2` decimal(10,2) DEFAULT NULL COMMENT '用户实际支付金额，单位元',
                `total_pay_order_count_for_roi2` decimal(10,2) DEFAULT NULL COMMENT '整体成交订单数',
                `total_cost_per_pay_order_for_roi2` decimal(10,2) DEFAULT NULL COMMENT '整体成交订单成本，单位元',
                `total_pay_order_coupon_amount_for_roi2` decimal(10,2) DEFAULT NULL COMMENT '整体成交智能优惠券金额，单位元',
                `total_unfinished_estimate_order_gmv_for_roi2` decimal(10,2) DEFAULT NULL COMMENT '整体未完结预售订单预估金额，单位元',
                `is_delete` tinyint(2) DEFAULT NULL COMMENT '是否已删除',
                `product_info` mediumtext COMMENT '商品信息',
                `material_status` varchar(50) DEFAULT NULL COMMENT '素材状态，DELIVERY_OK 投放中 DELETED 已删除',
                `material_select_type` varchar(50) DEFAULT NULL COMMENT '素材类型，CUSTOM 自选投放素材,AUTO 智能优选素材',
                `material_type` varchar(50) DEFAULT NULL COMMENT '素材类型',
                `audit_status` varchar(50) DEFAULT NULL COMMENT '审核状态 PASS审核通过 REJECT审核拒绝IN_PROGRESS审核中',
                `create_time` int(11) NOT NULL,
                `update_time` int(11) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `adv_obj_material_idx` (`adv_id`,`obj_id`,`material_id`) USING BTREE COMMENT '联合唯一索引',
                KEY `idx_adv_obj_cost_stat` (`adv_id`,`obj_id`,`stat_cost_for_roi2`,`material_status`) USING BTREE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='全域计划素材消耗表_{$tableName}';";

            Db::execute($sql);

            // 记录日志
            trace("成功创建月度表: {$fullTableName}", 'info');

        } catch (\Exception $e) {
            throw new Exception("创建月度表失败: " . $e->getMessage());
        }
    }

    /**
     * 获取当前使用的表名
     *
     * @return string
     */
    public function getCurrentTableName()
    {
        return $this->name;
    }

    /**
     * 获取指定月份的表名
     *
     * @param string $month 月份，格式：YYYYMM
     * @return string
     */
    public static function getTableNameByMonth(string $month): string
    {
        return 'fission_global_obj_material_' . $month;
    }

    /**
     * 获取可用的月份表列表
     *
     * @return array
     */
    public function getAvailableMonthlyTables(): array
    {
        try {
            $prefix = config('database.prefix');
            $pattern = $prefix . 'fission_global_obj_material_%';

            $result = Db::query("SHOW TABLES LIKE '{$pattern}'");
            $tables = [];

            foreach ($result as $row) {
                $tableName = current($row);
                // 提取月份
                if (preg_match('/fission_global_obj_material_(\d{6})$/', $tableName, $matches)) {
                    $tables[] = [
                        'table_name' => str_replace($prefix, '', $tableName),
                        'month' => $matches[1],
                        'full_table_name' => $tableName
                    ];
                }
            }

            // 按月份排序
            usort($tables, function($a, $b) {
                return strcmp($b['month'], $a['month']);
            });

            return $tables;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 静态方法：创建指定月份的模型实例
     *
     * @param string $month 月份，格式：YYYYMM
     * @return static
     * @throws Exception
     */
    public static function forMonth(string $month): AdvGlobalObjMaterial
    {
        $instance = new static();
        return $instance->setMonth($month);
    }
}