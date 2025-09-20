<?php

namespace app\common\model\txgg;

use think\Model;

class TencentShareWallet extends Model
{
    public function store()
    {
        return $this->belongsTo('app\admin\model\Store', 'store_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }

}