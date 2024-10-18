define(['jquery', 'bootstrap', 'company', 'table', 'form'], function ($, undefined, Company, Table, Form) {

    var Controller = {
        index: function () {
            Controller.api.bindevent();
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'sub_wallet_transfer_details/index' + location.search,
                    table: 'sub_wallet_transfer_details',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                // ... 其他配置 ...
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                // fixedColumns: true,
                // fixedRightNumber: 1,
                searchFormVisible: true,
                searchFormTemplate: 'customformtpl',
                columns: [
                    [
                        {field: 'id', title: "ID"},
                        {field: 'store.username', title: "商户"},
                        {field: 'store_money_log.id', title: "对应资金流水ID"},
                        {field: 'sub_wallet_id', title: "子钱包ID"},
                        {field: 'money', title: "充值金额"},
                        {field: 'rebate', title: "返点"},
                        {field: 'actual_money', title: "实际到账金额"},
                        {field: 'transfer_direction', title: "转账类型", formatter: function(value,row,index) {
                                if (row.transfer_direction == 1){
                                    return "转入"
                                }else if (row.transfer_direction == 2){
                                    return "转出"
                                }
                            }, operate: 'LIKE'},
                        {field: 'account_type', title: "账户类型", formatter: function(value,row,index) {
                                if (row.account_type == 1){
                                    return "公"
                                }else if (row.account_type == 2){
                                    return "私"
                                }
                            }, operate: 'LIKE'},
                        {field: 'status', title: "状态", formatter: function(value,row,index) {
                                if (row.status == 0) {
                                    return '<button class="btn btn-warning disabled">未知</button>';
                                } else if (row.status == 1) {
                                    return '<button class="btn btn-success disabled">成功</button>';
                                } else if (row.status == 2) {
                                    return '<button class="btn btn-danger disabled">失败</button>';
                                }
                            }, operate: 'LIKE'},
                        {field: 'fail_reason', title: "失败原因"},
                        {field: 'create_time', title:"创建时间" ,formatter: Table.api.formatter.datetime},
                        {field: 'update_time', title:"更新时间" ,formatter: Table.api.formatter.datetime},
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});
