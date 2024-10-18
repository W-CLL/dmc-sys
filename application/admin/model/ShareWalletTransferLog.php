<?php

namespace app\admin\model;

use think\Model;

class ShareWalletTransferLog extends Model
{
    public function store()
    {
        return $this->belongsTo('Store','store_id','id');
    }

    public function storeMoneyLog()
    {
        return $this->hasOne('StoreMoneyLog','swtl_id','id');
    }
}