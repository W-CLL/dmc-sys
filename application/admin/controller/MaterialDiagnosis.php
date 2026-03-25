<?php

namespace app\admin\controller;

use app\common\controller\Backend;
use app\common\model\MaterialDiagnosis as MaterialDiagnosisModel;
use think\Db;

/**
 * 素材诊断管理
 *
 * @icon fa fa-file-video-o
 * @remark 管理素材诊断记录
 */
class MaterialDiagnosis extends Backend
{
    /**
     * MaterialDiagnosis模型对象
     * @var \app\common\model\MaterialDiagnosis
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
        $this->model = new MaterialDiagnosisModel;

        $this->view->assign("statusList", $this->model->getStatusList());
        $this->view->assign("isGetList", $this->model->getIsGetList());
        $this->view->assign("isEcpHighQualityList", $this->model->getIsEcpHighQualityList());
        $this->view->assign("isInefficientList", $this->model->getIsInefficientList());
        $this->view->assign("isFirstPublishList", $this->model->getIsFirstPublishList());
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
            
            // 获取搜索参数
            $searchParams = $this->request->param();
            $materialId = isset($searchParams['material_id']) ? trim($searchParams['material_id']) : '';
            $isFirstPublish = isset($searchParams['is_first_publish_material']) ? $searchParams['is_first_publish_material'] : '';
            $isEcpHighQuality = isset($searchParams['is_ecp_high_quality']) ? $searchParams['is_ecp_high_quality'] : '';
            $isInefficient = isset($searchParams['is_inefficient']) ? $searchParams['is_inefficient'] : '';

            // 构建查询条件
            $where = [];
            if (!empty($materialId)) {
                $where[] = ['material_id', 'like', "%{$materialId}%"];
            }
            if ($isFirstPublish !== '') {
                $where[] = ['is_first_publish_material', '=', $isFirstPublish];
            }
            if ($isEcpHighQuality !== '') {
                $where[] = ['is_ecp_high_quality_material', '=', $isEcpHighQuality];
            }
            if ($isInefficient !== '') {
                $where[] = ['is_inefficient_material', '=', $isInefficient];
            }

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

            $statusList = $this->model->getStatusList();
            $isGetList = $this->model->getIsGetList();
            $isEcpHighQualityList = $this->model->getIsEcpHighQualityList();
            $isInefficientList = $this->model->getIsInefficientList();
            $isFirstPublishList = $this->model->getIsFirstPublishList();
            
            // 获取所有素材ID对应的广告主信息
            $listData = is_array($list) ? $list : $list->toArray();
            $materialIds = array_column($listData, 'material_id');
            $advertiserMap = [];
            if (!empty($materialIds)) {
                $prequalList = Db::name('material_prequalification')
                    ->whereIn('material_id', $materialIds)
                    ->field('material_id, advertiser_id')
                    ->select();
                if ($prequalList && is_array($prequalList)) {
                    foreach ($prequalList as $item) {
                        if (!isset($advertiserMap[$item['material_id']])) {
                            $advertiserMap[$item['material_id']] = [];
                        }
                        if (!in_array($item['advertiser_id'], $advertiserMap[$item['material_id']])) {
                            $advertiserMap[$item['material_id']][] = $item['advertiser_id'];
                        }
                    }
                }
            }
            
            // 直接修改数组数据而不是模型
            $rows = [];
            foreach ($listData as $row) {
                $advertisers = isset($advertiserMap[$row['material_id']]) ? $advertiserMap[$row['material_id']] : [];
                $row['advertiser_count'] = count($advertisers);
                $row['advertisers'] = !empty($advertisers) ? implode('|', $advertisers) : '-';
                $row['status_text'] = isset($statusList[$row['status']]) ? $statusList[$row['status']] : '';
                $rows[] = $row;
            }

            $result = array("total" => $this->model->where($where)->count(), "rows" => $rows);

            return json($result);
        }
        
        // 传递权限配置到视图
        $this->assignconfig('operateView', $this->auth->check('material_diagnosis/view'));
        
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
        
        // 获取使用该素材的广告主列表
        $advertiserList = Db::name('material_prequalification')
            ->where('material_id', $row['material_id'])
            ->field('material_id, advertiser_id, status, object_id, video_id, filename')
            ->select();
        
        $statusList = $this->model->getStatusList();
        $isGetList = $this->model->getIsGetList();
        $isEcpHighQualityList = $this->model->getIsEcpHighQualityList();
        $isInefficientList = $this->model->getIsInefficientList();
        $isFirstPublishList = $this->model->getIsFirstPublishList();
        
        $this->view->assign('row', $row);
        $this->view->assign('statusList', $statusList);
        $this->view->assign('isGetList', $isGetList);
        $this->view->assign('isEcpHighQualityList', $isEcpHighQualityList);
        $this->view->assign('isInefficientList', $isInefficientList);
        $this->view->assign('isFirstPublishList', $isFirstPublishList);
        $this->view->assign('advertiserList', $advertiserList);
        
        return $this->view->fetch();
    }
}