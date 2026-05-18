<?php

namespace app\robotapi\model;

use think\Model;

class QcShareWallet extends Model
{
    public function getBySubWalletId($sub_wallet_id)
    {
        return $this->where('sub_wallet_id', $sub_wallet_id)->find();
    }

}