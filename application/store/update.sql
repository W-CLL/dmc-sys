--2024.08.10
--2024.8.16 已处理
CREATE TABLE `fa_store_refund`
(
    `id`                  int(11) NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `wallet`              decimal(10, 2) NOT NULL COMMENT '当前账号钱包已充值余额',
    `credit`              decimal(10, 2) NOT NULL COMMENT '当前账号已充值授信余额',
    `store_id`            int(11) NOT NULL COMMENT '商户ID',
    `type`                tinyint(1) NOT NULL COMMENT '账户类型:1公账,2私账',
    `discount_percentage` decimal(6, 3) DEFAULT '0.000' COMMENT '折扣百分比',
    `create_time`         int(11) DEFAULT NULL COMMENT '创建时间',
    `update_time`         int(11) DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci COMMENT='千川账户退款关联表';
--2024.08.13
--2024.8.16 已处理
ALTER TABLE `fa_store_money_log` MODIFY COLUMN `discount_percentage` DECIMAL (5,3);
--2024.08.13
--2024.8.16 已处理
ALTER TABLE `fa_transfer_records` MODIFY COLUMN `discount_percentage` DECIMAL (5,3);
--2024.09.13
--2024.9.13 测试服已处理
--2024.9.13 已处理
CREATE TABLE `fa_share_wallet_transfer_log`
(
    `id`                     int(10) unsigned NOT NULL AUTO_INCREMENT,
    `store_id`               int(11) NOT NULL COMMENT '转账用户id',
    `sub_wallet_id`          varchar(30)    NOT NULL COMMENT '子钱包ID',
    `main_wallet_id`         varchar(30)    NOT NULL COMMENT '主钱包ID',
    `transfer_direction`     tinyint(1) NOT NULL DEFAULT '1' COMMENT '1转入，2转出',
    `rebate`                 decimal(10, 2) NOT NULL DEFAULT '0.00' COMMENT '返点',
    `money`                  decimal(10, 2) NOT NULL COMMENT '转账金额',
    `deduction_credit_limit` decimal(10, 2) NOT NULL DEFAULT '0.00' COMMENT '扣除授信额度',
    `deduction_balance`      decimal(10, 2) NOT NULL DEFAULT '0.00' COMMENT '扣除余额',
    `actual_money`           decimal(10, 2) NOT NULL DEFAULT '0.00' COMMENT '实际金额',
    `transfer_serial`        varchar(50)             DEFAULT NULL COMMENT '转账编号',
    `record`                 text COMMENT '转账返回记录',
    `status`                 tinyint(1) NOT NULL DEFAULT '0' COMMENT '转账状态：0刚创建，1成功，2失败',
    `create_time`            int(11) NOT NULL COMMENT '创建时间',
    `update_time`            int(11) DEFAULT NULL COMMENT '更新时间',
    `account_type`           tinyint(1) NOT NULL DEFAULT '1' COMMENT '账户类型:1公账,2私账',
    `discount_percentage`    decimal(5, 3)  NOT NULL DEFAULT '0.00' COMMENT '折扣百分比',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='共享钱包转账记录表';
--2024.09.13
--2024.9.13 测试服已处理
--2024.9.13 已处理
ALTER TABLE fa_store_money_log
    ADD COLUMN `swtl_id` int(11) DEFAULT NULL COMMENT '共享钱包转账记录id';
ALTER TABLE fa_store_money_log
    MODIFY COLUMN `type` tinyint (1) COMMENT '类型：1为总后台增加余额，2为总后台扣款，3回单充值，4千川转入，5千川转出,6授信额度充值，7子账户充值，8共享钱包转入，9共享钱包转出';
ALTER TABLE fa_store_refund
    ADD COLUMN `wallet_type` tinyint(1) DEFAULT 1 COMMENT '目标钱包类型：1千川，2共享';
--2024.09.25
--2024.09.27 测试服已更新
--2024.9.30 正式服已处理
ALTER TABLE `fa_share_wallet_transfer_log`  ADD COLUMN `remark`varchar(255)  COMMENT '备注';
ALTER TABLE `fa_transfer_records`  ADD COLUMN `remark` varchar(255)  COMMENT '备注';

