<?php

namespace app\api\controller;

use app\admin\model\Company;

use app\admin\model\CompanyNameLog;
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
        // 1. 找出“不活跃”的广告主
        $res = Db::name('company')->alias('c')
            ->field('c.advertiser_id as adv_id')
            ->join('qc_adv_day_cost d',
                "c.advertiser_id = d.adv_id 
            AND d.type = 2 
            AND d.cost_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 90 DAY), '%Y%m%d')",
                'LEFT'
            )
            ->where('c.adv_status', 1)
            ->group('c.advertiser_id')
            ->having('SUM(d.cost) IS NULL OR SUM(d.cost) = 0')
            ->column('adv_id');

        try {
            if (!empty($res)) {
                // 2. 标记不活跃
                Db::name('company')
                    ->whereIn('advertiser_id', $res)
                    ->update(['is_active' => 0]);

                // 3. 标记活跃
                Db::name('company')
                    ->where('adv_status', 1)
                    ->whereNotIn('advertiser_id', $res)
                    ->update(['is_active' => 1]);
            } else {
                // 如果一个不活跃都没有，全部标活跃
                Db::name('company')
                    ->where('adv_status', 1)
                    ->update(['is_active' => 1]);
            }

        } catch (\Exception $e) {
            echo "有问题";
            dump($e->getMessage());
            die;
        }

        echo "处理账号活跃状态结束";
        die;
    }
    public function chunkObjGoodsList($start_time = '', $end_time = '')
    {
        if (!$start_time && !$end_time) {
            $start_time = date('Y-m-d', strtotime('-1 day'));
            $end_time = date('Y-m-d');
        }
        $com_model = new Company();
        $obj_model = new \app\admin\model\QcGlobalObj();
        $adv_list = $com_model->where(['adv_status' => 1,'is_active'=>1])->column('advertiser_id');
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

    public function companyNameNotice()
    {
        $now       = time();
        $startTime = $now - 180; // 最近3分钟

        $company = new CompanyNameLog();
        $list = $company->where('is_notified', 0)
            ->where('create_time', 'between', [$startTime, $now])
            ->select();

        if (empty($list)) {
            echo "无未通知记录";
            return;
        }

        // 按【旧名称→新名称】合并同一主体变更的多个账户
        $groups = [];
        foreach ($list as $item) {
            $key = $item['old_company_name'] . '||' . $item['new_company_name'];
            $groups[$key]['old']   = $item['old_company_name'] ?: '(空)';
            $groups[$key]['new']   = $item['new_company_name'] ?: '(空)';
            $groups[$key]['ids'][] = $item['advertiser_id'];
            // 取最早的变更时间作为代表时间
            if (!isset($groups[$key]['time']) || $item['create_time'] < $groups[$key]['time']) {
                $groups[$key]['time'] = $item['create_time'];
            }
        }

        // 组合通知文本
        $lines = ["【公司名称变更通知】\n共 " . count($list) . " 个账户，" . count($groups) . " 组变更：\n"];
        foreach ($groups as $group) {
            $lines[] = sprintf(
                "旧名称：%s\n新名称：%s\n账户ID：%s\n变更时间：%s\n",
                $group['old'],
                $group['new'],
                implode('、', $group['ids']),
                date('Y-m-d H:i:s', $group['time'])
            );
        }
        $msg = implode("----------\n", $lines);

        // 发送企业微信通知
        $notice = new WwNotice();
        $notice->sendMsg("dmc-company-name-log", $msg,"WuZhongJie");

        // 标记已通知
        $ids = array_column((array)$list, 'id');
        $company->whereIn('id', $ids)->update([
            'is_notified'   => 1,
            'notified_time' => $now,
        ]);

        echo "已通知 " . count($list) . " 条记录";
    }
}
