<?php

namespace app\admin\controller\wechat;

use app\common\controller\Backend;
use app\wechat\model\WechatUser;
use think\Db;

class User extends Backend
{

    /**
     * @var WechatUser
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new WechatUser();
    }

    /**
     * 查看
     */
    public function index()
    {
        //设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            //如果发送的来源是Selectpage，则转发到Selectpage
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $list = $this->model
                ->alias('ws')
                ->join('store s', 's.id=ws.store_id')
                ->field('ws.*,s.username as store_name')
//                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);

            foreach ($list as $k => &$v) {
                $v['is_bind_text'] = $v['is_bind'] ? '已绑定' : '未绑定';
                $v['subscribe_text'] = $v['subscribe'] ? '已关注' : '未关注';
            }
            $result = array("total" => $list->total(), "rows" => $list->items());

            return json($result);
        }
        return $this->view->fetch();
    }


}