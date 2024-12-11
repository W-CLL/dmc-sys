define(['jquery', 'bootstrap', 'company', 'table', 'form'], function ($, undefined, Company, Table, Form) {

    var Controller = {
        index: function () {
            Controller.api.bindevent();
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'operate_monitor/obj/index' + location.search,
                    table: 'qc_obj',
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
                        {field: 'object_id', title: "项目id"},
                        {field: 'kahuna', title: "负责人"},
                        {field: 'this_month_opt_sum', title: "本月总操作次数"},
                        {field: 'this_month_bmopt_sum', title: "斑马本月操作次数"},
                        {field: 'create_time', title:"创建时间" ,formatter: Table.api.formatter.datetime},
                        {
                            field: 'operate', title: __('Operate'), buttons: [
                                {
                                    name: "transfer_records",
                                    text: "操作详情列表", // 按钮名称
                                    classname: 'btn btn-xs btn-success btn-magic ',
                                    // classname: 'btn btn-xs btn-success btn-magic btn-dialog',
                                    icon: 'fa fa-magic',
                                    url: function(row) {
                                        return '/TBlQxHczkR.php/operate_monitor/monitor/index?obj_id=' + row.object_id + '&ad_id=' + row.advertiser_id + '&details=0';
                                    }, // 指向控制器对应方法
                                    confirm: '查看当前计划操作详情列表',
                                    visible: function(row) {
                                        // 返回true时按钮显示,返回false隐藏
                                        return true;
                                    }
                                },
                            ], table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate
                        }
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        details: function () {
            Controller.api.bindevent();
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'operate_monitor/obj/details' + location.search,
                    table: 'qc_obj',
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
                        {field: 'object_id', title: "项目id"},
                        {field: 'kahuna', title: "负责人"},
                        {field: 'this_month_opt_sum', title: "本月总操作次数"},
                        {field: 'this_month_bmopt_sum', title: "斑马本月操作次数"},
                        {field: 'create_time', title:"创建时间" ,formatter: Table.api.formatter.datetime},
                        {
                            field: 'operate', title: __('Operate'), buttons: [
                                {
                                    name: "transfer_records",
                                    text: "操作详情列表", // 按钮名称
                                    classname: 'btn btn-xs btn-success btn-magic ',
                                    // classname: 'btn btn-xs btn-success btn-magic btn-dialog',
                                    icon: 'fa fa-magic',
                                    url: function(row) {
                                        return '/TBlQxHczkR.php/operate_monitor/monitor/index?obj_id=' + row.object_id + '&ad_id=' + row.advertiser_id + '&details=1';
                                    }, // 指向控制器对应方法
                                    confirm: '查看当前计划操作详情列表',
                                    visible: function(row) {
                                        // 返回true时按钮显示,返回false隐藏
                                        return true;
                                    }
                                },
                            ], table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate
                        }
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
