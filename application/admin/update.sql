--2024.08.19
--2024.8.19 已处理
ALTER TABLE fa_store ADD COLUMN `bank` tinyint(1) DEFAULT 0 COMMENT '绑定银行（0：未绑定，1：招行）';
--2024.08.19
--2024.8.19 已处理
create table fa_zh_sub_account(
                      id int(10) UNSIGNED primary key auto_increment comment 'ID',
                      store_id int(10) not null comment '用户id',
                      bus_mod varchar(5) comment '业务模式',
                      settle_account varchar(40) not null comment '结算账号',
                      branch_num int(2) comment '分行号',
                      sub_account varchar(20) not null comment '记账子单元编号',
                      sub_name varchar(64) not null comment '记账子单元名称',
                      can_overdraw varchar(1) not null comment '是否可透支(Y：允许透支 N：不允许透支 X：不适用)',
                      return_method varchar(1) not null comment '支付失败退回方式(Y：退回原记账子单元 N：退回结算户 X：不适用)',
                      can_off varchar(1) not null comment '余额非零时是否可关闭(Y：可关闭 N：不可关闭 X：不适用)',
                      whether_limit varchar(1) not null comment '是否设置收款限额(N：不设置收款额度， Y：设置收款额度 X：不适用)',
                      max_limit decimal(15,2) not null comment '余额上限额度',
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='绑定招行子账户，不理解的字段去看文档：https://openbiz.cmbchina.com/developer/UI/Business/CloudDirectConnect/Public/DocumentCenter/DocDetail.aspx?bizkey=DCCT20231226155549458&fabizkey=1&treeID=100082838';

ALTER TABLE fa_zh_sub_account ADD UNIQUE INDEX sub_account (sub_account);
ALTER TABLE fa_zh_sub_account ADD UNIQUE INDEX store_id (store_id);