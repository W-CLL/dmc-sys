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
    protected $noNeedRight = ['index', 'getList', 'add', 'edit', 'del', 'batch_add', 'parse_excel', 'batch_add'];

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
        
        // 获取分页参数
        $offset = $this->request->get('offset/d', 0);
        $limit = $this->request->get('limit/d', 10);
        if ($limit <= 0 || $limit > 100) {
            $limit = 10;
        }
        
        // 排序
        $sort = $this->request->get('sort', 'id');
        $order = $this->request->get('order', 'desc');
        
        // 排序处理
        if ($sort == 'id') {
            if ($order == 'asc') {
                ksort($subjectList);
            } else {
                krsort($subjectList);
            }
        }
        
        $data = [];
        $subjectList = array_values($subjectList); // 重新索引
        $total = count($subjectList);
        
        // 分页切片
        $pageData = array_slice($subjectList, $offset, $limit);
        
        foreach ($pageData as $index => $name) {
            $data[] = [
                'id' => $offset + $index + 1,
                'name' => $name,
                'create_time' => date('Y-m-d H:i:s')
            ];
        }
        
        return json(['total' => $total, 'rows' => $data]);
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
        $ids = $ids ?: $this->request->param('ids');
        
        $subjectList = Cache::get('material_prequalification_subject_list', []);
        if (!is_array($subjectList)) {
            $subjectList = [];
        }
        
        // 支持批量删除，ids可能是逗号分隔的字符串或数组
        if (is_string($ids) && strpos($ids, ',') !== false) {
            $ids = explode(',', $ids);
        }
        
        if (is_array($ids)) {
            // 批量删除 - 前端传来的ID是1,2,3格式，需要减1转为数组索引0,1,2
            $ids = array_map(function($id) { return intval($id) - 1; }, $ids);
            $newList = [];
            foreach ($subjectList as $index => $subject) {
                if (!in_array($index, $ids)) {
                    $newList[] = $subject;
                }
            }
            $subjectList = $newList;
        } else {
            // 单个删除 - 前端传来的ID是1,2,3格式，需要减1转为数组索引0,1,2
            $index = intval($ids) - 1;
            if (!isset($subjectList[$index])) {
                $this->error('主体不存在');
            }
            unset($subjectList[$index]);
            $subjectList = array_values($subjectList);
        }
        
        Cache::set('material_prequalification_subject_list', $subjectList);
        
        $this->success('删除成功');
    }

    /**
     * 批量添加页面
     */
    public function batch_add()
    {
        return $this->view->fetch();
    }

    /**
     * 解析Excel文件
     */
    public function parse_excel()
    {
        $file = $this->request->file('file');
        if (!$file) {
            $this->error('请上传文件');
        }

        $info = $file->move(ROOT_PATH . 'runtime' . DS . 'upload');
        if (!$info) {
            $this->error('文件上传失败');
        }

        $filepath = ROOT_PATH . 'runtime' . DS . 'upload' . DS . $info->getSaveName();
        $ext = strtolower($info->getExtension());

        try {
            $data = [];
            
            if ($ext == 'csv') {
                // CSV文件直接读取
                $handle = fopen($filepath, 'r');
                $row = 0;
                while (($line = fgetcsv($handle)) !== false) {
                    $row++;
                    if ($row == 1) continue; // 跳过标题行
                    if (!empty(trim($line[0]))) {
                        $data[] = trim($line[0]);
                    }
                }
                fclose($handle);
            } elseif ($ext == 'xlsx') {
                // xlsx文件使用ZipArchive解析
                if (!class_exists('\ZipArchive')) {
                    $this->error('服务器不支持ZipArchive');
                }
                
                $zip = new \ZipArchive();
                $openResult = $zip->open($filepath);
                if ($openResult !== true) {
                    $this->error('无法打开xlsx文件,错误码:' . $openResult);
                }
                
                $sharedStrings = [];
                // 读取sharedStrings.xml获取字符串
                if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                    $xmlStr = $zip->getFromName('xl/sharedStrings.xml');
                    $xml = simplexml_load_string($xmlStr);
                    if ($xml && isset($xml->si)) {
                        foreach ($xml->si as $item) {
                            if (isset($item->t)) {
                                $sharedStrings[] = (string)$item->t;
                            } elseif (isset($item->r)) {
                                $str = '';
                                foreach ($item->r as $r) {
                                    $str .= (string)(isset($r->t) ? $r->t : '');
                                }
                                $sharedStrings[] = $str;
                            }
                        }
                    }
                }
                
                // 读取sheet1.xml
                if ($zip->locateName('xl/worksheets/sheet1.xml') !== false) {
                    $xmlStr = $zip->getFromName('xl/worksheets/sheet1.xml');
                    $xml = simplexml_load_string($xmlStr);
                    
                    if ($xml && isset($xml->sheetData)) {
                        $rowIndex = 0;
                        foreach ($xml->sheetData->row as $row) {
                            $rowIndex++;
                            if ($rowIndex == 1) continue; // 跳过标题行
                            
                            foreach ($row->c as $cell) {
                                $cellRef = (string)$cell['r'];
                                // 只取第一列 (A列)
                                if (preg_match('/^A(\d+)$/', $cellRef, $matches) && $matches[1] == $rowIndex) {
                                    $value = '';
                                    $cellType = isset($cell['t']) ? (string)$cell['t'] : '';
                                    
                                    if (isset($cell->v)) {
                                        if ($cellType == 's') {
                                            // 共享字符串
                                            $idx = (int)$cell->v;
                                            $value = isset($sharedStrings[$idx]) ? $sharedStrings[$idx] : '';
                                        } else {
                                            // 数字或直接值
                                            $value = (string)$cell->v;
                                        }
                                    } elseif (isset($cell->is)) {
                                        // 内联字符串
                                        if (isset($cell->is->t)) {
                                            $value = (string)$cell->is->t;
                                        }
                                    }
                                    
                                    if (!empty(trim($value))) {
                                        $data[] = trim($value);
                                    }
                                    break;
                                }
                            }
                        }
                    }
                }
                
                $zip->close();
            } else {
                $this->error('不支持的文件格式，请上传xlsx或csv文件');
            }
            
            // 删除临时文件
            @unlink($filepath);
            
            if (empty($data)) {
                $this->error('未找到有效数据，请确保Excel第一列有数据');
            }
            
            $this->success('解析成功', '', $data);
        } catch (\Exception $e) {
            @unlink($filepath);
            $this->error('解析失败: ' . $e->getMessage());
        }
    }

    /**
     * 批量添加主体
     */
    public function batch_add_post()
    {
        $subjectsJson = $this->request->post('subjects', '[]');
        $subjects = json_decode($subjectsJson, true);
        
        if (empty($subjects)) {
            $this->error('没有要导入的数据');
        }
        
        $subjectList = Cache::get('material_prequalification_subject_list', []);
        if (!is_array($subjectList)) {
            $subjectList = [];
        }
        
        $count = 0;
        foreach ($subjects as $name) {
            $name = trim($name);
            if (!empty($name) && !in_array($name, $subjectList)) {
                $subjectList[] = $name;
                $count++;
            }
        }
        
        Cache::set('material_prequalification_subject_list', $subjectList);
        
        $this->success('成功导入 ' . $count . ' 条数据');
    }
}
