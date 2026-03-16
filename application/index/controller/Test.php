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


    public function testGetAppToken()
    {
        $res = sendApiRes("https://open.oceanengine.com/open_api/oauth2/app_access_token/",
            ['app_id'=>(int)1789116881642596,'secret'=>'6dddcbede9bbf6cdf4a82bc91ba697dc3b065e0c'],
            "POST");
        dump($res);
        die;
        //ab29b3c1605de5694bb66309406c23a856e9ddb1
    }

       public function testInactiveAdv()
    {
        $res = sendApiRes("https://api.oceanengine.com/open_api/v3.0/tools/inactive_advertiser/list/",
            ['app_id'=>(int)1789116881642596,'cursor'=>0,'count'=>1000],
            "GET",['App-Access-Token'=>"ab29b3c1605de5694bb66309406c23a856e9ddb1"]);
        dump($res);
        die;
        //
    }
    public function testGlobal()
    {
        $res = sendApiRes("https://api.oceanengine.com/open_api/v1.0/qianchuan/report/custom/config/get/",
            ['app_id'=>(int)1789116881642596,'cursor'=>0,'count'=>1000],
            "GET",['App-Access-Token'=>"ab29b3c1605de5694bb66309406c23a856e9ddb1"]);
        dump($res);
        die;
        //
    }

}