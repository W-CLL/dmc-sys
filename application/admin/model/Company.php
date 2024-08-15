<?php

namespace app\admin\model;


use think\Model;

class Company extends Model
{

    public function store(){
        return $this->hasOne('Store',"id","store_id")->field("id,username");
    }

}