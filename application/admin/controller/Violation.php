<?php

namespace app\admin\controller;

use app\common\controller\Backend;
use app\common\model\Violation as ViolationModel;
use think\Db;

/**
 * 违规积分管理
 *
 * @icon fa fa-exclamation-triangle
 * @remark 管理违规积分记录，支持查看违规详情和申诉状态
 */
class Violation extends Backend
{
    /**
     * Violation模型对象
     * @var \app\common\model\Violation
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
        $this->model = new ViolationModel;

        $this->view->assign("typeList", $this->model->getTypeList());
        $this->view->assign("statusList", $this->model->getStatusList());
        $this->view->assign("illegalTypeList", $this->model->getIllegalTypeList());
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
            
            // 获取搜索参数 - 使用param方法获取
            $searchParams = $this->request->param();
            $advertiserId = isset($searchParams['advertiser_id']) ? trim($searchParams['advertiser_id']) : '';
            $adId = isset($searchParams['ad_id']) ? trim($searchParams['ad_id']) : '';
            $materialId = isset($searchParams['material_id']) ? trim($searchParams['material_id']) : '';

            // 使用Db类构建查询条件
            $where = function ($query) use ($advertiserId, $adId, $materialId) {
                if (!empty($advertiserId)) {
                    $query->where('advertiser_id', 'like', "%{$advertiserId}%");
                }
                if (!empty($adId)) {
                    $query->where('ad_id', 'like', "%{$adId}%");
                }
                if (!empty($materialId)) {
                    $query->where('material_id', 'like', "%{$materialId}%");
                }
            };

            // 获取排序参数
            $sort = $this->request->get('sort', 'id');
            $order = $this->request->get('order', 'desc');
            $offset = $this->request->get('offset', 0);
            $limit = $this->request->get('limit', 10);

            $list = $this->model
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            $typeList = $this->model->getTypeList();
            $statusList = $this->model->getStatusList();
            $illegalTypeList = $this->model->getIllegalTypeList();
            
            foreach ($list as $row) {
                $row->visible(['id', 'advertiser_id', 'ad_id', 'material_id', 'event_id', 'type', 'score', 'status', 'illegal_type', 'create_time', 'update_time']);
                $row->type_text = $typeList[$row->type] ?? '';
                $row->status_text = $statusList[$row->status] ?? '';
                $row->illegal_type_text = $illegalTypeList[$row->illegal_type] ?? '';
            }

            $result = array("total" => $this->model->where($where)->count(), "rows" => $list);

            return json($result);
        }
        
        // 传递权限配置到视图
        $this->assignconfig('operateView', $this->auth->check('violation/view'));
        
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
        
        $typeList = $this->model->getTypeList();
        $statusList = $this->model->getStatusList();
        $illegalTypeList = $this->model->getIllegalTypeList();
        
        $this->view->assign('row', $row);
        $this->view->assign('typeList', $typeList);
        $this->view->assign('statusList', $statusList);
        $this->view->assign('illegalTypeList', $illegalTypeList);
        
        return $this->view->fetch();
    }
}