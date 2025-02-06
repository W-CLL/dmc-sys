<?php

namespace app\index\controller;

use app\admin\model\Company as CompanyModel;
use app\common\controller\Frontend;
use app\common\model\Queue;
use app\qcdatahandle\controller\ComFun;
use fast\Random;
use jlqc\FundManagement;
use qywx\Api;
use thiagoalessio\TesseractOCR\TesseractOCR;
use think\Cache;


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

    public function test()
    {
        $token = Cache::get('qc_access_token');
        $params = [
            'advertiser_id' => 1816613270435852,
            'filtering' => json_encode([
                'marketing_goal' => 'VIDEO_PROM_GOODS',
                'ad_create_start_date' => '2024-11-01',
                'ad_create_end_date' => '2024-12-31',
            ]),
            'page_size' => 200
        ];
        $res = FundManagement::get_ad_list($token, $params);
        foreach ($res['data']['list'] as $i=> $item) {
            if($i==0){
                continue;
            }
            $data = FundManagement::get_ad_detail($token, 1816613270435852, $item['ad_id']);
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
            $current_time = "(.".date('md_His').".)";
            if (preg_match($pattern, $objDetail['name'])) {
                // 如果找到了匹配的内容，进行替换
                $newName = preg_replace($pattern, $current_time, $objDetail['name']);
            } else {
                // 如果没有找到匹配，拼接新的内容
                $newName =  $objDetail['name'] . $current_time;
            }
            // 将提取的中文字符拼接当前时间
            $updateData['name'] =$newName;
            if(isset($updateData['delivery_setting']['schedule_time'])) {
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

   protected function removeEmptyValues(&$array) {
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
        $path = ROOT_PATH.'/public/transfer_images/20241129/e398b1a8e07d01518c70e6195716c68(1).jpg';

       dump( base64_encode($path));
//        dump(ROOT_PATH.'public\upload\20240816\b356ed8f3116ff6a5ca0ae99b44ce549.png');
        if(file_exists($path)){
            echo "存在";
        }
        $ocr->image($path)->format('json');
        $res = $ocr->lang('chi_sim')->run();
//        foreach((new TesseractOCR())->availableLanguages() as $lang) echo $lang;
        dump($res);
        die;
    }


}