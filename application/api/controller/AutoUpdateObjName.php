<?php

namespace app\api\controller;

use app\admin\model\Company;
use app\admin\model\CompanySetting;
use app\admin\model\QcObj as ObjModel;
use app\common\controller\Api;
use app\common\model\QcAdvDayCost;
use app\common\model\Queue;
use think\Cache;
use think\Db;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\DbException;


/**
 * 判断百分比加入队列处理
 */
class AutoUpdateObjName extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';
    const GLOBAL_CACHE_KEY = 'global_handler_key';

    const CACHE_KEY = 'handler_key';

    public function index($user_name = '')
    {
        $this->checkQueueExecutionOver(self::CACHE_KEY);
//        $this->checkTimestamp(self::CACHE_KEY);
        $page = Cache::get('chunk_obj_page', 1);
        $redis = Cache::store('redis');
        list($advList, $notWhiteCom) = $this->getAdvList($page, $redis, $type = 'normal', $user_name);
        $comModel = new Company();
        $currentDate = new \DateTime();
        $currentDate->modify('-10 days');
        $end_time = strtotime(date('Y-m-d', time()));
        //获取本月的操作次数
//        $start_time = strtotime(date('Y-m-01', time()));
        //获取昨天的操作次数
        $start_time = $currentDate->getTimestamp();

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
            $redis->rm(self::CACHE_KEY.'_over');
            $redis->rm('company_setting_list_' . $type);
            Cache::set(self::CACHE_KEY, strtotime(date('Y-m-d')));
            die;
        }
        $queue = new Queue();
        $objModel = new ObjModel();
        $needComNum = 0;
        foreach ($list as $item) {
            $totalNum = (int)$item['total_num'];
            $companyNum = (int)$item['company_num'];
            $cusNum = $totalNum - $companyNum;
            if ($cusNum <= 0) {//客户操作次数小于等于零直接跳过
                continue;
            }
            if ($companyNum > 0) { //判断公司操作次数如果已经大于等于设置的百分比了就跳过
                $currPer = ($companyNum / $cusNum) * 100;
                if ($currPer >= $notWhiteCom[$item['company_name']]) {
                    continue;
                }
            }
            $actualComNum = $cusNum + ($cusNum * ($notWhiteCom[$item['company_name']] / 100));
            if ($companyNum > 0) {//公司操作大于0
//                if ($companyNum < $cusNum) { //公司<客户
                    $needComNum = $actualComNum - $companyNum;
//                }
            } else {
                $needComNum = $actualComNum;
            }
            $needComNum = (int)ceil($needComNum);
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

    /**
     * 获取公司设置
     * @param $page
     * @param $redis
     * @param string $type
     * @param string $user_name
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    protected function getAdvList($page, $redis, string $type = 'normal', string $user_name = ''): array
    {
        $operator = [
            'zqp' => "张秋萍",
            'mmc' => "莫美春",
            'cxy' => "陈秀玉",
            'tyx' => "罗文静",
            'wyc' => "王倚澄",
        ];
        $charge_name = '';
        if ($user_name) {
            if (!$operator[$user_name]) {
                echo "名字不存在";
                die;
            } else {
                $charge_name = $operator[$user_name];
            }
        }
        //1、先查询不是白名单的 公司下的广告账户
        $comSettingModel = new CompanySetting();
        $comCostModel = new QcAdvDayCost();
        $companyModel = new Company();
//        if ($redis->get('company_setting_list_' . $type)) {
//            $notWhiteCom = unserialize($redis->get('company_setting_list_' . $type));
//        } else {
        //获取非白名单公司
        if ($charge_name) {
            $ownerCompanyNames = $companyModel->where(['kahuna' => ['like', "%" . $charge_name . "%"]])->column('company_name');
            $name_where['company_name'] = ['in', $ownerCompanyNames];
        }
        $name_where['is_white'] = 0;

        $notWhiteCom = $comSettingModel->where($name_where)->column('percentage', 'company_name');
        //提取公司名
        $companyNames = array_keys($notWhiteCom);
        //获取公司下的广告主账户，每页1000条
        $adv_list = $comCostModel
            ->alias('cc')
            ->join('company com', 'cc.adv_id=com.advertiser_id', 'left')
            ->where(['com.company_name' => ['in', $companyNames], 'cc.cost_date' => ['between', [strtotime(date('Y-m-01')), time()]]])
            ->where(function ($query) use ($charge_name) {
                if ($charge_name) {
                    $query->where(['com.kahuna' => ['like', "%" . $charge_name . "%"]]);
                }
            })
            ->field('cc.*,sum(cc.cost) as mon_cost')
            ->group('cc.adv_id')
            ->order('mon_cost desc')
            ->page($page)
            ->limit(1000)
//                ->fetchSql(true)
            ->select();
//                ->column('cc.adv_id');
        $adv_ids = array_column((array)$adv_list, 'adv_id');
        return [$adv_ids, $notWhiteCom];
    }

    /**
     * 分割当天全域消耗下的广告计划
     * @param string $user_name
     * @return void
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws DataNotFoundException
     */
    public function chunkGlobalComAdv(string $user_name = '')
    {
        $this->checkQueueExecutionOver(self::GLOBAL_CACHE_KEY); //
//        $this->checkTimestamp(self::GLOBAL_CACHE_KEY);
        $page = Cache::get('chunk_obj_global_page', 1);
        $redis = Cache::store('redis');
        list($advList, $notWhiteCom) = $this->getAdvList($page, $redis, $type = 'global', $user_name);
        $cost_model = new QcAdvDayCost();
        $currentDate = new \DateTime();
        $currentDate->modify('yesterday');
        //获取昨天的全域消耗
        $adv_list = $cost_model->where([
            'adv_id' => ['in', $advList],
//            'cost_date' => strtotime('2024-12-14'),
            'cost_date' => $currentDate->getTimestamp(),
            'type' => 2,//全域
        ])->field('*,SUM(cost) as day_cost ')
            ->group('adv_id')
            ->select();

        if (empty($adv_list)) {
            echo "全部处理完了";
            Cache::rm('chunk_obj_global_page');
            $redis->rm(self::GLOBAL_CACHE_KEY.'_over');
            $redis->rm('company_setting_list_' . $type);
            Cache::set(self::GLOBAL_CACHE_KEY, strtotime(date('Y-m-d')));
            die;
        }

        $queue = new Queue();
        $objModel = new ObjModel();
        foreach ($adv_list as $item) {
            if ($item['day_cost'] > 0) {
                $need_num = $this->getDailyOperationLimit($item['day_cost']);
                $list = $objModel->where([
                    'obj_status' => ['not in', ['DELETE', "TIME_DONE", 'FROZEN']],
                    'lab_ad_type' => "LAB_AD",
                    'opt_status' => ['not in', ['DELETE', "TIME_DONE", 'FROZEN']],
                    'adv_id' => $item['adv_id']
                ])
                    ->field('obj_id,adv_id')
                    ->limit($need_num)
                    ->column('obj_id');
                if (!$list) {
                    continue;
                }
                $queueData = [
                    'need_opt_num' => $need_num,
                    'adv_id' => $item['adv_id'],
                    'obj_list' => $list
                ];
                $queue->addQueue('全域分块处理自动化', 'app\job\ChunkAutoObj', 'chunkAutoObj', $queueData);
            }
        }
        $page++;
        Cache::set('chunk_obj_global_page', $page);
        $this->chunkGlobalComAdv($user_name);
    }

    /**
     * 获取异常账户
     *
     */
    public function getAbnormalAccount()
    {
        $qcAdvModel = new QcAdvDayCost();
        $qc_opt_log = new \app\admin\model\QcObjOptLog();
        $qc_company_setting = new CompanySetting();
        $start_time = strtotime(date("Y-m-01"));
        $end_time = time();
        $list = $qcAdvModel
            ->where(['cost_date' => ['between', [$start_time, $end_time]]])
            ->field("adv_id,SUM(cost) AS mon_cost")
            ->group('adv_id')
            ->having('mon_cost <= 100000')
            ->select();
        $currentDayStart = strtotime('today');
        $queue = new Queue();
        $objModel = new ObjModel();
        $operator = Db::name('ad_operator')->where(['status' => 1])->column('name');
        foreach ($list as $item) {
            $opt_counts = $qc_opt_log->where([
                'adv_id' => $item['adv_id'],
                'operator' => ['NOT IN', $operator]
            ])
                ->field("
    adv_id,
    SUM(CASE WHEN opt_time BETWEEN {$start_time} AND " . time() . " THEN 1 ELSE 0 END) AS month_cus_num,
    SUM(CASE WHEN opt_time BETWEEN {$currentDayStart} AND " . time() . " THEN 1 ELSE 0 END) AS day_cus_num
")
//                ->fetchSql(true)
//                ->select();
                ->find();

// 改用JOIN查询（更高效）
            $percentage = $qc_company_setting
                ->alias('s')
                ->join('company c', 's.company_name = c.company_name')
                ->where('c.advertiser_id', $item['adv_id'])
                ->value('s.percentage');
            //1000，2000到时候改成设置
            if ($opt_counts['day_cus_num'] >= 1000) {
                $adv_list[] = $item['adv_id'];
                $needComNum = $this->getNeedOptCount($opt_counts['day_cus_num'], $percentage);
            } elseif ($opt_counts['month_cus_num'] >= 3000) {
                $adv_list[] = $item['adv_id'];
                $needComNum = $this->getNeedOptCount($opt_counts['month_cus_num'], $percentage);
            } else {
                continue;
            }
            $list = $objModel->where([
                'obj_status' => ['not in', ['DELETE', "TIME_DONE", 'FROZEN']],
                'lab_ad_type' => "LAB_AD",
                'opt_status' => ['not in', ['DELETE', "TIME_DONE", 'FROZEN']],
                'adv_id' => $item['adv_id']
            ])
                ->field('obj_id,adv_id')
                ->limit($needComNum)
//              ->fetchSql(true)
                ->column('obj_id');
            $queueData = [
                'need_opt_num' => $needComNum,
                'adv_id' => $item['adv_id'],
                'obj_list' => $list,
                'is_abnormal' => true
            ];
            //一个广告主下的托管计划，总的操作次数，写入任务再平分次数到每个计划，进行延时修改
            $queue->addQueue('分块处理自动化', 'app\job\ChunkAutoObj', 'chunkAutoObj', $queueData);
        }
        echo "处理完成了";
    }

    /**
     * 获取需要操作多少次
     * @param $cus_count
     * @param $percentage
     * @return int
     */
    protected function getNeedOptCount($cus_count, $percentage): int
    {
        $actualComNum = $cus_count + ($cus_count * ($percentage / 100));
        $needComNum = $actualComNum;
        return (int)ceil($needComNum);
    }

    /**
     * 获取次数限制
     * 全域的 5000以下含5000的一天操作50次
     * 全域的1w以下含1w的一天操作80次
     * 全域的2w以下含2w的一天操作120次
     * 全域的3w以下含3w的一天操作160次
     * 全域的4w以下含4w的一天操作200次
     * 每叠加1万增加操作40次
     */
    protected function getDailyOperationLimit($value)
    {
        $limits = [
            5000 => 50,
            10000 => 80,
            20000 => 120,
            30000 => 160,
            40000 => 200,
        ];
        foreach ($limits as $threshold => $limit) {
            if ($value <= $threshold) {
                return $limit;
            }
        }
        // 如果超过 40000，每叠加 1 万增加 40 次
        return 200 + intval(($value - 40000) / 10000) * 40;
    }

    public function checkTimestamp($key)
    {
        // 获取当前日期的时间戳（只保留日期部分）
        $currentDateTimestamp = strtotime(date('Y-m-d'));
        // 从缓存中获取上次记录的时间戳
        $lastTimestamp = Cache::get($key);
        if ($lastTimestamp && $lastTimestamp == $currentDateTimestamp) {
            // 判断是否是同一天
            echo "今天已经处理了";
            die;
        }
    }

    public function clearPageCache()
    {
        $redis = Cache::store('redis');
        dump(Cache::rm('chunk_obj_global_page'));
        dump(Cache::rm('chunk_obj_page'));
        dump(Cache::rm(self::CACHE_KEY));
        dump(Cache::rm(self::GLOBAL_CACHE_KEY));
        dump($redis->rm('company_setting_list_global'));
        dump($redis->rm('company_setting_list_normal'));
        echo "全部清理了";
        die;
    }

    /**
     * @param $fun_name
     * @return void
     */
    public function checkQueueExecutionOver($fun_name){
//         生成时间参数
//        $todayStart = strtotime('today');
        $todayStart = strtotime(date('Y-m-01'));
        $todayEnd = strtotime('tomorrow') - 1;

        // 构造原生SQL（使用命名占位符）
        $sql = "SELECT COUNT(*) AS count 
            FROM fa_queue_record 
            WHERE (
                (queue_name = :queue1 
                AND status = 0 
                AND create_time BETWEEN :start1 AND :end1)
                OR 
                (queue_name = :queue2 
                AND status = 0 
                AND create_time BETWEEN :start2 AND :end2)
            )";

        // 执行查询（使用ThinkPHP的数据库组件）
        $count = Db::query($sql, [
            'queue1'  => 'autoUpdateObjName',
            'queue2'  => 'chunkAutoObj',
            'start1' => $todayStart,
            'end1'   => $todayEnd,
            'start2' => $todayStart,
            'end2'   => $todayEnd
        ])[0]['count'];

        if($count == 0){
            Cache::store('redis')->set(self::CACHE_KEY.'_over', 1);
            Cache::store('redis')->set(self::GLOBAL_CACHE_KEY.'_over', 1);
        }
        $canRun = Cache::store('redis')->get($fun_name.'_over');
        if($canRun != 1){
            echo "时辰未到";
            die;
        }
    }

}