<?php

namespace app\api\controller;

use app\admin\model\CompanySetting;
use app\admin\model\QcObj as ObjModel;
use app\common\controller\Api;
use app\common\controller\TaskDistributor;
use app\common\model\QcAdvDayCost;
use app\common\model\Queue;
use GuzzleHttp\Exception\GuzzleException;
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

    private $logFile = null; // 日志文件路径

    /**
     * 初始化日志文件
     */
    private function initLogFile($taskType = 'standard')
    {
        $logDir = APP_PATH . '../logs/';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $timestamp = date('Y-m-d_H-i-s');
        $this->logFile = $logDir . "task_{$taskType}_{$timestamp}.log";

        // 写入任务开始信息
        $this->writeLog("=== 标准推广任务开始 ===");
        $this->writeLog("任务类型: {$taskType}");
        $this->writeLog("开始时间: " . date('Y-m-d H:i:s'));
        $this->writeLog("==================");
    }

    /**
     * 写入日志
     */
    private function writeLog($message)
    {
        if ($this->logFile) {
            $timestamp = date('Y-m-d H:i:s');
            file_put_contents($this->logFile, "[{$timestamp}] {$message}\n", FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws GuzzleException
     */
    public function index($user_name = '', $is_special = false)
    {
        // 初始化日志文件
        $this->initLogFile('standard_' . $user_name);

        // 防重复执行检查
        if (!$this->checkExecutionPermission($user_name, 'standard')) {
            echo "处理完成了"; // 返回标准完成信息
            return;
        }

        $page = Cache::get('chunk_obj_page', 1);
        if (!$is_special && $page == 1) {
            checkQueueExecutionOver('autoUpdateObjName','chunkAutoObj');
        }
        $redis = Cache::store('redis');
        $type = "normal";
        list($advList, $notWhiteCom) = $this->getAdvList($page, $user_name, $is_special);
        list($start_time, $end_time) = getPersonStartTime($user_name);

        if (empty($advList)) {
            $this->finishStandardProcessing($redis, $type);
            return;
        }

        $list = sendApiRes(API_BASE_URL."/getOptCountCollectionApi/", [
            'start_time' => $start_time,
            'end_time' => $end_time,
            'adv_list' => $advList
        ], 'POST')['data'];
        //获取本月的操作日志
//        $list = $this->getOptCountCollection($comModel, $start_time, $end_time, $advList);

        if (empty($list)) {
            $this->finishStandardProcessing($redis, $type);
            return;
        }

        // 使用TaskDistributor进行任务调度
        $distributor = new TaskDistributor();
        $distributor->delayMin = 8;
        $distributor->delayMax = 20;
        $distributor->setMaxConsecutiveTasks(3); // 标准推广更保守，连续任务数更少
        $distributor->setJob('【标准】账户_计划', 'app\job\AutoUpdateObjName', 'autoUpdateObjName');

        foreach ($list as $item) {
            // 使用动态计算方法
            $needComNum = $this->calculateStandardNeedComNum($item, $notWhiteCom);
            $objList = sendApiRes(API_BASE_URL."/getObjListApi/", [
                $item['advertiser_id'], $needComNum
            ])['data'];

            if (!$objList) {
                continue;
            }

            $count = count($objList);
            $totalOps = $needComNum;
            $perObjOps = ceil($totalOps / $count);

            // 跳过无效的任务
            if ($perObjOps <= 0) {
                $this->writeLog("跳过广告主 {$item['advertiser_id']}，操作次数为 {$perObjOps}，需要操作数为 {$needComNum}");
                continue;
            }

            foreach ($objList as $objId) {
                $distributor->addTask($item['advertiser_id'], $objId, $perObjOps);
            }
        }

        // 处理完所有数据后，统一dispatch
        $taskCount = $distributor->dispatch();
        $this->writeLog("任务分发完成，共生成 {$taskCount} 个任务");
        if ($is_special) {
            $this->finishStandardProcessing($redis, $type);
            return;
        }
        $page++;
        Cache::set('chunk_obj_page', $page);
        $this->index($user_name);
    }

    /**
     * 获取公司设置
     * @param $page
     * @param  $user_name
     * @param $is_special
     * @return array
     */
    public function getAdvList($page, $user_name, $is_special): array
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
        //获取非白名单公司
        if ($charge_name) {
            $ownerCompanyNames = sendApiRes(API_BASE_URL."/ownerCompanyNamesApi/", [
                $charge_name
            ])['data'];
            isset($ownerCompanyNames['status']) ?die($ownerCompanyNames['msg']):'';
            $name_where['company_name'] = ['in', $ownerCompanyNames];
        }
        $name_where['is_white'] = 0;
        $notWhiteCom = sendApiRes(API_BASE_URL."/notWhiteComApi/", $name_where, 'POST')['data'];
        isset($notWhiteCom['status']) ?die($notWhiteCom['msg']):'';
        if (!$is_special) {
            //提取公司名
            $companyNames = array_keys($notWhiteCom);
            $adv_list = sendApiRes(API_BASE_URL."/getAdvListApi/", [
                "company_name" => $companyNames,
                "page" => $page,
                "charge_name" => $charge_name,
                "min_cost" => "0",
                "limit" => 1000,
                "type" => 1
            ], 'POST')['data'];
            isset($adv_list['status']) ?die($adv_list['msg']):'';
            $adv_ids = array_column((array)$adv_list, 'adv_id');
        } else {
            $adv_ids = $this->handlerSpecialAdvIds($user_name);
        }

        return [$adv_ids, $notWhiteCom];
    }

    protected function handlerSpecialAdvIds($user_name): array
    {
        switch ($user_name) {

            case 'zqp':
                return [1798900300552202, 1801531189762058];
            case 'mmc':
                return [1758512218442765, 1772743741289549, 1773547895695364, 1808438118201411];
            case 'tyx':
                return [1818881230249995, 1824554226216235,
                    1779533087983680, 1807807620930633, 1823186604478618, 1777732145496080,
                    1826838617832651, 1818880672572507, 1796456560379914, 1823934842909353,
                    1823187059373466, 1814842941111385, 1823187119100122, 1825270447482067,
                    1777718934256704, 1823839192925242, 1824104708264379, 1804093297773577,
                    1823661062656266, 1823299972528266, 1823935039296793, 1820235995114505,
                    1816860832330761, 1825191216068683, 1825098629073929, 1803549847800964,
                    1823661033728089, 1823661005941898, 1801931161559099];
            case 'cxy':
                return [1823660724104201, 1732688473098254, 1772397939168263, 1804786464188426,
                    1810237662826522, 1809256736272459, 1809252248028363, 1813782044932153,
                    1807469019857036, 1796584296189083, 1815514481765580, 1802340657956873,
                    1819213529969162, 1811711435233290, 1826013248757771, 1809255158699163,
                    1772397751002120, 1772398215539726, 1759237466632205, 1764387173323848,
                    1801556161640457, 1782499507917914, 1796224122811402, 1795097317258249,
                    1798288325942298, 1818139722159435, 1795097228606473, 1796224670681257
                ];
            case 'wyc':
                return [
                    1824009871301770, 1788398571030528, 1768645243407453, 1771126890361864, 1780779287451722, 1766313160488974,
                    1782778829719556, 1820556783165883, 1788873143071812, 1782528755600459, 1788589466283081, 1805241831726148
                ];
            default:
                return [];
        }

    }

    /**
     * 获取异常账户
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
                'obj_status' => ['not in', ['DELETE', 'FROZEN']],
                'lab_ad_type' => "LAB_AD",
                'opt_status' => ['not in', ['DELETE', 'FROZEN']],
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
//        $redis = Cache::store('redis');
        dump(Cache::rm('chunk_obj_global_page'));
        dump(Cache::rm('chunk_obj_page'));
        dump(Cache::rm(self::CACHE_KEY));
        dump(Cache::rm(self::GLOBAL_CACHE_KEY));
        echo "全部清理了";
        die;
    }

    /**
     * 计算标准推广需要的操作次数（使用动态计算策略）
     */
    private function calculateStandardNeedComNum($item, $notWhiteCom)
    {
        $totalNum = (int)$item['total_num'];
        $companyNum = (int)$item['company_num'];
        $cusNum = $totalNum - $companyNum;

        // 从配置文件获取参数
        $config = include APP_PATH . 'config/dynamic_ratio_config.php';
        $standardConfig = $config['standard'];
        $strategyConfig = $config['new_strategy'] ?? [];

        // 新策略参数
        $normalThreshold = $strategyConfig['normal_threshold'] ?? 200;           // 正常追加阈值
        $dynamicThreshold = $strategyConfig['dynamic_threshold'] ?? 400;         // 动态计算阈值
        $activityThreshold = $strategyConfig['activity_threshold'] ?? 600;       // 活跃度阈值
        $minSpaceThreshold = $strategyConfig['min_space_threshold'] ?? 10;       // 最小操作空间阈值

        if ($cusNum <= 0) {
            return 0; // 没有客户数据，不需要操作
        }

        // 获取目标比例
        $targetPercentage = $notWhiteCom[$item['company_name']] ?? 30;
        if (!isset($notWhiteCom[$item['company_name']])) {
            $this->writeLog("⚠️ 标准推广广告主 {$item['advertiser_id']} 公司 {$item['company_name']} 未配置目标比例，使用默认30%");
        }

        // 计算当前比例和操作空间
        $currentPercentage = ($companyNum / $cusNum) * 100;
        $operatingSpace = $activityThreshold - $currentPercentage;

        // 🎯 新策略分层判断
        if ($currentPercentage > $activityThreshold) {
            // 超过600%：完全不操作
            $this->writeLog("🚫 标准推广广告主 {$item['advertiser_id']} 比例超过{$activityThreshold}%（当前{$currentPercentage}%），完全停止操作");
            return 0;
        } elseif ($currentPercentage > $dynamicThreshold) {
            // 400%-600%：只保持每天活跃度（标准推广更保守）
            $activeOps = $this->getStandardMinActiveOperations($standardConfig);
            $this->writeLog("🔄 标准推广广告主 {$item['advertiser_id']} 比例{$dynamicThreshold}%-{$activityThreshold}%（当前{$currentPercentage}%），保持活跃度 {$activeOps} 次");
            return $activeOps;
        } elseif ($currentPercentage > $normalThreshold) {
            // 200%-400%：检查操作空间（标准推广更严格）
            $standardMinSpaceThreshold = $minSpaceThreshold + 5; // 标准推广要求更多操作空间
            if ($operatingSpace <= $standardMinSpaceThreshold) {
                $this->writeLog("⚠️ 标准推广广告主 {$item['advertiser_id']} 操作空间不足{$standardMinSpaceThreshold}%（剩余{$operatingSpace}%），停止操作");
                return 0;
            } else {
                // 有足够操作空间，进行动态计算
                return $this->calculateStandardDynamicAddAmount($item, $cusNum, $companyNum, $targetPercentage, $activityThreshold, $standardConfig);
            }
        } else {
            // 小于200%：正常追加
            $actualComNum = $cusNum + ($cusNum * $targetPercentage / 100);
            $needComNum = $companyNum > 0 ? $actualComNum - $companyNum : $actualComNum;
            $this->writeLog("✅ 标准推广广告主 {$item['advertiser_id']} 比例正常（{$currentPercentage}%），正常追加 " . ceil($needComNum) . " 次");
            return (int)ceil($needComNum);
        }
    }

    /**
     * 获取标准推广最小活跃度操作次数
     */
    private function getStandardMinActiveOperations($standardConfig)
    {
        $minOps = $standardConfig['min_active_operations'] ?? 1;
        $maxOps = $standardConfig['max_active_operations'] ?? 12;
        return rand($minOps, $maxOps);
    }

    /**
     * 标准推广动态计算添加量（比全域推广更保守）
     */
    private function calculateStandardDynamicAddAmount($item, $cusNum, $companyNum, $targetPercentage, $maxPercentage, $standardConfig)
    {
        // 从配置中获取参数
        $minDailyAdd = $standardConfig['min_daily_add'];
        $maxDailyAdd = $standardConfig['max_daily_add'];

        // 计算当前比例
        $currentPercentage = ($companyNum / $cusNum) * 100;

        // 计算距离最大比例还有多少空间
        $remainingPercentage = $maxPercentage - $currentPercentage;

        if ($remainingPercentage <= 0) {
            return 0; // 已经达到上限
        }

        // 计算理想的添加量
        $idealComNum = $cusNum + ($cusNum * $targetPercentage / 100);
        $idealAddAmount = $idealComNum - $companyNum;

        // 计算最大可添加量（不能超过剩余空间）
        $maxCanAdd = ($remainingPercentage / 100) * $cusNum;

        // 动态每日限制（标准推广更保守）
        $conservativeFactor = $standardConfig['conservative_factor'] ?? 0.9;
        $dynamicDailyAdd = $minDailyAdd + (($maxDailyAdd - $minDailyAdd) * ($remainingPercentage / 100)) * $conservativeFactor;

        // 取最小值作为最终添加量
        $finalAddAmount = min($idealAddAmount, $maxCanAdd, $dynamicDailyAdd);
        $finalAddAmount = max(0, $finalAddAmount); // 确保不为负数

        // 记录详细计算过程到日志
        $this->writeLog("标准推广广告主 {$item['advertiser_id']} 动态计算详情:");
        $this->writeLog("- 当前比例: " . round($currentPercentage, 2) . "%");
        $this->writeLog("- 剩余空间: " . round($remainingPercentage, 2) . "%");
        $this->writeLog("- 目标比例: {$targetPercentage}%");
        $this->writeLog("- 客户数: {$cusNum}, 公司数: {$companyNum}");
        $this->writeLog("- 最大可添加: " . round($maxCanAdd, 2));
        $this->writeLog("- 理想添加量: " . round($idealAddAmount, 2));
        $this->writeLog("- 动态每日限制: " . round($dynamicDailyAdd, 2));
        $this->writeLog("- 保守系数: {$conservativeFactor}");
        $this->writeLog("- 最终添加: " . (int)ceil($finalAddAmount) . "个");
        $this->writeLog("---");

        return (int)ceil($finalAddAmount);
    }

    /**
     * 完成标准推广处理，清理缓存
     */
    private function finishStandardProcessing($redis, $type)
    {
        Cache::rm('chunk_obj_page');
        $redis->rm(self::CACHE_KEY . '_over');
        $redis->rm('company_setting_list_' . $type);
        Cache::set(self::CACHE_KEY, strtotime(date('Y-m-d')));

        // 写入任务结束信息到日志
        $this->writeLog("=== 标准推广任务结束 ===");
        $this->writeLog("结束时间: " . date('Y-m-d H:i:s'));
        $this->writeLog("==================");

        // 只在页面显示简单的完成信息
        echo "处理完成了";
    }

    /**
     * 检查执行权限，防止重复执行
     */
    private function checkExecutionPermission($user_name, $taskType)
    {
        $config = include APP_PATH . 'config/dynamic_ratio_config.php';
        $executionConfig = $config['execution_control'] ?? [];

        // 检查是否启用执行控制
        if (!($executionConfig['enable'] ?? true)) {
            return true; // 如果禁用控制，直接允许执行
        }

        $redis = Cache::store('redis');
        $today = date('Y-m-d');
        $currentHour = (int)date('H');

        // 1. 检查今日是否已执行过
        $dailyKey = "task_executed_{$taskType}_{$user_name}_{$today}";
        $executedToday = $redis->get($dailyKey);

        if ($executedToday) {
            $this->writeLog("⚠️ 今日已执行过标准推广任务，跳过执行");
            return false;
        }

        // 2. 检查最近执行时间间隔
        $lastExecutionKey = "last_execution_{$taskType}_{$user_name}";
        $lastExecution = $redis->get($lastExecutionKey);
        $minInterval = $executionConfig['min_interval_hours'] ?? 6; // 默认6小时间隔

        if ($lastExecution && (time() - $lastExecution) < ($minInterval * 3600)) {
            $nextTime = date('H:i', $lastExecution + ($minInterval * 3600));
            $this->writeLog("⚠️ 距离上次执行时间不足{$minInterval}小时，下次可执行时间：{$nextTime}");
            return false;
        }

        // 3. 检查饭点时间限制
        $currentMealPeriod = $this->getCurrentMealPeriod();
        if ($currentMealPeriod) {
            $mealLimit = $executionConfig['lunch_time_limit'] ?? 10;
            $mealKey = "meal_tasks_{$today}";
            $mealTasks = (int)$redis->get($mealKey);

            if ($mealTasks >= $mealLimit) {
                $mealName = $currentMealPeriod['name'] ?? '饭点时间';
                $this->writeLog("⚠️ {$mealName}任务数量已达上限({$mealLimit}个)，跳过执行");
                return false;
            }
        }

        // 4. 记录执行状态
        $redis->set($dailyKey, time(), 86400); // 24小时过期
        $redis->set($lastExecutionKey, time(), 86400 * 7); // 7天过期

        $this->writeLog("✅ 执行权限检查通过，开始执行标准推广任务");
        return true;
    }

    /**
     * 检查当前是否在饭点时间，返回饭点时间段信息
     */
    private function getCurrentMealPeriod()
    {
        $config = include APP_PATH . 'config/dynamic_ratio_config.php';
        $mealConfig = $config['meal_time_control'] ?? [];

        if (!($mealConfig['enable'] ?? true)) {
            return null;
        }

        $currentHour = (int)date('H');
        $currentMinute = (int)date('i');
        $currentTime = $currentHour * 60 + $currentMinute; // 转换为分钟数便于比较

        $timePeriods = $mealConfig['time_periods'] ?? [];

        foreach ($timePeriods as $periodKey => $period) {
            if (!($period['enabled'] ?? true)) {
                continue; // 跳过未启用的时间段
            }

            $startTime = ($period['start_hour'] ?? 0) * 60 + ($period['start_minute'] ?? 0);
            $endTime = ($period['end_hour'] ?? 0) * 60 + ($period['end_minute'] ?? 0);

            if ($currentTime >= $startTime && $currentTime <= $endTime) {
                return array_merge($period, ['key' => $periodKey]);
            }
        }

        return null; // 不在任何饭点时间内
    }

}