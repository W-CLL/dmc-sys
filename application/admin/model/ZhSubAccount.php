<?php

namespace app\admin\model;

use think\Model;

class ZhSubAccount extends Model
{
    public function store(){
        return $this->belongsTo('Store','store_id','id');
    }
}