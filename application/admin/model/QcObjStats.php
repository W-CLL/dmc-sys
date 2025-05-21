<?php

namespace app\admin\model;


use think\Model;

class QcObjStats extends Model
{

    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

}