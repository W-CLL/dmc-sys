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
 * 广告投放数据相关定时任务类
 */
class AutoUpdateObjName extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';

    public function index()
    {

        $page = Cache::get('chunk_obj_page',1);

//        if($page==25){
//            echo "加入了一部分";
//            echo $page;
//            die;
//        }
        //逻辑
        //1、先查询不是白名单的 公司下的广告账户
        $comSettingModel = new CompanySetting();
        $comModel = new Company();
        $redis = Cache::store('redis');
        if ($redis->get('company_setting_list')) {
            $notWhiteCom = unserialize($redis->get('company_setting_list'));
        } else {
            $notWhiteCom = $comSettingModel->where(['is_white' => 1])->column('percentage', 'company_name');
            $redis->set('company_setting_list', serialize($notWhiteCom));
        }
        $companyNames = array_keys($notWhiteCom, 'company_name');
        $advList = $comModel->where(['company_name' => ['in', $companyNames]])
            ->order('advertiser_id desc')
            ->page($page)
            ->limit(100)
            ->column('advertiser_id');
        $currentDate = new \DateTime();
        $currentDate->modify('first day of this month');
        $end_time = time();
        $start_time = $currentDate->getTimestamp();
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
//            ->page($page)
            ->limit(1000)
            ->select();
        if (empty($list)) {
            echo "全部处理完了";
            Cache::rm('chunk_obj_page');
            die;
        }
        $queue = new Queue();
        $objModel = new ObjModel();
        foreach ($list as $item) {
            $totalNum = (int)$item['total_num'];
            $companyNum = (int)$item['company_num'];
            $cusNum = $totalNum - $companyNum;
            if ($companyNum > 0 && $cusNum > 0) {
                $item['percentage'] = number_format($companyNum / $cusNum, 2) * 100;
            } else {
                $item['percentage'] = 0;
            }
            foreach ($companyNames as $name) {
                if ($item['company_name'] == $name) {
                    if ($notWhiteCom[$name] == 0) {
                        $per = 10;
                    } else {
                        $per = $notWhiteCom[$name];
                    }
                    $needNum = 0;
                    //客户操作次数大于0的时候，判断需要修改多少次（多少个）计划
                    if ($cusNum > 0) {
                        $needNum = round($cusNum * ($per / 100) + $cusNum);
                        if ($companyNum > 0) {
                            $needNum = $needNum - $companyNum;
                        }
                    }
                    //小于百分比就查询计划并写入延时任务
                    if ($item['percentage'] < $per && $needNum > 0) {
                        $list = $objModel->where([
                            'obj_status' => ['not in', ['DELETE', "TIME_DONE",'FROZEN']],
                            'lab_ad_type' => "LAB_AD",
                            'opt_status' => ['not in', ['DELETE', "TIME_DONE",'FROZEN']],
                            'adv_id' => $item['advertiser_id']
                        ])
                            ->field('obj_id,adv_id')
                            ->limit($needNum)
//                                ->fetchSql(true)
                            ->column('obj_id');
                        $queueData= [
                            'need_opt_num' => $needNum,
                            'adv_id' => $item['advertiser_id'],
                            'obj_list' => $list
                        ];
                        $queue->addQueue('分块处理自动化','app\job\ChunkAutoObj','chunkAutoObj',$queueData);
                    }
                }
            }
        }
        $page++;
        Cache::set('chunk_obj_page',$page);
        $this->index();
        //2、查询广告账户（有权限的），设置的百分比
        //3、判断广告账户下当天 斑马的次数/总操作次数  是否大于等于设置的百分比
        //4、查询广告账户下托管的，非删除，完成状态的广告账户
        //4、写入队列，随机时间进行延迟处理


    }


}