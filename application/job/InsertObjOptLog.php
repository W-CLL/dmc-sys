<?php

namespace app\job;

use app\job\Base\BaseGetOptLogJob;


class InsertObjOptLog extends BaseGetOptLogJob
{
    protected function getLogModelClass(): string
    {
        return '\app\admin\model\QcObjOptLog';
    }


    protected function getThisJobName(): string
    {
        return '插入计划操作日志';
    }


    protected function getThisJobClass(): string
    {
        return 'app\job\InsertObjOptLog';
    }

    protected function getThisQueueName(): string
    {
        return 'insertObjOptLog';
    }

}
