<?php

namespace app\job;

use app\job\Base\BaseUpdateWebJob;


/**
 * 每天早上八点，下午三点自动跑刷名称
 */
class AutoUpdateObjNameWeb extends BaseUpdateWebJob
{
    protected function getEditUrl($obj_info): string
    {
        $base_edit_url = "https://qianchuan.jinritemai.com/creation/";
        $marketing_scene = strtolower($obj_info['marketing_scene']);
        if ($obj_info['marketingGoal'] == "LIVE_PROM_GOODS") {
            $type = "live";
        } else {
            $type = 'video';
        }
        if ($marketing_scene == "shopping_mall") {
            $marketing_scene = 'mall';
            $type = "product";
        }

        $lab_type = $obj_info['lab_ad_type'] === "NOT_LAB_AD" ? "standard" : "auto";

        return sprintf(
            "%s%s-%s-%s?type=edit&adId=%s&aavid=%s",
            $base_edit_url,
            $marketing_scene,
            $type,
            $lab_type,
            $obj_info['obj_id'],
            $obj_info['adv_id']
        );
    }

    protected function getEditApiUrl(): string
    {
        return "http://localhost:2025/edit_plan/";
    }

    protected function getQueueName(): string
    {
        return "autoUpdateObjNameWeb";
    }
}
