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
INSERT INTO `dmc`.`fa_external_accounts` (`id`, `account`, `secret`, `platform`, `create_time`, `update_time`, `status`)
VALUES (1, '20240919001', '密钥自行生成添加', 'yuanxi_crm', NULL, NULL, 1);