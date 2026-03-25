<?php

namespace app\common\model;

use think\Model;

/**
 * 素材诊断模型
 */
class MaterialDiagnosis extends Model
{
    // 表名
    protected $name = 'material_diagnosis';
    
    // 自动写入时间戳
    protected $autoWriteTimestamp = false;
    
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    // 状态文本映射
    public function getStatusList($status = null)
    {
        $list = [
            0 => 'PENDING',
            1 => 'SUCCESS',
            2 => 'FAILED'
        ];
        
        if ($status !== null) {
            return isset($list[$status]) ? $list[$status] : '';
        }
        
        return $list;
    }
    
    // 是否获取详情文本映射
    public function getIsGetList($isGet = null)
    {
        $list = [
            0 => '未获取详情',
            1 => '已获取详情'
        ];
        
        if ($isGet !== null) {
            return isset($list[$isGet]) ? $list[$isGet] : '';
        }
        
        return $list;
    }
    
    // 是否千川优质素材文本映射
    public function getIsEcpHighQualityList($isEcp = null)
    {
        $list = [
            0 => 'UNKNOWN',
            1 => 'YES',
            2 => 'NO'
        ];
        
        if ($isEcp !== null) {
            return isset($list[$isEcp]) ? $list[$isEcp] : '';
        }
        
        return $list;
    }
    
    // 是否低效素材文本映射
    public function getIsInefficientList($isInefficient = null)
    {
        $list = [
            0 => 'UNKNOWN',
            1 => 'YES',
            2 => 'NO'
        ];
        
        if ($isInefficient !== null) {
            return isset($list[$isInefficient]) ? $list[$isInefficient] : '';
        }
        
        return $list;
    }
    
    // 是否首发素材文本映射
    public function getIsFirstPublishList($isFirst = null)
    {
        $list = [
            0 => 'UNKNOWN',
            1 => 'YES',
            2 => 'NO'
        ];
        
        if ($isFirst !== null) {
            return isset($list[$isFirst]) ? $list[$isFirst] : '';
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