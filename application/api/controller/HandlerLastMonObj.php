<?php

namespace app\api\controller;

use app\admin\model\Company;
use app\admin\model\QcObj as ObjModel;
use app\common\controller\Api;
use app\common\model\Queue;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use think\Cache;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\Exception;
use think\exception\DbException;


/**
 * 根据上个月的次数，平均分到每个人，每个户，平均一天要做改多少
 */
class HandlerLastMonObj extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';
    const GLOBAL_CACHE_KEY = 'last_mon_global_handler_key';//全域
    const NORMAL_CACHE_KEY = 'last_mon_normal_handler_key';//标准

    /**
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws GuzzleException
     */
    public function index($user_name)
    {
        //检查当天是不是已经执行了
        $this->checkDayIsHandler(self::NORMAL_CACHE_KEY);
        $page = Cache::get(self::NORMAL_CACHE_KEY.'_page', 1);
        //分页获取负责人的广告账号
        $advList= $this->getOwnerAdvList($page, $user_name);
        //获取上个月整月的时间范围，如当月是4月，返回则是3.1-3.31的时间戳
        list($start_time, $end_time) = $this->getTimeRange();
        if (empty($advList)) {
            echo "全部处理完了";
            Cache::rm(self::NORMAL_CACHE_KEY.'_page');
            Cache::set(self::NORMAL_CACHE_KEY, strtotime(date('Y-m-d')));
            die;
        }
        $url = "https://dmc.zebranumber.cn/index.php/api/handler_last_mon_obj/getOptCountCollectionApi/";
        $params = [
            'start_time' => $start_time,
            'end_time' => $end_time,
            'adv_list' =>$advList
        ];
       $rep =  $this->sendApiRes($url,$params,"POST");
       if(isset($rep['msg'])){
           echo $rep['msg'];
           die;
       }
       $list = $rep['data'];
        if (empty($list)) {
            echo "全部处理完了";
            Cache::rm(self::NORMAL_CACHE_KEY.'_page');
            Cache::set(self::NORMAL_CACHE_KEY, strtotime(date('Y-m-d')));
            die;
        }
        $queue = new Queue();
        foreach ($list as $item) {
            $cusNum = (int)$item['cus_num'];
            $needComNum = $cusNum / 27;
            if($needComNum < 50){
                $needComNum = $needComNum + 60;
            }else{
                $needComNum = ($needComNum * 0.8) + $needComNum;
            }
            $needComNum = (int)ceil($needComNum);

            $url = "https://dmc.zebranumber.cn/index.php/api/handler_last_mon_obj/getObjListApi/";
            $params = [
                $item['advertiser_id'], $needComNum
            ];
            $rep =  $this->sendApiRes($url,$params);
            if(isset($rep['msg'])){
                echo $rep['msg'];
                die;
            }

            if (!$rep['data']) {
                continue;
            }
            $queueData = [
                'need_opt_num' => $needComNum,
                'adv_id' => $item['advertiser_id'],
                'obj_list' => $rep['data']
            ];
            //一个广告主下的托管计划，总的操作次数，写入任务再平分次数到每个计划，进行延时修改
            $queue->addQueue('分块处理自动化', 'app\job\ChunkAutoObj', 'chunkAutoObj', $queueData);
        }
        $page++;
        Cache::set(self::NORMAL_CACHE_KEY.'_page', $page);
        $this->index($user_name);
    }


    public function getOptCountCollectionApi()
    {
        $params = input();
        $start_time = $params['start_time'];
        $end_time = $params['end_time'] ;
        $adv_list = $params['adv_list'] ;
        if (!is_array($adv_list)) {
            // 处理解码失败的情况（如返回错误信息）
            return json(['status' => -1, 'msg' => '参数格式错误']);
        }
        $comModel = new Company();
        $list = $comModel
            ->alias('adv_c')
            ->join(
                "(SELECT adv_id, COUNT(*) AS cus_num FROM fa_qc_obj_opt_log WHERE opt_time BETWEEN " . $start_time . " AND " . $end_time . " AND operator NOT IN (SELECT name FROM fa_ad_operator WHERE status = 1) GROUP BY adv_id) AS cus_stats",
                'adv_c.advertiser_id = cus_stats.adv_id',
                'left'
            )
            ->where(['adv_c.advertiser_id' => ['in', $adv_list], 'cus_stats.cus_num' => ['>', 0]])
            ->field("adv_c.*, cus_stats.cus_num")
            ->order('cus_stats.cus_num desc')
            ->select();

        return json($list);
    }



    /**
     * 获取公司设置
     * @param $page
     * @param  $user_name
     * @return array
     */
    protected function getOwnerAdvList($page, $user_name): array
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
        $companyModel = new Company();

        //获取公司下的广告主账户，每页1000条
        return $companyModel
            ->where(function ($query) use ($charge_name) {
                if ($charge_name) {
                    $query->where(['kahuna' => ['like', "%" . $charge_name . "%"]]);
                }
            })
            ->order('advertiser_id desc')
            ->page($page)
            ->limit(1000)
            ->column('advertiser_id');
    }

    public function checkDayIsHandler($key)
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

    /**
     * 获取上一个月的时间戳范围如:2-1 —— 3-1的时间戳
     * @return array
     */
    protected function getTimeRange(): array
    {
        // 获取上个月1号凌晨时间戳
        $startDate = new \DateTime('first day of last month');
        $startDate->setTime(0, 0, 0);
        $start_time = $startDate->getTimestamp();

        // 获取本月1号凌晨时间戳
        $endDate = new \DateTime('first day of this month');
        $endDate->setTime(0, 0, 0);
        $end_time = $endDate->getTimestamp();
        return [$start_time, $end_time];
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
            ->column('obj_id');

        return json($list);
    }


    /**
     * 向正式服发送请求
     * @param $url
     * @param array $params
     * @param string $method
     * @return array
     */
    private function sendApiRes($url, array $params, string $method = 'GET'): array
    {
        try {
            $client = new Client(['verify' => false]);
            if ($method === 'POST') {
                $response = $client->post($url, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $params // 自动将数组转为 JSON 字符串
                ]);
            } else {
                $response = $client->get($url, [
                    'query' => $params
                ]);
            }
            $contents = $response->getBody()->getContents();
            return ['data' => json_decode($contents, true), 'status' => 0];
        } catch (Exception | GuzzleException $e) {
            return ['data' => [], 'status' => -1, 'msg' => $e->getMessage()];
        }
    }

}