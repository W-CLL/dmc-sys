<?php

namespace app\admin\model;

use think\Model;

/**
 * 爆款裂变后的素材模型
 */
class AdvDeriveMaterial extends Model
{
    // 表名
    protected $name = 'adv_derive_material';
    
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    // 追加属性
    protected $append = [
        'create_time_text',
        'update_time_text'
    ];
    
    /**
     * 获取创建时间文本
     */
    public function getCreateTimeTextAttr($value, $data)
    {
        $value = $value ? $value : (isset($data['create_time']) ? $data['create_time'] : '');
        return is_numeric($value) ? date("Y-m-d H:i:s", $value) : $value;
    }
    
    /**
     * 获取更新时间文本
     */
    public function getUpdateTimeTextAttr($value, $data)
    {
        $value = $value ? $value : (isset($data['update_time']) ? $data['update_time'] : '');
        return is_numeric($value) ? date("Y-m-d H:i:s", $value) : $value;
    }
    
    /**
     * 设置创建时间
     */
    public function setCreateTimeAttr($value)
    {
        return $value === '' ? null : ($value && !is_numeric($value) ? strtotime($value) : $value);
    }

    /**
     * 设置更新时间
     */
    public function setUpdateTimeAttr($value)
    {
        return $value === '' ? null : ($value && !is_numeric($value) ? strtotime($value) : $value);
    }
}
