define(['jquery', 'bootstrap', 'store', 'table', 'form'], function ($, undefined, Store, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'tencent/money_log/index' + location.search,
                    table: 'tencent_transaction_log',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
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
                        {field: 'id', title: __('Id'), visible: false},
                        {field: 'id', title: "ID"},
                        {field: 'account_id', title: "千川账户ID"},
                        {field: 'sub_wallet_id', title: "子钱包ID"},
                        {field: 'money', title: "变动金额"},
                        {field: 'type', title: "类型", formatter: function(value,row,index) {
                            switch (row.type) {
                                case 1: return "总后台增加余额";
                                case 2: return "总后台扣款";
                                case 3: return "回单充值";
                                case 4: return "转入";
                                case 5: return "转出";
                                case 8: return "共享钱包转入";
                                case 9: return "共享钱包转出";
                                default: return "-";
                            }
                        }, operate: 'LIKE'},
                        {field: 'explain', title: "说明"},
                        {field: 'account_type', title: "账户类型", formatter: function(value,row,index) {
                            if (row.account_type == 1){
                                return "公账";
                            }else if(row.account_type == 2){
                                return "私账";
                            }
                            return "-";
                        }, operate: 'LIKE'},
                        {field: 'balance_surplus', title: "变动后钱包余额", formatter: function(value,row,index) {
                            if (row.balance_surplus == 0 && row.credit_limit_surplus == 0){
                                return "-";
                            }else{
                                return row.balance_surplus;
                            }
                        }, operate: 'LIKE'},
                        {field: 'credit_limit_surplus', title: "变动后授信余额", formatter: function(value,row,index) {
                            if (row.balance_surplus == 0 && row.credit_limit_surplus == 0){
                                return "-";
                            }else{
                                return row.credit_limit_surplus;
                            }
                        }, operate: 'LIKE'},
                        {field: 'create_time', title: "时间", formatter: Table.api.formatter.datetime},
                    ]
                ],
                queryParams: function (params) {
                    let time_data = document.getElementById('dateRange').value.split(' - ');
                    params.start_date = time_data[0];
                    params.end_date = time_data[1];
                    params.money = document.getElementById('money').value;
                    params.account_id = $("select[name='account_id']").val();
                    params.sub_wallet_id = $("select[name='sub_wallet_id']").val();
                    return params;
                }
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
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