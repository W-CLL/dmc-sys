<?php

namespace app\admin\model;


use app\admin\controller\QueueRecord;
use app\common\model\Queue;
use think\Model;

class Company extends Model
{
    // 表名
    protected $name = 'company';

    public function store()
    {
        return $this->hasOne('Store', "id", "store_id")->field("id,username");
    }

    /**
     * 关联素材表
     */
    public function materials()
    {
        return $this->hasMany('app\common\model\viral_fission\AdvGlobalMaterial', 'adv_id', 'advertiser_id');
    }

//
//    protected static function init()
//    {
//        static::afterInsert(function ($user) {
//            $queue = new Queue();
//           dump($user->data['name']);
//           die;
//        });
//    }


}