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
use think\Exception;
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
                if (in_array($item['status'], ["STATUS_DISABLE", "STATUS_LIMIT"])) {
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
//        $this->index();
    }


    public function restore($ids = [])
    {
        $page = Cache::get('qc_adv_restore_page', 1);
        $companyModel = new Company();
        if (!empty($ids)) {
            $all = $ids;
            $all = array_map('intval', $all);
        } else {
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
        } else if ($res['code'] == 0) {
            $restore_adv = [];
            foreach ($res['data'] as $item) {
                if ($item['status'] != "STATUS_DISABLE") {
                    $restore_adv[] = $item['id'];
                }
            }
            if ($restore_adv) {
                $companyModel->where(['advertiser_id' => ['IN', $restore_adv]])->update(['adv_status' => 1]);
            }
            //防止一些户恢复了权限
            $page++;
            Cache::set('qc_adv_restore_page', $page);
            $this->restore();
        } else {
            // 未知状态，重试此页
            $this->restore($ids);
        }
    }


    /**
     * 标记账户活跃状态，一天两次
     */
    public function markAdvActiveStatus()
    {
        $res = Db::name('company')->alias('c')
            ->distinct(true)
            ->field('c.advertiser_id as adv_id')
            ->join('qc_adv_day_cost d', 'c.advertiser_id = d.adv_id')
            ->where('c.adv_status', 1)
            ->where('d.type', 2)
            ->where('d.cost', 0)
            ->whereExp('d.cost_date', " >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 90 DAY), '%Y%m%d')")
            ->column('adv_id');
        try {
            if ($res) {
                Db::name('company')->whereIn('advertiser_id', $res)->update(['is_active' => 0]);
                Db::name('company')->where(['adv_status' => 1])->whereNotIn('advertiser_id', $res)->update(['is_active' => 1]);
            }
        }catch (Exception $e){
            echo "有问题";
            dump($e->getMessage());
            die;
        }

        echo "处理账号活跃状态结束";
        die;
    }
}
