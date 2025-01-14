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
                        {field: 'total_opt_num', title: "本月总操作次数"},
                        {field: 'company_num', title: "斑马本月操作次数"},
                        {field: 'per', title: "当前斑马占比",formatter: function (value, row, index) {
                                return ((row.company_num / row.total_opt_num) * 100).toFixed(2) + "%";
                            }},
                        {field: 'status', title: "状态"},
                        {
                            field: 'is_white', title: "是否白名单", formatter: function (value, row, index) {
                                return row.is_white == 0 ? "是" : "否";
                            }
                        },
                        {field: 'percentage', title: "百分比"},
                        {
                            field: 'operate', title: __('Operate'),
                            buttons: [
                                {
                                    name: "setting",
                                    text: "设置",//按钮名称
                                    classname: 'btn btn-xs btn-success btn-dialog',
                                    // classname: 'btn btn-xs btn-success btn-magic btn-dialog',
                                    // icon: 'fa fa-magic',
                                    url: 'operate_monitor/auto_monitor_opt/setting?company_name={company_name}',//指向控制器对应方法
                                    visible: function (row) {
                                        //返回true时按钮显示,返回false隐藏
                                        return true;
                                    }
                                }
                            ],

                            table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate
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