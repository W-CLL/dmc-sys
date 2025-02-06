define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'bootstrap-table-fixed-columns'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'operate_monitor/company_adv_obj/index',
                    // table: 'company',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                columns: [
                    [
                        {checkbox: true},
                        {field: 'company_name', title: "公司名称"},
                        {field: 'adv_id', title: "广告主ID"},
                        {field: 'obj_id', title: "计划id"},
                        {
                            field: 'is_white', title: "是否白名单", formatter: function (value, row, index) {
                                return row.is_white == 1 ? "是" : "否";
                            }
                        },
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