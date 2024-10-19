define(['jquery', 'bootstrap', 'company', 'table', 'form'], function ($, undefined, Company, Table, Form) {

    var Controller = {
        index: function () {
            Controller.api.bindevent();
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'store_money_log/index' + location.search,
                    table: 'store_money_log',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                // ... 其他配置 ...
                search: false, // 禁用默认搜索
                commonSearch: false, // 启用普通表单搜索
                searchFormVisible: true, // 控制搜索栏是否显示在页面上
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                fixedColumns: true,
                fixedRightNumber: 1,
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('Id'),visible: false},
                        {field: 'id', title: "ID"},
                        {field: 'advertiser_id', title: "千川id"},
                        {field: 'store_username', title: "账户名称"},
                        {field: 'money', title: "变更金额"},
                        {field: 'type', title: "类型", formatter: function(value,row,index) {
                            if (row.type == 1){
                                return "平台赠送"
                            }else if (row.type == 2){
                                return "平台扣款"
                            }else if (row.type == 3){
                                return "充值"
                            }else if (row.type == 4){
                                return "千川转入"
                            }else if (row.type == 5){
                                return "千川转出"
                            }else if (row.type == 6){
                                return "授信充值"
                            }else if (row.type == 7){
                                return "子账号充值"
                            }else if (row.type == 8){
                                return "转入共享钱包"
                            }else if (row.type == 9){
                                return "共享钱包转出"
                            }
                            }, operate: 'LIKE'},
                        {field: 'explain', title: "说明"},
                        {field: 'account_type', title: "账户类型" ,formatter: function(value,row,index) {
                                if (row.account_type == 1){
                                    return "公"
                                }else if(row.account_type == 2){
                                    return "私"
                                }
                            }, operate: 'LIKE'},
                        {field: 'balance_surplus', title: "变动后钱包余额", formatter: function(value,row,index) {
                                if (row.balance_surplus == 0 && row.credit_limit_surplus == 0){
                                    return "-"
                                }else{
                                    return row.balance_surplus
                                }
                            }, operate: 'LIKE'},
                        {field: 'credit_limit_surplus', title: "变动后授信余额", formatter: function(value,row,index) {
                                if (row.balance_surplus == 0 && row.credit_limit_surplus == 0){
                                    return "-"
                                }else{
                                    return row.credit_limit_surplus
                                }
                            }, operate: 'LIKE'},
                        {field: 'create_time', title:"时间" ,formatter: Table.api.formatter.datetime},
                        // {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate},
                    ]
                ],
                queryParams:function (params) {
                    let time_data = document.getElementById('dateRange').value.split(' - ');
                    params.start_date = time_data[0];
                    params.end_date = time_data[1];
                    params.account_id = document.getElementById('account_id').value;
                    params.store_id = document.getElementById('store_id').value;
                    params.money = document.getElementById('money').value;
                    params.id = document.getElementById('id').value;
                    return params;
                }
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        recharge_list: function () {
            Controller.api.bindevent();
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'store_money_log/recharge_list' + location.search,
                    table: 'store_money_log',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                // ... 其他配置 ...
                search: false, // 禁用默认搜索
                commonSearch: false, // 启用普通表单搜索
                searchFormVisible: true, // 控制搜索栏是否显示在页面上
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                fixedColumns: true,
                fixedRightNumber: 1,
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('Id'),visible: false},
                        {field: 'order_number', title: "唯一标识"},
                        {field: 'salesman',title: '绑定人员'},
                        {field: 'username', title: "账号名称"},
                        {field: 'money', title: "充值金额"},
                        {field: 'deduction_credit_limit', title: "授信额度扣款"},
                        {field: 'receipt_image', title: "银行回单", formatter: function(value,row,index) {
                                if (row.receipt_image){
                                    return `<a href="` + row.receipt_image +`" target="_blank" class="thumbnail"><img  style="width: 50px;height: 50px;" src="` + row.receipt_image + `"class="img-responsive"></a>`
                                }
                            }, operate: 'LIKE'},
                        {field: 'status', title: "状态", formatter: function(value,row,index) {
                                if (row.status == 0){
                                    return "未审核"
                                }else if (row.status == 1){
                                    return "通过"
                                }else if (row.status == 2){
                                    return "拒绝"
                                }
                            }, operate: 'LIKE'},
                        {field: 'auditing_explain', title: "审核备注"},

                        {field: 'create_time', title:"时间" ,formatter: Table.api.formatter.datetime},
                        {field: 'operate', title: __('Operate'), buttons: [{
                                name: "transfer_records",
                                text: "审核",//按钮名称
                                classname: 'btn btn-xs btn-success btn-magic ',
                                // classname: 'btn btn-xs btn-success btn-magic btn-dialog',
                                icon: '',
                                url: 'store_money_log/auditing',//指向控制器对应方法
                                confirm: '审核',
                                visible: function (row) {
                                    //返回true时按钮显示,返回false隐藏
                                    return true;
                                }
                            }], table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate},
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
