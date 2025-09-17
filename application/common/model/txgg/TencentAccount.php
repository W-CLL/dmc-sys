<?php

namespace app\common\model\txgg;

use think\Model;

class TencentAccount extends Model
{
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    // 定义字段类型以确保字段存在
    protected $schema = [
        'account_type' => 'integer', // account_type 是整数类型
        'store_id' => 'integer', // store_id 是整数类型
    ];
    
    public function store()
    {
        return $this->belongsTo('app\admin\model\Store', 'store_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}