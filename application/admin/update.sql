--2024.08.19
--2024.8.19 已处理
ALTER TABLE fa_store ADD COLUMN `bank` tinyint(1) DEFAULT 0 COMMENT '绑定银行（0：未绑定，1：招行）';
--2024.08.19
--2024.8.19 已处理
CREATE TABLE `fa_zh_sub_account` (
                                     `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
                                     `store_id` int(10) NOT NULL COMMENT '用户id',
                                     `bus_mod` varchar(5) DEFAULT NULL COMMENT '业务模式',
                                     `settle_account` varchar(40) NOT NULL COMMENT '结算账号',
                                     `branch_num` int(2) DEFAULT NULL COMMENT '分行号',
                                     `sub_account` varchar(20) NOT NULL COMMENT '记账子单元编号',
                                     `sub_name` varchar(64) NOT NULL COMMENT '记账子单元名称',
                                     `can_overdraw` varchar(1) NOT NULL COMMENT '是否可透支(Y：允许透支 N：不允许透支 X：不适用)',
                                     `return_method` varchar(1) NOT NULL COMMENT '支付失败退回方式(Y：退回原记账子单元 N：退回结算户 X：不适用)',
                                     `can_off` varchar(1) NOT NULL COMMENT '余额非零时是否可关闭(Y：可关闭 N：不可关闭 X：不适用)',
                                     `whether_limit` varchar(1) NOT NULL COMMENT '是否设置收款限额(N：不设置收款额度， Y：设置收款额度 X：不适用)',
                                     `max_limit` decimal(15,2) NOT NULL DEFAULT '0.00' COMMENT '余额上限额度',
                                     PRIMARY KEY (`id`),
                                     UNIQUE KEY `sub_account` (`sub_account`) USING BTREE,
                                     UNIQUE KEY `store_id` (`store_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='绑定招行子账户，不理解的字段去看文档：https://openbiz.cmbchina.com/developer/UI/Business/CloudDirectConnect/Public/DocumentCenter/DocDetail.aspx?bizkey=DCCT20231226155549458&fabizkey=1&treeID=100082838';
--2024.09.13
--2024.9.13 已处理
CREATE TABLE `fa_qc_share_wallet` (
                                      `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
                                      `sub_wallet_id` varchar(30) NOT NULL COMMENT '子钱包ID',
                                      `bind_store_id` int(10) DEFAULT NULL COMMENT '绑定的商户ID',
                                      `main_wallet_id` varchar(30) NOT NULL COMMENT '主钱包id',
                                      `sub_wallet_type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '账户类型:0未绑定,1公账,2私账',
                                      PRIMARY KEY (`id`),
                                      UNIQUE KEY `sub_wallet_id` (`sub_wallet_id`) USING BTREE,
                                      KEY `store_id` (`bind_store_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='子钱包列表';
