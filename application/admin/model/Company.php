<?php

namespace app\admin\model;


use app\admin\controller\QueueRecord;
use app\common\model\Queue;
use think\Model;

class Company extends Model
{

    public function store()
    {
        return $this->hasOne('Store', "id", "store_id")->field("id,username");
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