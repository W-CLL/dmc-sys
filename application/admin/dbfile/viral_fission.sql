CREATE TABLE `fa_fission_account_rules`
(
    `id`           int(11) NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `adv_id`       varchar(50) NOT NULL COMMENT '账户ID',
    `rules_config` json DEFAULT NULL COMMENT '规则配置（JSON格式）',
    `create_time`  int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time`  int(11) DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `adv_id` (`adv_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='账户裂变规则设置表';

CREATE TABLE `fa_fission_company_rules`
(
    `id`           int(11) NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `company_name` varchar(100) NOT NULL COMMENT '公司名称',
    `rules_config` json DEFAULT NULL COMMENT '规则配置（JSON格式）',
    `create_time`  int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time`  int(11) DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `company_name` (`company_name`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COMMENT='公司裂变规则设置表';

CREATE TABLE `fa_fission_derive_material`
(
    `id`                   int(11) NOT NULL AUTO_INCREMENT,
    `task_id`              bigint(14) NOT NULL COMMENT '任务id',
    `adv_id`               varchar(50) NOT NULL COMMENT '广告id',
    `old_material_id`      varchar(50) NOT NULL COMMENT '原素材id',
    `strategy`             varchar(255)  DEFAULT NULL COMMENT '策略',
    `apply_times`          text,
    `strategy_description` varchar(255)  DEFAULT NULL COMMENT '策略描述',
    `strategy_name`        varchar(255)  DEFAULT NULL COMMENT '策略中文名',
    `title`                varchar(255)  DEFAULT NULL COMMENT '标题',
    `video_id`             varchar(50)   DEFAULT NULL COMMENT '视频id',
    `video_url`            varchar(1000) DEFAULT NULL COMMENT '视频链接',
    `adopt_video_url`      varchar(1000) DEFAULT NULL COMMENT '采纳后的视频链接',
    `adopt_material_id`    varchar(30)   DEFAULT NULL COMMENT '采纳后的视频id',
    `adopt_status_code`    int(10) DEFAULT NULL COMMENT '采纳状态码',
    `adopt_status_message` varchar(50)   DEFAULT NULL COMMENT '采纳状态信息',
    `material_info`        text COMMENT '采纳后才有的详情',
    `create_time`          int(11) NOT NULL,
    `update_time`          int(11) NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COMMENT='爆款裂变后的素材表';


CREATE TABLE `fa_fission_global_material`
(
    `id`                              int(11) NOT NULL AUTO_INCREMENT,
    `adv_id`                          varchar(100)   NOT NULL COMMENT '广告id',
    `material_id`                     varchar(255)   NOT NULL COMMENT '素材',
    `roi2_material_video_name`        varchar(255)            DEFAULT NULL COMMENT '素材名称',
    `total_pay_order_count_for_roi2`  int(11) NOT NULL DEFAULT '0' COMMENT '整体成交订单数',
    `total_prepay_and_pay_order_roi2` decimal(10, 2)          DEFAULT NULL COMMENT '整体支付ROI',
    `stat_cost_for_roi2`              decimal(10, 2) NOT NULL DEFAULT '0.00' COMMENT '整体消耗',
    `cost_date`                       int(11) DEFAULT NULL COMMENT '消耗日期',
    `video_url`                       varchar(255)            DEFAULT NULL COMMENT '素材链接',
    `url_expired_at`                  int(11) DEFAULT NULL COMMENT '链接过期时间',
    `create_time`                     int(11) NOT NULL COMMENT '创建时间',
    `update_time`                     int(11) NOT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COMMENT='全域计划素材列表';

CREATE TABLE `fa_fission_material_task`
(
    `id`             int(11) NOT NULL AUTO_INCREMENT,
    `adv_id`         varchar(50) NOT NULL COMMENT '千川id',
    `task_id`        varchar(50) NOT NULL DEFAULT '0' COMMENT '任务id',
    `material_id`    varchar(50) NOT NULL COMMENT '素材id',
    `status_code`    varchar(255)         DEFAULT NULL COMMENT '任务返回码',
    `status_message` varchar(255)         DEFAULT NULL COMMENT '任务返回信息',
    `fission_msg`    varchar(255)         DEFAULT NULL COMMENT '任务裂变结果信息',
    `fission_status` varchar(50)          DEFAULT NULL COMMENT '任务裂变结果状态',
    `is_handle`      tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否已经处理了0未处理，1已经处理',
    `request_id`     varchar(50)          DEFAULT NULL COMMENT '请求日志 ID',
    `create_time`    int(11) NOT NULL COMMENT '创建时间',
    `update_time`    int(11) NOT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COMMENT='爆款列表任务表';

CREATE TABLE `fa_fission_queue`
(
    `id`             int(11) NOT NULL AUTO_INCREMENT,
    `job_name`       varchar(255) NOT NULL COMMENT '任务名',
    `job_id`         varchar(255) NOT NULL COMMENT '任务id',
    `class_name`     varchar(100) NOT NULL COMMENT '任务执行类',
    `job_data`       mediumtext   NOT NULL COMMENT '任务数据（参数）',
    `queue_name`     varchar(100) NOT NULL COMMENT '任务队列名称',
    `relation_table` varchar(100) NOT NULL DEFAULT '' COMMENT '关联表名',
    `remark`         varchar(255) NOT NULL COMMENT '备注',
    `status`         tinyint(2) NOT NULL DEFAULT '0' COMMENT '状态 0待执行 1完成 2失败',
    `msg`            text COMMENT '执行信息文本 如:执行成功/执行失败，原因是....',
    `create_time`    int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time`    int(11) DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE KEY `job_id` (`job_id`) USING BTREE,
    KEY              `queue_name` (`queue_name`) USING BTREE
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COMMENT='爆款裂变队列任务记录表';

-- 给 fa_fission_derive_material 加索引
ALTER TABLE `fa_fission_derive_material`
    ADD INDEX `idx_task` (`task_id`),
  ADD INDEX `idx_adv` (`adv_id`),
  ADD INDEX `idx_old_material` (`old_material_id`),
  ADD INDEX `idx_status` (`adopt_status_code`, `adopt_status_message`),
  ADD INDEX `idx_ctime` (`create_time`);

-- 给 fa_fission_global_material 加索引
ALTER TABLE `fa_fission_global_material`
    ADD INDEX `idx_adv_material` (`adv_id`, `material_id`),
  ADD INDEX `idx_cost_date` (`cost_date`),
  ADD INDEX `idx_ctime` (`create_time`);

-- 给 fa_fission_material_task 加索引
ALTER TABLE `fa_fission_material_task`
    ADD INDEX `idx_task` (`task_id`),
  ADD INDEX `idx_material` (`material_id`),
  ADD INDEX `idx_status` (`status_code`, `status_message`, `fission_status`),
  ADD INDEX `idx_handle` (`is_handle`),
  ADD INDEX `idx_ctime` (`create_time`);

--新增索引
ALTER TABLE fa_fission_derive_material
    ADD INDEX idx_adopt_material_id (adopt_material_id);

ALTER TABLE fa_fission_global_material
    ADD INDEX idx_material_id (material_id);

ALTER TABLE fa_fission_derive_material
    ADD INDEX idx_adopt_adv (adopt_material_id, adv_id);

--再加索引
ALTER TABLE fa_fission_global_material
    ADD INDEX idx_cost_date_roi (cost_date, stat_cost_for_roi2);

ALTER TABLE fa_fission_global_material
    ADD INDEX idx_cover (cost_date, stat_cost_for_roi2, material_id, adv_id);

