<?php

namespace app\robotapi\model;

use think\Model;

class TencentTransactionLog extends Model
{

    // 默认主键为id
    protected $pk = 'id';

    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    // 自动写入时间戳
    protected $autoWriteTimestamp = 'int';

}