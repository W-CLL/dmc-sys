<?php

namespace app\common\model;

use think\Model;

/**
 * 广告主积分
 */
class AdvScore extends Model
{
    /**
     * @var mixed|string[]
     */

    protected $name = 'adv_score';
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

}