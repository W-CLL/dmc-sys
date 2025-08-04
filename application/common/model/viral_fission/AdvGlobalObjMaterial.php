<?php

namespace app\common\model\viral_fission;

use think\Model;

/**
 * 全域计划的素材数据
 */
class AdvGlobalObjMaterial extends Model
{
    /**
     * @var mixed|string[]
     */

    protected $name = 'fission_global_obj_material';
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';


}