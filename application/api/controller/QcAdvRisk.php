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
use think\Exception;
use think\exception\DbException;
use think\exception\PDOException;


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

    /**
     * @return void
     * @throws Exception
     * @throws PDOException
     */
    public function clearIsDelAdvProduct()
    {

        $adv_ids = Db::name('risk_obj_product')
            ->alias('ro')
            ->join('company com','ro.adv_id=com.advertiser_id','left')
            ->where(['com.adv_status'=>0])
            ->group('ro.adv_id')
            ->column('com.advertiser_id');
        if($adv_ids){
            $res = Db::name('risk_obj_product')->where(['adv_id'=>['in',$adv_ids]])->delete();
            echo "已删除".$res."条";
        }else{
            echo "没有数据需要处理";
        }
    }


}
