<?php

namespace app\admin\model;

use think\Model;

class QcShareWallet extends Model
{
    public function store()
    {
        return $this->belongsTo('Store','bind_store_id','id');
    }
}