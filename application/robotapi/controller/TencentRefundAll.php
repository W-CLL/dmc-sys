<?php

namespace app\robotapi\controller;

class TencentRefundAll extends Base
{
    public function get(){
        return $this->handleRequest(1, '', '');
    }

    public function post(){
        return $this->handleRequest(2, 'refundAll', '转账处理中...【结果最晚将于10分钟后返回，超出10分钟请联系工作人员】');
    }


    public function put(){
        return $this->handleRequest(3, '', '');
    }


    public function delete(){
        return $this->handleRequest(4, '', '');
    }
}