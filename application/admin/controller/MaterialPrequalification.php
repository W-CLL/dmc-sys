<?php

namespace app\admin\controller;

use app\common\controller\Backend;
use app\common\model\MaterialPrequalification as MaterialPrequalificationModel;
use think\Cache;
use think\Db;
use think\Exception;

/**
 * 素材预审资格管理
 *
 * @icon fa fa-file-video-o
 * @remark 管理素材预审资格状态，支持查看预审进度和审核结果
 */
class MaterialPrequalification extends Backend
{
    /**
     * MaterialPrequalification模型对象
     * @var \app\common\model\MaterialPrequalification
     */
    protected $model = null;

    /**
     * 数据限制字段
     */
    protected $dataLimitField = 'id';

    /**
     * 无需鉴权的接口
     */
    protected $noNeedRight = ['view'];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new MaterialPrequalificationModel;

        $this->view->assign("statusList", $this->model->getStatusList());
    }

    /**
     * 查看
     */
    public function index()
    {
        //当前是否为关联查询
        $this->relationSearch = false;
        //设置过滤方法
        $this->request->filter(['strip_tags', 'trim']);
        if ($this->request->isAjax()) {
            //如果发送的来源是Selectpage，则转发到Selectpage
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();

            $list = $this->model
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            $statusList = $this->model->getStatusList();
            
            foreach ($list as $row) {
                $row->visible(['id', 'material_id', 'advertiser_id', 'status', 'reason_text', 'object_id', 'video_id', 'filename', 'create_time', 'update_time']);
                $row->status_text = $statusList[$row->status] ?? '';
            }

            $result = array("total" => $this->model->where($where)->count(), "rows" => $list);

            return json($result);
        }
        
        // 传递权限配置到视图
        $this->assignconfig('operateView', $this->auth->check('material_prequalification/view'));
        
        return $this->view->fetch();
    }

    /**
     * 查看详情
     */
    public function view()
    {
        $id = $this->request->param('id', 0, 'intval');
        $row = $this->model->find($id);
        
        if (!$row) {
            $this->error('记录不存在');
        }
        
        $statusList = $this->model->getStatusList();
        $this->view->assign('row', $row);
        $this->view->assign('statusList', $statusList);
        
        return $this->view->fetch();
    }
}
