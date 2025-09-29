<?php

namespace app\robotapi\model;

use think\Model;
class TencentWalletTransferLog extends Model
{

    // 默认主键为id，如果你没有使用id作为主键名，需要在此设置
    protected $pk = 'id';

    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    protected $autoWriteTimestamp = 'int';

}