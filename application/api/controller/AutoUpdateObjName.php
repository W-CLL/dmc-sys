<?php

namespace app\api\controller;

use app\admin\model\Company;
use app\admin\model\CompanySetting;
use app\admin\model\QcObj as ObjModel;
use app\common\controller\Api;
use app\common\model\QcAdvDayCost;
use app\common\model\Queue;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use think\Cache;
use think\Collection;
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

    public function index($user_name = '', $is_special = false)
    {
        if (!$is_special) {
            $this->checkQueueExecutionOver(self::GLOBAL_CACHE_KEY); //
        }
//        $this->checkTimestamp(self::CACHE_KEY);
        $page = Cache::get('chunk_obj_page', 1);
        $redis = Cache::store('redis');
        list($advList, $notWhiteCom) = $this->getAdvList($page, $redis, $type = 'normal', $user_name);
        $comModel = new Company();
        list($start_time, $end_time) = $this->getPersonStartTime($user_name);

        $url = "http://dmc.zebranumber.cn/index.php/api/auto_update_obj_name/getOptCountCollectionApi/";
        $res = new Client(['verify' => false]);
        $params = [
            'comModel' => $comModel,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'advList' => $advList
        ];
        $rep = $res->get($url, [
            'headers' => [
                'Content-Type' => 'application/json', // 可以根据需要添加其他头信息
            ],
            'query' => $params]);

        $contents = $rep->getBody()->getContents();
        $list = json_decode($contents, true);
        //获取本月的操作日志
//        $list = $this->getOptCountCollection($comModel, $start_time, $end_time, $advList);

        if (empty($list)) {
            echo "全部处理完了";
            Cache::rm('chunk_obj_page');
            $redis->rm(self::CACHE_KEY . '_over');
            $redis->rm('company_setting_list_' . $type);
            Cache::set(self::CACHE_KEY, strtotime(date('Y-m-d')));
            die;
        }
        $queue = new Queue();
        $objModel = new ObjModel();
        foreach ($list as $item) {
            if (in_array($item['advertiser_id'], ['1816678114059481'])) {
                continue;
            }
            $totalNum = (int)$item['total_num'];
            $companyNum = (int)$item['company_num'];
            $cusNum = $totalNum - $companyNum;

            if ($cusNum <= 0 || ($companyNum > 0 && ($companyNum / $cusNum) * 100 >= ($notWhiteCom[$item['company_name']] * 2))) {
                continue;
            }

            $actualComNum = $cusNum + ($cusNum * ($notWhiteCom[$item['company_name']] / 100));
            $needComNum = $companyNum > 0 ? $actualComNum - $companyNum : $actualComNum;
            $needComNum = (int)ceil($needComNum);

            //只查托管的计划
//            $list = $objModel->where([
//                'obj_status' => ['not in', ['DELETE', "TIME_DONE", 'FROZEN']],
//                'lab_ad_type' => "LAB_AD",
//                'opt_status' => ['not in', ['DELETE', "TIME_DONE", 'FROZEN']],
//                'adv_id' => $item['advertiser_id']
//            ])
//                ->field('obj_id,adv_id')
//                ->limit($needComNum)
////              ->fetchSql(true)
//                ->column('obj_id');

            $url = "http://dmc.zebranumber.cn/index.php/api/auto_update_obj_name/getObjListApi/";
            $res = new Client(['verify' => false]);
            $params = [
                $item['advertiser_id'], $needComNum
            ];
            $rep = $res->get($url, [
                'headers' => [
                    'Content-Type' => 'application/json', // 可以根据需要添加其他头信息
                ],
                'query' => $params]);

            $contents = $rep->getBody()->getContents();
            $list = json_decode($contents, true);

            if (!$list) {
                continue;
            }
            $queueData = [
                'need_opt_num' => $needComNum,
                'adv_id' => $item['advertiser_id'],
                'obj_list' => $list
            ];
            //一个广告主下的托管计划，总的操作次数，写入任务再平分次数到每个计划，进行延时修改
            $queue->addQueue('分块处理自动化', 'app\job\ChunkAutoObj', 'chunkAutoObj', $queueData);
        }
        if ($is_special) {
            echo "全部处理完了";
            die;
        }
        $page++;
        Cache::set('chunk_obj_page', $page);
        $this->index($user_name);
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
        if (!$this->handlerSpecialAdvIds($user_name)) {
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
                ->select();
            $adv_ids = array_column((array)$adv_list, 'adv_id');
        } else {
            $adv_ids = $this->handlerSpecialAdvIds($user_name);
        }

        return [$adv_ids, $notWhiteCom];
    }

    protected function handlerSpecialAdvIds($user_name)
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
     * 分割当天全域消耗下的广告计划
     * @param string $user_name
     * @return void
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws DataNotFoundException|GuzzleException
     */
    public function chunkGlobalComAdv(string $user_name = '', $is_special = false)
    {
        if (!$is_special) {
            $this->checkQueueExecutionOver(self::GLOBAL_CACHE_KEY); //
        }

//        $this->checkTimestamp(self::GLOBAL_CACHE_KEY);
        $page = Cache::get('chunk_obj_global_page', 1);
        $redis = Cache::store('redis');
        list($advList, $notWhiteCom) = $this->getAdvList($page, $redis, $type = 'global', $user_name);
        $cost_model = new QcAdvDayCost();
        $comModel = new Company();

        list($start_time, $end_time) = $this->getPersonStartTime($user_name);
        //获取昨天的全域消耗
        $adv_list = $cost_model->where([
            'adv_id' => ['in', $advList],
            'cost_date' => ['between', [$start_time, $end_time]],
            'type' => 2,//全域
        ])->field('*,SUM(cost) as day_cost ')
            ->group('adv_id')
            ->select();

//        $count_list = $this->getOptCountCollection($comModel, $start_time, $end_time, $advList);
        $url = "http://dmc.zebranumber.cn/index.php/api/auto_update_obj_name/getOptCountCollectionApi/";
        $res = new Client(['verify' => false]);
        $params = [
            'comModel' => $comModel,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'advList' => $advList
        ];
        $rep = $res->get($url, [
            'headers' => [
                'Content-Type' => 'application/json', // 可以根据需要添加其他头信息
            ],
            'query' => $params]);

        $contents = $rep->getBody()->getContents();
        $count_list = json_decode($contents, true);

        if (empty($adv_list)) {
            echo "全部处理完了";
            Cache::rm('chunk_obj_global_page');
            $redis->rm(self::GLOBAL_CACHE_KEY . '_over');
            $redis->rm('company_setting_list_' . $type);
            Cache::set(self::GLOBAL_CACHE_KEY, strtotime(date('Y-m-d')));
            die;
        }

        $queue = new Queue();
        $objModel = new ObjModel();
        foreach ($adv_list as $item) {
            if (in_array($item['adv_id'], ['1816678114059481'])) {
                continue;
            }
            foreach ($count_list as $value) {
                if ($item['day_cost'] > 0 && $item['adv_id'] == $value['advertiser_id']) {
                    $need_num = $this->getDailyOperationLimit($item['day_cost']);
                    $companyNum = (int)$value['company_num'];

                    if ($companyNum >= $need_num) {
                        continue;
                    }

                    $need_num = $need_num - $companyNum;
//                    $list = $objModel->where([
//                        'obj_status' => ['not in', ['DELETE', "TIME_DONE", 'FROZEN']],
//                        'lab_ad_type' => "LAB_AD",
//                        'opt_status' => ['not in', ['DELETE', "TIME_DONE", 'FROZEN']],
//                        'adv_id' => $item['adv_id']
//                    ])
//                        ->field('obj_id,adv_id')
//                        ->limit($need_num)
//                        ->column('obj_id');
                    $url = "http://dmc.zebranumber.cn/index.php/api/auto_update_obj_name/getObjListApi/";
                    $res = new Client(['verify' => false]);
                    $params = [
                        $item['adv_id'], $need_num
                    ];
                    $rep = $res->get($url, [
                        'headers' => [
                            'Content-Type' => 'application/json', // 可以根据需要添加其他头信息
                        ],
                        'query' => $params]);

                    $contents = $rep->getBody()->getContents();
                    $list = json_decode($contents, true);
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
        }
        if ($is_special) {
            echo "全部处理完了";
            die;
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
    public function checkQueueExecutionOver($fun_name)
    {
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
            'queue1' => 'autoUpdateObjName',
            'queue2' => 'chunkAutoObj',
            'start1' => $todayStart,
            'end1' => $todayEnd,
            'start2' => $todayStart,
            'end2' => $todayEnd
        ])[0]['count'];

        if ($count <= 50) {
            Cache::store('redis')->set(self::CACHE_KEY . '_over', 1);
            Cache::store('redis')->set(self::GLOBAL_CACHE_KEY . '_over', 1);
        }
        $canRun = Cache::store('redis')->get($fun_name . '_over');
        if ($canRun != 1) {
            echo "时辰未到";
            die;
        }
    }

    public function delNoPermission($str = "No permission")
    {
        $queue = new Queue();
        $result = Db::table('fa_queue_record')
            ->field([
                'SUBSTRING_INDEX(SUBSTRING_INDEX(msg, \'"adv_id":"\', -1), \'"\', 1)' => 'adv_id',
                'GROUP_CONCAT(id)' => 'id_list',
                'id',
                'job_data'
            ])
            ->where('status', 2)
            ->where('msg', 'like', '%' . $str . '%')
            ->group('adv_id')
            ->select();

        foreach ($result as $value) {
            if ((string)$value['id'] == $value['id_list']) {
                continue;
            }
            $idListArray = explode(',', $value['id_list']);
            if (count($idListArray) > 1) {
                $idListArray = array_filter($idListArray, function ($item) use ($value) {
                    return $item != $value['id'];
                });
                $queue->where(['id' => ['in', $idListArray]])->delete();
            }
            $number = json_decode($value['job_data'], true)['adv_id'];
            if ($number) {
                $queue->where(['job_data' => ['like', "%" . $number . "%"], 'id' => ['neq', $value['id']]])->delete();
            }
        }
        echo "全部处理完了";
        die;
    }

    protected function getPersonStartTime($user_name = '')
    {
        $mon = date('d');
        switch ($user_name) {
            case 'mmc':
            case 'wyc':
            case 'tyx':
            case 'cxy':
            case 'zqp':
                $day_before = $mon;
                break;
            default:
                $day_before = 1;
                break;
        }
        $currentDate = new \DateTime();
        $currentDate->modify('-' . $day_before . ' days');
        $start_time = $currentDate->getTimestamp();
        $end_time = time();
        return [$start_time, $end_time];
    }

    /**
     * @param Company $comModel
     * @param $start_time
     * @param $end_time
     * @param $advList
     * @return bool|\PDOStatement|string|Collection
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function getOptCountCollection(Company $comModel, $start_time, $end_time, $advList)
    {
        return $comModel
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
    }

    public function getOptCountCollectionApi(Company $comModel, $start_time, $end_time, $advList)
    {
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

        return json($list);
    }


    public function getObjListApi($adv_id, $needComNum)
    {
        $objModel = new ObjModel();
        $list = $objModel->where([
            'obj_status' => ['not in', ['DELETE', "TIME_DONE", 'FROZEN']],
            'lab_ad_type' => "LAB_AD",
            'opt_status' => ['not in', ['DELETE', "TIME_DONE", 'FROZEN']],
            'adv_id' => $adv_id
        ])
            ->field('obj_id,adv_id')
            ->limit($needComNum)
//              ->fetchSql(true)
            ->column('obj_id');

        return json($list);
    }

}