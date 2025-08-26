<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\model\MaterialControlTaskRecord;
use think\Exception;

/**
 * 素材调控任务记录API
 */
class MaterialControlTaskApi extends Api
{
    protected $noNeedLogin = ['*'];
    protected $noNeedRight = ['*'];

    /**
     * 获取任务统计信息
     */
    public function getStats()
    {
        try {
            $advId = $this->request->param('adv_id');
            $startDate = $this->request->param('start_date');
            $endDate = $this->request->param('end_date');

            $stats = MaterialControlTaskRecord::getTaskStats($advId, $startDate, $endDate);
            
            $result = [
                'total' => 0,
                'success' => 0,
                'failed' => 0,
                'running' => 0,
                'success_rate' => 0,
                'avg_duration' => 0,
                'details' => []
            ];

            foreach ($stats as $stat) {
                $result['total'] += $stat['count'];
                $statusText = MaterialControlTaskRecord::getStatusText($stat['status']);
                
                $result['details'][] = [
                    'status' => $stat['status'],
                    'status_text' => $statusText,
                    'count' => $stat['count'],
                    'avg_duration' => round($stat['avg_duration'], 2)
                ];
                
                switch ($stat['status']) {
                    case MaterialControlTaskRecord::STATUS_COMPLETE_SUCCESS:
                        $result['success'] += $stat['count'];
                        break;
                    case MaterialControlTaskRecord::STATUS_CREATE_FAILED:
                    case MaterialControlTaskRecord::STATUS_STOP_FAILED:
                        $result['failed'] += $stat['count'];
                        break;
                    case MaterialControlTaskRecord::STATUS_STARTED:
                    case MaterialControlTaskRecord::STATUS_CREATE_SUCCESS:
                        $result['running'] += $stat['count'];
                        break;
                }
                
                if ($stat['avg_duration']) {
                    $result['avg_duration'] = max($result['avg_duration'], $stat['avg_duration']);
                }
            }

            // 计算成功率
            if ($result['total'] > 0) {
                $result['success_rate'] = round(($result['success'] / $result['total']) * 100, 2);
            }
            
            $result['avg_duration'] = round($result['avg_duration'], 2);

            $this->success('获取统计信息成功', $result);

        } catch (Exception $e) {
            $this->error('获取统计信息失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取任务记录列表
     */
    public function getRecords()
    {
        try {
            $page = $this->request->param('page', 1);
            $limit = $this->request->param('limit', 20);
            $advId = $this->request->param('adv_id');
            $status = $this->request->param('status');
            $objId = $this->request->param('obj_id');
            $materialId = $this->request->param('material_id');

            $query = MaterialControlTaskRecord::order('create_time desc');

            if ($advId) {
                $query->where('adv_id', $advId);
            }
            if ($status !== null && $status !== '') {
                $query->where('status', $status);
            }
            if ($objId) {
                $query->where('obj_id', $objId);
            }
            if ($materialId) {
                $query->where('material_id', $materialId);
            }

            $list = $query->paginate($limit, false, ['page' => $page]);
            
            $result = [
                'total' => $list->total(),
                'page' => $page,
                'limit' => $limit,
                'records' => []
            ];

            foreach ($list as $record) {
                $item = $record->toArray();
                $item['status_text'] = MaterialControlTaskRecord::getStatusText($item['status']);
                $item['start_time_text'] = $item['start_time'] ? date('Y-m-d H:i:s', $item['start_time']) : '';
                $item['task_create_time_text'] = $item['task_create_time'] ? date('Y-m-d H:i:s', $item['task_create_time']) : '';
                $item['stop_time_text'] = $item['stop_time'] ? date('Y-m-d H:i:s', $item['stop_time']) : '';
                $item['duration_text'] = $item['total_duration'] ? $item['total_duration'] . '秒' : '';
                
                $result['records'][] = $item;
            }

            $this->success('获取记录列表成功', $result);

        } catch (Exception $e) {
            $this->error('获取记录列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取任务详情
     */
    public function getDetail()
    {
        try {
            $id = $this->request->param('id');
            if (!$id) {
                $this->error('参数错误：缺少任务ID');
            }

            $record = MaterialControlTaskRecord::get($id);
            if (!$record) {
                $this->error('任务记录不存在');
            }

            $result = $record->toArray();
            $result['status_text'] = MaterialControlTaskRecord::getStatusText($result['status']);
            $result['start_time_text'] = $result['start_time'] ? date('Y-m-d H:i:s', $result['start_time']) : '';
            $result['task_create_time_text'] = $result['task_create_time'] ? date('Y-m-d H:i:s', $result['task_create_time']) : '';
            $result['stop_time_text'] = $result['stop_time'] ? date('Y-m-d H:i:s', $result['stop_time']) : '';
            
            // 解析JSON数据
            if ($result['create_result']) {
                $result['create_result_data'] = json_decode($result['create_result'], true);
            }
            if ($result['stop_result']) {
                $result['stop_result_data'] = json_decode($result['stop_result'], true);
            }

            $this->success('获取任务详情成功', $result);

        } catch (Exception $e) {
            $this->error('获取任务详情失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取失败任务列表
     */
    public function getFailedTasks()
    {
        try {
            $limit = $this->request->param('limit', 50);
            $failedTasks = MaterialControlTaskRecord::getFailedTasks($limit);
            
            $result = [];
            foreach ($failedTasks as $task) {
                $item = $task->toArray();
                $item['status_text'] = MaterialControlTaskRecord::getStatusText($item['status']);
                $item['start_time_text'] = $item['start_time'] ? date('Y-m-d H:i:s', $item['start_time']) : '';
                $result[] = $item;
            }

            $this->success('获取失败任务列表成功', $result);

        } catch (Exception $e) {
            $this->error('获取失败任务列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 检查任务是否正在运行
     */
    public function checkRunning()
    {
        try {
            $advId = $this->request->param('adv_id');
            $objId = $this->request->param('obj_id');
            $materialId = $this->request->param('material_id');

            if (!$advId || !$objId || !$materialId) {
                $this->error('参数错误：缺少必要参数');
            }

            $isRunning = MaterialControlTaskRecord::hasRunningTask($advId, $objId, $materialId);
            
            $this->success('检查完成', [
                'is_running' => $isRunning,
                'message' => $isRunning ? '该素材有正在进行的调控任务' : '该素材没有正在进行的调控任务'
            ]);

        } catch (Exception $e) {
            $this->error('检查失败: ' . $e->getMessage());
        }
    }
}
