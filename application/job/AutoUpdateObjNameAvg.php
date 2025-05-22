<?php

namespace app\job;

use app\admin\model\QcObj;
use app\common\model\QueueAvg;
use app\job\Base\BaseUpdateStandJob;
use jlqc\FundManagement;
use think\Cache;
use think\Exception;
use think\queue\Job;

/**
 * 每天早上九点执行平均
 */

class AutoUpdateObjNameAvg extends BaseUpdateStandJob
{
    protected function getQueueModelClass(): string
    {
        return '\app\common\model\QueueAvg';
    }


}
