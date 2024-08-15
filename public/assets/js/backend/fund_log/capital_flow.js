define(['jquery', 'bootstrap', 'table', 'form'], function ($, undefined, Table, Form) {

    var Controller = {
        index: function () {
            Controller.api.bindevent();
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'fund_log/capital_flow/index' + location.search,
                    table: 'capital_flow',
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
                        {field: 'cost', title: "消耗", formatter: function(value,row,index) {

                                var processedValue = row.cost / 100000;
                                return processedValue.toFixed(2); // 返回处理后的值，保留两位小数
                            }, operate: 'LIKE'},
                        {field: 'date', title: "日期" }
                        // {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate},
                    ]
                ],
                queryParams:function (params) {
                    let time_data = document.getElementById('dateRange').value.split(' - ');
                    params.start_date = time_data[0];
                    params.end_date = time_data[1];
                    return params;
                }
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
