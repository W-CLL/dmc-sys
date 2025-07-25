<?php

namespace app\bytespi\service;

use think\Db;
use think\Exception;
use think\Log;

/**
 * 素材采纳服务类
 * 负责处理裂变素材的采纳功能
 */
class AdoptionService
{
    // 采纳状态常量
    const STATUS_NOT_ADOPTED = 0;    // 未采纳
    const STATUS_ADOPTING = 1;       // 采纳中
    const STATUS_ADOPTED = 2;        // 已采纳
    const STATUS_ADOPT_FAILED = 3;   // 采纳失败

    /**
     * 单个素材采纳
     * @param int $deriveMaterialId 裂变素材ID
     * @param string $advId 千川ID
     * @return array 采纳结果
     */
    public function adoptSingle($deriveMaterialId, $advId)
    {
        try {
            // 获取裂变素材信息
            $deriveMaterial = Db::name('adv_derive_material')
                ->where('id', $deriveMaterialId)
                ->where('adv_id', $advId)
                ->find();
            
            if (!$deriveMaterial) {
                throw new Exception('裂变素材不存在');
            }
            
            // 检查素材状态
            if ($deriveMaterial['generation_status'] != FissionTaskService::STATUS_SUCCESS) {
                throw new Exception('素材尚未生成完成，无法采纳');
            }
            
            if ($deriveMaterial['adoption_status'] == self::STATUS_ADOPTED) {
                return ['success' => true, 'message' => '素材已采纳'];
            }
            
            // 更新采纳状态为采纳中
            Db::name('adv_derive_material')
                ->where('id', $deriveMaterialId)
                ->update([
                    'adoption_status' => self::STATUS_ADOPTING,
                    'update_time' => time()
                ]);
            
            // 调用字节API进行采纳
            $result = $this->callByteApiAdopt($deriveMaterial);
            
            if ($result['success']) {
                // 采纳成功，更新状态
                Db::name('adv_derive_material')
                    ->where('id', $deriveMaterialId)
                    ->update([
                        'adoption_status' => self::STATUS_ADOPTED,
                        'adoption_time' => time(),
                        'update_time' => time()
                    ]);
                
                // 记录操作日志
                $this->logOperation('ADOPT_SINGLE', $advId, $deriveMaterialId, '单个素材采纳成功');
                
                return ['success' => true, 'message' => '采纳成功'];
            } else {
                // 采纳失败，更新状态
                Db::name('adv_derive_material')
                    ->where('id', $deriveMaterialId)
                    ->update([
                        'adoption_status' => self::STATUS_ADOPT_FAILED,
                        'error_message' => $result['message'],
                        'update_time' => time()
                    ]);
                
                return ['success' => false, 'message' => $result['message']];
            }
            
        } catch (Exception $e) {
            Log::error('单个素材采纳失败: ' . $e->getMessage());
            
            // 更新状态为失败
            if (isset($deriveMaterialId)) {
                Db::name('adv_derive_material')
                    ->where('id', $deriveMaterialId)
                    ->update([
                        'adoption_status' => self::STATUS_ADOPT_FAILED,
                        'error_message' => $e->getMessage(),
                        'update_time' => time()
                    ]);
            }
            
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 批量采纳
     * @param array $deriveMaterialIds 裂变素材ID数组
     * @param string $advId 千川ID
     * @return array 批量采纳结果
     */
    public function batchAdopt($deriveMaterialIds, $advId)
    {
        $results = [];
        $successCount = 0;
        $failCount = 0;
        
        foreach ($deriveMaterialIds as $deriveMaterialId) {
            $result = $this->adoptSingle($deriveMaterialId, $advId);
            $results[$deriveMaterialId] = $result;
            
            if ($result['success']) {
                $successCount++;
            } else {
                $failCount++;
            }
        }
        
        // 记录批量操作日志
        $this->logOperation('BATCH_ADOPT', $advId, implode(',', $deriveMaterialIds), 
            "批量采纳完成，成功：{$successCount}，失败：{$failCount}");
        
        return [
            'success' => $successCount > 0,
            'total' => count($deriveMaterialIds),
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'details' => $results
        ];
    }

    /**
     * 获取采纳状态
     * @param int $deriveMaterialId 裂变素材ID
     * @return array 状态信息
     */
    public function getAdoptionStatus($deriveMaterialId)
    {
        $deriveMaterial = Db::name('adv_derive_material')
            ->where('id', $deriveMaterialId)
            ->find();
        
        if (!$deriveMaterial) {
            return ['error' => '素材不存在'];
        }
        
        $statusMap = [
            self::STATUS_NOT_ADOPTED => '未采纳',
            self::STATUS_ADOPTING => '采纳中',
            self::STATUS_ADOPTED => '已采纳',
            self::STATUS_ADOPT_FAILED => '采纳失败'
        ];
        
        return [
            'id' => $deriveMaterial['id'],
            'adoption_status' => $deriveMaterial['adoption_status'],
            'adoption_status_text' => $statusMap[$deriveMaterial['adoption_status']] ?? '未知状态',
            'adoption_time' => $deriveMaterial['adoption_time'],
            'error_message' => $deriveMaterial['error_message']
        ];
    }

    /**
     * 采纳失败重试
     * @param int $deriveMaterialId 裂变素材ID
     * @return array 重试结果
     */
    public function retryAdoption($deriveMaterialId)
    {
        try {
            $deriveMaterial = Db::name('adv_derive_material')
                ->where('id', $deriveMaterialId)
                ->find();
            
            if (!$deriveMaterial) {
                throw new Exception('素材不存在');
            }
            
            if ($deriveMaterial['adoption_status'] != self::STATUS_ADOPT_FAILED) {
                throw new Exception('只能重试采纳失败的素材');
            }
            
            // 重新采纳
            return $this->adoptSingle($deriveMaterialId, $deriveMaterial['adv_id']);
            
        } catch (Exception $e) {
            Log::error('重试采纳失败: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 获取可采纳的素材列表
     * @param string $advId 千川ID
     * @param array $filters 筛选条件
     * @return array 素材列表
     */
    public function getAdoptableMaterials($advId, $filters = [])
    {
        $where = [
            'adv_id' => $advId,
            'generation_status' => FissionTaskService::STATUS_SUCCESS,
            'adoption_status' => ['in', [self::STATUS_NOT_ADOPTED, self::STATUS_ADOPT_FAILED]]
        ];
        
        // 应用筛选条件
        if (!empty($filters['fission_strategy'])) {
            $where['fission_strategy'] = $filters['fission_strategy'];
        }
        
        if (!empty($filters['date_range'])) {
            $where['create_time'] = ['between', $filters['date_range']];
        }
        
        $materials = Db::name('adv_derive_material')
            ->where($where)
            ->order('create_time desc')
            ->select();
        
        return $materials;
    }

    /**
     * 获取采纳统计信息
     * @param string $advId 千川ID
     * @param string $dateRange 日期范围
     * @return array 统计信息
     */
    public function getAdoptionStats($advId, $dateRange = null)
    {
        $where = ['adv_id' => $advId];
        
        if ($dateRange) {
            $where['create_time'] = ['between', $dateRange];
        }
        
        $stats = Db::name('adv_derive_material')
            ->where($where)
            ->field([
                'COUNT(*) as total',
                'SUM(CASE WHEN adoption_status = ' . self::STATUS_ADOPTED . ' THEN 1 ELSE 0 END) as adopted',
                'SUM(CASE WHEN adoption_status = ' . self::STATUS_ADOPTING . ' THEN 1 ELSE 0 END) as adopting',
                'SUM(CASE WHEN adoption_status = ' . self::STATUS_ADOPT_FAILED . ' THEN 1 ELSE 0 END) as failed',
                'SUM(CASE WHEN adoption_status = ' . self::STATUS_NOT_ADOPTED . ' THEN 1 ELSE 0 END) as not_adopted'
            ])
            ->find();
        
        $stats['adoption_rate'] = $stats['total'] > 0 ? 
            round($stats['adopted'] / $stats['total'] * 100, 2) : 0;
        
        return $stats;
    }

    /**
     * 调用字节API进行采纳
     * @param array $deriveMaterial 裂变素材信息
     * @return array 调用结果
     */
    private function callByteApiAdopt($deriveMaterial)
    {
        // TODO: 实现具体的字节API调用逻辑
        // 这里应该调用实际的字节跳动API接口进行素材采纳
        
        // 模拟API调用
        $success = rand(1, 10) > 2; // 80%成功率
        
        if ($success) {
            return [
                'success' => true,
                'message' => '采纳成功',
                'adopt_id' => 'ADOPT_' . uniqid()
            ];
        } else {
            return [
                'success' => false,
                'message' => '采纳失败：API调用异常'
            ];
        }
    }

    /**
     * 记录操作日志
     * @param string $operation 操作类型
     * @param string $advId 千川ID
     * @param string $targetId 目标ID
     * @param string $description 操作描述
     */
    private function logOperation($operation, $advId, $targetId, $description)
    {
        $logData = [
            'adv_id' => $advId,
            'operation_type' => $operation,
            'target_id' => $targetId,
            'operation_desc' => $description,
            'operation_data' => json_encode([
                'timestamp' => time(),
                'operation' => $operation
            ]),
            'result_status' => 1,
            'create_time' => time(),
            'update_time' => time()
        ];
        
        Db::name('fission_operation_log')->insert($logData);
    }
}
