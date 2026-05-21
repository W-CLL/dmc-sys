-- 2026.05.21公司名称变更日志表
CREATE TABLE `fa_company_name_log`
(
    `id`               bigint unsigned  NOT NULL AUTO_INCREMENT,
    `company_id`       int(10) unsigned NOT NULL COMMENT '公司ID',
    `advertiser_id`    varchar(50)      NOT NULL COMMENT '广告主ID',
    `old_company_name` varchar(255) DEFAULT NULL COMMENT '旧名称',
    `new_company_name` varchar(255) DEFAULT NULL COMMENT '新名称',
    `create_time`      bigint(16)       NOT NULL COMMENT '记录时间',
    PRIMARY KEY (`id`),
    KEY `idx_company_id` (`company_id`),
    KEY `idx_advertiser_id` (`advertiser_id`),
    KEY `idx_create_time` (`create_time`)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4 COMMENT ='公司名称变更日志';
-- 2026.05.21 加字段
ALTER TABLE fa_company_name_log
    ADD COLUMN is_notified   tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已通知：0未通知 1已通知',
    ADD COLUMN notified_time bigint(16) DEFAULT NULL COMMENT '通知时间';

-- 2026.05.21触发器
DELIMITER $$
CREATE TRIGGER trg_company_name_change
    AFTER UPDATE
    ON fa_company
    FOR EACH ROW
BEGIN
    -- 名称发生变化才记录
    IF (
        LOWER(TRIM(COALESCE(OLD.company_name, '')))
            <>
        LOWER(TRIM(COALESCE(NEW.company_name, '')))
        ) THEN
        INSERT INTO fa_company_name_log (company_id,
                                         advertiser_id,
                                         old_company_name,
                                         new_company_name,
                                         create_time)
        VALUES (OLD.id,
                OLD.advertiser_id,
                OLD.company_name,
                NEW.company_name,
                UNIX_TIMESTAMP());
    END IF;
END$$
DELIMITER ;

