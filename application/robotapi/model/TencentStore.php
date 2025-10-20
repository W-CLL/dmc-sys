<?php

namespace app\robotapi\model;

use think\Collection;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\Exception;
use think\exception\DbException;
use think\Model;

class TencentStore extends Model
{
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    // 自动写入时间戳
    protected $autoWriteTimestamp = 'int';


    // 定义字段类型
    protected $type = [
        'public_money_tencent' => 'decimal',
        'private_money_tencent' => 'decimal',
        'public_credit_limit_tencent' => 'decimal',
        'private_credit_limit_tencent' => 'decimal',
        'public_spending_credit_limit_tencent' => 'decimal',
        'private_spending_credit_limit_tencent' => 'decimal',
        'public_discount_percentage_tencent' => 'decimal',
        'private_discount_percentage_tencent' => 'decimal',
    ];
    
    // 定义字段默认值
    protected $default = [
        'public_money_tencent' => 0.00,
        'private_money_tencent' => 0.00,
        'public_credit_limit_tencent' => 0.00,
        'private_credit_limit_tencent' => 0.00,
        'public_spending_credit_limit_tencent' => 0.00,
        'private_spending_credit_limit_tencent' => 0.00,
        'public_discount_percentage_tencent' => 0.0000,
        'private_discount_percentage_tencent' => 0.0000,
    ];
    
    // 定义不允许被批量赋值的字段
    protected $guarded = ['id', 'create_time', 'update_time'];

    public function getTencentStore($store_id)
    {
        return $this->where('store_id', $store_id)->find();
    }

}