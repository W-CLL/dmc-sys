<?php

namespace app\api\controller;

use app\admin\model\Company;
use app\admin\model\CompanySetting;
use app\admin\model\QcObj as ObjModel;
use app\common\controller\Api;
use app\common\model\QcAdvDayCost;
use app\common\model\Queue;
use GuzzleHttp\Psr7\Request;
use think\Cache;
use think\Db;
use think\Exception;


/**
 * 判断百分比加入队列处理
 */
class AutoUpdateObjName extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';

    public function index()
    {
        $page = Cache::get('chunk_obj_page', 1);
//        if($page==25){
//            echo "加入了一部分";
//            echo $page;
//            die;
//        }
        //1、先查询不是白名单的 公司下的广告账户
        $comSettingModel = new CompanySetting();
        $comModel = new Company();
        $redis = Cache::store('redis');
        if ($redis->get('company_setting_list')) {
            $notWhiteCom = unserialize($redis->get('company_setting_list'));
        } else {
            //获取非白名单公司
            $notWhiteCom = $comSettingModel->where(['is_white' => 0])->column('percentage', 'company_name');
            $redis->set('company_setting_list', serialize($notWhiteCom));
        }
        //提取公司名
        $companyNames = array_keys($notWhiteCom);
        //获取公司下的广告主账户，每页100条
        $advList = $comModel->where(['company_name' => ['in', $companyNames]])
            ->order('advertiser_id desc')
            ->page($page)
            ->limit(100)
            ->column('advertiser_id');
//        dump($page);
//        dump($advList);
        $currentDate = new \DateTime();
        $currentDate->modify('first day of this month');
        $end_time = time();
//        $end_time = "1736922704";
        $start_time = $currentDate->getTimestamp();
//        $start_time = "1735713104";
        //获取本月的操作日志
        $list = $comModel
            ->alias('adv_c')
            ->join(
                "(SELECT adv_id, COUNT(*) AS total_num FROM fa_qc_obj_opt_log WHERE opt_time BETWEEN " . $start_time . " AND " . $end_time . " GROUP BY adv_id) AS total_stats",
                'adv_c.advertiser_id = total_stats.adv_id',
                'left'
            )
            ->join(
                "(SELECT adv_id, COUNT(*) AS company_num FROM fa_qc_obj_opt_log WHERE opt_time BETWEEN " . $start_time . " AND " . $end_time . " AND operator IN (SELECT name FROM fa_ad_operator WHERE status = 1) GROUP BY adv_id) AS company_stats",
                'adv_c.advertiser_id = company_stats.adv_id',
                'left'
            )
            ->where(['adv_c.advertiser_id' => ['in', $advList], 'total_stats.total_num' => ['>', 0]])
            ->field("adv_c.*, total_stats.total_num, company_stats.company_num")
            ->order('total_stats.total_num desc')
            ->select();

        if (empty($list)) {
            echo "全部处理完了";
            Cache::rm('chunk_obj_page');
            die;
        }
        $queue = new Queue();
        $objModel = new ObjModel();
        $needComNum = 0;
        foreach ($list as $item) {
            $totalNum = (int)$item['total_num'];
            $companyNum = (int)$item['company_num'];
            $cusNum = $totalNum - $companyNum;
            $actualComNum = $cusNum + ($cusNum * $notWhiteCom[$item['company_name']]);
            if ($cusNum <= 0) {
                continue;
            }
            if ($companyNum > 0) {
                if ($companyNum < $cusNum) {
                    $needComNum = $actualComNum - $companyNum;
                }
            } else {
                $needComNum = $actualComNum;
            }
            //只查托管的计划
            $list = $objModel->where([
                'obj_status' => ['not in', ['DELETE', "TIME_DONE", 'FROZEN']],
                'lab_ad_type' => "LAB_AD",
                'opt_status' => ['not in', ['DELETE', "TIME_DONE", 'FROZEN']],
                'adv_id' => $item['advertiser_id']
            ])
                ->field('obj_id,adv_id')
                ->limit($needComNum)
//              ->fetchSql(true)
                ->column('obj_id');
            $queueData = [
                'need_opt_num' => $needComNum,
                'adv_id' => $item['advertiser_id'],
                'obj_list' => $list
            ];
            //一个广告主下的托管计划，总的操作次数，写入任务再平分次数到每个计划，进行延时修改
            $queue->addQueue('分块处理自动化', 'app\job\ChunkAutoObj', 'chunkAutoObj', $queueData);
        }
        $page++;
        Cache::set('chunk_obj_page', $page);
        $this->index();
    }


}