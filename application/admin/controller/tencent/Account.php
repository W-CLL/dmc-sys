<?php

namespace app\admin\controller\tencent;

use app\common\controller\Backend;
use app\common\model\txgg\TencentAccount;
use think\Db;

/**
 * 腾讯广告账户管理
 *
 * @icon fa fa-user
 */
class Account extends Backend
{
    protected $relationSearch = true;
    protected $searchFields = 'account_id,name,store.username';

    /**
     * @var TencentAccount
     */
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new TencentAccount();
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
                ->with(['store'])
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);
            
            $result = array("total" => $list->total(), "rows" => $list->items());

            return json($result);
        }
        return $this->view->fetch();
    }

    /**
     * 编辑
     */
    public function edit($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        
        if ($this->request->isPost()) {
            $this->token();
            $params = $this->request->post("row/a");
            // 格式化折扣比例字段
            if (isset($params['discount_percentage'])) {
                $params['discount_percentage'] = number_format($params['discount_percentage'], 4, '.', '');
            }
            if ($this->model->where(['id' => $ids])->update($params)) {
                $this->success();
            }
            $this->error();
        }
        
        $this->view->assign("row", $row);
        $this->view->assign('storeList', build_select('row[store_id]', [0=>"不绑定"]+Db::name("store")->column('id,username'), $row['store_id'], ['class' => 'form-control selectpicker']));
        return $this->view->fetch();
    }

    /**
     * 批量绑定
     */
    public function batch_binding()
    {
        if ($this->request->isPost()) {
            $this->token();
            $ids = $this->request->post("ids");
            if (!$ids) {
                $this->error(__('Invalid parameters'));
            }
            
            $data['store_id'] = $this->request->post("store_id");
            $data['account_type'] = $this->request->post("account_type");
            // 格式化折扣比例字段
            $data['discount_percentage'] = number_format($this->request->post("discount_percentage", 0), 4, '.', '');
            
            // 将逗号分隔的ID转换为数组
            $idArray = explode(',', $ids);
            
            if ($this->model->where(["id"=>["in",$idArray]])->update($data)){
                $this->success();
            }
            $this->error();
        }
        
        $store_data = Db::name("store")->column('id,username');
        $store_data[0] = "不绑定";
        $this->view->assign('storeList', build_select('store_id', $store_data, 0, ['class' => 'form-control selectpicker' ,'data-live-search'=>'true']));
        return $this->view->fetch();
    }
}