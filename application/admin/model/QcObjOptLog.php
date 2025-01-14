<?php

namespace app\admin\model;

use think\Model;

class QcObjOptLog extends Model
{

    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'create_time';
//    protected $updateTime = 'update_time';
    public function obj()
    {
        return $this->belongsTo('QcObj','obj_id','object_id');
    }


    protected static function init(){
//        static::afterInsert(function ($user) {
//
//        });
    }

}