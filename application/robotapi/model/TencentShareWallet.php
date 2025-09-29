<?php

namespace app\robotapi\model;

use think\Model;

class TencentShareWallet extends Model
{
    public function store()
    {
        return $this->belongsTo('app\admin\model\Store', 'store_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }


    public function getByWalletId($wallet_id)
    {
        return $this->where('sub_wallet_id', $wallet_id)->find();
    }

}