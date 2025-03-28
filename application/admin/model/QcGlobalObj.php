<?php

namespace app\admin\model;

use app\common\model\Queue;
use app\qcdatahandle\controller\InitObjOptLog;
use think\Model;

class QcGlobalObj extends Model
{
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    public function company()
    {
        return $this->belongsTo('Company','company_id','id');
    }


    protected static function init()
    {
//        static::afterInsert(function ($data) {
//            $initObjOptLog = new InitObjOptLog();
//            $res = $initObjOptLog->insertObjOptLog($data->data['adv_id'],[$data->data['obj_id']]);
////            dump($res);
//        });
    }

}