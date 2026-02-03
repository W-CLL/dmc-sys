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



}