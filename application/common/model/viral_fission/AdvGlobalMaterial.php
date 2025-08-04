<?php

namespace app\common\model\viral_fission;

use think\Model;

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
     * 获取指定时间范围的消耗数据
     */
    public static function getConsumptionData($startTimestamp, $endTimestamp)
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
                'g.cost_date',
                'g.stat_cost_for_roi2',
                'g.material_id',
                'c.company_name'
            ])
            ->select();
    }

    /**
     * 获取裂变素材消耗数据
     */
    public static function getFissionConsumptionData($startTimestamp, $endTimestamp)
    {
        return self::alias('g')
            ->join('fa_company c', 'g.adv_id = c.advertiser_id')
            ->join('fa_fission_derive_material d', 'g.material_id = d.adopt_material_id')
            ->where('c.company_name', 'neq', '')
            ->where('c.company_name', 'not null')
            ->where('g.cost_date', '>=', $startTimestamp)
            ->where('g.cost_date', '<=', $endTimestamp)
            ->where('g.cost_date', '>', 0)
            ->where('g.stat_cost_for_roi2', '>', 0)
            ->where('d.adopt_status_message', 'success')
            ->field([
                'g.cost_date',
                'g.stat_cost_for_roi2',
                'g.material_id',
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