CREATE TABLE `fa_request_logs` (
                                   `id` int(11) NOT NULL AUTO_INCREMENT,
                                   `namespace` varchar(200) NOT NULL COMMENT '命名空间',
                                   `action` varchar(100) NOT NULL COMMENT '方法名',
                                   `controller` varchar(100) NOT NULL COMMENT '控制器',
                                   `ip` varchar(100) NOT NULL COMMENT 'IP',
                                   `url` longtext NOT NULL COMMENT 'url',
                                   `method` varchar(100) NOT NULL COMMENT '请求方法',
                                   `params` text NOT NULL COMMENT '请求参数',
                                   `time` bigint(11) NOT NULL COMMENT '创建时间',
                                   PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE `fa_queue_record_robot` (
                                         `id` int(11) NOT NULL AUTO_INCREMENT,
                                         `job_name` varchar(255) NOT NULL COMMENT '任务名',
                                         `job_id` varchar(255) NOT NULL COMMENT '任务id',
                                         `class_name` varchar(100) NOT NULL COMMENT '任务执行类',
                                         `job_data` longtext NOT NULL COMMENT '任务数据（参数）',
                                         `queue_name` varchar(100) NOT NULL COMMENT '任务队列名称',
                                         `relation_table` varchar(100) NOT NULL DEFAULT '' COMMENT '关联表名',
                                         `remark` varchar(255) NOT NULL COMMENT '备注',
                                         `status` tinyint(2) NOT NULL DEFAULT '0' COMMENT '状态 0待执行 1完成 2失败',
                                         `msg` text COMMENT '执行信息文本 如:执行成功/执行失败，原因是....',
                                         `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
                                         `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
                                         PRIMARY KEY (`id`),
                                         UNIQUE KEY `job_id` (`job_id`) USING BTREE,
                                         KEY `queue_name` (`queue_name`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE `fa_wechat_group` (
                                   `id` int(11) NOT NULL AUTO_INCREMENT,
                                   `group_id` varchar(50) NOT NULL COMMENT '微信群id',
                                   `group_name` text NOT NULL,
                                   `bind_store_id` int(11) DEFAULT NULL COMMENT '绑定商户id',
                                   `create_time` bigint(11) DEFAULT NULL COMMENT '创建时间',
                                   `update_time` bigint(11) DEFAULT NULL COMMENT '更新时间',
                                   PRIMARY KEY (`id`),
                                   UNIQUE KEY `group` (`group_id`) USING BTREE,
                                   KEY `bind_store` (`bind_store_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


ALTER TABLE fa_transfer_records
    ADD COLUMN `from` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1：dmc后台； 2：robot';


ALTER TABLE fa_share_wallet_transfer_log
    ADD COLUMN `from` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1：dmc后台； 2：robot';



