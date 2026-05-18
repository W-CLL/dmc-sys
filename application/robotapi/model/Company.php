<?php

namespace app\robotapi\model;

use think\Model;

class Company extends Model
{
    public function getByAdvId($adv_id)
    {
        return $this->where('advertiser_id', $adv_id)->find();
    }
}