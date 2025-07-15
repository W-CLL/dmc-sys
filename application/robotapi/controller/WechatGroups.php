<?php

namespace app\robotapi\controller;

class WechatGroups extends Base
{
    public function get(){
        return $this->handleRequest(1, '', '');

    }

    public function post()
    {
        return $this->handleRequest(2, 'addGroupData', 'Successfully added');
    }

    public function put()
    {
        return $this->handleRequest(3, 'updateGroupData', 'Successfully updated');
    }

    public function delete()
    {
//        return $this->handleRequest(4, 'deleteGroupData', 'Successfully deleted');
        return $this->handleRequest(4, '', '');
    }


}