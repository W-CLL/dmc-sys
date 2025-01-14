<?php


namespace app\api\controller;

use app\admin\model\QcObj as ObjModel;
use app\common\controller\Api;
use app\common\model\Queue;
use GuzzleHttp\Psr7\Request;
use think\Cache;
use think\Db;
use think\Exception;


/**
 * 广告投放数据相关定时任务类
 */
class QcAdvCost extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';


    public function index()
    {
        $page = 1;
        $pageSize = 100;
        $queue = new Queue();
        while (true) {
//            sleep(5);
            // 获取当前页的数据
            $advIdList = $this->getAdvByPage($page, $pageSize);
            if (empty($advIdList)) {
                echo "已经全部处理完了";
                break;
            }
            $queue->addQueue('1h更新账户当天消耗','app\job\UpdateAdvDayCost','upAdvDayCost',$advIdList);
            $page++;
        }
    }

    public function getAdvByPage($page = 1, $pageSize = 100)
    {
        // 计算查询的偏移量
        $offset = ($page - 1) * $pageSize;
        // 查询某一页的数据
        return Db::name('company')
            ->order('advertiser_id', 'desc')
            ->limit($offset, $pageSize) // 通过offset和pageSize控制查询范围
            ->column('advertiser_id');
    }


}