<?php

namespace app\admin\controller;

use app\common\controller\Backend;
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
     * 无需鉴权的接口
     */
    protected $noNeedRight = ['view'];

    public function _initialize()
    {
        parent::_initialize();
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
            
            // 获取搜索参数
            $materialId = $this->request->get('material_id', '');
            $advertiserId = $this->request->get('advertiser_id', '');
            $isFirstPublishMaterial = $this->request->get('is_first_publish_material', '');
            $isEcpHighQuality = $this->request->get('is_ecp_high_quality', '');
            $isInefficient = $this->request->get('is_inefficient', '');

            // 广告主ID筛选 - 先获取匹配的素材ID列表
            $materialIdsByAdvertiser = [];
            if (!empty($advertiserId)) {
                $materialIdsByAdvertiser = Db::name('material_prequalification')
                    ->where('advertiser_id', $advertiserId)
                    ->column('material_id');
            }

            // 构建查询条件 - 使用数组方式
            $where = [];
            // 素材ID筛选
            if (!empty($materialId)) {
                $where['material_id'] = ['like', "%{$materialId}%"];
            }
            // 广告主ID筛选 - 与素材ID筛选合并
            if (!empty($materialIdsByAdvertiser)) {
                if (isset($where['material_id'])) {
                    // 两者都存在，计算交集
                    $materialIdsLike = Db::name('material_diagnosis')->where('material_id', 'like', "%{$materialId}%")->column('material_id');
                    $intersection = array_intersect($materialIdsLike, $materialIdsByAdvertiser);
                    if (!empty($intersection)) {
                        $where['material_id'] = ['in', array_values($intersection)];
                    } else {
                        $where['material_id'] = ['in', [-1]];
                    }
                } else {
                    // 只广告主ID筛选
                    $where['material_id'] = ['in', $materialIdsByAdvertiser];
                }
            }
            // 首发素材筛选 (is_first_publish_material = 1)
            if ($isFirstPublishMaterial !== '') {
                $where['is_first_publish_material'] = $isFirstPublishMaterial;
            }
            // 优质素材筛选 (is_ecp_high_quality_material = 1)
            if ($isEcpHighQuality !== '') {
                $where['is_ecp_high_quality_material'] = $isEcpHighQuality;
            }
            // 低效素材筛选
            if ($isInefficient !== '') {
                $where['is_inefficient_material'] = $isInefficient;
            }

            // 获取排序参数
            $sort = $this->request->get('sort', 'id');
            $order = $this->request->get('order', 'desc');
            $offset = $this->request->get('offset', 0);
            $limit = $this->request->get('limit', 10);

            // 直接使用Db查询
            $list = Db::name('material_diagnosis')
                ->where($where)
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            $statusList = [0 => 'PENDING', 1 => 'SUCCESS', 2 => 'FAILED'];
            $isGetList = [0 => '未获取详情', 1 => '已获取详情'];
            $isEcpHighQualityList = [0 => 'UNKNOWN', 1 => 'YES', 2 => 'NO'];
            $isInefficientList = [0 => 'UNKNOWN', 1 => 'YES', 2 => 'NO'];
            $isFirstPublishList = [0 => 'UNKNOWN', 1 => 'YES', 2 => 'NO'];
            
            // 获取所有素材ID对应的广告主信息
            $materialIds = array_column($list, 'material_id');
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
            
            // 处理列表数据
            $rows = [];
            foreach ($list as $row) {
                $advertisers = isset($advertiserMap[$row['material_id']]) ? $advertiserMap[$row['material_id']] : [];
                $row['advertiser_count'] = count($advertisers);
                $row['advertisers'] = !empty($advertisers) ? implode('|', $advertisers) : '-';
                $row['status_text'] = isset($statusList[$row['status']]) ? $statusList[$row['status']] : '';
                $rows[] = $row;
            }

            // 获取总数
            $total = Db::name('material_diagnosis')
                ->where($where)
                ->count();

            $result = array("total" => $total, "rows" => $rows);

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
        $row = Db::name('material_diagnosis')->find($id);
        
        if (!$row) {
            $this->error('记录不存在');
        }
        
        // 获取使用该素材的广告主列表
        $advertiserList = Db::name('material_prequalification')
            ->where('material_id', $row['material_id'])
            ->field('material_id, advertiser_id, status, object_id, video_id, filename')
            ->select();
        
        $statusList = [0 => 'PENDING', 1 => 'SUCCESS', 2 => 'FAILED'];
        $isGetList = [0 => '未获取详情', 1 => '已获取详情'];
        $isEcpHighQualityList = [0 => 'UNKNOWN', 1 => 'YES', 2 => 'NO'];
        $isInefficientList = [0 => 'UNKNOWN', 1 => 'YES', 2 => 'NO'];
        $isFirstPublishList = [0 => 'UNKNOWN', 1 => 'YES', 2 => 'NO'];
        
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