<?php

namespace app\job;

use app\job\Base\BaseUpdateWebJob;

/**
 * 每天早上八点，下午三点自动跑刷名称
 */
class AutoUpdateObjNameGlobalWeb extends BaseUpdateWebJob
{
    protected function getEditUrl($obj_info): string
    {
        return sprintf(
            "https://qianchuan.jinritemai.com/creation/uni-prom-product?type=edit&adId=%s&aavid=%s",
            $obj_info['obj_id'],
            $obj_info['adv_id']
        );
    }

    protected function getEditApiUrl(): string
    {
        return "http://localhost:2025/edit_global_plan/";
    }

    protected function getQueueName(): string
    {
        return "autoUpdateObjNameGlobalWeb";
    }

    protected function buildGetObjParams($adv_id, $obj_id): array
    {
        return [$adv_id, $obj_id, 'global'];
    }
}
