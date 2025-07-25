<?php

namespace app\common\model\viral_fission;

use think\Model;

/**
 * 爆款列表
 */
class CompanySetting extends Model
{
    /**
     * @var mixed|string[]
     */

    protected $name = 'fission_company_rules';
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

}