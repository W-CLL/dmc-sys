<?php

namespace app\admin\controller;

use app\common\controller\Backend;
use app\common\model\MaterialControlTaskRecord as TaskRecordModel;
use think\Db;
use think\Exception;

/**
 * 素材调控任务记录管理
 */
class MaterialControlTaskRecord extends Backend
{
    protected $model = null;
    protected $noNeedLogin = [];
    protected $noNeedRight = [];

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new TaskRecordModel();
    }

    /**
     * 查看任务记录列表
     */
    public function index()
    {
        if ($this->request->isAjax()) {
            // 处理DataTable请求
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            
            $list = $this->model
                ->where($where)
                ->order($sort, $order)
                ->paginate($limit);

            $result = [
                'total' => $list->total(),
                'rows' => []
            ];

            foreach ($list as $row) {
                $row['status_text'] = TaskRecordModel::getStatusText($row['status']);
                $row['start_time_text'] = $row['start_time'] ? date('Y-m-d H:i:s', $row['start_time']) : '';
                $row['task_create_time_text'] = $row['task_create_time'] ? date('Y-m-d H:i:s', $row['task_create_time']) : '';
                $row['stop_time_text'] = $row['stop_time'] ? date('Y-m-d H:i:s', $row['stop_time']) : '';
                $row['duration_text'] = $row['total_duration'] ? $row['total_duration'] . '秒' : '';
                
                $result['rows'][] = $row;
            }

            return json($result);
        }

        return $this->view->fetch();
    }

    /**
     * 查看任务详情
     */
    public function detail()
    {
        $id = $this->request->param('id');
        if (!$id) {
            $this->error('参数错误');
        }

        $record = $this->model->get($id);
        if (!$record) {
            $this->error('记录不存在');
        }

        // 格式化数据
        $record['status_text'] = TaskRecordModel::getStatusText($record['status']);
        $record['start_time_text'] = $record['start_time'] ? date('Y-m-d H:i:s', $record['start_time']) : '';
        $record['task_create_time_text'] = $record['task_create_time'] ? date('Y-m-d H:i:s', $record['task_create_time']) : '';
        $record['stop_time_text'] = $record['stop_time'] ? date('Y-m-d H:i:s', $record['stop_time']) : '';
        
        // 解析JSON数据
        if ($record['create_result']) {
            $record['create_result_formatted'] = json_decode($record['create_result'], true);
        }
        if ($record['stop_result']) {
            $record['stop_result_formatted'] = json_decode($record['stop_result'], true);
        }

        $this->assign('record', $record);
        return $this->view->fetch();
    }

    /**
     * 获取统计数据
     */
    public function stats()
    {
        $advId = $this->request->param('adv_id');
        $startDate = $this->request->param('start_date');
        $endDate = $this->request->param('end_date');

        $stats = TaskRecordModel::getTaskStats($advId, $startDate, $endDate);
        
        $result = [
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'running' => 0,
            'avg_duration' => 0
        ];

        foreach ($stats as $stat) {
            $result['total'] += $stat['count'];
            
            switch ($stat['status']) {
                case TaskRecordModel::STATUS_COMPLETE_SUCCESS:
                    $result['success'] += $stat['count'];
                    break;
                case TaskRecordModel::STATUS_CREATE_FAILED:
                case TaskRecordModel::STATUS_STOP_FAILED:
                    $result['failed'] += $stat['count'];
                    break;
                case TaskRecordModel::STATUS_STARTED:
                case TaskRecordModel::STATUS_CREATE_SUCCESS:
                    $result['running'] += $stat['count'];
                    break;
            }
            
            if ($stat['avg_duration']) {
                $result['avg_duration'] = max($result['avg_duration'], $stat['avg_duration']);
            }
        }

        return json($result);
    }

    /**
     * 获取失败任务列表
     */
    public function failed()
    {
        $limit = $this->request->param('limit', 50);
        $failedTasks = TaskRecordModel::getFailedTasks($limit);
        
        $result = [];
        foreach ($failedTasks as $task) {
            $task['status_text'] = TaskRecordModel::getStatusText($task['status']);
            $task['start_time_text'] = $task['start_time'] ? date('Y-m-d H:i:s', $task['start_time']) : '';
            $result[] = $task;
        }

        return json($result);
    }

    /**
     * 重试失败的任务
     */
    public function retry()
    {
        $id = $this->request->param('id');
        if (!$id) {
            $this->error('参数错误');
        }

        $record = $this->model->get($id);
        if (!$record) {
            $this->error('记录不存在');
        }

        // 只有失败的任务才能重试
        if (!in_array($record['status'], [TaskRecordModel::STATUS_CREATE_FAILED, TaskRecordModel::STATUS_STOP_FAILED])) {
            $this->error('该任务状态不允许重试');
        }

        try {
            // 重新创建队列任务
            $queueModel = new \app\common\model\Queue();
            $jobData = [
                'adv_id' => $record['adv_id'],
                'obj_id' => $record['obj_id'] . '|' . $record['material_id'],
                'queue_job_id' => $record['queue_job_id']
            ];
            
            $result = $queueModel->addQueue(
                '素材调控任务重试',
                'app\job\AutoUpdateGlobalObjMaterial',
                'material_control',
                $jobData,
                'fa_material_control_task_record',
                "重试任务ID: {$id}"
            );

            if ($result) {
                $this->success('重试任务已添加到队列');
            } else {
                $this->error('添加重试任务失败');
            }

        } catch (Exception $e) {
            $this->error('重试失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除记录
     */
    public function delete()
    {
        $ids = $this->request->param('ids');
        if (!$ids) {
            $this->error('参数错误');
        }

        $ids = is_array($ids) ? $ids : explode(',', $ids);
        
        try {
            $this->model->where('id', 'in', $ids)->delete();
            $this->success('删除成功');
        } catch (Exception $e) {
            $this->error('删除失败: ' . $e->getMessage());
        }
    }
}
