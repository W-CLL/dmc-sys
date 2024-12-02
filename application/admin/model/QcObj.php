<?php

namespace app\admin\model;

use think\Model;

class QcObj extends Model
{
    public function company()
    {
        return $this->belongsTo('Company','company_id','id');
    }
}