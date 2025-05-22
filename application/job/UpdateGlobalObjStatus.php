<?php

namespace app\job;

use app\job\Base\BaseUpdateObjStatusJob;

class UpdateGlobalObjStatus extends BaseUpdateObjStatusJob
{
    public function getModelClass(): string
    {
        return '\app\admin\model\QcGlobalObj';
    }

    public function getJobName(): string
    {
        return '更新全域计划状态';
    }

    public function getQueueName(): string
    {
        return 'updateGlobalObjStatus';
    }

    public function getRequestUrl(): string
    {
        return "https://api.oceanengine.com/open_api/v1.0/qianchuan/uni_promotion/ad/detail/";
    }

    public function extraParams(): array
    {
        return [];
    }
}
