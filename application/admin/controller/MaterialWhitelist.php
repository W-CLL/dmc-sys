<?php

namespace app\admin\controller;

use app\common\controller\Backend;
use app\common\model\MaterialWhitelist as MaterialWhitelistModel;
use think\Db;
use think\Exception;

/**
 * 素材追投白名单管理
 *
 * @icon fa fa-shield
 * @remark 管理不进行素材追投操作的公司白名单，支持批量添加、删除和状态管理
 */
class MaterialWhitelist extends Backend
{
    /**
     * MaterialWhitelist模型对象
     * @var \app\common\model\MaterialWhitelist
     */
    protected $model = null;

    /**
     * 数据限制字段
     */
    protected $dataLimitField = 'id';

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new MaterialWhitelistModel;

        $this->view->assign("statusList", [
            0 => '禁用',
            1 => '启用'
        ]);
    }

    /**
     * 默认生成的控制器所继承的父类中有index/add/edit/del/multi五个基础方法、destroy/restore/recyclebin三个回收站方法
     * 因此在当前控制器中可不用编写增删改查的代码,除非需要自己控制这部分逻辑
     * 需要将application/admin/library/traits/Backend.php中对应的方法复制到当前控制器,然后进行修改
     */

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

            foreach ($list as $row) {
                $row->visible(['id', 'company_name', 'status', 'remark', 'create_time', 'update_time']);
                $row->visible(['status_text']);
                $row->getRelation('status_text');
            }

            $result = array("total" => $this->model->where($where)->count(), "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }

    /**
     * 批量添加白名单公司
     */
    public function batch_add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post("row/a");
            if (empty($params)) {
                $this->error(__('Parameter %s can not be empty', ''));
            }

            $companies = $params['companies'] ?? '';
            $remark = $params['remark'] ?? '';

            if (empty($companies)) {
                $this->error('请输入公司名称');
            }

            // 处理公司名称列表
            $companyList = [];
            $lines = explode("\n", $companies);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line)) {
                    // 支持逗号分隔
                    $items = explode(',', $line);
                    foreach ($items as $item) {
                        $item = trim($item);
                        if (!empty($item)) {
                            $companyList[] = $item;
                        }
                    }
                }
            }

            if (empty($companyList)) {
                $this->error('没有有效的公司名称');
            }

            // 去重
            $companyList = array_unique($companyList);

            Db::startTrans();
            try {
                $result = MaterialWhitelistModel::batchAdd($companyList, $remark);
                if ($result) {
                    Db::commit();
                    $this->success('批量添加成功，共添加 ' . count($companyList) . ' 个公司');
                } else {
                    Db::rollback();
                    $this->error('批量添加失败');
                }
            } catch (Exception $e) {
                Db::rollback();
                $this->error('批量添加失败：' . $e->getMessage());
            }
        }
        return $this->view->fetch();
    }

    /**
     * 批量删除
     */
    public function batch_delete()
    {
        if ($this->request->isPost()) {
            $ids = $this->request->post("ids");
            if (empty($ids)) {
                $this->error(__('Parameter %s can not be empty', 'ids'));
            }
            $pk = $this->model->getPk();
            $adminIds = $this->getDataLimitAdminIds();
            if (is_array($adminIds)) {
                $this->model->where($this->dataLimitField, 'in', $adminIds);
            }
            $list = $this->model->where($pk, 'in', $ids)->select();

            $count = 0;
            Db::startTrans();
            try {
                foreach ($list as $k => $v) {
                    $count += $v->delete();
                }
                Db::commit();
            } catch (Exception $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }
            if ($count) {
                $this->success();
            } else {
                $this->error(__('No rows were deleted'));
            }
        }
        $this->error(__('Parameter %s can not be empty', ''));
    }

    /**
     * 批量启用/禁用
     */
    public function multi($ids = null)
    {
        if (false === $this->request->isPost()) {
            $this->error(__('Invalid parameters'));
        }
        $ids = $ids ?: $this->request->post('ids');
        if (empty($ids)) {
            $this->error(__('Parameter %s can not be empty', 'ids'));
        }

        if (false === $this->request->has('params')) {
            $this->error(__('No rows were updated'));
        }

        // 解析params参数
        parse_str($this->request->post('params'), $values);
        if (empty($values)) {
            $this->error(__('You have no permission'));
        }

        // 确保ids是数组
        if (!is_array($ids)) {
            $ids = explode(',', $ids);
        }

        $count = 0;
        Db::startTrans();
        try {
            // 根据params中的action参数进行操作
            if (isset($values['action'])) {
                switch ($values['action']) {
                    case 'enable':
                        $count = $this->model->where('id', 'in', $ids)->update([
                            'status' => 1,
                            'update_time' => time()
                        ]);
                        break;
                    case 'disable':
                        $count = $this->model->where('id', 'in', $ids)->update([
                            'status' => 0,
                            'update_time' => time()
                        ]);
                        break;
                    default:
                        $this->error('未知操作：' . $values['action']);
                }
            } else {
                // 如果没有action，尝试直接更新字段
                $count = $this->model->where('id', 'in', $ids)->update($values);
            }
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            $this->error('操作失败：' . $e->getMessage());
        }

        if ($count) {
            $this->success();
        } else {
            $this->error(__('No rows were updated'));
        }
    }
}
