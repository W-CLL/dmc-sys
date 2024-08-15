<?php

namespace app\admin\model;

use think\Model;

class StoreMoneyLog extends Model
{
    public function StoreAdminAccess()
    {
        return $this->hasMany('StoreAdminAccess','store_id','store_id');
    }

}