--2025-06-12
CREATE TABLE `fa_tag`
(
    `id`          int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'id',
    `name`        varchar(500) NOT NULL COMMENT '标签名称',
    `create_time` int(11) NOT NULL,
    `update_time` int(11) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `name` (`name`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `fa_keyword`
(
    `id`          int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'id',
    `tag_id`      int(11) NOT NULL COMMENT '标签id',
    `keyword`     longtext NOT NULL COMMENT '关键词',
    `create_time` int(11) NOT NULL,
    `update_time` int(11) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY           `tag` (`tag_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `fa_mark_log`
(
    `id`          int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'id',
    `admin_id`    int(11) NOT NULL,
    `operator`    varchar(100) NOT NULL COMMENT '操作人',
    `adv_id`      varchar(50)  NOT NULL COMMENT '千川id',
    `content`     longtext     NOT NULL COMMENT '内容',
    `create_time` bigint(11) NOT NULL,
    PRIMARY KEY (`id`),
    KEY           `normal` (`admin_id`,`operator`,`adv_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--2025-06-17
--违规账户记录表
CREATE TABLE `fa_risk_adv`
(
    `id`             int(11) NOT NULL AUTO_INCREMENT,
    `adv_id`         varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
    `handle_status`  tinyint(1) DEFAULT '0' COMMENT '处理状态',
    `sys_tag`        tinyint(2) DEFAULT '0' COMMENT '系统标签',
    `tag`            tinyint(2) DEFAULT NULL COMMENT '人工标签',
    `check_staff`    varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '巡查',
    `business_staff` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci  DEFAULT NULL COMMENT '商务',
    `remark`         varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '备注',
    `keywords`       varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '命中的敏感词',
    `create_time`    int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
    `update_time`    int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
    PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--2025-06-17
--违规计划商品表
CREATE TABLE `fa_risk_obj_product`
(
    `id`            int(11) NOT NULL AUTO_INCREMENT,
    `adv_id`        varchar(50) COLLATE utf8mb4_general_ci                       NOT NULL COMMENT '广告主id',
    `obj_id`        varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '计划id',
    `product_id`    varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '商品id',
    `name`          varchar(255) COLLATE utf8mb4_general_ci                      NOT NULL COMMENT '商品名称',
    `product_image` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '商品图链接',
    `tag`           varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '标签',
    `is_del`        tinyint(1) DEFAULT NULL COMMENT '商品是否删除了',
    `audit_status`  int(11) DEFAULT NULL COMMENT '商品状态',
    `sys_tag`       tinyint(1) NOT NULL DEFAULT '0' COMMENT '系统标签',
    `key_words`     varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT '命中的关键字',
    `create_time`   int(11) NOT NULL COMMENT '创建时间',
    `update_time`   int(11) NOT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    KEY             `adv_product_idx` (`adv_id`,`product_id`) USING BTREE,
    KEY             `adv_idx` (`adv_id`) USING BTREE,
    KEY             `name_idx` (`name`) USING BTREE,
    KEY             `adv_obj_product_idx` (`adv_id`,`obj_id`,`product_id`) USING BTREE,
    KEY             `adv_obj_idx` (`adv_id`,`obj_id`) USING BTREE,
    KEY             `obj_product_idx` (`obj_id`,`product_id`) USING BTREE,
    KEY             `product_idx` (`product_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=1519545 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;