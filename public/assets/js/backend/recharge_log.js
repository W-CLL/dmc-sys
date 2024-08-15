define(['jquery', 'bootstrap', 'company', 'table', 'form'], function ($, undefined, Company, Table, Form) {

    var Controller = {
        index: function () {
            Controller.api.bindevent();
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'recharge_log/index' + location.search,
                    table: 'recharge_log',
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
                        {field: 'username', title: "账号名称"},
                        {field: 'gift_percentage', title: "赠送比例"},
                        {field: 'money', title: "充值金额"},
                        {field: 'gifts_money', title: "赠送金额"},
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
                        {field: 'create_time', title:"时间" ,formatter: Table.api.formatter.datetime},
                        {field: 'operate', title: __('Operate'), buttons: [{
                                name: "transfer_records",
                                text: "审核",//按钮名称
                                classname: 'btn btn-xs btn-success btn-magic ',
                                // classname: 'btn btn-xs btn-success btn-magic btn-dialog',
                                icon: '',
                                url: 'recharge_log/auditing',//指向控制器对应方法
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
