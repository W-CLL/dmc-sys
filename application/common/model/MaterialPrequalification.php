<?php

namespace app\common\model;

use think\Model;

/**
 * 素材预审资格模型
 */
class MaterialPrequalification extends Model
{
    // 表名
    protected $name = 'material_prequalification';
    
    // 自动写入时间戳
    protected $autoWriteTimestamp = false;
    
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    // 状态文本映射
    public function getStatusList($status = null)
    {
        $list = [
            0 => '等待推送',
            1 => '预审中',
            2 => '通过',
            3 => '驳回',
            4 => '无法推送'
        ];
        
        if ($status !== null) {
            return isset($list[$status]) ? $list[$status] : '';
        }
        
        return $list;
    }
    
    // 获取状态文本
    public function getStatusTextAttr($value, $data)
    {
        return $this->getStatusList($data['status']) ?? '';
    }
    
    // 时间格式化
    public function getCreateTimeTextAttr($value, $data)
    {
        return $data['create_time'] ? date('Y-m-d H:i:s', $data['create_time']) : '-';
    }
    
    public function getUpdateTimeTextAttr($value, $data)
    {
        return $data['update_time'] ? date('Y-m-d H:i:s', $data['update_time']) : '-';
    }
}
