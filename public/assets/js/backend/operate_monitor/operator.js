define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'bootstrap-table-fixed-columns'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'operate_monitor/operator/index',
                    add_url: 'operate_monitor/operator/add',
                    edit_url: 'operate_monitor/operator/edit',
                    multi_url: 'operate_monitor/operator/multi',
                    table: 'operator',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                fixedColumns: true, // 固定列代码
                fixedRightNumber: 1, // 固定右侧第一列
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: "id", sortable: true},
                        {field: 'name', title:"名称"},
                        {field: 'type', title: "角色", operate: 'LIKE',formatter: function(value,row,index) {
                                if (row.type==0){
                                    return "运营"
                                }else if (row.type == 1){
                                    return "客户"
                                }
                            }},
                        {field: 'status', title: "状态", formatter: function(value,row,index) {
                                if (row.status==0){
                                    return "禁用"
                                }else if (row.status == 1){
                                    return "正常"
                                }
                            }},
                        {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}

                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        add: function () {
            Controller.api.bindevent();
        },
        edit: function () {
            Controller.api.bindevent();
        },
        bind_bank_sub_account: function () {
            Controller.api.bindevent();
        },
        edit_sub_account: function () {
            Controller.api.bindevent();
        },
        transfer_records: function () {
            Controller.api.bindevent();
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});