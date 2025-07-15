<?php

namespace app\robotapi\controller;


class QcAccountFunds extends Base
{
    public function get(){
        return $this->handleRequest(1, 'getBalance', '');
    }

    public function post(){
        return $this->handleRequest(2, 'transfer', '转账处理中，请等待处理结果');
    }


    public function put(){
        return $this->handleRequest(3, '', '');
    }


    public function delete(){
        return $this->handleRequest(4, '', '');
    }

}