define(['jquery', 'bootstrap', 'company', 'table', 'form'], function ($, undefined, Company, Table, Form) {

    var Controller = {
        index: function () {
            Controller.api.bindevent();
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'operate_monitor/ad_list/index' + location.search,
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                // ... 其他配置 ...
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                pageSize: 10,
                pageList: [10, 15, 20],
                // fixedColumns: true,
                // fixedRightNumber: 1,
                columns: [
                    [
                        {field: 'id', title: "ID"},
                        {field: 'advertiser_id', title: "广告主id"},
                        {field: 'name', title: "账户名"},
                        {field: 'kahuna', title: "负责人"},
                        {field: 'this_month_opt_sum', title: "本月总操作次数（截至昨日）"},
                        {field: 'this_month_bmopt_sum', title: "本月斑马操作次数（截至昨日）"},
                        {field: 'no_grant_sum', title: "非赠款消耗"},
                        {field: 'grant_sum', title: "总消耗"},
                    ]
                ],
                queryParams:function (params) {
                    let time_data = document.getElementById('dateRange').value.split(' - ');
                    params.start_date = time_data[0];
                    params.end_date = time_data[1];
                    params.kahuna = document.getElementById('kahuna').value;
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
