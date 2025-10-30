define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'more/write_off_receipt/index',
                    add_url: 'more/write_off_receipt/add',
                    del_url: 'more/write_off_receipt/del',
                    multi_url: 'more/write_off_receipt/multi',
                    table: 'receipt_use_log',
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
                        {field: 'receipt_no', title: __('回单号'), align: 'left'},
                        {field: 'image_path', title: __('回单图片'), align: 'center', formatter: Controller.api.formatter.image},
                        {field: 'admin_name', title: __('操作员')},
                        {field: 'create_time', title: __('创建时间'), operate:'RANGE', addclass:'datetimerange', formatter: Table.api.formatter.datetime},
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
        upload: function () {
            // 空函数，实际处理在模板中的JavaScript完成
            // 这样可以避免FastAdmin框架自动绑定表单提交事件
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            },
            formatter: {
                image: function (value, row, index) {
                    if (value) {
                        return '<a href="' + value + '" target="_blank"><img src="' + value + '" alt="回单图片" style="max-height:50px;max-width:100px"></a>';
                    }
                    return '';
                }
            }
        }
    };
    return Controller;
});