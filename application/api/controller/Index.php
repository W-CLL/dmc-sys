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

    public function getQueueStatusList()
    {
        $post = file_get_contents('php://input');
        $post = json_decode($post, true);

        if (!isset($post['validate'])) {
            $data = [
                'code' => 1001,
                'msg' => '非法请求',
            ];
            return json($data);
        }
        $validate = $post['validate'];
        unset($post['validate']);

        if ($validate == md5(json_encode($post))) {
            $code_type = $post['where']['code_type'];
            unset($post['where']['code_type']);
            $kefu = $post['kefu'];
            if (in_array($kefu, ['tyx', 'wyc','zqp','cxy']) && $code_type==1) {
                list($list, $count) = $this->getListAndCountAvg($post, $code_type);
            } else {
                list($list, $count) = $this->getListAndCount($post, $code_type);
            }
            $data = [
                'code' => 0,
                'list' => $list,
                'count' => $count,
            ];
            return json($data);
        } else {
            $data = [
                'code' => 1002,
                'msg' => '验证失败',
            ];
            return json($data);
        }
    }

    public function getListAndCountAvg($post, $code_type)
    {
        $post['where']['queue_name'] = "autoUpdateObjNameAvg";
        $list = Db::name("queue_record_avg")
            ->where($post['where'])
            ->order($post['sort'], $post['order'])
            ->limit($post['offset'], $post['limit'])
            ->select();

        $count = Db::name("queue_record_avg")
            ->where($post['where'])
            ->count();

        return [$list,$count];
    }

    public function getListAndCount($post, $code_type)
    {
        $list = Db::name("queue_record")
            ->where($post['where'])
            ->where(function ($query) use ($code_type) {
                if ($code_type == '1') {
                    $query->whereRaw("TIME(FROM_UNIXTIME(create_time))  BETWEEN '09:00:00' AND '09:02:00'");
                }
            })
            ->order($post['sort'], $post['order'])
            ->limit($post['offset'], $post['limit'])
            ->select();

        $count = Db::name("queue_record")
            ->where($post['where'])
            ->where(function ($query) use ($code_type) {
                if ($code_type == '1') {
                    $query->whereRaw("TIME(FROM_UNIXTIME(create_time))  BETWEEN '09:00:00' AND '09:02:00'");
                }
            })
            ->count();

        return [$list,$count];
    }
}
