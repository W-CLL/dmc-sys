<?php

namespace app\store\model;

use think\Model;
use think\Session;

class TransferRecords extends Model
{

    // 开启自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';


    public function company()
    {
        return $this->hasOne('Company','id','company_id')->field('id,company_name,advertiser_id');
    }

}