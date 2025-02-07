define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'bootstrap-table-fixed-columns'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'operate_monitor/company_setting/index',
                    // table: 'company',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                searchFormVisible: true,
                searchFormTemplate: 'customformtpl',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('Id')},
                        {field: 'company_name', title: "公司名称"},
                        {
                            field: 'is_white', title: "是否白名单", formatter: function (value, row, index) {
                                return row.is_white == 1 ? "是" : "否";
                            }
                        },
                        {field: 'percentage', title: "百分比"},
                        {field: 'adv_num', title: "账户数"},
                        {
                            field: 'operate', title: __('Operate'),
                            buttons: [{
                                name: "adv_list",
                                text: "广告主列表",//按钮名称
                                classname: 'btn btn-xs btn-success btn-magic ',
                                // classname: 'btn btn-xs btn-success btn-magic btn-dialog',
                                // icon: 'fa fa-magic',
                                url: 'operate_monitor/company_adv/index?company_name={company_name}',
                                visible: function (row) {
                                    //返回true时按钮显示,返回false隐藏
                                    return true;
                                }
                            },
                                {
                                    name: "setting",
                                    text: "设置",//按钮名称
                                    classname: 'btn btn-xs btn-success btn-magic btn-dialog ',
                                    // classname: 'btn btn-xs btn-success btn-magic btn-dialog',
                                    icon: 'fa fa-setting',
                                    refresh: true,
                                    url: 'operate_monitor/company_setting/setting',
                                    visible: function (row) {
                                        //返回true时按钮显示,返回false隐藏
                                        return true;
                                    }
                                },

                            ],

                            table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate
                        }
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
            table.on('check-all.bs.table', function (e, rows) {
                // 点击全选触发事件
                var select_total = 0;
                for (i = 0; i < rows.length; i++) {
                    select_total = select_total + 1;
                }
                $("#select_total").text(select_total);
            })

            table.on('uncheck-all.bs.table', function (e, rows) {
                // 点击反选触发事件
                $("#select_total").text("0");
            })

            table.on('check.bs.table', function (e, row) {
                // 勾选某一行触发事件
                var select_total = parseInt($("#select_total").text()) + 1;
                $("#select_total").text(select_total);
            })

            table.on('uncheck.bs.table', function (e, row) {
                // 反选某一行触发事件
                var select_total = parseInt($("#select_total").text()) - 1;
                $("#select_total").text(select_total);
            })

            table.on('post-body.bs.table', function (e, row) {
                $("#select_total").text("0");
            })


            /* 获取选中的id */
            function getIdSelections() {
                return $.map($("#table").bootstrapTable('getSelections'), function (row) {
                    return row.id
                });
            }

            $(".btn-edits").on('click', function () {
                var checkids = [];
                checkids = getIdSelections();
                // 使用JavaScript通过类名选择器找到隐藏的输入字段
                layer.open({
                    type: 2,
                    area: ['450px', '350px'],
                    content: 'edit',
                    fixed: false, // 不固定
                    maxmin: true,
                    shadeClose: true,
                    title: "设置",
                    btnAlign: 'c',
                    success: function (layero, index) {
                        var iframe = layero.find('iframe')[0];
                        var iframeWindow = iframe.contentWindow || iframe.contentDocument || iframe;

                        // 获取 iframe 中的输入框，并赋值
                        var hiddenInput = $(iframeWindow.document).find("input[name='ids']");
                        hiddenInput.val(checkids.join(','));
                    }
                });

            })
        },
        add: function () {
            Controller.api.bindevent();
        },
        edit: function () {
            Controller.api.bindevent();
        },
        setting: function () {
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