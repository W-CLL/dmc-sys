<?php

namespace app\api\controller;

use app\admin\model\Company;
use app\admin\model\QcObj as ObjModel;
use app\common\controller\Api;
use app\common\model\Queue;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use jlqc\FundManagement;
use think\Cache;
use think\Db;
use think\Exception;


/**
 * 广告投放数据相关定时任务类
 */
class QcAdv extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';

    public function index()
    {
//        Cache::rm('qc_adv_status_page');
//        die;
        $token = Cache::get("qc_access_token");
        $page = Cache::get('qc_adv_status_page', 1);
        echo $page;
//        die;
        $companyModel = new Company();
        $all = $companyModel->where(['adv_status' => 1])->order('id desc')->page($page)->limit(100)->column('advertiser_id');
        if (empty($all)) {
            echo "全部处理完了";
            Cache::rm('qc_adv_status_page');
            die;
        }
        $res = FundManagement::get_adv_info($token, urlencode(json_encode($all)));
        $numbers = [];
        if ($res['code'] == 40002) {
            //将一些没权限的账号设置为0
            preg_match_all('/\d+/', $res['message'], $matches);
            $numbers = $matches[0];
            $companyModel->where(['advertiser_id' => ['IN', $numbers]])->update(['adv_status' => 0]);
        } else {
            echo '没问题';
        }
        //防止一些户恢复了权限
        $normalAdvIds = array_diff($all, $numbers);
        $companyModel->where(['advertiser_id' => ['IN', $normalAdvIds]])->update(['adv_status' => 1]);
        $page++;
        Cache::set('qc_adv_status_page', $page);
        $this->index();
    }

}
