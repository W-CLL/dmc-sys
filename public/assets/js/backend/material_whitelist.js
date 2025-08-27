define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'material_whitelist/index' + location.search,
                    add_url: 'material_whitelist/add',
                    edit_url: 'material_whitelist/edit',
                    del_url: 'material_whitelist/del',
                    multi_url: 'material_whitelist/multi',
                    import_url: 'material_whitelist/import',
                    table: 'material_whitelist',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('Id')},
                        {field: 'company_name', title: '公司名称', operate: 'LIKE'},
                        {field: 'status', title: '状态', searchList: {"1":"启用","0":"禁用"}, formatter: Table.api.formatter.status},
                        {field: 'remark', title: '备注', operate: 'LIKE'},
                        {field: 'create_time', title: '创建时间', operate:'RANGE', addclass:'datetimerange', autocomplete:false, formatter: Table.api.formatter.datetime},
                        {field: 'update_time', title: '更新时间', operate:'RANGE', addclass:'datetimerange', autocomplete:false, formatter: Table.api.formatter.datetime},
                        {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);

            // 批量添加按钮事件
            $(document).on('click', '.btn-batch-add', function () {
                Fast.api.open('material_whitelist/batch_add', '批量添加白名单公司', {
                    callback: function (data) {
                        table.bootstrapTable('refresh');
                    }
                });
            });
        },
        add: function () {
            Controller.api.bindevent();
        },
        edit: function () {
            Controller.api.bindevent();
        },
        batch_add: function () {
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
