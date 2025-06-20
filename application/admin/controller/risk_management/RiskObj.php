<?php

namespace app\admin\controller\risk_management;

use app\admin\model\MarkLog;
use app\admin\model\Tag;
use app\common\controller\Backend;
use app\common\model\AdvStats;
use app\common\model\ObjProduct;
use think\Db;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\Exception;
use think\exception\DbException;
use think\response\Json;


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

    protected $handle_status = [ '正常', '跟进中', '已删除', '已完成'];

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
                $product_ids = $risk_obj_model->where(['obj_id' => $item['obj_id'],'is_del'=>0])->limit(12)->column('product_id');
                $item['product_ids'] = implode(';', $product_ids);
                $item['status_text'] = $this->obj_status[$item['obj_status']];
                $item['sys_tag_text'] = $item['sys_tag'] ? $tag[$item['sys_tag']] : '-';
//                $item['check_staff'] = $info['check_staff'];
//                $item['business_staff'] = $info['business_staff'];
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


    public function edit($ids = null)
    {
        $model = new ObjProduct();
        $info = $model->where(['id' => $ids])->find();
        if (!$info) {
            $this->error('记录不存在');
        }
        $before = $info->toArray();
        if ($this->request->isPost()) {
            $data['id'] = input("id");
            $data['handle_status'] = input("handle_status");
            $data['remark'] = input("remark");
            if (empty($data['id'])) {
                $this->error("数据异常，请刷新后重试");
            }
            try {
                $info->save($data);
                $this->saveLog($before, $data);
            } catch (Exception $exception) {
                $this->error("保存失败:" . $exception->getMessage());
            }
            $this->success('保存成功');
        }

        $tag_list = Db::name('tag')->column('name', 'id');
        $this->assign('handle_status_list', $this->handle_status);
        $this->assign('tag_list', $tag_list);
        $this->assign('row', $info);
        return $this->view->fetch();
    }


    /**
     * 插入修改日志
     * @throws Exception
     */
    public function saveLog($info, $update_data)
    {
        if (!$info) {
            throw new Exception('源数据不存在');
        }
        $content = '';
        foreach ($update_data as $key => $item) {
            if ($info[$key] != $item) {
                $content .= $key . ": " . $info[$key] . " -> " . $item . ";";
            }
        }
        if (!$content) {
            throw new Exception('无修改数据');
        }
        $log = [
            'admin_id' => $this->auth->id,
            'operator' => $this->auth->username,
            'adv_id' => $info['adv_id'],  // $info里的如果跟表设置的字段不一致，请自行修改
            'obj_id' => isset($info['obj_id'])?$info['adv_id']:'',
            'content' => $content,
        ];
        return MarkLog::create($log);
    }

    /**
     * 获取操作日志列表
     * @param $ids
     * @return string|Json
     * @throws Exception
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws DbException
     */
    public function get_log_list($ids = null)
    {

        $model = new ObjProduct();
        $adv_id = $model->where(['id' => $ids])->value('adv_id');
        if (!$adv_id) {
            $this->error('千川记录不存在');
        }
        $log_model = new MarkLog();
        if ($this->request->isAjax()) {
            $fields_map = [
                'handle_status' => "处理状态",
                'remark' => '备注',
            ];
            $list = $log_model->where(['adv_id' => $adv_id])->order('create_time desc')->select();
            foreach ($list as &$item) {
                $content = explode(';', rtrim($item['content'], ';'));
                $result = [];
                foreach ($content as $value) {
                    if (preg_match('/([^:]+):\s*(.+)\s*->\s*(.+)/', $value, $matches)) {
                        $field = trim($matches[1]);
                        $old_value = trim($matches[2])?:"-";
                        $new_value = trim($matches[3])?:"-";

                        // 获取中文字段名
                        $chinese_field = $fields_map[$field] ?? $field;
                        // 处理 handle_status 的值映射
                        if ($field === 'handle_status') {
                            $old_value = $this->handle_status[$old_value] ?? $old_value;
                            $new_value = $this->handle_status[$new_value] ?? $new_value;
                        }
                        // 拼接结果
                        $result[] = $chinese_field."：".$old_value." 修改为：".$new_value;
                    }
                }
                $item['contents'] = implode(';',$result);
            }
            $count = $log_model->where(['adv_id' => $adv_id])->order('create_time desc')->count();
            $result = array("total" => $count, "rows" => $list);
            return json($result);
        }
        $this->assign('ids', $ids);
        return $this->view->fetch();
    }


}