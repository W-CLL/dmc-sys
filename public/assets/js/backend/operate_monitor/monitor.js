define(['jquery', 'bootstrap', 'company', 'table', 'form'], function ($, undefined, Company, Table, Form) {

    var Controller = {
        index: function () {
            Controller.api.bindevent();
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'operate_monitor/monitor/index' + location.search,
                    table: 'plan_opt_log',
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
                columns: [
                    [
                        {field: 'id', title: "ID"},
                        {field: 'advertiser_id', title: "广告主id"},
                        {field: 'obj_id', title: "项目id"},
                        {field: 'content_title', title: "主题内容"},
                        {field: 'object_name', title: "项目名称"},
                        {field: 'object_type', title: "项目类型"},
                        {field: 'operator', title: "操作人"},
                        {field: 'opt_ip', title: "操作ip"},
                        {field: 'kahuna', title: "负责人"},
                        {field: 'opt_time', title:"操作时间" ,formatter: Table.api.formatter.datetime},
                        {field: 'content_log', title: "日志内容"},
                    ]
                ],
                queryParams:function (params) {
                    let time_data = document.getElementById('dateRange').value.split(' - ');
                    params.start_date = time_data[0];
                    params.end_date = time_data[1];
                    params.is_bm = document.getElementById('is_bm').value;
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
