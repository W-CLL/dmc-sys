<?php

namespace app\common\model;

use think\Model;

/**
 * 素材追投白名单模型
 */
class MaterialWhitelist extends Model
{
    // 表名
    protected $name = 'material_whitelist';
    
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    // 追加属性
    protected $append = [
        'status_text',
        'filter_type_text'
    ];

    // 过滤类型常量
    const FILTER_TYPE_COMPANY = 1; // 公司级别
    const FILTER_TYPE_ADV = 2;     // 广告主级别

    /**
     * 获取状态文本
     */
    public function getStatusTextAttr($value, $data)
    {
        $status = $data['status'] ?? 0;
        $statusList = [0 => '禁用', 1 => '启用'];
        return $statusList[$status] ?? '未知';
    }

    /**
     * 获取过滤类型文本
     */
    public function getFilterTypeTextAttr($value, $data)
    {
        $filterType = $data['filter_type'] ?? 1;
        $typeList = [
            self::FILTER_TYPE_COMPANY => '公司级别',
            self::FILTER_TYPE_ADV => '广告主级别'
        ];
        return $typeList[$filterType] ?? '未知';
    }

    /**
     * 获取过滤类型列表
     */
    public static function getFilterTypeList()
    {
        return [
            self::FILTER_TYPE_COMPANY => '公司级别',
            self::FILTER_TYPE_ADV => '广告主级别'
        ];
    }

    /**
     * 获取启用状态的白名单公司列表
     * @return array
     */
    public static function getActiveCompanies()
    {
        return self::where('status', 1)->column('company_name');
    }

    /**
     * 检查公司是否在白名单中
     * @param string $companyName
     * @return bool
     */
    public static function isWhitelisted($companyName)
    {
        return self::where('company_name', $companyName)
            ->where('status', 1)
            ->count() > 0;
    }

    /**
     * 批量添加白名单公司
     * @param array $companies
     * @param string $remark
     * @return bool
     */
    public static function batchAdd($companies, $remark = '')
    {
        if (empty($companies)) {
            return false;
        }

        $data = [];
        $time = time();
        
        foreach ($companies as $company) {
            $company = trim($company);
            if (empty($company)) {
                continue;
            }
            
            $data[] = [
                'company_name' => $company,
                'status' => 1,
                'remark' => $remark,
                'create_time' => $time,
                'update_time' => $time
            ];
        }

        if (empty($data)) {
            return false;
        }

        try {
            // 使用replace into避免重复插入
            $model = new self();
            foreach ($data as $item) {
                $existing = self::where('company_name', $item['company_name'])->find();
                if ($existing) {
                    // 更新现有记录
                    $existing->status = 1;
                    $existing->remark = $item['remark'];
                    $existing->update_time = $time;
                    $existing->save();
                } else {
                    // 插入新记录
                    self::create($item);
                }
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 批量删除白名单公司
     * @param array $companies
     * @return bool
     */
    public static function batchDelete($companies)
    {
        if (empty($companies)) {
            return false;
        }

        try {
            return self::where('company_name', 'in', $companies)->delete();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 批量更新状态
     * @param array $ids
     * @param int $status
     * @return bool
     */
    public static function batchUpdateStatus($ids, $status)
    {
        if (empty($ids)) {
            return false;
        }

        try {
            return self::where('id', 'in', $ids)->update([
                'status' => $status,
                'update_time' => time()
            ]);
        } catch (\Exception $e) {
            return false;
        }
    }
}
