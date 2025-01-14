<?php

namespace app\common\model;

use think\Db;
use think\Model;
use txy\TextRecognition;

/**
 * 广告日消耗表
 */
class QcAdvDayCost extends Model
{
    /**
     * @var mixed|string[]
     */

    protected $name = 'qc_adv_day_cost';
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

}