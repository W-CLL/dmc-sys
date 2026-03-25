<?php

namespace app\common\model;

use think\Model;

/**
 * 违规积分模型
 */
class Violation extends Model
{
    // 表名
    protected $name = 'violation';
    
    // 自动写入时间戳
    protected $autoWriteTimestamp = false;
    
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    // 类型文本映射
    public function getTypeList($type = null)
    {
        $list = [
            1 => '新增违规积分',
            2 => '更新违规积分'
        ];
        
        if ($type !== null) {
            return isset($list[$type]) ? $list[$type] : '';
        }
        
        return $list;
    }
    
    // 状态文本映射
    public function getStatusList($status = null)
    {
        $list = [
            1 => '已申诉(失效)',
            2 => '申诉失败',
            3 => '申诉中',
            4 => '生效'
        ];
        
        if ($status !== null) {
            return isset($list[$status]) ? $list[$status] : '';
        }
        
        return $list;
    }
    
    // 违规类型文本映射
    public function getIllegalTypeList($illegalType = null)
    {
        $list = [
            1 => '一类违规',
            2 => '二类违规'
        ];
        
        if ($illegalType !== null) {
            return isset($list[$illegalType]) ? $list[$illegalType] : '';
        }
        
        return $list;
    }
    
    // 获取类型文本
    public function getTypeTextAttr($value, $data)
    {
        return $this->getTypeList($data['type']) ?? '';
    }
    
    // 获取状态文本
    public function getStatusTextAttr($value, $data)
    {
        return $this->getStatusList($data['status']) ?? '';
    }
    
    // 获取违规类型文本
    public function getIllegalTypeTextAttr($value, $data)
    {
        return $this->getIllegalTypeList($data['illegal_type']) ?? '';
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