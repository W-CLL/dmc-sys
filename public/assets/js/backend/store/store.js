define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'store/store/index',
                    add_url: 'store/store/add',
                    edit_url: 'store/store/edit',
                    multi_url: 'store/store/multi',
                    transfer_records_url :"transfer_records/index",
                    table: 'store',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: "id", sortable: true},
                        {field: 'group.name', title:"角色组"},
                        {field: 'username', title: "账户名", operate: 'LIKE'},
                        {field: 'public_money',title: '钱包余额(公)'},
                        {field: 'private_money',title: '钱包余额(私)'},
                        {field: 'public_credit_limit',title: '剩余授信额度(公)'},
                        {field: 'private_credit_limit',title: '剩余授信额度(私)'},
                        {field: 'public_spending_credit_limit',title: '已使用额度(公)'},
                        {field: 'private_spending_credit_limit',title: '已使用额度(私)'},
                        {field: 'public_discount_percentage',title: '折扣比例(公)'},
                        {field: 'private_discount_percentage',title: '折扣比例(私)'},
                        {field: 'login_time', title: "登录时间", formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange', sortable: true},
                        {field: 'loginip', title: "登录ip", formatter: function(value,row,index) {
                                if (row.loginip == ""){
                                    return "无"
                                }
                                return row.loginip
                            }},
                        {field: 'status', title: "状态", formatter: function(value,row,index) {
                            if (row.status==0){
                                return "禁用"
                            }else if (row.status == 1){
                                return "正常"
                            }
                            }},
                        {field: 'operate', title: __('Operate'), buttons: [{
                                name: "transfer_records",
                                text: "资金流水",//按钮名称
                                classname: 'btn btn-xs btn-success btn-magic ',
                                // classname: 'btn btn-xs btn-success btn-magic btn-dialog',
                                icon: 'fa fa-magic',
                                url: 'transfer_records/store_list',//指向控制器对应方法
                                confirm: '查看当前用户资金流水',
                                visible: function (row) {
                                    //返回true时按钮显示,返回false隐藏
                                    return true;
                                }
                            }], table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
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