define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'bootstrap-table-fixed-columns'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'risk_management/risk_adv/index' + location.search,
                    edit_url: 'risk_management/risk_adv/edit',
                    table: 'risk_adv',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                sortName: 'adv_id',
                fixedColumns: true, // 固定列代码
                fixedRightNumber: 1, // 固定右侧第一列
                search: false, // 禁用默认搜索
                commonSearch: true, // 启用普通表单搜索
                searchFormVisible: true, // 控制搜索栏是否显示在页面上
                searchFormTemplate: 'search-tpl',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'adv_id', title: "千川id"},
                        {field: 'company_name', title: "公司名"},
                        {field: 'one_class_score', title: "严重违规积分",sortable: true},
                        {
                            field: 'tag_obj_count', title: "标签计划数", formatter: function (value) {
                                if (!value) return '';
                                // 将字符串按逗号分割成数组
                                const ids = value.split(';');
                                // 定义每行显示的数量
                                const itemsPerRow = 1;
                                // 分块处理：每三个一组
                                const rows = [];
                                for (let i = 0; i < ids.length; i += itemsPerRow) {
                                    const row = ids.slice(i, i + itemsPerRow).join(', '); // 每组最多三个
                                    rows.push(row);
                                }
                                // 用 <br> 连接各组，实现每三组换一行
                                return rows.join('<br>');
                            }
                        },
                        {field: 'sys_tag_text', title: "系统标签"},
                        {field: 'tag_text', title: "人工标签"},
                        {field: 'keywords', title: "命中词"},
                        {field: 'status_text', title: "处理状态"},
                        {field: 'kahuna', title: "客服"},
                        {field: 'check_staff', title: "巡查"},
                        {field: 'business_staff', title: "商务"},
                        {field: 'remark', title: "自定义备注"},
                        {
                            field: 'operate', title: __('Operate'),
                            buttons: [
                                {
                                    name: 'edit',
                                    text: "编辑",
                                    title: "编辑",
                                    classname: 'btn  btn-success btn-dialog ',
                                    url: 'risk_management/risk_adv/edit',
                                },
                                {
                                    name: 'log_list',
                                    text: "跟进日志",
                                    title: "跟进日志",
                                    classname: 'btn btn-dialog btn-success',
                                    url: 'risk_management/risk_adv/get_log_list',
                                },
                                {
                                    name: 'obj_list',
                                    text: "计划列表",
                                    title: "计划列表",
                                    classname: 'btn  btn-magic  btn-primary',
                                    url: 'risk_management/risk_obj/index',
                                },
                            ],

                            table: table,
                            events: Table.api.events.operate,
                            formatter: Table.api.formatter.operate
                        }

                    ]
                ],
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        get_log_list: function () {
            console.log("sdfkj");
            Controller.api.bindevent();
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'risk_management/risk_adv/get_log_list/' + location.search,
                }
            });

            var table = $("#list-table");

            // 初始化表格
            table.bootstrapTable({
                // ... 其他配置 ...
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                pageSize: 10,
                pageList: [10, 15, 20, 50],
                exportDataType: 'basic',
                columns: [
                    [
                        {field: 'adv_id', title: "千川id"},
                        {field: 'operator', title: "操作人"},
                        {field: 'contents', title: "操作记录"},
                        {field: 'create_time', title: "操作时间",formatter: Table.api.formatter.datetime},
                    ]
                ],
                queryParams: function (params) {
                    params.ids = document.getElementById('ids').value;
                    return params;
                }
            });
            // 为表格绑定事件
            Table.api.bindevent(table);
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