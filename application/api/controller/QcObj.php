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
        $list = Cache::get('company_list_obj');
        if (!$list) {
            $list = Db::name('company')
                ->where('adv_status', 1)
                ->order('advertiser_id', 'desc')
                ->column('advertiser_id');
            Cache::set('company_list_obj', $list, 21600);
        }
        return $list;
    }

    public function getNewObjRecursive($pageSize = 200, $type = "VIDEO_PROM_GOODS")
    {
        $allAdvIds = $this->getAllAdvIds();
        $chunks = array_chunk($allAdvIds, $pageSize);
        foreach ($chunks as $chunk) {
            $job_data = [
                'filtering'=>[
                    'marketing_goal' =>$type,
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
                'adv_list'=>$chunk
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


}