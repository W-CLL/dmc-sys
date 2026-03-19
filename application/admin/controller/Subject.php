<?php

namespace app\admin\controller;

use app\common\controller\Backend;
use think\Cache;
use think\Db;

/**
 * 主体管理
 */
class Subject extends Backend
{
    /**
     * 无需鉴权的接口
     */
    protected $noNeedRight = ['index', 'getList', 'add', 'edit', 'del'];

    public function _initialize()
    {
        parent::_initialize();
    }

    /**
     * 查看
     */
    public function index()
    {
        if ($this->request->isAjax()) {
            return $this->getList();
        }
        return $this->view->fetch();
    }

    /**
     * 获取主体列表
     */
    public function getList()
    {
        $subjectList = Cache::get('material_prequalification_subject_list', []);
        if (!is_array($subjectList)) {
            $subjectList = [];
        }
        
        $data = [];
        foreach ($subjectList as $index => $name) {
            $data[] = [
                'id' => $index + 1,
                'name' => $name,
                'create_time' => date('Y-m-d H:i:s')
            ];
        }
        
        return json(['total' => count($data), 'rows' => $data]);
    }

    /**
     * 添加
     */
    public function add()
    {
        if ($this->request->isPost()) {
            $name = $this->request->post('name', '', 'trim');
            
            if (!$name) {
                $this->error('请输入主体名称');
            }
            
            $subjectList = Cache::get('material_prequalification_subject_list', []);
            if (!is_array($subjectList)) {
                $subjectList = [];
            }
            
            if (in_array($name, $subjectList)) {
                $this->error('主体名称已存在');
            }
            
            $subjectList[] = $name;
            Cache::set('material_prequalification_subject_list', $subjectList);
            
            $this->success('添加成功');
        }
        return $this->view->fetch();
    }

    /**
     * 编辑
     */
    public function edit($ids = null)
    {
        $ids = $ids ?: $this->request->param('ids', 0, 'intval');
        
        if ($this->request->isPost()) {
            $name = $this->request->post('name', '', 'trim');
            
            if (!$name) {
                $this->error('请输入主体名称');
            }
            
            $subjectList = Cache::get('material_prequalification_subject_list', []);
            if (!is_array($subjectList)) {
                $subjectList = [];
            }
            
            $index = $ids - 1;
            if (!isset($subjectList[$index])) {
                $this->error('主体不存在');
            }
            
            // 检查是否与其他重复
            $tempList = $subjectList;
            unset($tempList[$index]);
            $tempList = array_values($tempList);
            if (in_array($name, $tempList)) {
                $this->error('主体名称已存在');
            }
            
            // 修改
            $subjectList[$index] = $name;
            Cache::set('material_prequalification_subject_list', $subjectList);
            
            $this->success('修改成功');
        }
        
        // 获取要编辑的数据
        $subjectList = Cache::get('material_prequalification_subject_list', []);
        $index = $ids - 1;
        $name = isset($subjectList[$index]) ? $subjectList[$index] : '';
        
        $this->assign('ids', $ids);
        $this->assign('name', $name);
        return $this->view->fetch();
    }

    /**
     * 删除
     */
    public function del($ids = null)
    {
        $ids = $ids ?: $this->request->param('ids', 0, 'intval');
        
        $subjectList = Cache::get('material_prequalification_subject_list', []);
        if (!is_array($subjectList)) {
            $subjectList = [];
        }
        
        $index = $ids - 1;
        if (!isset($subjectList[$index])) {
            $this->error('主体不存在');
        }
        
        unset($subjectList[$index]);
        $subjectList = array_values($subjectList);
        Cache::set('material_prequalification_subject_list', $subjectList);
        
        $this->success('删除成功');
    }
}
