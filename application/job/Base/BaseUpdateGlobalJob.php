<?php

namespace app\job\Base;

use app\api\controller\Oauth2;
use app\common\controller\NameRuleManager;
use jlqc\FundManagement;
use think\Cache;
use think\Exception;
use think\queue\Job;

abstract class BaseUpdateGlobalJob
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
        if($queueData['status'] !=0){
            $job->delete();
            return '';
        }
        try {
            list($isJobDone,$msg) = $this->doJob($data, $queueData);
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
            if($this->checkRebootMsg($e->getMessage())){
                if($e->getMessage() == "access_token已过期"){
                    $update_token = new Oauth2();
                    $update_token->access_token_save();
                }
                $this->queueModel->rebootOne($queueData['id']);
            }else {
                $queueData->save(['id' => $queueData['id'], 'status' => 2, 'msg' => $e->getMessage()]);
            }
            $job->delete();
            return '';
        }
    }

    /**
     * @throws Exception
     * @throws \Exception
     */
    protected function doJob($data, $queueData)
    {
        // 🍽️ 饭点时间控制：检查是否需要额外延时
        $this->applyLunchTimeControl();

        // 优化：智能延时计算，避免魔法数字
//        $delay = $this->calculateOptimalDelay($data['delay']);
        sleep($data['delay']);

        // 优化：缓存token，减少重复获取
        $token = $this->getCachedAccessToken();

        // 优化：添加重试机制的API调用
        $objInfo = $this->getObjDetailWithRetry($data['adv_id'], $data['obj_id'], $token);
        if ($objInfo['code'] != 0) {
            throw new Exception($objInfo['message']);
        }

        $objDetail = $objInfo['data'];

        // 优化：提前检查状态，避免不必要的处理
        $this->validateObjStatus($objDetail, $data, $queueData);

        // 优化：缓存状态检查结果
        $statusKey = "obj_status_{$data['obj_id']}";
        $cachedStatus = Cache::get($statusKey);
        if ($cachedStatus && $cachedStatus['opt_status'] === $objDetail['opt_status'] &&
            $cachedStatus['status'] === $objDetail['status']) {
            // 状态未变化，可以跳过某些检查
        } else {
            Cache::set($statusKey, [
                'opt_status' => $objDetail['opt_status'],
                'status' => $objDetail['status']
            ], 300); // 缓存5分钟
        }
        $this->removeEmptyValues($objDetail['multi_product_creative_list']);
        foreach ($objDetail['multi_product_creative_list'] as $key => $item) {
            if(isset($objDetail['multi_product_creative_list'][$key]['block_material'])){
                $objDetail['multi_product_creative_list'][$key]['block_video_material'] = $objDetail['multi_product_creative_list'][$key]['block_material'];
                unset($objDetail['multi_product_creative_list'][$key]['block_material']);
            }
        }// 傻逼字节字段命名变更，进行重赋值
        $updateData = $this->buildData($objDetail);
        $updateData['advertiser_id'] = (int)$data['adv_id'];
        // 使用新的命名规则管理器
        $newName = $this->generateNewName($objDetail['name'], $data['adv_id'], $data['obj_id'], $queueData);
        $updateData['name'] = $newName;
        $url = "https://api.oceanengine.com/open_api/v1.0/qianchuan/uni_aweme/ad/update/";
        $header = array(
            'Access-Token:' . $token,
            'Content-Type:application/json',
        );
        $res = \Requests::post($url, json_encode($updateData, JSON_UNESCAPED_UNICODE), $header);
        if($res['code'] == 0 && $res['message'] == "OK"){
            return [true,'处理成功'];
        }else{
            if($this->checkResultMsg($res)){
                $this->deleteRedundantJob($queueData);
            }
            throw new Exception($res['message']);
        }
    }

    protected function removeEmptyValues(&$array) {
        foreach ($array as $key => &$value) {
            // 如果值是数组，则递归处理
            if (is_array($value)) {
                $this->removeEmptyValues($value);
            }

            // 如果值为空且不是数组，则删除该键
            if (empty($value)) {
                unset($array[$key]);
            }
        }
    }

    public function convertStatus($status)
    {

        switch ($status){
            case 'DELETE':
                $text = "已删除";
                break;
            case "TIME_DONE":
                $text = "已终止";
                break;
            case "FROZEN":
                $text = "已冻结";
                break;
            default :
                $text = '';
        }
        return $text;
    }


    private function checkResultMsg($res){
        $msg_arr = [
            '低效素材',
            '不在素材库',
            '服务内部错误',
            'permission',
            '抖音原生视频的imageModel',
            '当前广告主状态已禁用',
            '计划状态不符合更新',
            '账户已失去该抖音号下对应店铺的商品全域推广权限',
            '用户没有绑定千川权限',
            '体验分低于60分',
            '找不到或在抖店已删除',
            '您传入的抖音号id有误',
            '不支持传入http类型图片ID',
        ];
        foreach ($msg_arr as $msg){
            if (strpos($res['message'], $msg) !== false) {
                return true;
            }
        }
        return false;
    }


    private function deleteRedundantJob($queueData){
        $queue = new $this->queueModel();
        $where['job_name'] = $queueData['job_name'];
        $where['queue_name'] = $queueData['queue_name'];
        $where['status'] = ['in',[0,2]];
        $where['id'] = ['neq',$queueData['id']];
        $queue->where($where)->delete();
    }


    private function buildData($objDetail){
        return array(
            'ad_id' => $objDetail['ad_id'],
            'delivery_setting' => [
                'qcpx_mode' => $objDetail['delivery_setting']['qcpx_mode'],
                'budget' => $objDetail['delivery_setting']['budget'],
                'video_schedule_type' => $objDetail['delivery_setting']['video_schedule_type'],
                'start_time' => $objDetail['delivery_setting']['start_time'],
                'end_time' => $objDetail['delivery_setting']['end_time'],
            ],
            'multi_product_creative_list' => $objDetail['multi_product_creative_list'],
        );
    }


    // 检查需要重启的错误信息
    private function checkRebootMsg($str){
        $msg_arr = [
            '系统开小差啦',
            'Internal service timed out',
            'Too many requests',
            'remote or network error[remote]',
            '计划正在更新中',
            '存在正在处理的全域推广项目',
            'access_token已过期'
        ];
        foreach ($msg_arr as $msg){
            if (strpos($str, $msg) !== false) {
                return true;
            }
        }
        return false;
    }


    // 此处推送的data数据，需要包含主键id，不然批量更新无法操作
    private function pushUpdateData(array $data){
        $pushRedisApiUrl = API_BASE_URL."/pushRedisApi/";
        $params = [
            "key_name" => "updateGlobalObjStatus",
            "value" => json_encode($data,  JSON_UNESCAPED_UNICODE)
        ];
        $result = sendApiRes($pushRedisApiUrl,$params);
        if ($result['status'] != 0){
            throw new Exception($result['msg']);
        }
    }

    private function getId(array $where, string $table_name){
        $getIdApiUrl = API_BASE_URL."/getIdApi/";
        $params = [
            "table_name" => $table_name,
            "where" => $where,
        ];
        $result = sendApiRes($getIdApiUrl,$params,'POST');
        if ($result['status'] != 0){
            throw new Exception($result['msg']);
        }
        return $result['data'];
    }

    /**
     * 智能延时计算，避免魔法数字
     */
    private function calculateOptimalDelay($originalDelay)
    {
        // 优化延时策略，基于系统负载动态调整
        if ($originalDelay <= 5) {
            return $originalDelay;
        } elseif ($originalDelay <= 10) {
            return max(1, $originalDelay - 2);
        } else {
            return max(1, $originalDelay - 6);
        }
    }

    /**
     * 获取缓存的访问令牌
     */
    private function getCachedAccessToken()
    {
        $token = Cache::get('qc_access_token');
        if (!$token) {
            // 如果缓存中没有token，尝试刷新
            $oauth = new Oauth2();
            $oauth->access_token_save();
            $token = Cache::get('qc_access_token');
        }
        return $token;
    }

    /**
     * 带重试机制的获取对象详情
     */
    private function getObjDetailWithRetry($advId, $objId, $token, $maxRetries = 3)
    {
        $retryCount = 0;
        $lastException = null;

        while ($retryCount < $maxRetries) {
            try {
                $objInfo = FundManagement::get_global_obj_detail($advId, $objId);
                if ($objInfo['code'] == 0) {
                    return $objInfo;
                }

                // 如果是token过期，刷新token后重试
                if (strpos($objInfo['message'], 'access_token') !== false) {
                    $token = $this->getCachedAccessToken();
                }

                $lastException = new Exception($objInfo['message']);
            } catch (Exception $e) {
                $lastException = $e;
            }

            $retryCount++;
            if ($retryCount < $maxRetries) {
                sleep(1); // 重试前等待1秒
            }
        }

        throw $lastException;
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
        $checkKey = "queue_check_{$queueData['job_name']}_{$queueData['job_id']}";
        $hasOtherTasks = Cache::get($checkKey);

        if ($hasOtherTasks === null) {
            $queue_model_class = $this->getQueueModelClass();
            $queueModel = new $queue_model_class();
            $checkResult = $queueModel
                ->where([
                    'job_id' => ['neq', $queueData['job_id']],
                    'job_name' => $queueData['job_name'],
                    'status' => 0
                ])
                ->field('id')
                ->find();

            $hasOtherTasks = $checkResult ? true : false;
            Cache::set($checkKey, $hasOtherTasks, 30);
        }

        // 检查当前名称是否已被修改
        list($isModified, $matchedRuleKey, $matchedContent, $isLegacyRule) = NameRuleManager::checkNameModified($originalName);

        if ($isModified) {
            if ($isLegacyRule) {
                // 如果是旧规则，直接还原并应用新规则
                $restoredName = NameRuleManager::restoreLegacyName($originalName, $matchedRuleKey);
                echo "【全域推广】检测到旧规则标记，还原名称: {$originalName} -> {$restoredName} (旧规则: {$matchedRuleKey})\n";

                // 应用新规则进行修改
                $currentRule = NameRuleManager::getCurrentRule($advId, $objId);
                $currentRule['rule']['key'] = $currentRule['key'];
                $modifiedName = NameRuleManager::generateModifiedName($restoredName, $currentRule['rule'], $advId, $objId);
                echo "【全域推广】应用新规则: {$restoredName} -> {$modifiedName} (新规则: {$currentRule['rule']['name']})\n";

                return $modifiedName;
            } else {
                // 如果是新规则，按原逻辑还原
                $currentRule = NameRuleManager::getCurrentRule($advId, $objId);
                $restoredName = NameRuleManager::restoreName($originalName, $currentRule['rule']);

                echo "【全域推广】还原计划名称: {$originalName} -> {$restoredName} (使用规则: {$currentRule['rule']['name']})\n";

                // 更新到下一个规则
                NameRuleManager::updateRuleIndex($advId, $objId);

                return $restoredName;
            }
        } else {
            // 如果未被修改，则应用当前规则进行修改
            $currentRule = NameRuleManager::getCurrentRule($advId, $objId);
            $currentRule['rule']['key'] = $currentRule['key']; // 添加key到rule中

            if (!$hasOtherTasks) {
                // 如果是最后一个任务，检查特殊情况
                if (preg_match('/\.{5}$/', $originalName)) {
                    // 如果末尾有5个点，清除它们
                    $modifiedName = rtrim($originalName, '.');
                    echo "【全域推广】清除末尾点号: {$originalName} -> {$modifiedName}\n";
                } else {
                    // 使用当前规则生成修改后的名称（包括最后一个任务）
                    $modifiedName = NameRuleManager::generateModifiedName($originalName, $currentRule['rule'], $advId, $objId);
                    echo "【全域推广】最后任务随机标记: {$originalName} -> {$modifiedName} (使用规则: {$currentRule['rule']['name']})\n";
                }
            } else {
                // 使用当前规则生成修改后的名称
                $modifiedName = NameRuleManager::generateModifiedName($originalName, $currentRule['rule'], $advId, $objId);
                echo "【全域推广】修改计划名称: {$originalName} -> {$modifiedName} (使用规则: {$currentRule['rule']['name']})\n";
            }

            return $modifiedName;
        }
    }

    /**
     * 饭点时间控制：在饭点时间降低执行频率
     */
    private function applyLunchTimeControl()
    {
        $config = include APP_PATH . 'config/dynamic_ratio_config.php';
        $mealConfig = $config['meal_time_control'] ?? [];

        if (!($mealConfig['enable'] ?? true)) {
            return; // 如果禁用饭点控制，直接返回
        }

        $currentMealPeriod = $this->getCurrentMealPeriod($mealConfig);

        if ($currentMealPeriod) {
            // 在饭点时间内，应用执行频率控制
            $this->controlMealTimeExecution($mealConfig, $currentMealPeriod);
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

            // 检查是否为跨天时间段（结束时间小于开始时间）
            if ($endTime < $startTime) {
                // 跨天时间段：如23:30-01:30
                // 当前时间在开始时间之后（今天晚上）或结束时间之前（明天凌晨）
                if ($currentTime >= $startTime || $currentTime <= $endTime) {
                    return array_merge($period, ['key' => $periodKey]);
                }
            } else {
                // 同一天内的时间段：如12:00-13:30
                if ($currentTime >= $startTime && $currentTime <= $endTime) {
                    return array_merge($period, ['key' => $periodKey]);
                }
            }
        }

        return null; // 不在任何饭点时间内
    }

    /**
     * 控制饭点时间的执行频率
     */
    private function controlMealTimeExecution($mealConfig, $currentMealPeriod)
    {
        $redis = Cache::store('redis');
        $today = date('Y-m-d');
        $currentMinute = date('H:i');
        $mealName = $currentMealPeriod['name'] ?? '饭点时间';

        // 饭点时间配置
        $maxTasksPerMinute = $mealConfig['max_tasks_per_minute'] ?? 2; // 每分钟最多执行2个任务
        $extraDelay = $mealConfig['extra_delay_seconds'] ?? 30;        // 额外延时30秒
        $skipProbability = $mealConfig['skip_probability'] ?? 70;      // 70%概率跳过执行
        $minSkipDelay = $mealConfig['min_skip_delay'] ?? 300;          // 最小跳过延时
        $maxSkipDelay = $mealConfig['max_skip_delay'] ?? 900;          // 最大跳过延时

        // 1. 概率性跳过执行（模拟饭点时间不太活跃）
        if (rand(1, 100) <= $skipProbability) {
            $skipDelay = rand($minSkipDelay, $maxSkipDelay);
            echo "{$mealName}随机跳过执行，延时 {$skipDelay} 秒\n";
            sleep($skipDelay);
            return;
        }

        // 2. 检查当前分钟的执行次数
        $minuteKey = "meal_tasks_minute_{$today}_{$currentMinute}";
        $currentMinuteTasks = (int)$redis->get($minuteKey);

        if ($currentMinuteTasks >= $maxTasksPerMinute) {
            // 如果当前分钟执行次数已达上限，等待到下一分钟
            $waitSeconds = 60 - (int)date('s') + rand(5, 15); // 等到下一分钟+随机5-15秒
            echo "{$mealName}当前分钟任务已达上限，等待 {$waitSeconds} 秒\n";
            sleep($waitSeconds);
        }

        // 3. 记录当前分钟的执行次数
        $currentCount = (int)$redis->get($minuteKey);
        $redis->set($minuteKey, $currentCount + 1, 120); // 2分钟过期

        // 4. 添加额外的随机延时（模拟饭点时间操作较慢）
        $randomDelay = rand($extraDelay, $extraDelay * 2);
        echo "{$mealName}额外延时 {$randomDelay} 秒\n";
        sleep($randomDelay);
    }

    /**
     * 验证对象状态
     */
    private function validateObjStatus($objDetail, $data, $queueData)
    {
        // 检查操作状态
        if (in_array($objDetail['opt_status'], ['DELETE'])) {
            $id = $this->getId(['obj_id' => $data['obj_id']], 'qc_global_obj');
            $this->pushUpdateData(['opt_status' => $objDetail['opt_status'], 'id' => $id]);
            $this->deleteRedundantJob($queueData);
            throw new Exception("计划状态不符合更新,该计划操作状态为:" . $this->convertStatus($objDetail['opt_status']));
        }

        // 检查投放状态
        if (in_array($objDetail['status'], ['DELETE', 'FROZEN'])) {
            $id = $this->getId(['obj_id' => $data['obj_id']], 'qc_global_obj');
            $this->pushUpdateData(['obj_status' => $objDetail['status'], 'id' => $id]);
            $this->deleteRedundantJob($queueData);
            throw new Exception("计划状态不符合更新,该计划投放状态为:" . $this->convertStatus($objDetail['status']));
        }
    }


}