<?php

namespace app\robotapi\controller;

class WorkWechat extends Base
{
    public function get(){
        return $this->handleRequest(1, '', '');
    }

    public function post(){
        return $this->handleRequest(2, 'sandMessage', '');
    }

    public function put(){
        return $this->handleRequest(3, '', '');
    }

    public function delete(){
        return $this->handleRequest(4, '', '');
    }

}