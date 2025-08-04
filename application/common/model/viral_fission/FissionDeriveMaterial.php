<?php

namespace app\common\model\viral_fission;

use think\Model;

/**
 * 爆款裂变后的视频
 */
class FissionDeriveMaterial extends Model
{
    /**
     * @var mixed|string[]
     */

    protected $name = 'fission_derive_material';
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    /**
     * 关联全域素材表
     */
    public function globalMaterial()
    {
        return $this->belongsTo('AdvGlobalMaterial', 'adopt_material_id', 'material_id');
    }

}