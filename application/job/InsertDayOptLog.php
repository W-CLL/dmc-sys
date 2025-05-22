<?php

namespace app\job;

use app\job\Base\BaseInsertDayOptLogJob;

class InsertDayOptLog extends BaseInsertDayOptLogJob
{
    protected function getLogModelClass(): string
    {
        return '\app\admin\model\QcObjOptLog';
    }

    protected function getNextJobClass(): string
    {
        return 'app\job\InsertObjOptLog';
    }

    protected function getQueueName(): string
    {
        return 'insertObjOptLog';
    }
}
