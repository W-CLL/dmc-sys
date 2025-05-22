<?php

namespace app\job;

use app\job\Base\BaseInsertDayOptLogJob;

class InsertDayGlobalOptLog extends BaseInsertDayOptLogJob
{
    protected function getLogModelClass(): string
    {
        return '\app\admin\model\QcGlobalObjOptLog';
    }

    protected function getNextJobClass(): string
    {
        return 'app\job\InsertGlobalObjOptLog';
    }

    protected function getQueueName(): string
    {
        return 'insertGlobalObjOptLog';
    }
}
