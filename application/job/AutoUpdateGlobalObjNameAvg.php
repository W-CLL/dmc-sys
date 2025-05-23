<?php

namespace app\job;

use app\job\Base\BaseUpdateGlobalJob;

class AutoUpdateGlobalObjNameAvg extends BaseUpdateGlobalJob
{
    protected function getQueueModelClass(): string
    {
        return '\app\common\model\QueueAvg';
    }
}