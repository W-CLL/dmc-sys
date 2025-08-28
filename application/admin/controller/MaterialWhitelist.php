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

        $this->view->assign("filterTypeList", [
            1 => '公司级别',
            2 => '广告主级别'
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
                $row->visible(['id', 'filter_type', 'company_name', 'adv_id', 'status', 'remark', 'create_time', 'update_time']);
                $row->visible(['filter_type_text', 'status_text']);
                $row->getRelation('filter_type_text');
                $row->getRelation('status_text');
            }

            $result = array("total" => $this->model->where($where)->count(), "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }

    /**
     * 添加
     */
    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post("row/a");
            if (empty($params)) {
                $this->error(__('Parameter %s can not be empty', ''));
            }

            $filterType = $params['filter_type'] ?? 1;
            $companyName = $params['company_name'] ?? '';
            $advId = $params['adv_id'] ?? '';
            $status = $params['status'] ?? 1;
            $remark = $params['remark'] ?? '';

            // 验证必填字段
            if ($filterType == 1) {
                // 公司级别
                if (empty($companyName)) {
                    $this->error('公司级别白名单必须填写公司名称');
                }
                $advId = null; // 清空广告主ID
            } else {
                // 广告主级别
                if (empty($advId)) {
                    $this->error('广告主级别白名单必须填写广告主ID');
                }
                $companyName = null; // 清空公司名称

                // 检查广告主是否可以添加
                $checkResult = $this->checkAdvCanAddInternal($advId);
                if (!$checkResult['can_add']) {
                    $this->error($checkResult['message']);
                }
            }

            // 检查重复
            $existing = $this->model->where([
                'filter_type' => $filterType,
                'company_name' => $companyName,
                'adv_id' => $advId
            ])->find();

            if ($existing) {
                $this->error('该记录已存在');
            }

            $data = [
                'filter_type' => $filterType,
                'company_name' => $companyName,
                'adv_id' => $advId,
                'status' => $status,
                'remark' => $remark,
                'create_time' => time(),
                'update_time' => time()
            ];

            $result = $this->model->save($data);
            if ($result !== false) {
                $this->success();
            } else {
                $this->error(__('No rows were inserted'));
            }
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
        $adminIds = $this->getDataLimitAdminIds();
        if (is_array($adminIds) && !in_array($row[$this->dataLimitField], $adminIds)) {
            $this->error(__('You have no permission'));
        }
        if ($this->request->isPost()) {
            $params = $this->request->post("row/a");
            if (empty($params)) {
                $this->error(__('Parameter %s can not be empty', ''));
            }

            $filterType = $params['filter_type'] ?? 1;
            $companyName = $params['company_name'] ?? '';
            $advId = $params['adv_id'] ?? '';
            $status = $params['status'] ?? 1;
            $remark = $params['remark'] ?? '';

            // 验证必填字段
            if ($filterType == 1) {
                // 公司级别
                if (empty($companyName)) {
                    $this->error('公司级别白名单必须填写公司名称');
                }
                $advId = null; // 清空广告主ID
            } else {
                // 广告主级别
                if (empty($advId)) {
                    $this->error('广告主级别白名单必须填写广告主ID');
                }
                $companyName = null; // 清空公司名称
            }

            // 检查重复（排除当前记录）
            $existing = $this->model->where([
                'filter_type' => $filterType,
                'company_name' => $companyName,
                'adv_id' => $advId,
                'id' => ['neq', $ids]
            ])->find();

            if ($existing) {
                $this->error('该记录已存在');
            }

            $data = [
                'filter_type' => $filterType,
                'company_name' => $companyName,
                'adv_id' => $advId,
                'status' => $status,
                'remark' => $remark,
                'update_time' => time()
            ];

            $result = $row->save($data);
            if ($result !== false) {
                $this->success();
            } else {
                $this->error(__('No rows were updated'));
            }
        }
        $this->view->assign("row", $row);
        return $this->view->fetch();
    }

    /**
     * 批量添加白名单
     */
    public function batch_add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post("row/a");
            if (empty($params)) {
                $this->error(__('Parameter %s can not be empty', ''));
            }

            $batchType = $params['batch_type'] ?? 1;
            $remark = $params['remark'] ?? '';

            if ($batchType == 1) {
                // 公司级别批量添加
                $this->batchAddCompanies($params, $remark);
            } else {
                // 广告主级别批量添加
                $this->batchAddAdvs($params, $remark);
            }
        }
        return $this->view->fetch();
    }

    /**
     * 批量添加公司级别白名单
     */
    private function batchAddCompanies($params, $remark)
    {
        $companies = $params['companies'] ?? '';

        if (empty($companies)) {
            $this->error('请输入公司名称');
        }

        // 处理公司名称列表
        $companyList = $this->parseTextList($companies);

        if (empty($companyList)) {
            $this->error('没有有效的公司名称');
        }

        Db::startTrans();
        try {
            $successCount = 0;
            $skipCount = 0;
            $errorList = [];

            foreach ($companyList as $companyName) {
                // 检查是否已存在
                $existing = $this->model->where([
                    'filter_type' => 1,
                    'company_name' => $companyName
                ])->find();

                if ($existing) {
                    $skipCount++;
                    continue;
                }

                // 添加记录
                $data = [
                    'filter_type' => 1,
                    'company_name' => $companyName,
                    'adv_id' => null,
                    'status' => 1,
                    'remark' => $remark ?: "批量添加-公司级别",
                    'create_time' => time(),
                    'update_time' => time()
                ];

                $result = $this->model->save($data);
                if ($result) {
                    $successCount++;
                    $this->model = new MaterialWhitelistModel; // 重新实例化
                } else {
                    $errorList[] = $companyName;
                }
            }

            Db::commit();

            $message = "批量添加完成！成功: {$successCount}，跳过: {$skipCount}";
            if (!empty($errorList)) {
                $message .= "，失败: " . count($errorList);
            }

            $this->success($message);

        } catch (Exception $e) {
            Db::rollback();
            $this->error('批量添加失败：' . $e->getMessage());
        }
    }

    /**
     * 批量添加广告主级别白名单
     */
    private function batchAddAdvs($params, $remark)
    {
        $advIds = $params['adv_ids'] ?? '';

        if (empty($advIds)) {
            $this->error('请输入广告主ID');
        }

        // 处理广告主ID列表
        $advIdList = $this->parseTextList($advIds);

        if (empty($advIdList)) {
            $this->error('没有有效的广告主ID');
        }

        Db::startTrans();
        try {
            $successCount = 0;
            $skipCount = 0;
            $errorList = [];

            foreach ($advIdList as $advId) {
                // 检查是否已存在
                $existing = $this->model->where([
                    'filter_type' => 2,
                    'adv_id' => $advId
                ])->find();

                if ($existing) {
                    $skipCount++;
                    continue;
                }

                // 检查是否可以添加（公司是否已在白名单中）
                $checkResult = $this->checkAdvCanAddInternal($advId);
                if (!$checkResult['can_add']) {
                    $errorList[] = $advId . ' (' . $checkResult['message'] . ')';
                    continue;
                }

                // 添加记录
                $data = [
                    'filter_type' => 2,
                    'company_name' => null,
                    'adv_id' => $advId,
                    'status' => 1,
                    'remark' => $remark ?: "批量添加-广告主级别",
                    'create_time' => time(),
                    'update_time' => time()
                ];

                $result = $this->model->save($data);
                if ($result) {
                    $successCount++;
                    $this->model = new MaterialWhitelistModel; // 重新实例化
                } else {
                    $errorList[] = $advId . ' (保存失败)';
                }
            }

            Db::commit();

            $message = "批量添加完成！成功: {$successCount}，跳过: {$skipCount}";
            if (!empty($errorList)) {
                $message .= "，失败: " . count($errorList) . " (" . implode(', ', array_slice($errorList, 0, 3));
                if (count($errorList) > 3) {
                    $message .= "...";
                }
                $message .= ")";
            }

            $this->success($message);

        } catch (Exception $e) {
            Db::rollback();
            $this->error('批量添加失败：' . $e->getMessage());
        }
    }

    /**
     * 解析文本列表（支持换行和逗号分隔）
     */
    private function parseTextList($text)
    {
        $list = [];
        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                // 支持逗号分隔
                $items = explode(',', $line);
                foreach ($items as $item) {
                    $item = trim($item);
                    if (!empty($item)) {
                        $list[] = $item;
                    }
                }
            }
        }

        // 去重
        return array_unique($list);
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

    /**
     * 检查广告主是否可以添加到白名单（AJAX接口）
     */
    public function checkAdvCanAdd()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }

        $advId = $this->request->post('adv_id', '');
        if (empty($advId)) {
            $this->error('广告主ID不能为空');
        }

        $result = $this->checkAdvCanAddInternal($advId);

        if ($result['can_add']) {
            $this->success($result['message'], null, $result);
        } else {
            // 使用code=2表示不建议添加，但不是错误
            return json([
                'code' => 2,
                'msg' => $result['message'],
                'data' => $result,
                'url' => '',
                'wait' => 3
            ]);
        }
    }

    /**
     * 内部检查方法，重命名避免冲突
     */
    private function checkAdvCanAddInternal($advId)
    {
        try {
            // 通过fa_company表获取广告主信息
            $companyInfo = Db::name('company')
                ->where('advertiser_id', $advId)
                ->field('advertiser_id, company_name, name')
                ->find();

            if (!$companyInfo) {
                return [
                    'can_add' => false,
                    'message' => "找不到广告主ID「{$advId}」的信息，请确认广告主ID是否正确",
                    'adv_id' => $advId,
                    'reason' => 'adv_not_found'
                ];
            }

            $companyName = $companyInfo['company_name'];
            $advName = $companyInfo['name'] ?? '';

            // 检查该公司是否已经在公司级别白名单中
            $companyInWhitelist = $this->model->where([
                'filter_type' => 1,
                'company_name' => $companyName,
                'status' => 1
            ])->count() > 0;

            if ($companyInWhitelist) {
                return [
                    'can_add' => false,
                    'message' => "该广告主所属公司「{$companyName}」已在公司级别白名单中，无需重复添加",
                    'company_name' => $companyName,
                    'adv_name' => $advName,
                    'adv_id' => $advId,
                    'reason' => 'company_already_whitelisted'
                ];
            }

            // 检查该广告主是否已经在广告主级别白名单中
            $advInWhitelist = $this->model->where([
                'filter_type' => 2,
                'adv_id' => $advId,
                'status' => 1
            ])->count() > 0;

            if ($advInWhitelist) {
                return [
                    'can_add' => false,
                    'message' => "该广告主已在广告主级别白名单中",
                    'company_name' => $companyName,
                    'adv_name' => $advName,
                    'adv_id' => $advId,
                    'reason' => 'adv_already_whitelisted'
                ];
            }

            return [
                'can_add' => true,
                'message' => '可以添加到白名单',
                'company_name' => $companyName,
                'adv_name' => $advName,
                'adv_id' => $advId
            ];
        } catch (\Exception $e) {
            return [
                'can_add' => false,
                'message' => '检查失败：' . $e->getMessage(),
                'adv_id' => $advId,
                'reason' => 'check_error'
            ];
        }
    }
}
