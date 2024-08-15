define(['jquery', 'bootstrap', 'store', 'table', 'form'], function ($, undefined, Store, Table, Form) {

    var Controller = {
        index: function () {
            Controller.api.bindevent();
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'transfer_records/index',
                    table: 'transfer_records',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                // ... 其他配置 ...
                search: false, // 禁用默认搜索
                searchFormVisible: true, // 控制搜索栏是否显示在页面上
                searchFormTemplate: 'customformtpl',
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                fixedColumns: true,
                fixedRightNumber: 1,
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('Id'),visible: false},
                        {field: 'advertiser_id',title: '千川id'},
                        {field: 'company_name',title:'公司名称'},
                        {field: 'money', title: "金额"},
                        {field: 'transfer_direction', title: "类型", formatter: function(value,row,index) {
                            if (row.transfer_direction == 1){
                                return "转入"
                            }else if (row.transfer_direction == 2){
                                return "转出"
                            }
                            }, operate: 'LIKE'},
                        {field: 'transfer_serial', title: "转账编号"},
                        {field: 'status', title: "状态", formatter: function(value,row,index) {
                                if (row.status == 0){
                                    return "未开始转账"
                                }else if (row.status == 1){
                                    return "成功"
                                }else if (row.status == 2){
                                    return "失败"
                                }else if (row.status == 3){
                                    return "未转账"
                                }else if (row.status == 4){
                                    return "转账中"
                                }else if (row.status == 5){
                                    return "查询转账状态失败"
                                }else if (row.status == 6){
                                    return "转账成功，扣款或加钱失败"
                                }
                            }, operate: 'LIKE'},
                        {field: 'explain', title: "失败原因"},
                        {field: 'image', title: "转账截图", formatter: function(value,row,index) {
                                if (row.image){
                                    return `<a href="/` + row.image +`" target="_blank" class="thumbnail"><img src="/` + row.image + `"class="img-responsive"></a>`
                                }
                            }, operate: 'LIKE'},

                        {field: 'create_time', title:"时间" ,formatter: Table.api.formatter.datetime},
                        // {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate},
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
