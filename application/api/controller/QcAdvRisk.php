<?php

namespace app\api\controller;

use app\admin\model\Company;

use app\common\controller\Api;
use app\common\model\AdvAweme;
use app\common\model\ObjProduct;
use jlqc\FundManagement;
use think\Cache;
use think\Db;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\DbException;


/**
 * 账户风控检测类
 */
class QcAdvRisk extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';

    public function index()
    {
        $model = new ObjProduct();
        $obj_list = $model->group('product_id')->column('name','product_id');
        $chunks = array_chunk($obj_list,100,true);
        foreach ($chunks as $chunk){
            \think\Queue::push('app\job\risk_job\CheckRiskWords', $chunk, "checkRiskWords");
        }
        echo "全部处理完了";
    }

    public function advStats()
    {
        $model = new ObjProduct();
        $adv_list = $model
            ->alias('op')
            ->join('company com','op.adv_id=com.advertiser_id','left')
            ->where(['com.adv_status'=>1])
            ->group('op.adv_id')
            ->column('op.adv_id');
        $chunks = array_chunk($adv_list,100);
        foreach ($chunks as $chunk){
            \think\Queue::push('app\job\risk_job\UpdateAdvStats', $chunk, "updateAdvStats");
        }
        echo "全部处理完了";
    }


}
