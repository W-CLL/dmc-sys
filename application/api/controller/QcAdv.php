<?php

namespace app\api\controller;

use app\admin\model\Company;

use app\common\controller\Api;
use app\common\model\AdvAweme;
use jlqc\FundManagement;
use think\Cache;
use think\Db;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\DbException;


/**
 * 广告投放数据相关定时任务类
 */
class QcAdv extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';

    public function index()
    {
        $page = Cache::get('qc_adv_status_page', 1);
        $companyModel = new Company();
        $all = $companyModel->where(['adv_status' => 1])->order('id desc')->page($page)->limit(100)->column('advertiser_id');
        if (empty($all)) {
            echo "全部处理完了";
            Cache::rm('qc_adv_status_page');
            die;
        }
        $res = FundManagement::get_adv_info($all);
        if ($res['code'] == 40002) {
            //将一些没权限的账号设置为0
            preg_match_all('/\d+/', $res['message'], $matches);
            $numbers = $matches[0];
            $companyModel->where(['advertiser_id' => ['IN', $numbers]])->update(['adv_status' => 0]);
        } else {
            $disable_adv =[];
            foreach ($res['data'] as $item){
                if($item['status'] == "STATUS_DISABLE"){
                    $disable_adv[] = $item['id'];
                }
            }
            if($disable_adv){
                $companyModel->where(['advertiser_id' => ['IN', $disable_adv]])->update(['adv_status' => 0]);
            }
        }
        //防止一些户恢复了权限
        $page++;
        Cache::set('qc_adv_status_page', $page);
        $this->index();
    }


    /**
     * 获取千川账户下的抖音号，分割
     * @return void
     */
    public function chunkAdvForGetAwemeList()
    {
      $adv_list =   Db::name('company')
            ->where('adv_status', 1)
            ->order('advertiser_id', 'desc')
            ->column('advertiser_id');
        $chunks = array_chunk($adv_list, 20);
        foreach ($chunks as $chunk){
            $job_data = [
                'adv_list' => $chunk,
                'params'=>['page'=>1,'page_size'=>100]
            ];
            \think\Queue::push('app\job\InsertAdvAweme', $job_data, "insertAdvAweme");
        }
        echo "分割完成";
    }

    /**
     * 获取抖音号的可投商品
     * @return void
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws DbException
     */
    public function chunkAwemeForGetGoodsList()
    {
        $aweme_model = new AdvAweme();
        $adv_list = $aweme_model
            ->where('status', "EFFECTIVE")
            ->field('adv_id,aweme_id')
            ->select();

        $chunks = array_chunk((array)$adv_list, 20);
        foreach ($chunks as $chunk) {
            $job_data = [];
            foreach ($chunk as $item){
                $job_data[] = [
                    'adv_id'=>$item['adv_id'],
                    'aweme_id'=>$item['aweme_id'],
                    'params'=>[
                        'filtering'=> json_encode(['tab' => 'ALL']),
                        'page' => 1,
                        'page_size' => 100
                    ]
                ];
            }
            \think\Queue::push('app\job\InsertAwemeGoods', $job_data, "insertAwemeGoods");
        }
        echo "分割完成";
    }

}
