<?php

namespace app\admin\model;

use think\Model;

class Keyword extends Model
{
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    public function tag(){
        return $this->belongsTo('Tag', 'tag_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}