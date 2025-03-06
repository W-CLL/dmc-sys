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
class QcObj extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';

    /**
     */
    public function getAdvByPage($page = 1, $pageSize = 100)
    {
        // 计算查询的偏移量
        $offset = ($page - 1) * $pageSize;
        // 查询某一页的数据
        return Db::name('company')
            ->order('advertiser_id', 'desc')
            ->where(['adv_status'=>1])
            ->limit($offset, $pageSize) // 通过offset和pageSize控制查询范围
            ->column('advertiser_id');
    }

    /**
     * 每天的零点23:58
     * @param int $pageSize
     * @param string $type
     * @return void
     */
    public function getNewObj(int $pageSize = 1000, string $type = "VIDEO_PROM_GOODS")
    {
        $page = 1;
        $objModel = new ObjModel();
        $queue = new Queue();
        while (true) {
//            sleep(5);
            // 获取当前页的数据
            $advList = $this->getAdvByPage($page, $pageSize);
            // 如果没有数据，表示已经查询完毕
            if (empty($advList)) {
                echo "已经全部处理完了";
                break;
            }
            foreach ($advList as $id) {
                //查询广告账号当天已经存在的计划
                //查询状态不为删除的，因为接口默认不查找已经删除的计划
                $objIds = $objModel
                    ->where([
                        'adv_id' => $id,
                        'obj_create_time' => ['>', strtotime(date('Y-m-d', time()))],
                        'marketing_goal' => $type,
                        'obj_status' => ['<>', "DELETE"]
                    ])->column('obj_id');
                $params = [
                    'advertiser_id' => $id,
                    'filtering' =>
                        [
                            'marketing_goal' => $type,
                            'ad_create_start_date' => date('Y-m-d', time()),
                            'ad_create_end_date' => date('Y-m-d', time()),
                            'marketing_scene'=>'ALL',
                            "status" => "ALL_INCLUDE_DELETED",
                            "campaign_scene" => [
                                'DAILY_SALE',
                                'NEW_CUSTOMER_TRANSFORMATION',
                                'LIVE_HEAT',
                                'PLANT_GRASS',
                                'PRODUCT_HEAT',
                                'NEW_PRODUCT_BOOST',
                            ],
                        ],
                    'page' => 1,
                    'page_size' => 200
                ];
                if (count($objIds) > 100) {
                    echo $id . "竟然一天创建了超过一百条计划";
                } else {
                    $params['filtering']['ids'] = $objIds;
                }
                $params['filtering'] = json_encode($params['filtering']);
                $queue->addQueue('插入当天新增计划', 'app\job\InsertDayObj', 'insertDayObj', $params);
            }
            $page++;
        }
    }


    /**
     * 推商品
     * @return void
     */
    public function initInsertVideoObj()
    {
        $this->getNewObj(1000, "VIDEO_PROM_GOODS");
    }

    /**
     * 推直播间
     * @return void
     */
    public function initInsertLiveObj()
    {
        $this->getNewObj(1000, "LIVE_PROM_GOODS");
    }

    /**
     * 构建请求
     * @param $count
     * @param $advIds
     * @return array
     */
    protected function buildGuzzleRequest($count, $advIds): array
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/ad/get/";
        $headers = [
            'Access-Token' => $access_token,
            'Content-Type' => 'application/json'
        ];
        $requests = [];

        foreach ($advIds as $params) {

            $request = new Request('GET', $url, $headers, json_encode($params));
            $requests[] = ['request' => $request, 'params' => $params];
        }
        return $requests;
    }


    /**
     * 每天凌晨四点更新一下非禁用计划的状态
     * @return void
     */
    public function updateObjStatus()
    {

    }

}