<?php

namespace app\job;


use app\job\Base\BaseGetOptLogJob;

class InsertGlobalObjOptLog extends BaseGetOptLogJob
{
    protected function getLogModelClass(): string
    {
        return '\app\admin\model\QcGlobalObjOptLog';
    }

    protected function getThisJobName(): string
    {
        return '插入计划操作日志【全域】';
    }


    protected function getThisJobClass(): string
    {
        return 'app\job\InsertGlobalObjOptLog';
    }

    protected function getThisQueueName(): string
    {
        return 'insertGlobalObjOptLog';
    }

}
