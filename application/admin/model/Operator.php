<?php

namespace app\admin\model;

use think\Model;


class Operator extends Model
{
    protected $name = 'ad_operator';

    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

}