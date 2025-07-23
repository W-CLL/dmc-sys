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

    protected function getThisJobName(): string
    {
        return '插入当天新增日志【全域】';
    }

    protected function getThisJobClass(): string
    {
        return 'app\job\InsertDayGlobalOptLog';
    }

    protected function getThisQueueName(): string
    {
        return 'insertDayGlobalOptLog';
    }

}
