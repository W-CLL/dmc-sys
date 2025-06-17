<?php

namespace app\common\model;

use think\Model;

/**
 * 计划商品
 */
class ObjProduct extends Model
{
    /**
     * @var mixed|string[]
     */

    protected $name = 'risk_obj_product';
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';


}