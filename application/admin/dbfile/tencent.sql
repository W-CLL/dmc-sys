CREATE TABLE `fa_tencent_account` (
                                      `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'id',
                                      `store_id` int(11) NOT NULL DEFAULT '0' COMMENT '绑定账号id',
                                      `group_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '组别ID',
                                      `account_id` int(11) NOT NULL DEFAULT '0' COMMENT '子客id',
                                      `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '主体名称',
                                      `status` tinyint(2) NOT NULL DEFAULT '0' COMMENT '账号状态',
                                      `reject_message` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '审核信息',
                                      `agency_account_id` int(11) NOT NULL DEFAULT '0' COMMENT '所属服务商id',
                                      `account_type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '账户类型:1公账,2私账',
                                      `discount_percentage` decimal(7,4) NOT NULL DEFAULT '0.0000' COMMENT '折扣百分比',
                                      `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
                                      `update_time` int(11) DEFAULT '0' COMMENT '更新时间',
                                      PRIMARY KEY (`id`),
                                      UNIQUE KEY `account_id` (`account_id`) USING BTREE,
                                      KEY `normal` (`store_id`,`group_id`,`status`,`create_time`,`update_time`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `fa_tencent_refund` (
                                     `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'ID',
                                     `wallet` decimal(10,2) NOT NULL COMMENT '当前账号钱包已充值余额',
                                     `credit` decimal(10,2) NOT NULL COMMENT '当前账号已充值授信余额',
                                     `store_id` int(11) NOT NULL COMMENT '商户ID',
                                     `platform_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_danish_ci NOT NULL COMMENT '平台id',
                                     `type` tinyint(1) NOT NULL COMMENT '账户类型:1公账,2私账',
                                     `discount_percentage` decimal(6,3) DEFAULT '0.000' COMMENT '折扣百分比',
                                     `wallet_type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '目标钱包类型：1千川，2共享',
                                     `create_time` bigint(11) DEFAULT NULL COMMENT '创建时间',
                                     `update_time` bigint(11) DEFAULT NULL COMMENT '更新时间',
                                     PRIMARY KEY (`id`),
                                     KEY `normal` (`store_id`,`platform_id`,`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `fa_tencent_store` (
                                    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                                    `store_id` int(11) NOT NULL,
                                    `public_money_tencent` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '公账钱包',
                                    `private_money_tencent` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '私有钱包',
                                    `public_credit_limit_tencent` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '授信额度(公)',
                                    `private_credit_limit_tencent` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '授信额度(私)',
                                    `public_spending_credit_limit_tencent` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '已使用授信额度(公)',
                                    `private_spending_credit_limit_tencent` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '已使用授信额度(私)',
                                    `public_discount_percentage_tencent` decimal(7,4) NOT NULL DEFAULT '0.0000' COMMENT '折扣百分比(公)',
                                    `private_discount_percentage_tencent` decimal(7,4) NOT NULL COMMENT '折扣百分比(私)',
                                    `create_time` int(11) NOT NULL COMMENT '创建时间',
                                    `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
                                    PRIMARY KEY (`id`),
                                    UNIQUE KEY `only` (`store_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `fa_tencent_transaction_log` (
                                              `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'id',
                                              `admin_id` int(11) DEFAULT NULL COMMENT '管理员id',
                                              `admin_username` varchar(20) CHARACTER SET utf8mb4 DEFAULT NULL COMMENT '管理员用户名',
                                              `store_id` int(11) NOT NULL COMMENT '商户id',
                                              `tencent_account_id` int(11) DEFAULT NULL COMMENT '关联id',
                                              `account_id` varchar(50) CHARACTER SET utf8mb4 DEFAULT NULL COMMENT '腾讯子客id',
                                              `transfer_log_id` int(11) DEFAULT NULL COMMENT '转账记录id',
                                              `money` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '金额',
                                              `deduction_balance` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '扣除余额',
                                              `deduction_credit_limit` decimal(10,2) DEFAULT '0.00' COMMENT '授信额度扣款',
                                              `receipt_image` varchar(255) CHARACTER SET utf8mb4 DEFAULT NULL COMMENT '银行回单',
                                              `explain` varchar(255) CHARACTER SET utf8mb4 NOT NULL COMMENT '说明',
                                              `order_number` varchar(200) CHARACTER SET utf8mb4 DEFAULT NULL COMMENT '银行单号',
                                              `rebate` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '返点',
                                              `username` varchar(50) CHARACTER SET utf8mb4 DEFAULT NULL COMMENT '商户名称',
                                              `discount_percentage` decimal(5,3) DEFAULT NULL,
                                              `actual_money` decimal(10,2) DEFAULT '0.00' COMMENT '实际金额',
                                              `type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '类型：1为总后台增加余额，2为总后台扣款，3回单充值，4转入，5转出，8共享钱包转入，9共享钱包转出',
                                              `account_type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '账户类型:1公账,2私账',
                                              `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '状态，0未审核，1通过，2不通过',
                                              `auditing_admin_id` int(11) DEFAULT '0' COMMENT '审核人员id',
                                              `auditing_explain` varchar(255) CHARACTER SET utf8mb4 DEFAULT NULL COMMENT '审核说明',
                                              `create_time` int(11) NOT NULL COMMENT '创建时间',
                                              `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
                                              `before_money` decimal(10,2) DEFAULT '0.00' COMMENT '当前余额',
                                              `today_money` decimal(10,2) DEFAULT '0.00' COMMENT '变动后余额',
                                              `swtl_id` int(11) DEFAULT NULL COMMENT '共享钱包转账记录id',
                                              `balance_surplus` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '变动后钱包余额',
                                              `credit_limit_surplus` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '变动后授信余额',
                                              `from` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1：dmc后台； 2：robot',
                                              PRIMARY KEY (`id`),
                                              UNIQUE KEY `only` (`order_number`) USING BTREE,
                                              KEY `normal` (`admin_id`,`store_id`,`tencent_account_id`,`account_id`,`transfer_log_id`,`swtl_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `fa_tencent_transfer_log` (
                                           `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                                           `store_id` int(11) NOT NULL COMMENT '转账账号id',
                                           `tencent_account_id` int(11) NOT NULL COMMENT '关联id',
                                           `account_id` int(11) NOT NULL COMMENT '腾讯广告账户id',
                                           `transfer_direction` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1转入，2转出',
                                           `rebate` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '返点',
                                           `money` decimal(10,2) NOT NULL COMMENT '转账金额',
                                           `deduction_credit_limit` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '扣除授信额度',
                                           `deduction_balance` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '扣除余额',
                                           `actual_money` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '实际金额',
                                           `order_uid` varchar(50) CHARACTER SET utf8mb4 DEFAULT NULL COMMENT '转账编号',
                                           `record` text CHARACTER SET utf8mb4 COMMENT '转账返回记录',
                                           `image` varchar(255) CHARACTER SET utf8mb4 DEFAULT NULL COMMENT '截图',
                                           `create_time` int(11) NOT NULL COMMENT '创建时间',
                                           `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
                                           `account_type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '账户类型:1公账,2私账',
                                           `discount_percentage` decimal(5,3) DEFAULT NULL,
                                           `remark` varchar(255) CHARACTER SET utf8mb4 DEFAULT NULL COMMENT '备注',
                                           `from` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1：dmc后台； 2：robot',
                                           PRIMARY KEY (`id`),
                                           UNIQUE KEY `only` (`order_uid`) USING BTREE,
                                           KEY `normal` (`store_id`,`tencent_account_id`,`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;





