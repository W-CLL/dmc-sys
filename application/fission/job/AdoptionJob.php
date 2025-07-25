<?php

namespace app\fission\job;

use app\bytespi\service\AdoptionService;
use think\Log;

/**
 * 素材采纳任务队列处理类
 */
class AdoptionJob extends BaseJob
{
    /**
     * 执行素材采纳任务
     * @param array $data 任务数据
     * @return bool 是否完成
     */
    protected function doJob($data)
    {
        try {
            Log::info('开始执行素材采纳任务: ' . json_encode($data));
            
            $deriveMaterialIds = $data['derive_material_ids'] ?? [];
            $advId = $data['adv_id'] ?? '';
            $isBatch = $data['is_batch'] ?? false;
            
            if (empty($deriveMaterialIds) || empty($advId)) {
                throw new \Exception('任务参数不完整');
            }
            
            $adoptionService = new AdoptionService();
            
            if ($isBatch) {
                // 批量采纳
                $result = $adoptionService->batchAdopt($deriveMaterialIds, $advId);
                
                if ($result['success']) {
                    Log::info('批量采纳任务完成，成功: ' . $result['success_count'] . ', 失败: ' . $result['fail_count']);
                    return true;
                } else {
                    Log::error('批量采纳任务失败');
                    return false;
                }
            } else {
                // 单个采纳
                $deriveMaterialId = $deriveMaterialIds[0];
                $result = $adoptionService->adoptSingle($deriveMaterialId, $advId);
                
                if ($result['success']) {
                    Log::info('单个采纳任务完成: ' . $deriveMaterialId);
                    return true;
                } else {
                    Log::error('单个采纳任务失败: ' . $result['message']);
                    return false;
                }
            }
            
        } catch (\Exception $e) {
            Log::error('素材采纳任务异常: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 获取任务名称
     * @return string
     */
    protected function getJobName(): string
    {
        return '素材采纳任务';
    }

    /**
     * 获取队列名称
     * @return string
     */
    protected function getQueueName(): string
    {
        return 'adoption';
    }
}
