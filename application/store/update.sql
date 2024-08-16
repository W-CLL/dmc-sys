--2024.08.10
--2024.8.16 已处理
CREATE TABLE `fa_store_refund`
(
    `id`                  int(11) NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `wallet`              decimal(10, 2)                    NOT NULL COMMENT '当前账号钱包已充值余额',
    `credit`              decimal(10, 2)                    NOT NULL COMMENT '当前账号已充值授信余额',
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
