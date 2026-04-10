<?php

namespace app\admin\model;

use think\Model;

class TencentShareWallet extends Model
{
    // 表名
    protected $name = 'tencent_share_wallet';
    
    // 主键
    protected $pk = 'id';
    
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = false;
    
    // 不隐藏任何字段
    protected $hidden = [];
    
    // 明确指定可见字段确保包含id和store关联字段
    protected $visible = ['id', 'store_id', 'sub_wallet_id', 'sub_wallet_name', 'wallet_type', 'discount_percentage', 'agency', 'store'];
    
    // 关联店铺模型
    public function store()
    {
        return $this->belongsTo('app\admin\model\Store', 'store_id', 'id');
    }
}