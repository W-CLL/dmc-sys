-- 素材追投白名单表
-- 创建时间：2025-01-27
-- 用途：管理不进行素材追投操作的公司白名单

CREATE TABLE `fa_material_whitelist` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
    `company_name` varchar(255) NOT NULL COMMENT '公司名称',
    `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：1启用，0禁用',
    `remark` varchar(500) DEFAULT NULL COMMENT '备注说明',
    `create_time` int(11) NOT NULL COMMENT '创建时间',
    `update_time` int(11) DEFAULT NULL COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `company_name` (`company_name`) USING BTREE,
    KEY `status` (`status`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='素材追投白名单表';

-- 扩展表结构以支持公司级别和广告主级别过滤
ALTER TABLE `fa_material_whitelist`
ADD COLUMN `filter_type` tinyint(1) NOT NULL DEFAULT '1' COMMENT '过滤类型：1=公司级别，2=广告主级别' AFTER `id`,
ADD COLUMN `adv_id` varchar(50) DEFAULT NULL COMMENT '广告主ID（filter_type=2时使用）' AFTER `company_name`,
MODIFY COLUMN `company_name` varchar(255) DEFAULT NULL COMMENT '公司名称（filter_type=1时使用）',
ADD KEY `filter_type` (`filter_type`) USING BTREE,
ADD KEY `adv_id` (`adv_id`) USING BTREE,
DROP KEY `company_name`,
ADD KEY `company_name` (`company_name`) USING BTREE;