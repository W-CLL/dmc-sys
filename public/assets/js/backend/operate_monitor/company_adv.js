define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'bootstrap-table-fixed-columns'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'operate_monitor/company_adv/index',
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
                        {field: 'advertiser_id', title: "广告主id"},
                        {
                            field: 'is_white', title: "是否白名单", formatter: function (value, row, index) {
                                return row.is_white == 0 ? "是" : "否";
                            }
                        },
                        {field: 'percentage', title: "百分比"},
                        {
                            field: 'operate', title: __('Operate'),
                            buttons: [{
                                name: "adv_list",
                                text: "计划列表",//按钮名称
                                classname: 'btn btn-xs btn-success btn-magic ',
                                // classname: 'btn btn-xs btn-success btn-magic btn-dialog',
                                // icon: 'fa fa-magic',
                                url:'operate_monitor/company_adv_obj/index?company_name={company_name}',
                                visible: function (row) {
                                    //返回true时按钮显示,返回false隐藏
                                    return true;
                                }
                            },
                                {
                                    name: "setting",
                                    text: "设置",//按钮名称
                                    classname: 'btn btn-xs btn-success btn-magic btn-dialog',
                                    refresh: true,
                                    // classname: 'btn btn-xs btn-success btn-magic btn-dialog',
                                    // icon: 'fa fa-magic',
                                    url: 'operate_monitor/company_adv/setting?company_name={company_name}',//指向控制器对应方法
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