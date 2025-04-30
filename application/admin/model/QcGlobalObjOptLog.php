<?php

namespace app\admin\model;

use think\Model;

class QcGlobalObjOptLog extends Model
{

    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'create_time';
//    protected $updateTime = 'update_time';
    public function obj()
    {
        return $this->belongsTo('QcGlobalObj','obj_id','object_id');
    }


    protected static function init(){
//        static::afterInsert(function ($user) {
//
//        });
    }

}