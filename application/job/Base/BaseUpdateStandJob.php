<?php

namespace app\job\Base;

use app\admin\model\QcObj;
use app\common\controller\NameRuleManager;
use jlqc\FundManagement;
use think\Cache;
use think\Exception;
use think\queue\Job;

abstract class BaseUpdateStandJob
{
    /**
     * @return string 队列模型类名（子类需指定）
     */
    protected abstract function getQueueModelClass(): string;

    /**
     * @var string 队列模型实例
     */
    protected $queueModel;

    public function fire(Job $job, $data)
    {
        $queue_model = $this->getQueueModelClass();
        $this->queueModel = new $queue_model();
        $jobId = json_decode($job->getRawBody(), true)['id'];
        $queueData = $this->queueModel->where('job_id', $jobId)->lock(true)->find();

        if (!$queueData) {
            $job->delete();
            return '';
        }

        if ($queueData['status'] != 0) {
            $job->delete();
            return '';
        }

        try {
            list($isJobDone, $msg) = $this->doJob($data, $queueData);
            if ($isJobDone) {
                $queueData->save(['id' => $queueData['id'], 'status' => 1, 'msg' => $msg]);
                $job->delete();
                return '';
            } else {
                if ($job->attempts() > 3) {
                    $job->delete();
                }
            }
        } catch (Exception $e) {
            $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => $e->getMessage()]);
            $job->delete();
            return '';
        }
    }

    /**
     * @throws Exception
     */
    protected function doJob($data, $queueData): array
    {
        // 🍽️ 饭点时间控制：检查是否需要额外延时
        $this->applyMealTimeControl();

        // 可选延迟
        if (isset($data['delay'])) {
            $delay = $data['delay'];
            if ($delay > 5 && $delay < 10) {
                $delay -= 2;
            } elseif ($delay > 10) {
                $delay -= 6;
            }
            sleep($delay);
        }

        $token = Cache::get('qc_access_token');
        $objInfo = FundManagement::get_ad_detail($token, $data['adv_id'], $data['obj_id']);

        if ($objInfo['code'] != 0) {
            throw new Exception($objInfo['message']);
        }

        $objDetail = $objInfo['data'];

        if (in_array($objDetail['opt_status'], ['DELETE', 'FROZEN'])) {
            $this->deleteRedundantJob($queueData);
            throw new Exception("计划状态不符合更新,该计划状态为:" . $this->convertStatus($objDetail['opt_status']));
        }

        if (in_array($objDetail['status'], ['DELETE', 'FROZEN'])) {
            $this->deleteRedundantJob($queueData);
            throw new Exception("计划状态不符合更新,该计划状态为:" . $this->convertStatus($objDetail['opt_status']));
        }

        $this->removeEmptyValues($objDetail);

        unset($objDetail['ad_create_time'], $objDetail['ad_modify_time']);

        $updateData = $objDetail;
        $updateData['advertiser_id'] = $data['adv_id'];

        // 使用新的命名规则管理器
        $newName = $this->generateNewName($objDetail['name'], $data['adv_id'], $data['obj_id'], $queueData);
        $updateData['name'] = $newName;

        if (isset($updateData['delivery_setting']['schedule_time']) &&
            (preg_match('/^0+$/', $updateData['delivery_setting']['schedule_time']) ||
                preg_match('/^1+$/', $updateData['delivery_setting']['schedule_time']))) {
            unset($updateData['delivery_setting']['schedule_time']);
        }

        $url = "https://ad.oceanengine.com/open_api/v1.0/qianchuan/ad/update/";
        $header = [
            'Access-Token:' . $token,
            'Content-Type:application/json',
        ];

        $res = \Requests::post($url, json_encode($updateData, JSON_UNESCAPED_UNICODE), $header);

        if ($res['code'] == 0 && $res['message'] == "OK") {
            return [true, '处理成功'];
        } else {
            list($status,$key) = $this->checkResultMsg($res);
            if ($status) {
                $this->deleteRedundantJob($queueData,$key,$data['adv_id']);
            }
            throw new Exception($res['message'] . json_encode($updateData));
        }
    }

    protected function removeEmptyValues(&$array)
    {
        if (isset($array['marketing_scene']) && $array['marketing_scene'] == "SEARCH") {
            unset($array['audience']['new_customer']);
            $programmatic = [
                'programmatic_creative_card',
                'multi_product_creative_list',
                'programmatic_creative_media_list',
                'programmatic_creative_title_list'
            ];
            foreach ($programmatic as $item) {
                if (empty($array[$item])) {
                    unset($array[$item]);
                }
            }
        }

        if (isset($array['audience']['new_customer']) && $array['audience']['new_customer'] == "NO_BUY_DOUYIN") {
            $array['audience']['new_customer'] = "NO_BUY";
        }

        foreach ($array as $key => &$value) {
            if (is_array($value)) {
                $this->removeEmptyValues($value);
            }

            if (empty($value)) {
                unset($array[$key]);
            }
        }
    }

    public function convertStatus($status): string
    {
        switch ($status) {
            case 'DELETE':
                return "已删除";
            case "TIME_DONE":
                return "已终止";
            case "FROZEN":
                return "已冻结";
            default:
                return '';
        }
    }

    private function checkResultMsg($res): array
    {
        $msg_arr = [
            '低效素材',
            '不在素材库中',
            '服务内部错误',
            '商品托管计划',
            'No permission',
            '抖音原生视频的imageModel',
            '计划状态不符合更新',
            '搜索计划只支持',
            '成本稳投通投广告不',
            "is_forbidden"=>'当前广告主状态已禁用',
            "not_support"=>"已不再支持商品标准推广的计划操作，请尽快迁移至全域推广"
        ];

        foreach ($msg_arr as $key=> $msg) {
            if (strpos($res['message'], $msg)) {
                return [true,$key];
            }
        }

        return [false,null];
    }

    protected function deleteRedundantJob($queueData,$is_del_adv=false,$adv_id='')
    {
        $queue = new $this->queueModel();

        $where = [
            'job_name' => $queueData['job_name'],
            'queue_name' => $queueData['queue_name'],
            'status' => ['in', [0, 2]],
            'id' => ['neq', $queueData['id']]
        ];
        if(in_array($is_del_adv ,['not_support','is_forbidden']) && $adv_id){
            $where['job_data'] = ['like',"%".$adv_id."%"];
            unset($where['job_name']);
        }

        $queue->where($where)->delete();
    }

    /**
     * 生成新的计划名称（使用多规则循环）
     * @param string $originalName 原始计划名称
     * @param string $advId 广告主ID
     * @param string $objId 计划ID
     * @param array $queueData 队列数据
     * @return string
     */
    private function generateNewName($originalName, $advId, $objId, $queueData)
    {
        // 检查是否还有其他待执行的任务
        $queue_model = $this->getQueueModelClass();
        $queueModel = new $queue_model();
        $hasOtherTasks = $queueModel->where([
            'job_id' => ['neq', $queueData['job_id']],
            'job_name' => $queueData['job_name'],
            'status' => 0
        ])->field('id')->find();

        // 检查当前名称是否已被修改
        list($isModified, $matchedRuleKey, $matchedContent) = NameRuleManager::checkNameModified($originalName);

        if ($isModified) {
            // 如果已被修改，则还原名称
            $currentRule = NameRuleManager::getCurrentRule($advId, $objId);
            $restoredName = NameRuleManager::restoreName($originalName, $currentRule['rule']);

            echo "【标准推广】还原计划名称: {$originalName} -> {$restoredName} (使用规则: {$currentRule['rule']['name']})\n";

            // 更新到下一个规则
            NameRuleManager::updateRuleIndex($advId, $objId);

            return $restoredName;
        } else {
            // 如果未被修改，则应用当前规则进行修改
            $currentRule = NameRuleManager::getCurrentRule($advId, $objId);
            $currentRule['rule']['key'] = $currentRule['key']; // 添加key到rule中

            if (!$hasOtherTasks) {
                // 如果是最后一个任务，使用简单的点号标记
                $modifiedName = $originalName . '.';
                echo "【标准推广】最后任务简单标记: {$originalName} -> {$modifiedName}\n";
            } else {
                // 使用当前规则生成修改后的名称
                $modifiedName = NameRuleManager::generateModifiedName($originalName, $currentRule, $advId, $objId);
                echo "【标准推广】修改计划名称: {$originalName} -> {$modifiedName} (使用规则: {$currentRule['rule']['name']})\n";
            }

            return $modifiedName;
        }
    }

    /**
     * 饭点时间控制：在饭点时间降低执行频率
     */
    private function applyMealTimeControl()
    {
        $config = include APP_PATH . 'config/dynamic_ratio_config.php';
        $mealConfig = $config['meal_time_control'] ?? [];

        if (!($mealConfig['enable'] ?? true)) {
            return; // 如果禁用饭点控制，直接返回
        }

        $currentMealPeriod = $this->getCurrentMealPeriod($mealConfig);

        if ($currentMealPeriod) {
            // 在饭点时间内，应用执行频率控制（标准推广更保守）
            $this->controlStandardMealTimeExecution($mealConfig, $currentMealPeriod);
        }
    }

    /**
     * 检查当前是否在饭点时间，返回饭点时间段信息
     */
    private function getCurrentMealPeriod($mealConfig)
    {
        $currentHour = (int)date('H');
        $currentMinute = (int)date('i');
        $currentTime = $currentHour * 60 + $currentMinute; // 转换为分钟数便于比较

        $timePeriods = $mealConfig['time_periods'] ?? [];

        foreach ($timePeriods as $periodKey => $period) {
            if (!($period['enabled'] ?? true)) {
                continue; // 跳过未启用的时间段
            }

            $startTime = ($period['start_hour'] ?? 0) * 60 + ($period['start_minute'] ?? 0);
            $endTime = ($period['end_hour'] ?? 0) * 60 + ($period['end_minute'] ?? 0);

            if ($currentTime >= $startTime && $currentTime <= $endTime) {
                return array_merge($period, ['key' => $periodKey]);
            }
        }

        return null; // 不在任何饭点时间内
    }

    /**
     * 控制标准推广饭点时间的执行频率（比全域推广更保守）
     */
    private function controlStandardMealTimeExecution($mealConfig, $currentMealPeriod)
    {
        $redis = Cache::store('redis');
        $today = date('Y-m-d');
        $currentMinute = date('H:i');
        $mealName = $currentMealPeriod['name'] ?? '饭点时间';

        // 标准推广饭点时间配置（更保守）
        $maxTasksPerMinute = max(1, ($mealConfig['max_tasks_per_minute'] ?? 2) - 1); // 比全域少1个
        $extraDelay = ($mealConfig['extra_delay_seconds'] ?? 30) + 15;              // 比全域多15秒
        $skipProbability = min(90, ($mealConfig['skip_probability'] ?? 70) + 10);   // 比全域多10%概率
        $minSkipDelay = ($mealConfig['min_skip_delay'] ?? 300) + 120;               // 比全域多2分钟
        $maxSkipDelay = ($mealConfig['max_skip_delay'] ?? 900) + 300;               // 比全域多5分钟

        // 1. 概率性跳过执行（标准推广更保守，80%概率跳过）
        if (rand(1, 100) <= $skipProbability) {
            $skipDelay = rand($minSkipDelay, $maxSkipDelay);
            echo "【标准推广】{$mealName}随机跳过执行，延时 {$skipDelay} 秒\n";
            sleep($skipDelay);
            return;
        }

        // 2. 检查当前分钟的执行次数
        $minuteKey = "standard_meal_tasks_minute_{$today}_{$currentMinute}";
        $currentMinuteTasks = (int)$redis->get($minuteKey);

        if ($currentMinuteTasks >= $maxTasksPerMinute) {
            // 如果当前分钟执行次数已达上限，等待到下一分钟
            $waitSeconds = 60 - (int)date('s') + rand(10, 25); // 比全域等待时间更长
            echo "【标准推广】{$mealName}当前分钟任务已达上限，等待 {$waitSeconds} 秒\n";
            sleep($waitSeconds);
        }

        // 3. 记录当前分钟的执行次数
        $currentCount = (int)$redis->get($minuteKey);
        $redis->set($minuteKey, $currentCount + 1, 120); // 2分钟过期

        // 4. 添加额外的随机延时（标准推广延时更长）
        $randomDelay = rand($extraDelay, $extraDelay * 2);
        echo "【标准推广】{$mealName}额外延时 {$randomDelay} 秒\n";
        sleep($randomDelay);
    }
}
