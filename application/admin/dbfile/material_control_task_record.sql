-- 素材调控任务记录表
-- 用于记录素材调控任务的完整生命周期（创建+停止）
CREATE TABLE `fa_material_control_task_record` (
    `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
    `adv_id` varchar(50) NOT NULL COMMENT '广告主ID',
    `obj_id` varchar(50) NOT NULL COMMENT '计划ID',
    `material_id` varchar(50) NOT NULL COMMENT '素材ID',
    `task_id` varchar(100) DEFAULT NULL COMMENT '调控任务ID（API返回）',
    `task_name` varchar(255) DEFAULT NULL COMMENT '调控任务名称',
    `queue_job_id` varchar(255) DEFAULT NULL COMMENT '关联的队列任务ID',
    `status` tinyint(2) NOT NULL DEFAULT '0' COMMENT '任务状态：0=开始，1=创建成功，2=创建失败，3=完全成功，4=停止失败',
    `create_result` text COMMENT '创建任务的API返回结果',
    `stop_result` text COMMENT '停止任务的API返回结果',
    `error_message` varchar(500) DEFAULT NULL COMMENT '错误信息',
    `start_time` int(11) NOT NULL COMMENT '任务开始时间',
    `task_create_time` int(11) DEFAULT NULL COMMENT '创建任务完成时间',
    `stop_time` int(11) DEFAULT NULL COMMENT '停止任务完成时间',
    `total_duration` int(11) DEFAULT NULL COMMENT '总耗时（秒）',
    `create_time` int(11) NOT NULL COMMENT '记录创建时间戳',
    `update_time` int(11) NOT NULL COMMENT '记录更新时间戳',
    PRIMARY KEY (`id`),
    KEY `idx_adv_obj_material` (`adv_id`, `obj_id`, `material_id`),
    KEY `idx_task_id` (`task_id`),
    KEY `idx_status` (`status`),
    KEY `idx_start_time` (`start_time`),
    KEY `idx_queue_job_id` (`queue_job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='素材调控任务记录表';
