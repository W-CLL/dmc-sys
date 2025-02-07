--广告计划表
--正式服已经更新
--测试服未更新
CREATE TABLE `fa_qc_obj`
(
    `id`               int(10) unsigned NOT NULL AUTO_INCREMENT,
    `adv_id`           varchar(50) NOT NULL COMMENT '广告账户id',
    `obj_id`           varchar(50) NOT NULL COMMENT '计划id',
    `name`             varchar(255)         DEFAULT NULL COMMENT '计划名称',
    `obj_status`       varchar(50) NOT NULL DEFAULT '1' COMMENT '计划投放状态',
    `marketing_goal`   varchar(50)          DEFAULT '0' COMMENT '营销目标("VIDEO_PROM_GOODS":推商品  LIVE_PROM_GOODS:推直播间)',
    `marketing_scene`  varchar(255)         DEFAULT NULL COMMENT '广告类型\r\n\r\nFEED 通投广告\r\n\r\nSEARCH 搜索广告\r\n\r\nSHOPPING_MALL商城广告',
    `campaign_scene`   varchar(255)         DEFAULT NULL COMMENT '营销场景DAILY_SALE：日常销售\r\n\r\nNEW_CUSTOMER_TRANSFORMATION：新客转化\r\n\r\nLIVE_HEAT：直播间加热\r\n\r\nPLANT_GRASS：人群种草\r\n\r\nPRODUCT_HEAT：商品加热\r\n\r\nNEW_PRODUCT_BOOST新品起量',
    `campaign_id`      int(11) DEFAULT NULL COMMENT '广告组ID（若为托管计划，则为null）',
    `lab_ad_type`      varchar(100)         DEFAULT NULL COMMENT '推广方式\r\n\r\nNOT_LAB_AD：非托管计划，\r\n\r\nLAB_AD：托管计划',
    `opt_status`       varchar(255)         DEFAULT NULL COMMENT '计划操作状态',
    `product_info`     text COMMENT '商品列表',
    `aweme_info`       text COMMENT '抖音号信息',
    `delivery_setting` text COMMENT '投放设置',
    `obj_create_time`  bigint(12) NOT NULL COMMENT '广告创建时间',
    `obj_modify_time`  bigint(12) DEFAULT NULL COMMENT '广告修改时间',
    `create_time`      bigint(16) NOT NULL COMMENT '创建时间',
    `update_time`      int(11) DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    KEY                `advertiser_id` (`adv_id`) USING BTREE,
    KEY                `object_id` (`obj_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='计划表';

--广告账户日消耗表
--正式服已经更新
--测试服未更新
CREATE TABLE `fa_qc_adv_day_cost`
(
    `id`          int(11) NOT NULL AUTO_INCREMENT,
    `adv_id`      varchar(100)   NOT NULL DEFAULT '0' COMMENT '广告账户id',
    `cost`        decimal(12, 2) NOT NULL DEFAULT '0.00' COMMENT '总消耗',
    `cost_date`   int(11) NOT NULL DEFAULT '0' COMMENT '日期时间戳（具体日期:20241201）',
    `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    KEY           `idx_adv_id` (`adv_id`),
    KEY           `idx_cost_date` (`cost_date`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COMMENT='广告账号每天消耗记录表';

--广告计划日志记录表
--正式服已经更新
--测试服未更新
CREATE TABLE `fa_qc_obj_opt_log`
(
    `id`            int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'id',
    `adv_id`        varchar(30)  NOT NULL COMMENT '广告主id',
    `obj_id`        varchar(30)  NOT NULL COMMENT '项目id',
    `content_log`   text COMMENT '日志内容',
    `content_title` varchar(255) DEFAULT NULL COMMENT '主题内容',
    `object_name`   varchar(255) DEFAULT NULL COMMENT '项目名称',
    `object_type`   varchar(100) DEFAULT NULL COMMENT '项目类型',
    `operator`      varchar(255) NOT NULL COMMENT '操作人',
    `opt_ip`        varchar(50)  DEFAULT NULL COMMENT '操作ip',
    `opt_time`      bigint(16) NOT NULL COMMENT '操作时间',
    `create_time`   int(11) NOT NULL COMMENT '创建时间',
    `update_time`   int(11) DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`, `opt_time`),
    KEY             `advertiser_id` (`adv_id`) USING BTREE,
    KEY             `obj_id` (`obj_id`) USING BTREE,
    KEY             `idx_adv_id_opt_time` (`adv_id`,`opt_time`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4
/*!50100 PARTITION BY RANGE (opt_time)
(PARTITION p202411 VALUES LESS THAN (1730400000) ENGINE = InnoDB,
 PARTITION p202412 VALUES LESS THAN (1733078400) ENGINE = InnoDB,
 PARTITION p202501 VALUES LESS THAN (1735756800) ENGINE = InnoDB,
 PARTITION p202502 VALUES LESS THAN (1738358400) ENGINE = InnoDB,
 PARTITION p202503 VALUES LESS THAN (1741036800) ENGINE = InnoDB,
 PARTITION p202504 VALUES LESS THAN (1743628800) ENGINE = InnoDB,
 PARTITION p202505 VALUES LESS THAN (1746307200) ENGINE = InnoDB,
 PARTITION p202506 VALUES LESS THAN (1748899200) ENGINE = InnoDB,
 PARTITION p202507 VALUES LESS THAN (1751577600) ENGINE = InnoDB,
 PARTITION p202508 VALUES LESS THAN (1754256000) ENGINE = InnoDB,
 PARTITION p202509 VALUES LESS THAN (1756848000) ENGINE = InnoDB,
 PARTITION p202510 VALUES LESS THAN (1759526400) ENGINE = InnoDB,
 PARTITION p202511 VALUES LESS THAN (1762118400) ENGINE = InnoDB,
 PARTITION p202512 VALUES LESS THAN (1764796800) ENGINE = InnoDB,
 PARTITION pMax VALUES LESS THAN MAXVALUE ENGINE = InnoDB) */;

--公司主体设置表
--正式服已经更新
--测试服未更新
CREATE TABLE `fa_company_setting`
(
    `id`           int(11) NOT NULL AUTO_INCREMENT,
    `company_name` varchar(255) NOT NULL DEFAULT '' COMMENT '公司名称',
    `is_white`     tinyint(1) NOT NULL DEFAULT '0' COMMENT '1白名单，0正常监测，默认0',
    `percentage`   int(11) NOT NULL DEFAULT '0' COMMENT '百分比 要比客户操作超出百分几',
    `create_time`  int(11) NOT NULL DEFAULT '0' COMMENT '0',
    `update_time`  int(11) NOT NULL DEFAULT '0' COMMENT '0',
    PRIMARY KEY (`id`),
    KEY            `name` (`company_name`(250)) USING BTREE COMMENT '公司名字'
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COMMENT='公司主体设置表';

--新增字段
--正式服已经更新
--测试服未更新
ALTER TABLE fa_company
    ADD COLUMN `adv_status` TINYINT(2) DEFAULT 1 COMMENT '广告账号状态';

ALTER TABLE fa_company
    ADD COLUMN `is_white` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1白名单，0正常监测，默认0',

ALTER TABLE fa_company
    ADD COLUMN `monitor_percentage` tinyint(3) NOT NULL DEFAULT 10 COMMENT '检测百分比，默认10',