define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'bootstrap-table-fixed-columns'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'store/store/index',
                    add_url: 'store/store/add',
                    edit_url: 'store/store/edit',
                    multi_url: 'store/store/multi',
                    del_url: 'store/store/del',
                    transfer_records_url :"transfer_records/index",
                    bind_url: 'store/store/bind_bank_sub_account',
                    table: 'store',
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
                        {field: 'bank', title: "绑定银行", formatter: function(value,row,index) {
                                if (row.bank == 0){
                                    return "未绑定"
                                }else if (row.bank == 1){
                                    return "招商银行"
                                }
                            }},
                        {field: 'sub_account', title: "子账户账户", formatter: function(value,row,index) {
                                if (row.sub_account == ''){
                                    return "-"
                                }
                                return row.sub_account.settle_account + row.sub_account.sub_account
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
                            },{
                                name: "bind_bank_sub_account",
                                text: "绑定子账户",//按钮名称
                                classname: 'btn btn-xs btn-success btn-dialog',
                                icon: 'fa fa-plus',
                                url: 'store/store/bind_bank_sub_account',//指向控制器对应方法
                                confirm: '绑定子账户',
                                visible:function(row){
                                    if(row.bank == 0){
                                        return true;
                                    }else{
                                        return false;
                                    }
                                },
                            },{
                                name: "edit_sub_account",
                                text: "修改子账户",//按钮名称
                                classname: 'btn btn-xs btn-info btn-dialog',
                                icon: 'fa fa-align-justify',
                                url: 'store/store/edit_sub_account',//指向控制器对应方法
                                confirm: '修改子账户',
                                visible:function(row){
                                    if(row.bank != 0){
                                        return true;
                                    }else{
                                        return false;
                                    }
                                },
                            },{
                                name: "off_zh_sub_account",
                                text: "注销招行子账户",//按钮名称
                                // classname: 'btn btn-xs btn-success btn-',
                                classname: 'btn btn-xs btn-danger btn-magic btn-ajax',
                                icon: 'fa fa-times',
                                url: 'store/store/off_zh_sub_account',//指向控制器对应方法
                                confirm: '确定注销招行子账户吗？',
                                visible:function(row){
                                    if(row.bank == 1){
                                        return true;
                                    }else{
                                        return false;
                                    }
                                },
                            },{
                                name: "reset_pwd",
                                text: "重置密码",//按钮名称
                                classname: 'btn btn-xs btn-success btn-dialog ',
                                // classname: 'btn btn-xs btn-success btn-magic btn-dialog',
                                icon: 'fa fa-refresh',
                                url: 'store/store/reset_pwd',//指向控制器对应方法
                                confirm: '重置密码',
                                visible: function (row) {
                                    //返回true时按钮显示,返回false隐藏
                                    return true;
                                }
                            }
                            ], table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
                    ]
                ],
                queryParams:function (params) {
                    params.group_id = document.getElementById('group_id').value;
                    return params;
                }
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
        reset_pwd: function () {
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