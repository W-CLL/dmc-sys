<?php

namespace app\admin\model;

use think\Model;
class WechatGroup extends Model
{
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    public function store()
    {
        return $this->belongsTo('Store','bind_store_id','id');
    }
}