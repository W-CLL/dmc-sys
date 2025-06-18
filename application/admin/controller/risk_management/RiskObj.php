<?php

namespace app\admin\controller\risk_management;

use app\admin\model\Tag;
use app\common\controller\Backend;
use app\common\model\ObjProduct;
use think\Db;


class RiskObj extends Backend
{

    protected $obj_status = [
        '0'=>'请选择',
        'DELETE' => '已删除',
        'AUDIT' => '新建审核中',
        'TIME_DONE' => '已完成',
        'DISABLE' => '已暂停',
        'TIME_NO_REACH' => '未到达投放时间',
        'OFFLINE_BALANCE' => '账户余额不足',
        'OFFLINE_BUDGET' => '广告预算不足',
        'DELIVERY_OK' => '投放中',
        'NO_SCHEDULE' => '不在投放时段',
        'REAUDIT' => '修改审核中',
        'OFFLINE_AUDIT' => '审核不通过',
        'EXTERNAL_URL_DISABLE' => '落地页暂不可用',
        'LIVE_ROOM_OFF' => '关联直播间未开播',
        'FROZEN' => '已终止',
        'SYSTEM_DISABLE' => '系统暂停',
        'ALL_INCLUDE_DELETED' => '全部（包含已删除）',
        'QUOTA_DISABLE' => '在投计划配额超限',
        'ROI2_DISABLE' => '全域推广暂停',
    ];

    protected $handle_status = ['-1' => '无', '正常', '跟进中', '已注销', '已处理'];

    private function _filter(&$where)
    {
        $params = json_decode(input('filter'), true);

        if (!empty($params['keyword'])) {
            $where['ro.name'] = ['like',"%".$params['keyword']."%"];
        }
        if(!isset($params['sys_tag'])){
            $where['ro.sys_tag'] = ['>',0];
        }elseif(strlen($params['sys_tag'])>0){
            $where['ro.sys_tag'] = $params['sys_tag'];
        }
        if(!empty($params['obj_status'])){
            $where['qo.obj_status'] = $params['obj_status'];
        }

    }

    public function index($ids = null, $adv_id = '')
    {
        $risk_obj_model = new ObjProduct();
        $tag_model = new Tag();
        $tag = $tag_model->column('name', 'id');
        $info = Db::name('risk_adv')->where(['id' => $ids])->find();
        if (!$info && !$adv_id) {
            $this->error('账户不存在');
        }
        if ($this->request->isAjax()) {
            $where = [];
            $offset = input("offset", 0);
            $limit = input("limit", 10);
            $this->_filter($where);

            $list = $risk_obj_model->alias('ro')
                ->join('qc_global_obj qo', 'ro.obj_id=qo.obj_id', 'left')
                ->field('ro.*,qo.obj_status,qo.name,qo.obj_create_time')
                ->where(['ro.adv_id' => $adv_id])
                ->where($where)
                ->group('ro.obj_id')
//                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();


            foreach ($list as &$item) {
                $product_ids = $risk_obj_model->where(['obj_id' => $item['obj_id']])->limit(12)->column('product_id');
                $item['product_ids'] = implode(';', $product_ids);
                $item['status_text'] = $this->obj_status[$item['obj_status']];
                $item['sys_tag_text'] = $item['sys_tag'] ? $tag[$item['sys_tag']] : '-';
            }
            // 查询总数
            $countQuery = $risk_obj_model->alias('ro')
                ->join('qc_global_obj qo', 'ro.obj_id=qo.obj_id', 'left')
                ->where(['ro.adv_id' => $adv_id])
                ->where($where)
                ->group('ro.obj_id')->count();
            $result = array("total" => $countQuery, "rows" => $list);
            return json($result);
        }
        $this->assign('handle_status_list', $this->handle_status);
        $this->assign('tag_list', $tag);
        $this->assign('obj_status', $this->obj_status);
        $this->assign('adv_id', $info['adv_id']);
        $this->assign('company_name', Db::name('company')->where(['advertiser_id'=>$info['adv_id']])->value('company_name'));
        return $this->view->fetch();
    }
}