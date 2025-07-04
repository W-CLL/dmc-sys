# RpaUpObjName 任务调度优化总结

## 优化概述

将 `RpaUpObjName.php` 从传统的分块处理模式改造为使用 `TaskDistributor` 的任务调度模式，实现任务打乱和性能优化。

## 主要改进

### 1. **任务调度模式**

#### 优化前：
- 使用传统的 `ChunkAutoObjWeb` 分块处理
- 任务按广告主顺序执行，容易被检测
- 每个广告主单独创建一个队列任务

#### 优化后：
- 使用 `TaskDistributor` 进行智能任务分发
- 任务完全打乱，避免规律性执行
- 支持加权轮转算法，平衡任务分布

### 2. **批量处理优化**

#### 新增功能：
- **批量API调用**：减少网络请求次数
- **分页批量处理**：每次处理5页数据
- **内存管理**：定期垃圾回收，避免内存泄漏
- **缓存机制**：缓存时间范围和配置数据

### 3. **任务配置优化**

#### 全域任务配置：
```php
$distributor->delayMin = 8;
$distributor->delayMax = 20;
$distributor->setJob('【全域】RPA_计划', 'app\job\AutoUpdateObjNameGlobalWeb', 'autoUpdateObjNameGlobalWeb');
```

#### 标准任务配置：
```php
$distributor->delayMin = 5;
$distributor->delayMax = 15;
$distributor->setJob('【标准】RPA_计划', 'app\job\AutoUpdateObjNameWeb', 'autoUpdateObjNameWeb');
```

### 4. **任务描述优化**

- **全域任务**：`【全域】RPA_计划 广告主ID_计划ID`
- **标准任务**：`【标准】RPA_计划 广告主ID_计划ID`
- 便于在队列中识别和管理

## 核心优化功能

### 1. **智能任务分发**
```php
// 配置分发器
private function configureDistributor($distributor, $type)
{
    if ($type === 'global') {
        $distributor->setJob('【全域】RPA_计划', 'app\job\AutoUpdateObjNameGlobalWeb', 'autoUpdateObjNameGlobalWeb');
    } else {
        $distributor->setJob('【标准】RPA_计划', 'app\job\AutoUpdateObjNameWeb', 'autoUpdateObjNameWeb');
    }
    $distributor->setMaxConsecutiveTasks(4); // 限制连续任务数
}
```

### 2. **批量数据处理**
```php
// 批量获取广告主数据
$batchData = $this->getBatchAdvData($page, $batchSize, $user_name, $modelName);

// 批量处理API调用
$batchOptCountData = $this->getBatchOptCountData($allAdvIds, $start_time, $end_time, $apiUrl);
$batchObjListData = $this->getBatchObjListData($batchOptCountData, $notWhiteCom, $listApiUrl, $type);
```

### 3. **任务过滤机制**
```php
// 过滤无效任务
if ($data['perObjOps'] <= 0) {
    echo "跳过广告主 {$advId}，操作次数为 {$data['perObjOps']}\n";
    continue;
}
```

## 性能提升预期

### 1. **API调用优化**
- **减少60-80%**：批量处理减少网络请求
- **提升稳定性**：减少API限流风险

### 2. **任务执行优化**
- **打乱执行顺序**：避免规律性被检测
- **智能延时**：8-20秒随机延时（全域），5-15秒（标准）
- **负载均衡**：限制同一广告主连续执行任务数

### 3. **内存使用优化**
- **定期清理**：每20页进行垃圾回收
- **批量大小控制**：每次处理5页数据
- **缓存策略**：合理的缓存过期时间

## 使用方法

### 1. **全域推广任务**
```php
$rpa = new RpaUpObjName();
$rpa->getGlobalObjList('用户名');
```

### 2. **标准推广任务**
```php
$rpa = new RpaUpObjName();
$rpa->getNotLabObjList('用户名');
```

## 兼容性说明

### 1. **保持接口不变**
- 外部调用方式完全不变
- 参数和返回值保持一致

### 2. **Job类兼容**
- 复用现有的 `AutoUpdateObjNameGlobalWeb` 和 `AutoUpdateObjNameWeb`
- 不需要修改现有Job处理逻辑

### 3. **配置兼容**
- 保持原有的缓存键和配置
- 向后兼容现有的监控和管理工具

## 监控建议

### 1. **关键指标**
- 任务生成数量
- 任务执行成功率
- API调用频率
- 内存使用情况

### 2. **日志监控**
- 跳过的广告主数量和原因
- 批量处理的页面数
- 任务分发的平衡性

### 3. **性能监控**
- 处理速度对比
- 资源消耗对比
- 错误率变化

## 部署建议

### 1. **测试验证**
- 在测试环境验证任务生成
- 检查任务执行顺序的随机性
- 验证批量处理的正确性

### 2. **分阶段部署**
- 先部署标准推广任务
- 验证无误后部署全域推广任务
- 监控性能指标变化

### 3. **回滚准备**
- 保留原始代码备份
- 准备快速回滚方案
- 监控关键业务指标

## 风险评估

### 低风险
- 接口保持不变
- Job类无需修改
- 有完善的过滤机制

### 中等风险
- 批量处理逻辑较复杂
- 需要验证任务分发的正确性

### 建议
- 充分测试边界情况
- 监控任务执行效果
- 准备应急处理方案
