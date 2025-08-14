<?php

namespace app\common\model\viral_fission;

use think\Model;
use think\Db;

/**
 * 全域账户的素材数据
 */
class AdvGlobalMaterial extends Model
{
    /**
     * @var mixed|string[]
     */

    protected $name = 'fission_global_material';
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    /**
     * 关联公司表
     */
    public function company()
    {
        return $this->belongsTo('app\admin\model\Company', 'adv_id', 'advertiser_id');
    }

    /**
     * 关联裂变素材表
     */
    public function fissionDerive()
    {
        return $this->hasOne('FissionDeriveMaterial', 'adopt_material_id', 'material_id');
    }

    /**
     * 获取指定时间范围的消耗数据（优化内存版）
     */
    public static function getConsumptionData($startTimestamp, $endTimestamp)
    {
        // 使用更精确的条件组合减少内存占用
        return Db::name('fission_global_material') // 使用实际表名，不含前缀
            ->alias('g')
            ->join('fa_company c', 'g.adv_id = c.advertiser_id')
//            ->where('c.company_name', '<>', '')
//            ->where('c.company_name', 'not null') // 修复表达式错误
            ->where('g.cost_date', 'between', [$startTimestamp, $endTimestamp])
            ->where('g.cost_date', '>', 0)
            ->where('g.stat_cost_for_roi2', '>', 0)
            ->field([
                'g.cost_date',
                'g.stat_cost_for_roi2',
                'g.material_id',
                'c.company_name'
            ])
            ->select();
    }

    /**
     * 获取裂变素材消耗数据（优化内存版）
     */
    public static function getFissionConsumptionData($startTimestamp, $endTimestamp)
    {
        // 优化JOIN条件和查询逻辑
        return Db::name('fission_global_material') // 使用实际表名，不含前缀
            ->alias('g')
            ->join('fa_company c', 'g.adv_id = c.advertiser_id')
            ->join(
                'fa_fission_derive_material d',
                'g.material_id = d.adopt_material_id AND d.adopt_status_message = "success"',
                'INNER'
            )
            ->where('c.company_name', '<>', '')
            ->where('c.company_name', 'not null') // 修复表达式错误
            ->where('g.cost_date', 'between', [$startTimestamp, $endTimestamp])
            ->where('g.cost_date', '>', 0)
            ->where('g.stat_cost_for_roi2', '>', 0)
            ->field([
                'g.cost_date',
                'g.stat_cost_for_roi2',
//                'g.material_id',
                'c.company_name'
            ])
            ->select();
    }

    /**
     * 获取公司消耗排行数据
     */
    public static function getCompanyRankingData($startTimestamp, $endTimestamp, $limit = 20)
    {
        return self::alias('g')
            ->join('fa_company c', 'g.adv_id = c.advertiser_id')
            ->where('c.company_name', 'neq', '')
            ->where('c.company_name', 'not null')
            ->where('g.cost_date', '>=', $startTimestamp)
            ->where('g.cost_date', '<=', $endTimestamp)
            ->where('g.cost_date', '>', 0)
            ->where('g.stat_cost_for_roi2', '>', 0)
            ->field([
                'c.company_name',
                'SUM(g.stat_cost_for_roi2) as total_cost',
                'COUNT(DISTINCT g.material_id) as material_count'
            ])
            ->group('c.company_name')
            ->order('total_cost', 'desc')
            ->limit($limit)
            ->select();
    }

    /**
     * 获取公司裂变消耗数据
     */
    public static function getCompanyFissionData($startTimestamp, $endTimestamp, $companyNames)
    {
        if (empty($companyNames)) {
            return [];
        }

        return self::alias('g')
            ->join('fa_company c', 'g.adv_id = c.advertiser_id')
            ->join('fa_fission_derive_material d', 'g.material_id = d.adopt_material_id')
            ->where('c.company_name', 'in', $companyNames)
            ->where('g.cost_date', '>=', $startTimestamp)
            ->where('g.cost_date', '<=', $endTimestamp)
            ->where('g.cost_date', '>', 0)
            ->where('g.stat_cost_for_roi2', '>', 0)
            ->where('d.adopt_status_message', 'success')
            ->field([
                'c.company_name',
                'SUM(g.stat_cost_for_roi2) as fission_cost',
                'COUNT(DISTINCT g.material_id) as fission_material_count'
            ])
            ->group('c.company_name')
            ->select();
    }

}