<?php

namespace app\store\model;

use think\Model;

class TencentTransactionLog extends Model
{
    // 设置数据表（不含前缀）
    protected $name = 'tencent_transfer_log';

    // 默认主键为id
    protected $pk = 'id';

    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    // 自动写入时间戳
    protected $autoWriteTimestamp = 'int';

    // 定义字段类型
    protected $type = [
        'money' => 'decimal',
        'deduction_balance' => 'decimal',
        'deduction_credit_limit' => 'decimal',
        'rebate' => 'decimal',
        'discount_percentage' => 'decimal',
        'actual_money' => 'decimal',
        'before_money' => 'decimal',
        'today_money' => 'decimal',
        'balance_surplus' => 'decimal',
        'credit_limit_surplus' => 'decimal',
    ];
    
    // 定义字段默认值
    protected $default = [
        'money' => 0.00,
        'deduction_balance' => 0.00,
        'deduction_credit_limit' => 0.00,
        'rebate' => 0.00,
        'actual_money' => 0.00,
        'before_money' => 0.00,
        'today_money' => 0.00,
        'balance_surplus' => 0.00,
        'credit_limit_surplus' => 0.00,
    ];
}