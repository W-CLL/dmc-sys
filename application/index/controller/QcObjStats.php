<?php

namespace app\index\controller;

use app\admin\model\Company;
use app\admin\model\CompanySetting;
use app\common\controller\Frontend;
use app\common\model\QcObjOptStats;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use think\Cache;
use think\Db;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\Exception;
use think\exception\DbException;

class QcObjStats extends Frontend
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';
    protected $layout = '';

    public function _initialize()
    {
        parent::_initialize();
    }

    public function index()
    {

//        die;
//        for($i=1;$i<=20;$i++){
//            $this->getObjData();
//            sleep(1);
//        }
        dump(explode(',', Cache::get('qc_ad_ids', 0)));
        die;
    }

    /**
     * 获取并统计计划数据
     * @return void
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws DbException
     */
    public function getObjData()
    {
//        Cache::rm('qc_ad_ids');
//        die;

        $access_token = Cache::get("qc_access_token");
        $qcObjStatsModel = new \app\admin\model\QcObjStats();
        $existedIds = $qcObjStatsModel->column('obj_id');
        $existedAdIds = Cache::get('qc_ad_ids', 0);
        $result = Db::name('qc_obj')
            ->field('advertiser_id, GROUP_CONCAT(object_id) AS obj_ids')
            ->where(['status' => 1, 'object_id' => ['not in', $existedIds]])
//            ->where(['advertiser_id'=>['not in',explode(',',$existedAdIds)]])
            ->group('advertiser_id')
//            ->limit(1)
            ->select();
        dump($result);
        die;
        if (empty($result)) {
            echo "已全部处理完";
            die;
        }
        $objIds = explode(',', $result[0]['obj_ids']);
        $objIds = array_map('intval', $objIds);
        $objCount = count($objIds);
        $advId = $result[0]['advertiser_id'];


        $guzzleClient = new Client();
        $requests = function ($total_page) use ($access_token, $advId, $objIds) {
            $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/report/ad/get/";
            $headers = [
                'Access-Token' => $access_token,
                'Content-Type' => 'application/json'
            ];
            for ($i = 1; $i <= $total_page; $i++) {
                $params = array(
                    "advertiser_id" => (int)$advId,
                    "start_date" => date('Y-m-d', strtotime('-30 days')),
                    "end_date" => date('Y-m-d', time()),
                    "page" => $i,
                    "fields" => ['stat_cost'],
                    "page_size" => 100,
                    "order_type" => "DESC",
                    "filtering" => array(
                        "ad_ids" => $objIds,
                        "marketing_goal" => 'ALL'
                    )
                );
                yield new Request('GET', $url, $headers, json_encode($params));
            }

        };
        $total_page = ceil(max(1, $objCount / 100));
        $insertData = [];
        $is_empty = false;
        $pool = new Pool($guzzleClient, $requests($total_page), [
            'concurrency' => 20, // 并发请求数量
            'fulfilled' => function ($response, $index) use (&$insertData, &$is_empty) {
                $resData = json_decode($response->getBody()->getContents(), true);
                if (!empty($resData)) {
                    if ($resData['code'] == 0 && !empty($resData['data']['list'])) {
                        foreach ($resData['data']['list'] as $item) {
                            $insertData[] = [
                                'obj_id' => $item['ad_id'],
                                'day_before_total_cost' => $item['stat_cost'],
                                'total_cost' => $item['stat_cost'],
                                'create_time' => time(),
                            ];
                        }
                    } else {
                        $is_empty = true;
                    }
                }
            },
            'rejected' => function ($reason, $index) {
                // 请求失败时的回调
                echo "Request {$index} failed: " . $reason->getMessage() . "\n";
            },
        ]);
// 发送请求并等待所有请求完成
        $promise = $pool->promise();
        $promise->wait();
        if (!empty($insertData)) {
            $res = $qcObjStatsModel->saveAll($insertData);
            if (!$res) {
                dump($advId . '插入失败');
            } else {
                Cache::set('qc_ad_ids', $existedAdIds . ',' . $advId);
                dump($advId . '下的全部计划插入成功');
            }
//            die;
        } elseif ($is_empty) {
            Cache::set('qc_ad_ids', $existedAdIds . ',' . $advId);
            dump($advId . '数据为空');
        } else {
            dump($advId . '获取出错');
        }
    }


    public function forExcuteStats()
    {
//        Cache::rm('qc_obj_ids');
//        die;
        for ($i = 0; $i < 5; $i++) {
            $this->statsObjOptNum();
        }
        $existedIds = Cache::get('qc_obj_ids', 0);
        dump(count(explode(',', $existedIds)));
        die;
    }

    /**
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     * @throws Exception
     * @throws \Exception
     */
    public function statsObjOptNum()
    {
        $existedIds = Cache::get('qc_obj_ids', 0);
        $result = Db::name('qc_obj')
            ->field('advertiser_id,object_id')
            ->where(['object_id' => ['not in', $existedIds]])
            ->order('id desc')
            ->limit(1000)
            ->select();
        if (empty($result)) {
            echo "全部处理完了";
            die;
        }
        $insertList = [];
        $operatorList = Db::name('ad_operator')->column('name');
        foreach ($result as $item) {
            $insertData['obj_id'] = $item['object_id'];
            $insertData['adv_id'] = $item['advertiser_id'];
            $insertData['total_opt_num'] = Db::name('plan_opt_log')->where([
                    'opt_time'=>['>', strtotime('-30 days')],
                    'obj_id' => $item['object_id']
                ])
                ->count();
            $insertData['company_num'] = Db::name('plan_opt_log')->where([
                    'opt_time'=> ['>', strtotime('-30 days')],
                    'obj_id' => $item['object_id'],
                    'operator' => ['in', $operatorList]
                ])
                ->count();

            $insertList[] = $insertData;
        }
        $statsModel = new QcObjOptStats();
        if (!empty($insertList)) {
            $statsModel->saveAll($insertList);
            Cache::set('qc_obj_ids', $existedIds . ',' . implode(',', array_column((array)$result, 'object_id')));
        } else {
            dump('数据为空');
            die;
        }
    }

    public function getGtZeroData(){
        $statsModel = new QcObjOptStats();
        $gtZeroData = $statsModel
            ->where([
                'total_opt_num'=>['gt',0],
//                'set_percentage'=>['gt',0],
                'is_normal'=>1,
                'status'=>0
            ])
            ->where(function($query) {
                $query->whereRaw('(company_num / total_opt_num) * 100 < set_percentage');
            })
            ->select();
        $gtZeroData = $statsModel->where('total_opt_num','>',0)->select();
    }

    public function syncCompanyNameToCompanySetting(){
        $companyModel = new Company();
        $companyNameList = $companyModel->group('company_name')->column('company_name');
        $insert = [];
        foreach ($companyNameList as $item){
            $insert[] = [
                'company_name'=>  $item,
                'percentage'=>10
            ];
        }
//        dump($insert);
//        die;
        if(!empty($insert)){
            $companySettingModel = new CompanySetting();
            $res = $companySettingModel->saveAll($insert);
           if($res){
               echo "已全部处理";
           }

        }
        die;
    }
}