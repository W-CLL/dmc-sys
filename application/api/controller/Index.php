<?php

namespace app\api\controller;

use app\common\controller\Api;
use think\Db;

/**
 * 首页接口
 */
class Index extends Api
{
    protected $noNeedLogin = ['*'];
    protected $noNeedRight = ['*'];

    /**
     * 首页
     *
     */
    public function index()
    {
        $this->success('请求成功');
    }

    public function getQueueStatusList(){
        $post = file_get_contents('php://input');
        $post = json_decode($post,true);
        if(!isset($post['validate'])){
            $data = [
                'code' => 1001,
                'msg' => '非法请求',
            ];
            return json($data);
        }
        $validate = $post['validate'];
        unset($post['validate']);
        if($validate == md5(json_encode($post))){
            $list = Db::name("queue_record")
                ->where($post['where'])
                ->order($post['sort'], $post['order'])
                ->limit($post['offset'],$post['limit'])
                ->select();
            $count = Db::name("queue_record")->where($post['where'])->count();
            $data = [
                'code' => 0,
                'list' => $list,
                'count' => $count,
            ];
            return json($data);
        }else{
            $data = [
                'code' => 1002,
                'msg' => '验证失败',
            ];
            return json($data);
        }
    }
}
