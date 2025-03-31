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

    public function getAllAdvIds()
    {
        $list = Cache::rm('company_list_obj');
//        if (!$list) {
            $list = Db::name('company')
                ->where('adv_status', 1)
                ->order('advertiser_id', 'desc')
                ->column('advertiser_id');
//            Cache::set('company_list_obj', $list, 21600);
//        }
        return $list;
    }

    public function getNewObjRecursive($pageSize = 200, $type = "VIDEO_PROM_GOODS")
    {
        $allAdvIds = $this->getAllAdvIds();
        $chunks = array_chunk($allAdvIds, $pageSize);
        foreach ($chunks as $chunk) {
            $job_data = [
                'filtering' => [
                    'marketing_goal' => $type,
                    'ad_create_start_date' => date('Y-m-d', time()),
                    'ad_create_end_date' => date('Y-m-d', time()),
                    'marketing_scene' => 'ALL',
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
                'adv_list' => $chunk
            ];
            \think\Queue::push('app\job\InsertDayObj', $job_data, "insertDayObj");
        }
        echo "全部处理完了";
    }


    /**
     * 推商品
     * @return void
     */
    public function initInsertVideoObj()
    {
        $this->getNewObjRecursive(200, "VIDEO_PROM_GOODS");
    }

    /**
     * 推直播间
     * @return void
     */
    public function initInsertLiveObj()
    {
        $this->getNewObjRecursive(200, "LIVE_PROM_GOODS");
    }



    public function handlerSpecialAdvId($type)
    {
        $allAdvIds = $this->getAllAdvIds();
        $chunks = array_chunk($allAdvIds, 200);
        foreach ($chunks as $chunk) {
            $job_data = [
                'filtering' => [
                    'marketing_goal' => $type,
                    'ad_create_start_date' => "2025-03-01",
                    'ad_create_end_date' => "2025-03-31",
                    'marketing_scene' => 'ALL',
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
                'adv_list' => $chunk
            ];
            \think\Queue::push('app\job\InsertDayObj', $job_data, "insertDayObj");
        }
        echo "全部处理完了";
    }


    public function updateObjStatus()
    {

        $obj_model = new ObjModel();
        $adv_list = $obj_model
            ->alias('obj')
            ->join('company com','com.advertiser_id=obj.adv_id','left')
            ->where(['com.adv_status'=>1])
            ->group('adv_id')->column('adv_id');
        foreach ($adv_list as $item) {
            $list = $obj_model
                ->where(['opt_status' => ['NOT IN', ['DELETE', "TIME_DONE", 'FROZEN']]])
                ->where(['adv_id' => $item])
                ->column('obj_id');
            if($list){
                $chunks = array_chunk($list, 300);
                foreach ($chunks as $chunk) {
                    $job_data = [
                        'adv_id' => $item,
                        'obj_list' => $chunk
                    ];
                    \think\Queue::push('app\job\UpdateObjStatus', $job_data, "updateObjStatus");
                }
            }

        }
        echo "全部处理完了";

    }


}