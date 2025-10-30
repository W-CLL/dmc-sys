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
            // 绑定表单提交事件
            Form.api.bindevent($("form"), function (data) {
                // 上传成功后的回调处理
                if (data.code === 1) {
                    // 刷新父页面表格
                    var index = parent.layer.getFrameIndex(window.name);
                    parent.$("#table").bootstrapTable('refresh');
                    parent.layer.close(index);
                }
            }, function (data) {
                // 上传失败后的回调处理
                console.log('上传失败:', data);
                if (data.msg) {
                    Toastr.error(data.msg);
                } else {
                    Toastr.error("上传失败");
                }
            }, function (ret) {
                // 自定义处理
                console.log('自定义处理:', ret);
            });
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