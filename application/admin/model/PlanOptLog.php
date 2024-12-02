<?php

namespace app\admin\model;

use think\Model;

class PlanOptLog extends Model
{
    public function obj()
    {
        return $this->belongsTo('QcObj','obj_id','object_id');
    }
}