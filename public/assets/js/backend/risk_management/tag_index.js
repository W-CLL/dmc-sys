define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'bootstrap-table-fixed-columns'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'risk_management/tag_index/index',
                    add_url: 'risk_management/tag_index/add',
                    edit_url: 'risk_management/tag_index/edit',
                    del_url: 'risk_management/tag_index/del',
                    table: 'tag',
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
                search: false, // 禁用默认搜索
                commonSearch: true, // 启用普通表单搜索
                searchFormVisible: true, // 控制搜索栏是否显示在页面上
                searchFormTemplate: 'customformtpl',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: "id", sortable: true},
                        {field: 'name', title:"标签名"},
                        {field: 'create_time', title: "创建时间", formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange', sortable: true},
                        {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}

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
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});