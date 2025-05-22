<?php

namespace app\job;
use app\job\Base\BaseUpdateObjStatusJob;

class UpdateObjStatus extends BaseUpdateObjStatusJob
{
    public function getModelClass(): string
    {
        return '\app\admin\model\QcObj';
    }

    public function getJobName(): string
    {
        return '更新计划状态';
    }

    public function getQueueName(): string
    {
        return 'updateObjStatus';
    }

    public function getRequestUrl(): string
    {
        return "https://ad.oceanengine.com/open_api/v1.0/qianchuan/ad/detail/get/";
    }


    protected function extraParams(): array
    {
        return ['request_material_url'=>false];
    }
}
