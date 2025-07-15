<?php

namespace app\robotapi\model;

use think\Model;
class Store extends Model
{

    public function getStoreInfo($store_id){
        return $this->where('id', $store_id)->find();
    }
}