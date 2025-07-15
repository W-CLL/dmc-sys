<?php

namespace app\robotapi\controller;

class Reserve extends Base
{
    public function get(){
        return $this->handleRequest(1, 'getUrl', '');
    }

    public function post(){
        return $this->handleRequest(2, '', '');
    }


    public function put(){
        return $this->handleRequest(3, '', '');
    }


    public function delete(){
        return $this->handleRequest(4, '', '');
    }

}