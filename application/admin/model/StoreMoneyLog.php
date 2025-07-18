<?php

namespace app\admin\model;

use think\Model;

class StoreMoneyLog extends Model
{
    public function StoreAdminAccess()
    {
        return $this->hasMany('StoreAdminAccess','store_id','store_id');
    }


    public function transferRecords(){
        return $this->hasOne('TransferRecords','id','transfer_records_id');
    }

    public function swtl(){
        return $this->hasOne('ShareWalletTransferLog','id','swtl_id');
    }

}