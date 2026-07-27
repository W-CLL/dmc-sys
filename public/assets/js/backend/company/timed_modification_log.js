define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            Controller.api.bindevent();

            Table.api.init({
                extend: {
                    index_url: 'company/timed_modification_log/index',
                    add_url: 'company/timed_modification_log/add',
                    del_url: 'company/timed_modification_log/del',
                }
            });

            var table = $("#table");

            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                searchFormVisible: true,
                searchFormTemplate: 'customformtpl',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: "ID"},
                        {field: 'subject_name', title: "主体名", operate: 'LIKE'},
                        {
                            field: 'subject_type', title: "主体类型", operate: 'LIKE', formatter: function (value, row, index) {
                                return row.subject_type == 1 ? '私' : '公';
                            }
                        },
                        {
                            field: 'discount_percentage', title: "折扣百分比", formatter: function (value, row, index) {
                                return row.discount_percentage + '%';
                            }, operate: 'LIKE'
                        },
                        {
                            field: 'status', title: "状态", operate: 'LIKE', formatter: function (value, row, index) {
                                if (row.status == 0) {
                                    return '<span class="label label-warning">待执行</span>';
                                } else if (row.status == 1) {
                                    return '<span class="label label-success">完成</span>';
                                } else {
                                    return '<span class="label label-danger">失败</span>';
                                }
                            }
                        },
                        {field: 'msg', title: "执行信息", operate: 'LIKE'},
                        {
                            field: 'effective_time', title: "生效时间", operate: 'RANGE', formatter: function (value, row, index) {
                                return value ? Table.api.formatter.datetime.call(this, value, row, index) : '-';
                            }
                        },
                        {
                            field: 'create_time', title: "创建时间", operate: 'RANGE', formatter: function (value, row, index) {
                                return value ? Table.api.formatter.datetime.call(this, value, row, index) : '-';
                            }
                        },
                        {
                            field: 'update_time', title: "更新时间", operate: 'RANGE', formatter: function (value, row, index) {
                                return value ? Table.api.formatter.datetime.call(this, value, row, index) : '-';
                            }
                        },
                    ]
                ],
                queryParams: function (params) {
                    params.status = $("#status").val();
                    params.subject_type = $("#subject_type").val();
                    return params;
                }
            });

            Table.api.bindevent(table);

            $('#btn-execute').on('click', function () {
                Controller.api.execute();
            });
        },
        edit: function () {
            Controller.api.bindevent();
        },
        add: function () {
            Controller.api.bindevent();
        },
        execute: function () {
            Controller.api.bindevent();
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            },
            execute: function () {
                Layer.confirm('确定要执行所有待执行的定时修改政策吗？', {icon: 3, title: '提示'}, function (index) {
                    $.ajax({
                        type: "POST",
                        url: "company/timed_modification_log/execute",
                        dataType: "json",
                        success: function (ret) {
                            if (ret.code == 1) {
                                Toastr.success(ret.msg);
                                $("#table").bootstrapTable('refresh');
                            } else {
                                Toastr.error(ret.msg);
                            }
                        },
                        error: function () {
                            Toastr.error("请求失败，请重试");
                        }
                    });
                    Layer.close(index);
                });
            }
        }
    };
    return Controller;
});
