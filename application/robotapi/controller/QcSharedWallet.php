<?php

namespace app\robotapi\controller;


class QcSharedWallet extends Base
{
    public function get(){
        return $this->handleRequest(1, 'getWalletBalance', '');
    }

    public function post(){
        return $this->handleRequest(2, 'walletTransfer', "转账处理中，请等待处理结果\n（PS：转账处理结果返回最长等待时间为10分钟，超出10分钟请联系工作人员）");
    }


    public function put(){
        return $this->handleRequest(3, '', '');
    }


    public function delete(){
        return $this->handleRequest(4, '', '');
    }
}