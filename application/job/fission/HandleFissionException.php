<?php

namespace app\job\fission;

class HandleFissionException extends BaseJob
{

    protected function doJob($data)
    {

    }

    protected function getJobName(): string
    {
        return '处理裂变任务插入异常';
    }


    protected function getQueueName(): string
    {
        return 'handleFissionException';
    }
}