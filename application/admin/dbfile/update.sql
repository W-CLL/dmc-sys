--2024.08.19
--2024.8.19 测试服已处理
--2024.8.19 已处理
ALTER TABLE fa_store
    ADD COLUMN `bank` tinyint(1) DEFAULT 0 COMMENT '绑定银行（0：未绑定，1：招行）';
--2024.08.19
--2024.8.19 测试服已处理
--2024.8.19 已处理
CREATE TABLE `fa_zh_sub_account`
(
    `id`             int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `store_id`       int(10) NOT NULL COMMENT '用户id',
    `bus_mod`        varchar(5)              DEFAULT NULL COMMENT '业务模式',
    `settle_account` varchar(40)    NOT NULL COMMENT '结算账号',
    `branch_num`     int(2) DEFAULT NULL COMMENT '分行号',
    `sub_account`    varchar(20)    NOT NULL COMMENT '记账子单元编号',
    `sub_name`       varchar(64)    NOT NULL COMMENT '记账子单元名称',
    `can_overdraw`   varchar(1)     NOT NULL COMMENT '是否可透支(Y：允许透支 N：不允许透支 X：不适用)',
    `return_method`  varchar(1)     NOT NULL COMMENT '支付失败退回方式(Y：退回原记账子单元 N：退回结算户 X：不适用)',
    `can_off`        varchar(1)     NOT NULL COMMENT '余额非零时是否可关闭(Y：可关闭 N：不可关闭 X：不适用)',
    `whether_limit`  varchar(1)     NOT NULL COMMENT '是否设置收款限额(N：不设置收款额度， Y：设置收款额度 X：不适用)',
    `max_limit`      decimal(15, 2) NOT NULL DEFAULT '0.00' COMMENT '余额上限额度',
    PRIMARY KEY (`id`),
    UNIQUE KEY `sub_account` (`sub_account`) USING BTREE,
    UNIQUE KEY `store_id` (`store_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='绑定招行子账户，不理解的字段去看文档：https://openbiz.cmbchina.com/developer/UI/Business/CloudDirectConnect/Public/DocumentCenter/DocDetail.aspx?bizkey=DCCT20231226155549458&fabizkey=1&treeID=100082838';
--2024.09.13
--2024.8.19 测试服已处理
--2024.9.13 已处理
CREATE TABLE `fa_qc_share_wallet`
(
    `id`              int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `sub_wallet_id`   varchar(30) NOT NULL COMMENT '子钱包ID',
    `bind_store_id`   int(10) DEFAULT NULL COMMENT '绑定的商户ID',
    `main_wallet_id`  varchar(30) NOT NULL COMMENT '主钱包id',
    `sub_wallet_type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '账户类型:0未绑定,1公账,2私账',
    PRIMARY KEY (`id`),
    UNIQUE KEY `sub_wallet_id` (`sub_wallet_id`) USING BTREE,
    KEY               `store_id` (`bind_store_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='子钱包列表';

--2024.09.19
--2024.09.27 测试服已更新
--2024.9.30 正式服已处理
CREATE TABLE `fa_queue_record`
(
    `id`             int(11) NOT NULL AUTO_INCREMENT,
    `job_name`       varchar(255) NOT NULL COMMENT '任务名',
    `job_id`         varchar(255) NOT NULL COMMENT '任务id',
    `class_name`     varchar(100) NOT NULL COMMENT '任务执行类',
    `job_data`       text         NOT NULL COMMENT '任务数据（参数）',
    `queue_name`     varchar(100) NOT NULL COMMENT '任务队列名称',
    `relation_table` varchar(100) NOT NULL DEFAULT '' COMMENT '关联表名',
    `remark`         varchar(255) NOT NULL COMMENT '备注',
    `status`         tinyint(2) NOT NULL DEFAULT '0' COMMENT '状态 0待执行 1完成 2失败',
    `msg`            text COMMENT '执行信息文本 如:执行成功/执行失败，原因是....',
    `create_time`    int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time`    int(11) DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COMMENT='队列任务记录表';
--2024.09.19
--2024.09.27 测试服已更新
--2024.9.30 正式服已处理
CREATE TABLE `fa_sync_charge_record`
(
    `id`          int(11) NOT NULL AUTO_INCREMENT,
    `log_id`      int(11) NOT NULL COMMENT '转账记录id',
    `crm_id`      int(11) NOT NULL COMMENT 'crm新增记录id',
    `type`        tinyint(1) NOT NULL DEFAULT '0' COMMENT '1备款 2共享 0啥都不是',
    `create_time` int(11) NOT NULL COMMENT '创建时间',
    `update_time` int(11) NOT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COMMENT='同步充值记录表';

--2024.09.19
--2024.09.27 测试服已更新
--2024.9.30 正式服已处理
CREATE TABLE `fa_external_accounts`
(
    `id`          int(11) NOT NULL AUTO_INCREMENT,
    `account`     varchar(100) NOT NULL COMMENT '账号',
    `secret`      varchar(255) NOT NULL COMMENT '密钥',
    `platform`    varchar(255) NOT NULL COMMENT '平台/系统',
    `create_time` int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time` int(11) DEFAULT NULL,
    `status`      tinyint(1) NOT NULL DEFAULT '1',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='对外账号列表';
--2024.09.19
--2024.09.27 测试服已更新
--2024.9.30 正式服已处理
INSERT INTO `fa_external_accounts` (`id`, `account`, `secret`, `platform`, `create_time`, `update_time`, `status`)
VALUES (1, '20240919001', '密钥自行生成添加', 'yuanxi_crm', NULL, NULL, 1);
--2024.10.09
--2024.10.09 测试服已更新
--2024.10.09 已处理
ALTER TABLE fa_qc_share_wallet
    ADD COLUMN `discount_percentage` decimal(7, 4) DEFAULT 0.0000 COMMENT '折扣百分比';
ALTER TABLE fa_company
    ADD COLUMN `discount_percentage` decimal(7, 4) DEFAULT 0.0000 COMMENT '折扣百分比';
--2024.10.19
--2024.10.19 测试服已更新
--2024.10.19 已处理
CREATE TABLE `fa_share_wallet_once_bind`
(
    `id`                            int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `sub_wallet_id`                 varchar(30) NOT NULL COMMENT '子钱包ID',
    `bind_store_id`                 int(10) NOT NULL COMMENT '曾绑定过的商户ID',
    `transfer_in_sum_public_cash`   decimal(14, 2) DEFAULT '0.00' COMMENT '转入总金额(私帐转入)       实际金额',
    `transfer_out_sum_public_cash`  decimal(14, 2) DEFAULT '0.00' COMMENT '转出总金额(私帐转出)      实际金额',
    `transfer_in_sum_private_cash`  decimal(14, 2) DEFAULT '0.00' COMMENT '转入总金额(公帐转入)      实际金额',
    `transfer_out_sum_private_cash` decimal(14, 2) DEFAULT '0.00' COMMENT '转出总金额(公帐转出)     实际金额',
    `transfer_in_sum_public_vr`     decimal(14, 2) DEFAULT '0.00' COMMENT '转入总金额(私帐转入)    虚拟币(含返点)',
    `transfer_out_sum_public_vr`    decimal(14, 2) DEFAULT '0.00' COMMENT '转出总金额(私帐转出)    虚拟币(含返点)',
    `transfer_in_sum_private_vr`    decimal(14, 2) DEFAULT '0.00' COMMENT '转入总金额(公帐转入)    虚拟币(含返点)',
    `transfer_out_sum_private_vr`   decimal(14, 2) DEFAULT '0.00' COMMENT '转出总金额(公帐转出)    虚拟币(含返点)',
    PRIMARY KEY (`id`),
    KEY                             `sub_wallet_id` (`sub_wallet_id`) USING BTREE,
    KEY                             `bind_store_id` (`bind_store_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='记录子钱包曾经绑过的商户';

--2024.10.19
CREATE TABLE `fa_ad_operator`
(
    `id`          int(11) NOT NULL AUTO_INCREMENT,
    `name`        varchar(50) NOT NULL COMMENT '名字',
    `type`        tinyint(1) NOT NULL COMMENT '类型：0运营 1客户',
    `status`      tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：0禁用 1正常',
    `create_time` bigint(11) DEFAULT NULL COMMENT '创建时间',
    `update_time` bigint(11) DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COMMENT='千川投放计划操作员记录表';

--2024.11.27
CREATE TABLE `fa_plan_opt_log`
(
    `id`            int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'id',
    `advertiser_id` varchar(30)  NOT NULL COMMENT '广告主id',
    `obj_id`        varchar(30)  NOT NULL COMMENT '项目id',
    `content_log`   text COMMENT '日志内容',
    `content_title` varchar(255) DEFAULT NULL COMMENT '主题内容',
    `object_name`   varchar(255) DEFAULT NULL COMMENT '项目名称',
    `object_type`   varchar(100) DEFAULT NULL COMMENT '项目类型',
    `operator`      varchar(255) NOT NULL COMMENT '操作人',
    `opt_ip`        varchar(50)  DEFAULT NULL COMMENT '操作ip',
    `opt_time`      bigint(16) NOT NULL COMMENT '操作时间',
    `create_time`   bigint(16) NOT NULL COMMENT '创建时间',
    PRIMARY KEY (`id`),
    KEY             `advertiser_id` (`advertiser_id`) USING BTREE,
    KEY             `obj_id` (`obj_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='计划操作日志表';

CREATE TABLE `fa_qc_obj`
(
    `id`            int(10) unsigned NOT NULL AUTO_INCREMENT,
    `company_id`    int(10) NOT NULL COMMENT '千川表id',
    `advertiser_id` varchar(50) NOT NULL COMMENT '广告商id',
    `object_id`     varchar(50) NOT NULL COMMENT '项目id',
    `status`        tinyint(1) NOT NULL DEFAULT '1' COMMENT '计划状态   1可操作  0不可操作',
    `create_time`   bigint(16) NOT NULL COMMENT '创建时间',
    PRIMARY KEY (`id`),
    KEY             `company_id` (`company_id`) USING BTREE,
    KEY             `advertiser_id` (`advertiser_id`) USING BTREE,
    KEY             `object_id` (`object_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='项目表';

ALTER TABLE fa_company
    ADD COLUMN `kahuna` varchar(50) DEFAULT NULL COMMENT '负责人';

ALTER TABLE fa_queue_record
    MODIFY COLUMN `job_data` mediumtext NOT NULL COMMENT '任务数据（参数）';

ALTER TABLE fa_queue_record
    ADD UNIQUE INDEX job_id (job_id);

--2024.11.27
CREATE TABLE `fa_plan_opt_log`
(
    `id`            int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'id',
    `advertiser_id` varchar(30)  NOT NULL COMMENT '广告主id',
    `obj_id`        varchar(30)  NOT NULL COMMENT '项目id',
    `content_log`   text COMMENT '日志内容',
    `content_title` varchar(255) DEFAULT NULL COMMENT '主题内容',
    `object_name`   varchar(255) DEFAULT NULL COMMENT '项目名称',
    `object_type`   varchar(100) DEFAULT NULL COMMENT '项目类型',
    `operator`      varchar(255) NOT NULL COMMENT '操作人',
    `opt_ip`        varchar(50)  DEFAULT NULL COMMENT '操作ip',
    `opt_time`      bigint(16) NOT NULL COMMENT '操作时间',
    `create_time`   bigint(16) NOT NULL COMMENT '创建时间',
    PRIMARY KEY (`id`),
    KEY             `advertiser_id` (`advertiser_id`) USING BTREE,
    KEY             `obj_id` (`obj_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='计划操作日志表';

CREATE TABLE `fa_qc_obj`
(
    `id`            int(10) unsigned NOT NULL AUTO_INCREMENT,
    `company_id`    int(10) NOT NULL COMMENT '千川表id',
    `advertiser_id` varchar(50) NOT NULL COMMENT '广告商id',
    `object_id`     varchar(50) NOT NULL COMMENT '项目id',
    `object_name`   text COMMENT '项目名称',
    `status`        tinyint(1) NOT NULL DEFAULT '1' COMMENT '计划状态   1可操作  0不可操作',
    `create_time`   bigint(16) NOT NULL COMMENT '创建时间',
    PRIMARY KEY (`id`),
    KEY             `company_id` (`company_id`) USING BTREE,
    KEY             `advertiser_id` (`advertiser_id`) USING BTREE,
    KEY             `object_id` (`object_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='项目表';

ALTER TABLE fa_company
    ADD COLUMN `kahuna` varchar(50) DEFAULT NULL COMMENT '负责人';

ALTER TABLE fa_queue_record
    MODIFY COLUMN `job_data` mediumtext NOT NULL COMMENT '任务数据（参数）';

ALTER TABLE fa_queue_record
    ADD UNIQUE INDEX job_id (job_id);

ALTER TABLE fa_qc_obj
    ADD COLUMN `marketing_goal` tinyint(1) DEFAULT 0 COMMENT '营销目标(0:未知 1:推商品  2:推直播间)',
    ADD COLUMN `ad_create_time` bigint(16) NOT NULL COMMENT '广告创建时间';

ALTER TABLE fa_queue_record
    ADD NORMAL INDEX queue_name (queue_name);
--添加索引
CREATE INDEX idx_opt_time_adv_id ON fa_qc_obj_opt_log (opt_time, adv_id);
CREATE INDEX idx_operator ON fa_qc_obj_opt_log (operator);
CREATE INDEX idx_opt_operator_time ON fa_qc_obj_opt_log (operator, opt_time, adv_id);

ALTER TABLE fa_qc_global_obj_opt_log
    ADD INDEX idx_time_adv_operator (opt_time, adv_id, operator);
ALTER TABLE fa_ad_operator
    ADD INDEX idx_name_status (name, status);
ALTER TABLE fa_qc_adv_day_cost
    ADD INDEX idx_adv_date (adv_id, cost_date);
--全域计划日志表

CREATE TABLE `fa_qc_global_obj_opt_log`
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
    PRIMARY KEY (`id`, `opt_time`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

--25-05-15
CREATE TABLE `fa_adv_score`
(
    `id`                    int(11) NOT NULL AUTO_INCREMENT,
    `adv_id`                varchar(50) NOT NULL COMMENT '广告主id',
    `year`                  varchar(20) NOT NULL COMMENT '年度',
    `one_class_score`       int(5) NOT NULL DEFAULT '0' COMMENT '一类违规年分',
    `two_three_class_score` int(5) NOT NULL DEFAULT '0' COMMENT '二，三类违规年分',
    `status`                tinyint(1) NOT NULL DEFAULT '1' COMMENT '1正常,0注销/未授权',
    `request_id`            varchar(60) DEFAULT NULL COMMENT '接口请求日志id',
    `create_time`           int(11) NOT NULL COMMENT '创建时间',
    `update_time`           int(11) NOT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COMMENT='账户累计积分表';
--2025-05-20
--全域日志索引
ALTER TABLE fa_queue_record
    ADD INDEX idx_status_queue_name (status, queue_name);

CREATE INDEX idx_opt_time_adv_id ON fa_qc_global_obj_opt_log (opt_time, adv_id);
CREATE INDEX idx_operator ON fa_qc_global_obj_opt_log (operator);
--2025-06-17
--用于标识一些删除，终止，完成的计划
ALTER TABLE fa_qc_global_obj
    ADD COLUMN `is_handle` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0未处理1已经处理';


--2025-07-17
ALTER TABLE fa_share_wallet_transfer_log
    ADD COLUMN `image` varchar(255) COMMENT '方舟截图';
--2025-10-21
ALTER TABLE fa_company
    ADD COLUMN `collaborators` tinytext COMMENT '协作者';