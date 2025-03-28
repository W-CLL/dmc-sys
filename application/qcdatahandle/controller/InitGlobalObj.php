<?php


namespace app\qcdatahandle\controller;

use app\admin\model\Company;
use app\admin\model\QcObj;
use app\api\controller\QcGlobalObj;
use app\common\model\Queue;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use think\Cache;
use think\Controller;

/**
 * 全域计划初始化类
 */
class InitGlobalObj extends Controller
{
    /**
     * @var QcGlobalObj
     */
    private $handleGlobalObj;

    public function _initialize()
    {
        parent::_initialize();
        $this->handleGlobalObj = new QcGlobalObj();
    }

    /**
     * 初始化推商品
     * @param $start
     * @param $end
     * @return void
     * @throws Exception
     */
    public function handlerVideo($start,$end)
    {
        $this->handleGlobalObj->getNewObjRecursive(200, "VIDEO_PROM_GOODS",'SMART_BID_CUSTOM',$start,$end);
        $this->handleGlobalObj->getNewObjRecursive(200, "VIDEO_PROM_GOODS",'SMART_BID_CONSERVATIVE',$start,$end);
    }

    /**
     * 初始化推直播间
     * @param $start
     * @param $end
     * @return void
     * @throws Exception
     */
    public function handlerLive($start,$end)
    {
        $this->handleGlobalObj->getNewObjRecursive(200, "LIVE_PROM_GOODS",'SMART_BID_CUSTOM',$start,$end);
        $this->handleGlobalObj->getNewObjRecursive(200, "LIVE_PROM_GOODS",'SMART_BID_CONSERVATIVE',$start,$end);
    }
    
}
