<?php

namespace app\common\model;

use think\Model;

/**
 * 计划风控统计检测
 */
class AdvStats extends Model
{
    /**
     * @var mixed|string[]
     */

    protected $name = 'risk_adv';
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';


}