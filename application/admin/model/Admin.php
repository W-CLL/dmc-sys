<?php

namespace app\admin\model;

use think\Cache;
use think\Db;
use think\Model;
use think\Session;

class Admin extends Model
{

    // 开启自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';
    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $hidden = [
        'password',
        'salt'
    ];

    public static function init()
    {
        self::beforeWrite(function ($row) {
            $changed = $row->getChangedData();
            //如果修改了用户或或密码则需要重新登录
            if (isset($changed['username']) || isset($changed['password']) || isset($changed['salt'])) {
                $row->token = '';
            }
        });
    }

    public static function admin_nickname(){
        return Db::name("admin")
            ->alias('a')
            ->join('auth_group_access aga','a.id = aga.uid')
            ->join('auth_group ag','aga.group_id = ag.id')
            ->where("ag.id" ,'=','2')
            ->cache('__salesman__',60)
            ->column('a.id,a.nickname');
    }

}
