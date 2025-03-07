<?php

namespace app\qcdatahandle\controller;

use app\common\model\QcAdvDayCost;
use Requests;
use think\Cache;
use think\Controller;



class UpdateQcAdvCost extends Controller
{

    public function handleUpdateCost($start_date = '2025-03-01', $end_date = '2025-03-07')
    {
        $costModel = new QcAdvDayCost();
        $page = Cache::get('update_cost_page',1);
        $adv_list = $costModel
            ->where(['cost_date'=>['between',[strtotime($start_date),strtotime($end_date)]],'type'=>1])
            ->group('adv_id')
            ->order('adv_id asc')
            ->page($page)
            ->limit(50)
            ->column('adv_id');
        if(!$adv_list){
            echo "全部处理完了";
            die;
        }
        foreach ($adv_list as $item){
            $this->getSingleAdvCost($item,$start_date,$end_date);
        }
        echo "处理了第".$page.'页';
        Cache::set('update_cost_page',$page+1);
    }


    public function getSingleAdvCost($adv_id,$start_date,$end_date)
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/report/advertiser/get";
        $params = [
            "advertiser_id" => $adv_id,
            "start_date" => $start_date,
            "end_date" => $end_date,
            "page" => 1,
            "fields" => ['stat_cost'],
            "page_size" => 100,
            "order_type" => "DESC",
            "filtering" => [
                "marketing_goal" => 'ALL'
            ],
            'time_granularity' => 'TIME_GRANULARITY_DAILY'
        ];
        $url = buildUrlWithParams($url, $params);
        $header = array(
            'Access-Token:' . $access_token,
        );
        $res = Requests::get($url, $header);
        $costModel = new QcAdvDayCost();
        if($res['code'] == 0 && $res['data']['list']) {

            foreach ($res['data']['list'] as $item) {
                $cost_data = strtotime($item['stat_datetime']);
                $dayCost = $costModel->where(['adv_id' => $item['advertiser_id'], 'cost_date' => $cost_data, 'type' => 1])->find();
                if ($dayCost) {
                    $upData['id'] = $dayCost['id'];
                    $upData['cost'] = $item['stat_cost'];
                    $res = $dayCost->save($upData);
                    echo "更新";
                } else {
                    $insert_data = [
                        'adv_id' => $item['advertiser_id'],
                        'cost' => $item['stat_cost'],
                        'cost_date' => $cost_data,
                        'type' => 1
                    ];
                    $res = $costModel->save($insert_data);
                    echo "插入";
                }
                if (!$res && $res != 0) {
                    throw  new \think\Exception($res);
                }
            }
        }
        return true;
    }

}