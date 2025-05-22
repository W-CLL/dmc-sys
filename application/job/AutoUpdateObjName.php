<?php

namespace app\job;

use app\job\Base\BaseUpdateStandJob;


/**
 * 每天早上八点，下午三点自动跑刷名称
 */

class AutoUpdateObjName extends BaseUpdateStandJob
{
    protected function getQueueModelClass(): string
    {
        return '\app\common\model\Queue';
    }
}
