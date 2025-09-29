<?php

namespace app\robotapi\model;

use think\Model;

class TencentTransferLog extends Model
{

    // 默认主键为id，如果你没有使用id作为主键名，需要在此设置
    protected $pk = 'id';

    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

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

    const TYPE_LIST = [
        1 => '总后台增加余额',
        2 => '总后台扣款',
        3 => '回单充值',
        4 => '转入',
        5 => '转出',
        8 => '共享钱包转入',
        9 => '共享钱包转出'
    ];
    
    const ACCOUNT_TYPE_LIST = [
        1 => '公账',
        2 => '私账'
    ];
    
    const STATUS_LIST = [
        0 => '未审核',
        1 => '通过',
        2 => '不通过'
    ];
    
    const FROM_LIST = [
        1 => '抖秒冲后台',
        2 => '群聊助手'
    ];

    public function getTypeList()
    {
        return self::TYPE_LIST;
    }
    
    public function getAccountTypeList()
    {
        return self::ACCOUNT_TYPE_LIST;
    }
    
    public function getStatusList()
    {
        return self::STATUS_LIST;
    }
    
    public function getFromList()
    {
        return self::FROM_LIST;
    }
}