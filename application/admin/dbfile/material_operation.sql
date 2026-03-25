CREATE TABLE `fa_material_diagnosis` (
                                         `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                                         `material_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
                                         `video_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                                         `task_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                                         `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0:PENDING  1:SUCCESS   2:FAILED',
                                         `is_get` tinyint(1) NOT NULL COMMENT '0:未获取详情  1:已获取详情',
                                         `is_ecp_high_quality_material` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否千川优质素材  0:UNKNOWN   1:YES    2:NO',
                                         `is_inefficient_material` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否低效素材  0:UNKNOWN   1:YES    2:NO',
                                         `is_first_publish_material` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否首发素材  0:UNKNOWN   1:YES    2:NO',
                                         `not_ecp_high_quality_reason` varchar(2000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '千川非优质原因',
                                         `create_time` bigint(16) NOT NULL COMMENT '创建时间',
                                         `update_time` bigint(16) DEFAULT NULL COMMENT '更新时间',
                                         PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=464 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `fa_material_prequalification` (
                                                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                                                `material_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                                                `advertiser_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                                                `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '状态：0等待推送；1预审中；2通过；3驳回；4无法推送的素材类型',
                                                `reason_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT '驳回时的审核建议',
                                                `object_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '审核对象id。用于取消送审',
                                                `video_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '视频id',
                                                `filename` varchar(255) DEFAULT NULL COMMENT '视频名称',
                                                `to_diagnosis` tinyint(1) NOT NULL DEFAULT '0' COMMENT '提交到前测     0:未提交   1:已提交',
                                                `create_time` bigint(16) NOT NULL COMMENT '创建时间',
                                                `update_time` bigint(16) DEFAULT NULL COMMENT '更新时间',
                                                PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=48590 DEFAULT CHARSET=utf8mb4;