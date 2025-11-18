<?php

namespace app\robotapi\controller;

class TransferVirtualFund extends Base
{

    public function get(){
        return $this->handleRequest(1, '', '');
    }

    public function post(){
        return $this->handleRequest(2, 'transfer', '转账处理中...【结果最晚将于10分钟后返回】');
    }


    public function put(){
        return $this->handleRequest(3, '', '');
    }


    public function delete(){
        return $this->handleRequest(4, '', '');
    }

}