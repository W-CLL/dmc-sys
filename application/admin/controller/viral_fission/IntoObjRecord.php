<?php

namespace app\admin\controller\viral_fission;

use app\common\controller\Backend;
use think\Db;
use think\Log;

/**
 * 爆款裂变-投放记录
 *
 * @icon fa fa-history
 */
class IntoObjRecord extends Backend
{

    /**
     * @var \think\Model
     */
    protected $model = null;

    protected $relationSearch = true;
    protected $searchFields = 'adv_id,obj_id,product_id,mid,reason,status';

    public function _initialize()
    {
        parent::_initialize();
        // 直接使用Db模型操作表
        $this->model = Db::name('fission_into_obj_record');
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
            
            
            
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            
            // 特别处理时间戳类型的日期搜索
            $filter = $this->request->get('filter', '');
            if ($filter) {
                $filterArr = json_decode($filter, true);
                if (isset($filterArr['create_time']) && is_array($filterArr['create_time'])) {
                    // 处理时间范围搜索
                    $timeRange = $filterArr['create_time'];
                    if (isset($timeRange[0]) && isset($timeRange[1])) {
                        // 将日期字符串转换为时间戳
                        $startTime = strtotime($timeRange[0]);
                        $endTime = strtotime($timeRange[1]);
                        
                        if ($startTime && $endTime) {
                            // 添加时间范围条件
                            $where['create_time'] = [['>=', $startTime], ['<=', $endTime]];
                        }
                    } else if (isset($timeRange[0])) {
                        // 只有开始时间
                        $startTime = strtotime($timeRange[0]);
                        if ($startTime) {
                            $where['create_time'] = ['>=', $startTime];
                        }
                    } else if (isset($timeRange[1])) {
                        // 只有结束时间
                        $endTime = strtotime($timeRange[1]);
                        if ($endTime) {
                            $where['create_time'] = ['<=', $endTime];
                        }
                    }
                }
            }
            
            
            
            $list = $this->model
                    ->where($where)
                    ->order($sort, $order)
                    ->limit($offset, $limit)
                    ->select();
            
            $total = $this->model
                    ->where($where)
                    ->order($sort, $order)
                    ->count();
            
            $result = array("total" => $total, "rows" => $list);
            
            
            
            return json($result);
        }
        
        return $this->view->fetch();
    }
    
    /**
     * 详情
     */
    public function detail($ids)
    {
        $row = $this->model->where('id', $ids)->find();
        if (!$row)
            $this->error('记录不存在');
            
        // 格式化创建时间
        if ($row['create_time']) {
            $row['create_time_text'] = date('Y-m-d H:i:s', $row['create_time']);
        } else {
            $row['create_time_text'] = '无';
        }
            
        $this->view->assign("row", $row);
        return $this->view->fetch();
    }
}