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
            $disable_adv = [];
            foreach ($res['data'] as $item) {
                if ($item['status'] == "STATUS_DISABLE") {
                    $disable_adv[] = $item['id'];
                }
            }
            if ($disable_adv) {
                $companyModel->where(['advertiser_id' => ['IN', $disable_adv]])->update(['adv_status' => 0]);
            }
        }
        //防止一些户恢复了权限
        $page++;
        Cache::set('qc_adv_status_page', $page);
        $this->index();
    }


    public function restore($ids = [])
    {
        $page = Cache::get('qc_adv_restore_page', 1);
        $companyModel = new Company();
        if (!empty($ids)){
            $all = $ids;
            $all = array_map('intval', $all);
        }else{
            $all = $companyModel->where(['adv_status' => 0])->order('id desc')->page($page)->limit(100)->column('advertiser_id');
        }
        if (empty($all)) {
            echo "全部处理完了";
            Cache::rm('qc_adv_restore_page');
            die;
        }
        $res = FundManagement::get_adv_info($all);
        if ($res['code'] == 40002) {
            //将一些没权限的账号剔除
            preg_match_all('/\d+/', $res['message'], $matches);
            $numbers = $matches[0];
            $ids = array_values(array_diff($all, $numbers));
            $this->restore($ids);
        } else {
            $restore_adv = [];
            foreach ($res['data'] as $item) {
                if ($item['status'] != "STATUS_DISABLE") {
                    $restore_adv[] = $item['id'];
                }
            }
            if ($restore_adv) {
                $companyModel->where(['advertiser_id' => ['IN', $restore_adv]])->update(['adv_status' => 1]);
            }
        }
        //防止一些户恢复了权限
        $page++;
        Cache::set('qc_adv_restore_page', $page);
        $this->restore();
    }


    /**
     * 获取千川账户下的抖音号，分割
     * @return void
     */
    public function chunkAdvForGetAwemeList()
    {
        $adv_list = Db::name('company')
            ->where('adv_status', 1)
            ->order('advertiser_id', 'desc')
            ->column('advertiser_id');
        $chunks = array_chunk($adv_list, 20);
        foreach ($chunks as $chunk) {
            $job_data = [
                'adv_list' => $chunk,
                'params' => ['page' => 1, 'page_size' => 100]
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
            ->alias('aw')
            ->join('company com', 'aw.adv_id=com.advertiser_id', 'left')
            ->where(['aw.status' => "EFFECTIVE", 'com.adv_status' => 1])
            ->field('aw.adv_id,aw.aweme_id')
//            ->fetchSql(true)
            ->select();

        $chunks = array_chunk((array)$adv_list, 20);
        foreach ($chunks as $chunk) {
            $job_data = [];
            foreach ($chunk as $item) {
                $job_data[] = [
                    'adv_id' => $item['adv_id'],
                    'aweme_id' => $item['aweme_id'],
                    'params' => [
                        'filtering' => json_encode(['tab' => 'ALL']),
                        'page' => 1,
                        'page_size' => 100
                    ]
                ];
            }
            \think\Queue::push('app\job\InsertAwemeGoods', $job_data, "insertAwemeGoods");
        }
        echo "分割完成";
    }

    public function chunkObjGoodsList($start_time = '', $end_time = '')
    {
        if (!$start_time && !$end_time) {
            $start_time = date('Y-m-d', strtotime('-1 day'));
            $end_time = date('Y-m-d');
        }
        $com_model = new Company();
        $obj_model = new \app\admin\model\QcGlobalObj();
        $adv_list = $com_model->where(['adv_status' => 1])->column('advertiser_id');
        foreach ($adv_list as $item) {
            $obj_list = $obj_model->where([
                'is_handle' => 0,
                'adv_id' => $item,
                'marketing_goal'=>"VIDEO_PROM_GOODS",
                "obj_create_time"=>['>=',"1740758400"]//2025-3月之后的创建的计划
            ])->column('obj_status','obj_id');
            if (!$obj_list) {
                continue;
            }
            if (count($obj_list) > 30) {
                $chunks = array_chunk($obj_list, 30,true);
                foreach ($chunks as $chunk) {
                    $job_data = [
                        'adv_id' => $item,
                        'obj_ids' => $chunk,
                        'start_time' => $start_time,
                        'end_time' => $end_time,
                        "fields" => json_encode(['product_show_count_for_roi2'])
                    ];
                    \think\Queue::push('app\job\risk_job\InsertObjProduct', $job_data, "insertObjProduct");
                }
            } else {
                $job_data = [
                    'adv_id' => $item,
                    'obj_ids' => $obj_list,
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                    "fields" => json_encode(['product_show_count_for_roi2'])
                ];
                \think\Queue::push('app\job\risk_job\InsertObjProduct', $job_data, "insertObjProduct");
            }
        }
        echo "分割完成";
    }

}
