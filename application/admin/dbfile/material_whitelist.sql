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