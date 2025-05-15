<?php

namespace app\api\controller;

use app\admin\model\Company;
use app\common\controller\Api;
use think\Queue;

/**
 * 广告主的积分同步类
 */
class AdvScore extends Api
{
    protected $noNeedLogin = ['*'];
    protected $noNeedRight = ['*'];

    public function syncDayScore()
    {
        $companyModel = new Company();
        $adv_list = $companyModel->where(['adv_status' => 1])->order('id desc')->column('advertiser_id');
        $chunks = array_chunk($adv_list, 10);
        $year = date('Y');
        foreach ($chunks as $chunk){
            Queue::later(1,'app\job\UpdateAdvScore', ['adv_list'=>$chunk,'params'=>['filtering'=>['year'=>$year],'business_line'=> "QIANCHUAN"]], 'upAdvScore');
        }
        echo "全部处理成功了";
    }




}
