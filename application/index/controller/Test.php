<?php

namespace app\index\controller;

use app\admin\model\Company;
use app\admin\model\Company as CompanyModel;
use app\admin\model\MarkLog;
use app\admin\model\QcObjOptLog;
use app\common\controller\Frontend;
use app\common\model\QcAdvDayCost;
use app\common\model\Queue;
use app\admin\model\Tag;
use app\common\model\viral_fission\FissionDeriveMaterial;
use app\common\model\viral_fission\FissionMaterialTask;
use app\qcdatahandle\controller\ComFun;
use fast\Random;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use jlqc\FundManagement;
use qywx\Api;
use Requests;
use thiagoalessio\TesseractOCR\TesseractOCR;
use think\Cache;
use think\Db;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\Env;
use think\Exception;
use think\exception\DbException;


class Test extends Frontend
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';
    protected $layout = '';

    public function checkRedis()
    {
        $redis = Cache::store('redis_db2')->handler();
        $data = $redis->lrange('SyncCharge');
        dump($data);
        die;
    }

    public function getWorkWxUser()
    {
//        WuZhongJie
//        WangChunLong
        $res = Api::get_finance_department_member();
        $res1 = Api::send_application_messages('WangChunLong', '发了一条提醒');
        dump($res);
        dump($res1);
        die;
    }

    public function testInsertEvent()
    {
        $companyModel = new CompanyModel();
        $insertData = [
            [
                "advertiser_id" => 'test123456789',
                "company_name" => "测试公司名",
                "name" => '测试的',
                "first_industry_name" => '第一个啥名',
                "second_industry_name" => '第二个啥名',
                "salt" => Random::alnum(),
                "password" => $this->auth->getEncryptPassword("123456", Random::alnum()),
                "create_time" => time(),
                "update_time" => time(),
            ],
            [
                "advertiser_id" => 'test1234567891',
                "company_name" => '测试该公司',
                "name" => 'test',
                "first_industry_name" => '第一个地方',
                "second_industry_name" => 'dsfa',
                "salt" => Random::alnum(),
                "password" => $this->auth->getEncryptPassword("123456", Random::alnum()),
                "create_time" => time(),
                "update_time" => time(),
            ]
        ];
        $res = $companyModel->saveAll($insertData);
        dump('sadf');
        die;
    }

    public function arrayDiffRecursive($array1, $array2)
    {
        $diff = [];
        foreach ($array1 as $key => $value) {
            if (array_key_exists($key, $array2)) {
                if (is_array($value) && is_array($array2[$key])) {
                    // 递归比较子数组
                    $subDiff = $this->arrayDiffRecursive($value, $array2[$key]);
                    if (!empty($subDiff)) {
                        $diff[$key] = $subDiff;
                    }
                } elseif ($value !== $array2[$key]) {
                    // 值不同
                    $diff[$key] = $value;
                }
            } else {
                // 键不存在于第二个数组
                $diff[$key] = $value;
            }
        }
        return $diff;
    }

    public function test()
    {
        $model = new QcObjOptLog();
        $b = QcObjOptLog::scope('hello')->fetchSql(true)->select();

        $c = $model->scopeHello($model)->fetchSql(true)->select();
//        $a = $model->fetchSql(true)->select();
        $data = $model->where(['type' => 3])->fetchSql(true)->select();
        dump($b);
        dump($data);
        die;

        $token = Cache::get('qc_access_token');
//        $params = [
//            'advertiser_id' => 1795209597736064,
//            'filtering' => json_encode([
//                'marketing_goal' => 'VIDEO_PROM_GOODS',
//                'ad_create_start_date' => '2024-11-01',
//                'ad_create_end_date' => '2024-12-31',
//            ]),
//            'page_size' => 200
//        ];

        $data = FundManagement::get_ad_detail($token, 1821398283882794, 1822866533980266);

        $objDetail = $data['data'];

        $this->removeEmptyValues($objDetail);
        unset($objDetail['ad_create_time']);
        unset($objDetail['ad_modify_time']);
        $updateData = $objDetail;
        $updateData['advertiser_id'] = 1821398283882794;
        $pattern = '/\(\.\d+_\d+\.\)/';
        // 获取当前时间，精确到秒
        $current_time = "(." . date('md_His') . ".)";
        if (preg_match($pattern, $objDetail['name'])) {
            // 如果找到了匹配的内容，进行替换
            $newName = preg_replace($pattern, $current_time, $objDetail['name']);
        } else {
            // 如果没有找到匹配，拼接新的内容
            $newName = $objDetail['name'] . $current_time;
        }
        // 将提取的中文字符拼接当前时间
        $updateData['name'] = $newName;
        if (isset($updateData['delivery_setting']['schedule_time'])) {
            if (preg_match('/^0+$/', $updateData['delivery_setting']['schedule_time']) || preg_match('/^1+$/', $updateData['delivery_setting']['schedule_time'])) {
                unset($updateData['delivery_setting']['schedule_time']);
            }
        }

//            dump($updateData);
        $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/ad/update/";
        $header = array(
            'Access-Token:' . $token,
            'Content-Type:application/json',
        );
        $res = \Requests::post($url, json_encode($updateData, JSON_UNESCAPED_UNICODE), $header);

        dump($res);
        die;


        $res = FundManagement::get_ad_list($token, $params);
        foreach ($res['data']['list'] as $i => $item) {
            if ($i == 0) {
                continue;
            }
            $data = FundManagement::get_ad_detail($token, 1795209597736064, $item['ad_id']);
//            dump($data);
//            die;
//            $result = $this->generateRandomCharacter();
            $objDetail = $data['data'];
            $this->removeEmptyValues($objDetail);

            unset($objDetail['ad_create_time']);
            unset($objDetail['ad_modify_time']);
            $updateData = $objDetail;
            $updateData['advertiser_id'] = 1816613270435852;
            $pattern = '/\(\.\d+_\d+\.\)/';
            // 获取当前时间，精确到秒
            $current_time = "(." . date('md_His') . ".)";
            if (preg_match($pattern, $objDetail['name'])) {
                // 如果找到了匹配的内容，进行替换
                $newName = preg_replace($pattern, $current_time, $objDetail['name']);
            } else {
                // 如果没有找到匹配，拼接新的内容
                $newName = $objDetail['name'] . $current_time;
            }
            // 将提取的中文字符拼接当前时间
            $updateData['name'] = $newName;
            if (isset($updateData['delivery_setting']['schedule_time'])) {
                if (preg_match('/^0+$/', $updateData['delivery_setting']['schedule_time']) || preg_match('/^1+$/', $updateData['delivery_setting']['schedule_time'])) {
                    unset($updateData['delivery_setting']['schedule_time']);
                }
            }
//            dump($updateData);
            $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/ad/update/";
            $header = array(
                'Access-Token:' . $token,
                'Content-Type:application/json',
            );
            $res = \Requests::post($url, json_encode($updateData, JSON_UNESCAPED_UNICODE), $header);
            dump($res);
            die;
        }


//        dump($res);
        die;
//        dump($res);
//        die;
        $comFun = new ComFun();
        $queue = new Queue();
        $isPushed = \think\Queue::later(2, 'asd', ['sd' => 'sdf'], '测试');
        dump($isPushed);
        die;
        dump($comFun->getSearchDate(2));
        dump($comFun->getSearchDate(1));
        die;
    }

    protected function removeEmptyValues(&$array)
    {
        if (isset($array['marketing_scene']) && $array['marketing_scene'] == "SEARCH") {
            unset($array['audience']['new_customer']);
            if (isset($array['multi_product_creative_list'])) {
                unset($array['programmatic_creative_card']);
                unset($array['programmatic_creative_media_list']);
                unset($array['programmatic_creative_title_list']);
            }
        }
        foreach ($array as $key => &$value) {
            // 如果值是数组，则递归处理
            if (is_array($value)) {
                $this->removeEmptyValues($value);
            }

            // 如果值为空且不是数组，则删除该键
            if (empty($value)) {
                unset($array[$key]);
            }
        }
    }

    public function generateRandomCharacter()
    {
        // 随机选择一个非中文字符（字母或符号）
        $char = '';
        // 生成字母或符号，字母范围：A-Z, a-z，符号范围：非中文符号
        $ascii = rand(33, 126); // ASCII 值范围（33 到 126 是大部分可打印字符）
        // 排除中文字符的范围（ASCII 值：\x{4e00}-\x{9fa5}），检查是否是非中文字符
        while (($ascii >= 0x4e00 && $ascii <= 0x9fff) || ($ascii >= 0x3400 && $ascii <= 0x4DBF)) {
            $ascii = rand(33, 126); // 如果是中文字符，重新随机
        }
        $char = chr($ascii); // 将 ASCII 值转换为字符
        // 生成随机数字（0-9）
        $randomDigit = rand(0, 9);
        // 返回字符+数字
        return $char . $randomDigit;
    }

    public function testOcr()
    {
        putenv('Path=D:\ShadowBot;C:\WINDOWS\system32;C:\WINDOWS;C:\WINDOWS\System32\Wbem;C:\WINDOWS\System32\WindowsPowerShell\v1.0\;C:\WINDOWS\System32\OpenSSH\;C:\Program Files\dotnet\;D:\nvm;C:\Program Files\nodejs;C:\Program Files\Git\cmd;D:\phpstudy_pro\Extensions\php\php7.4.3nts;D:\phpstudy_pro\Extensions\composer2.5.8;C:\Users\A\AppData\Local\Programs\Python\Python312;C:\Users\A\AppData\Local\Programs\Python\Python312\Scripts;D:\project\AAVT_0.8.5_full\ffmpeg\bin;;C:\Program Files\Docker\Docker\resources\bin;D:\΢��web�����߹���\dll;C:\Program Files\python;C:\Program Files\python\Scripts;;D:\BtSoft\panel\script;C:\Users\A\AppData\Local\Programs\Python\Launcher\;C:\Users\A\AppData\Local\Microsoft\WindowsApps;D:\VsCode\bin;D:\PhpStorm 2024.1.4\bin;D:\cpplar\;C:\Program Files\Tesseract-OCR\\');

        $ocr = new TesseractOCR();
        $path = ROOT_PATH . '/public/transfer_images/20241129/e398b1a8e07d01518c70e6195716c68(1).jpg';

        dump(base64_encode($path));
//        dump(ROOT_PATH.'public\upload\20240816\b356ed8f3116ff6a5ca0ae99b44ce549.png');
        if (file_exists($path)) {
            echo "存在";
        }
        $ocr->image($path)->format('json');
        $res = $ocr->lang('chi_sim')->run();
//        foreach((new TesseractOCR())->availableLanguages() as $lang) echo $lang;
        dump($res);
        die;
    }


    public function testGetGlobalCost($marketing_goal = "LIVE_PROM_GOODS")
    {
        $redis = Cache::store('redis');
        $date = $redis->get('global_cost_date', '2024-12-14');
        $page = Cache::get('global_cost_page_' . $date, 1);
//        dump($date);
//        dump($page);
//        die;
        $model = new Company();
        $adv_list = $model->page($page)->limit(20)->order('id desc')->column('advertiser_id');
        if (empty($adv_list)) {
            $start_date = new \DateTime($date);
            $next_day = $start_date->modify('+1 day')->format('Y-m-d');
            $redis->set('global_cost_date', $next_day, 3600);
            Cache::rm('global_cost_page_' . $date);
            echo $date . $marketing_goal . "数据全部获取完";
            die;
        }
        $requests = $this->buildGuzzleRequest($adv_list, $marketing_goal, $date);
        $insertData = $this->sendGuzzleRequest($requests);
        $count = count($insertData);
        try {
            if ($insertData) {
                $costModel = new QcAdvDayCost();
                if ($marketing_goal == "VIDEO_PROM_GOODS") {
                    $res = $this->updateCost($insertData, $costModel);
                } else {
                    $res = $costModel->saveAll($insertData);
                }
                if ($res) {
                    echo "第{$page}页成功写进{$count}条数据";
                    Cache::set('global_cost_page_' . $date, $page + 1, 3600);
                }
            } else {
                echo "第{$page}页成功写进{$count}条数据";
                Cache::set('global_cost_page_' . $date, $page + 1, 3600);
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

    }

    public function updateCost($insertData, $costModel)
    {
        foreach ($insertData as $data) {
            $cost = $data['cost'];
            unset($data['cost']);
            $res = $costModel->where($data)->find();
            if ($res) {
                $final_cost = $cost + $res['cost'];
                $costModel->where(['id' => $res['id']])->update(['cost' => $final_cost]);
                echo "更新了" . $res['id'];
            } else {
                $data['cost'] = $cost;
                $costModel->save($data);
                echo "插入了";
            }
        }

        return true;
    }


    protected function buildGuzzleRequest($advIds, $marketing_goal, $date): array
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/report/uni_promotion/get";
        $headers = [
            'Access-Token' => $access_token,
            'Content-Type' => 'application/json'
        ];
        $start_date = $date . ' 00:00:00';
        $end_date = $date . ' 23:59:59';
        $requests = [];

        foreach ($advIds as $advId) {
            $params = [
                'advertiser_id' => intval($advId),
                'lab_ad_type' => 'LAB_AD',
                'fields' => [
                    'stat_cost'
                ],
                'start_date' => $start_date,
                'end_date' => $end_date,
                'marketing_goal' => $marketing_goal
            ];
            $res_url = buildUrlWithParams($url, $params);
            $request = new Request('GET', $res_url, $headers);
            $requests[] = ['request' => $request, 'params' => $params];
        }
        return $requests;
    }


    protected function sendGuzzleRequest($requests)
    {
//        dump($requests);
//        die;
        $insertData = [];
        $guzzleClient = new Client();
        // 并发请求
        $pool = new Pool($guzzleClient, array_column($requests, 'request'), [
            'concurrency' => 1,  // 控制并发数
            'fulfilled' => function ($response, $index) use (&$insertData, $requests) {
                $resData = json_decode($response->getBody()->getContents(), true);

                $requestInfo = $requests[$index]['params'];
                $requestAdvId = $requestInfo['advertiser_id'];
                if (!empty($resData) && $resData['code'] == 0 && $resData['message'] == "OK") {
                    echo $resData['data']['stat_cost'];
                    if ($resData['data']['stat_cost'] != 0) {
                        $insertData[] = [
                            'adv_id' => $requestAdvId,
                            'cost' => $resData['data']['stat_cost'],
                            'type' => 2,
                            'cost_date' => strtotime($requestInfo['start_date'])
                        ];
                    }
                }
            },
            'rejected' => function ($reason, $index) {
                echo "Request {$index} failed: " . $reason->getMessage() . "\n";
            },
        ]);

        // 等待所有请求完成
        $promise = $pool->promise();
        $promise->wait();

        return $insertData;
    }

    public function testGetOpt()
    {
//        $a = Env::get('dmc_ad_config');
//        dump($a);
//        die;
        $token = Cache::get("qc_access_token");
        $params = [
            'advertiser_id' => 1818881230249995,
            'object_id' => ['1825833568147738', '1825833539400009', "1825831570922028", "1825831541866745", "1825830916010355"],
            'start_time' => "2025-03-18 00:00:00",
            'end_time' => "2025-03-19 23:59:59",
            'page' => 1,
            'page_size' => 20];
        $data = FundManagement::get_opt_log($token, $params);
        dump($data);
        die;
    }

    public function fixedOperator()
    {
        $list = Db::name('ad_operator')->select();
        foreach ($list as $item) {
            $update_name = str_replace(["\n", "\r", "\t", " "], "", $item['name']);
            Db::name('ad_operator')->where(['id' => $item['id']])->update(['name' => $update_name]);
        }
        echo "全部处理完了";
        die;
    }

    public function testGetDateObj($date = '')
    {
        $params = [
            'advertiser_id' => 1823187119100122,
            'filtering' =>
                [
                    'marketing_goal' => 'VIDEO_PROM_GOODS',
                    'ad_create_start_date' => "2025-01-30",
                    'ad_create_end_date' => "2025-03-06",
                    'marketing_scene' => 'ALL',
                    "status" => "ALL_INCLUDE_DELETED",
                    "campaign_scene" => [
                        'DAILY_SALE',
                        'NEW_CUSTOMER_TRANSFORMATION',
                        'LIVE_HEAT',
                        'PLANT_GRASS',
                        'PRODUCT_HEAT',
                        'NEW_PRODUCT_BOOST',
                    ],
                ],
            'page' => 1,
            'page_size' => 200
        ];
        $accessToken = Cache::get("qc_access_token");
        $resData = FundManagement::get_ad_list($accessToken, $params);
        dump($resData);
        die;
    }

    public function getSingleAdvCost($adv_id = 1805463240633364, $start_date = '2025-03-01', $end_date = '2025-03-06')
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/report/advertiser/get";
        $params = [
            "advertiser_id" => $adv_id,
            "start_date" => $start_date,
            "end_date" => $end_date,
            "page" => 1,
            "fields" => ['stat_cost'],
            "page_size" => 100,
            "order_type" => "DESC",
            "filtering" => [
                "marketing_goal" => 'ALL'
            ],
            'time_granularity' => 'TIME_GRANULARITY_DAILY'
        ];
        $url = buildUrlWithParams($url, $params);
        $header = array(
            'Access-Token:' . $access_token,
        );
        $total = 0;
        $res = Requests::get($url, $header);
        foreach ($res['data']['list'] as $item) {
            $total += $item['stat_cost'];
        }
        dump($total);
        dump($res);
        die;
    }

    public function getGlobalObj()
    {
        $access_token = Cache::get("qc_access_token");
        $url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/uni_promotion/list/";
        $start_time = "2024-03-01";
        $params = [
            "advertiser_id" => 1818881230249995,//1818881230249995,
            "start_time" => $start_time . ' 00:00:00',
            "end_time" => "2025-03-25 23:59:59",
            "marketing_goal" => "VIDEO_PROM_GOODS",//LIVE_PROM_GOODS,VIDEO_PROM_GOODS
            "order_type" => "DESC",
            "page" => 1,
            "fields" => json_encode(['stat_cost']),
            "page_size" => 100,
            "filtering" => [
                "smart_bid_type" => 'SMART_BID_CUSTOM',//SMART_BID_CUSTOM(默认),SMART_BID_CONSERVATIVE
                "status" => "ALL_INCLUDE_DELETED",//当marketing_goal==VIDEO_PROM_GOODS 才生效
                "having_cost" => "ALL",//当marketing_goal==VIDEO_PROM_GOODS  才生效
                "create_start_date" => $start_time,
                "create_end_date" => "2025-03-25"
            ],
        ];
        $res = new Client();
        try {
            $rep = $res->get($url, [
                'headers' => [
                    'Access-Token' => $access_token, // 替换为实际的 token
                    'Content-Type' => 'application/json', // 可以根据需要添加其他头信息
                ],
                'query' => $params]);
            $contents = $rep->getBody()->getContents();
            dump(json_decode($contents, true));
//            dump($rep->getStatusCode());
        } catch (GuzzleException $e) {
//            dump($e->getMessage());
        }
        die;
        $url = buildUrlWithParams($url, $params);
        $header = array(
            'Access-Token:' . $access_token,
        );
        $res = Requests::get($url, $header);
//        foreach ($res['data']['ad_list'] as $item){
//          $log =   $this->getGlobalObjOpt(1818519029951595,[$item['ad_info']['id']]);
//          dump($log);
//          die;
//        }
        dump($res);
        die;
    }

    public function getGlobalObjOpt($adv_id, $obj_ids)
    {
        $token = Cache::get("qc_access_token");
        $params = [
            'advertiser_id' => $adv_id,
            'object_id' => json_encode($obj_ids),
            'start_date' => "2025-03-01 00:00:00",
            'end_date' => "2025-03-21 23:00:00",
            'page' => "1",
            'page_size' => 20];
        return FundManagement::get_opt_log($token, $params);
    }

    public function genImg()
    {
        $data = [
            '2025-03-26 05:51:05',
            "荆州区睿悦百货行 bm3 \n转出方ID: 1826996040404171",
            "佳悦严选bm1 \n转入方ID: 1826995930318026",
            '同级账户转账',
            '通用',
            '29,093.11',
            '账户余额',
            'OPENAPI'];
        dump(generateTransferImg($data));
        die;
    }

    public function testTotal()
    {
        $start_day = strtotime("2025-04-02 00:00:00");
        $end_day = strtotime("2025-04-02 23:59:59");
        $sql = "
REPLACE INTO fa_adv_daily_summary (
    adv_id, cost_date, mon_cost, stand_cost, global_cost,
    total_num, company_num, global_total_num, global_company_num
)
SELECT
    adv_c.adv_id,
    adv_c.cost_date,
    SUM(adv_c.cost) AS mon_cost,
    (CASE WHEN adv_c.type = 1 THEN adv_c.cost ELSE 0 END) AS stand_cost,
    (CASE WHEN adv_c.type = 2 THEN adv_c.cost ELSE 0 END) AS global_cost,
    total_stats.total_num,
    company_stats.company_num,
    global_total_stats.global_total_num,
    global_company_stats.global_company_num
FROM
    fa_qc_adv_day_cost adv_c
LEFT JOIN (
    SELECT adv_id, COUNT(*) AS total_num
    FROM fa_qc_obj_opt_log
    WHERE opt_time BETWEEN {$start_day} AND {$end_day}
    GROUP BY adv_id
) total_stats ON adv_c.adv_id = total_stats.adv_id
LEFT JOIN (
    SELECT fo.adv_id, COUNT(*) AS company_num
    FROM fa_qc_obj_opt_log fo
    JOIN fa_ad_operator ao ON fo.operator = ao.name AND ao.status = 1
    WHERE fo.opt_time BETWEEN {$start_day} AND {$end_day}
    GROUP BY fo.adv_id
) company_stats ON adv_c.adv_id = company_stats.adv_id
LEFT JOIN (
    SELECT adv_id, COUNT(*) AS global_total_num
    FROM fa_qc_global_obj_opt_log
    WHERE opt_time BETWEEN {$start_day} AND {$end_day}
    GROUP BY adv_id
) global_total_stats ON adv_c.adv_id = global_total_stats.adv_id
LEFT JOIN (
    SELECT fo.adv_id, COUNT(*) AS global_company_num
    FROM fa_qc_global_obj_opt_log fo
    JOIN fa_ad_operator ao ON fo.operator = ao.name AND ao.status = 1
    WHERE fo.opt_time BETWEEN {$start_day} AND {$end_day}
    GROUP BY fo.adv_id
) global_company_stats ON adv_c.adv_id = global_company_stats.adv_id
WHERE
    adv_c.cost_date BETWEEN {$start_day} AND {$end_day}
GROUP BY
    adv_c.adv_id;
";
        $a = Db::execute($sql);
        dump($a);
        die;
    }

    /**
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws DbException
     * @throws GuzzleException
     */
    public function testGetAdvScore()
    {
        $adv_list = Db::name('company')->where(['adv_status' => 1])->page(1, 5)->select();
        foreach ($adv_list as $list) {
            $res = FundManagement::get_adv_score(['advertiser_id' => $list['advertiser_id'], 'filtering' => json_encode(['year' => "2025"])]);
            dump($res);
        }
        die;
    }

    public function dsadas()
    {
        $dimensions = ['ad_id'];
        $metrics = ['stat_cost'];
        $filters = [

        ];
        $order_by = [
            [
                'type' => 2,
                'field' => 'stat_cost'
            ]
        ];
        $start_time = "2025-06-01 00:00:00";
        $end_time = "2025-06-30 23:59:59";
        $arr = [
            'advertiser_id' => 1826807488376899,
            'data_topic' => 'SITE_PROMOTION_PRODUCT_AD',
            'dimensions' => json_encode($dimensions),
            'metrics' => json_encode($metrics),
            'filters' => json_encode($filters),
            'start_time' => $start_time,
            'end_time' => $end_time,
            'order_by' => json_encode($order_by),
            'page' => 1,
            'page_size' => 200
        ];
        $res1 = FundManagement::obtain_global_data($arr);
        var_dump($res1);
    }


    public function testWatch()
    {
        $watch = new MultiProcessWatch();
        $watch->watch();
//        $watch->stop();
//        $watch->status();
    }

    public function test_robot()
    {
//      $list =   Api::get_department();
//      $member_list =   Api::get_department_member(0,7);
//      $res=   Api::create_group('测试机器人','WangChunLong',['WangChunLong','PanHaoWei','LuoWenJing01']);
        $res1 = Api::get_group_message("wr2KqeaQAAlsSH_QRbu7GJ2J8652_eCg");

//      dump($list);
//      dump($member_list);
//      dump($res);
        dump($res1);
        die;
    }

    public function test_task_history()
    {
        $adv_model = new Company();
        $page = Cache::get('test_task_page', 1);
        $adv_list = $adv_model->where(['adv_status' => 1])->page($page)->limit(40)->order('advertiser_id desc')->column('advertiser_id');
        if (!$adv_list) {
//            Cache::rm('test_task_page');
            echo "全部处理完成了";
            die;
        }
        $save_data = [];
        foreach ($adv_list as $adv_id) {
            $params = [
                'advertiser_id' => (int)$adv_id,
                'filtering' => json_encode(['start_time' => '2025-07-01 00:00:00', 'end_time' => '2025-07-27 00:00:00']),
                'page' => 1,
                'page_size' => 50
            ];
            $res = FundManagement::get_hot_material_derive_task_list($params);
            if ($res['code'] == 0 && !empty($res['data']['data'])) {
                $save_data[$adv_id] = $res['data']['data'];
                if ($res['data']['pagination']['total_number'] > 50) {
                    echo $adv_id;
                }
            }
        }
        $task_model = new FissionMaterialTask();
        $fission_model = new FissionDeriveMaterial();
        $task_save_data = [];
        $fission_data = [];
        if ($save_data) {
            foreach ($save_data as $adv_id => $item) {
                foreach ($item as $value) {
                    $task_where = [
                        'adv_id' => $adv_id,
                        'task_id' => $value['task_id'] ?? 0,
                        'material_id' => $value['origin_material_id'],
                    ];
                    $task_info = $task_model->where($task_where)->find();
                    $task_where['status_code'] = $value['status_code'] ?? 0;
                    $task_where['fission_status'] = $value['status'] ?? 0;
                    $task_where['status_message'] = $value['status_message'] ?? "success";
                    $task_where['is_handle'] = 1;
                    $task_where['create_time'] = strtotime($value['create_time']);
                    if ($task_info) {
                        $task_where['id'] = $task_info['id'];
                    }
                    $task_save_data[] = $task_where;
                    if ($value['derive_materials']) {
                        foreach ($value['derive_materials'] as $derive) {
                            $strategy_detail = $derive['strategy_detail'];
                            $where = [
                                'adv_id' => $adv_id,
                                'task_id' => $value['task_id'],
                                'old_material_id' => $value['origin_material_id'],
                                'strategy' => $strategy_detail['strategy'],
                                'strategy_name' => $strategy_detail['strategy_name'],
                                'video_id' => $derive['video_id'],
                            ];
                            $has = $fission_model->where($where)->find();
                            if ($has) {
                                continue;
                            }
                            $fission_data[] = [
                                'adv_id' => $adv_id,
                                'task_id' => $value['task_id'],
                                'old_material_id' => $value['origin_material_id'],
                                'strategy' => $strategy_detail['strategy'],
                                'apply_times' => json_encode($strategy_detail['apply_times']),
                                'strategy_description' => $strategy_detail['strategy_description'],
                                'strategy_name' => $strategy_detail['strategy_name'],
                                'title' => $derive['title'],
                                'video_id' => $derive['video_id'],
                                'video_url' => $derive['video_url'],
                                'adopt_status_code' => 0,
                                'adopt_status_message' => 'success',
                                'create_time' => strtotime($value['create_time']),
                                'update_time' => strtotime($value['modify_time']),
                            ];
                        }
                    }
                }
            }
            Db::startTrans();
            try {
                $task_model->saveAll($task_save_data);
                $fission_model->saveAll($fission_data);
                Db::commit();
            } catch (Exception $e) {
                Db::rollback();
                dump($e->getMessage());
                die;
            }
        }

        echo "处理完了第" . $page . "页，准备处理下一页";
        Cache::set('test_task_page', $page + 1);
    }

    public function handleMaterialTask($user_name)
    {
        $page_key = Cache::get($user_name . '_handle_material_page', 0);
        $queue_records_cache = Cache::get($user_name.'job_data_list');
        if ($queue_records_cache) {
            $queue_records = unserialize($queue_records_cache);
        } else {
            $queue_records = Db::name('queue_record')->where(['queue_name' => 'autoUpdateGlobalObjMaterial'])
                ->column('job_data');
            Cache::set($user_name.'job_data_list', serialize($queue_records));
        }
        $finally_data = [];
        foreach ($queue_records as $item) {
            $data = json_decode($item, true);
            list($obj_id, $mid) = explode('|', $data['obj_id']);
            $finally_data[$obj_id] = $data['adv_id'];
        }
        echo "共".count($finally_data)."条需要处理";
        $i = 0;
        $chunks = array_chunk($finally_data, 100, true);

        if (empty($chunks[$page_key])) {
            Cache::rm($user_name . '_handle_material_page');
            Cache::rm($user_name.'job_data_list');
            echo "全部处理完了";
            die;
        }
        foreach ($chunks[$page_key] as $obj_id => $adv_id) {
            $params = [
                'advertiser_id' => (int)$adv_id,
                'marketing_goal' => "VIDEO_PROM_GOODS",
                'ad_id' => (int)$obj_id,
                'start_time' => date('Y-m-d 00:00:00'),
                'end_time' => date('Y-m-d 23:59:59'),
                'scene' => "MATERIAL_ADD_BUDGET",
                'filtering' => json_encode([
                    'search_keyword' => "起量_",
                    'task_status' => 'PROCESSING',
//                    'create_start_time' => date('Y-m-d'),
//                    'create_end_time' => date('Y-m-d')
                ]),
                'page_size' => 100
            ];
            $res = FundManagement::get_global_control_task_list($params);
            if ($res['data']['code'] == 0) {
                $task_list = $res['data']['data']['task_list'];
                if ($task_list) {
                    foreach ($task_list as $task) {
                        if ((strpos($task['name'], '起量_202508') === 0)) {
                            dump($task);
                            $i++;
                            $update_params = [
                                'advertiser_id' => (int)$adv_id,
                                'task_ids' => [$task['id']],
                                'opt_type' => 'DISABLE'
                            ];
                            $res1 = FundManagement::update_global_control_task($update_params);
                            dump($res1);
                        }
                    }

                }
            }
        }
        echo "第" . $page_key . "页，处理了" . $i . "条记录";
        $page_key++;
        Cache::set($user_name . '_handle_material_page', $page_key);

    }


    public function getDayIntoObjMaterialList()
    {
//        $list = Db::name('fission_into_obj_record')->where([
////            'create_time'=>['>',strtotime(date('Y-m-d'))],
//            'status'=>'success',])->select();
        //
        $obj_count =count( Db::name('qc_global_obj')->alias('qo')
            ->join('company com','qo.adv_id=com.advertiser_id','left')
            ->where(['com.adv_status'=>1,'qo.obj_status'=>"DELIVERY_OK"])
            ->field('qo.obj_id')
            ->select());
        echo "正常所有的在投放的计划数:".$obj_count."</br>";

        $company_count = Db::name('company')->where(['adv_status'=>1])->group('company_name')->count('id');
        echo "正常的所有公司数:".$company_count."</br>";
        $black_company_name = $this->getBlackCompanyList();

        $back_company_count = count($black_company_name);
        echo "加入黑名单的公司数:".$back_company_count."</br>";
        //不愿意推送的计划的公司
        $black_obj =  count(Db::name('company')->alias('com')
            ->join('qc_global_obj qo','com.advertiser_id=qo.adv_id','left')
            ->where(['com.adv_status'=>1,'qo.obj_status'=>"DELIVERY_OK",'com.company_name'=>['in',$black_company_name]])
            ->field('qo.obj_id')
            ->select());
        echo "不愿意推送的计划的公司:".$black_obj."</br>";

        echo "可推送的计划数:".($obj_count - $black_obj)."</br>";
        die;

//        foreach ($list as $item){
//            $mids = explode(',',$item['mid']);
//            foreach ($mids as $mid){
//                $data[$mid] = $item['obj_id'];
//            }
//        }




        dump($data);
        die;


    }

    private function getBlackCompanyList($file_name="black_company_config.php"): array
    {
        $config_file_path = APP_PATH . 'api/controller/fission/'.$file_name;

        if (file_exists($config_file_path)) {
            try {
                $black_company_list = include $config_file_path;
                if (is_array($black_company_list) && !empty($black_company_list)) {
                    return $black_company_list;
                }
            } catch (\Exception $e) {
                echo "读取黑名单配置文件失败: " . $e->getMessage() . "\n";
            }
        }

        return [];
    }
}