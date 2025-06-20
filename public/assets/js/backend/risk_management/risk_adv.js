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
                sortName: 'one_class_score',
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
                        {field: 'two_three_class_score', title: "一般违规积分",sortable: true},
                        {
                            field: 'tag_obj_count', title: "标签计划数", formatter: function (value) {
                                if (!value) return '';
                                const ids = value.split(';');
                                const itemsPerRow = 1;
                                const rows = [];
                                for (let i = 0; i < ids.length; i += itemsPerRow) {
                                    const row = ids.slice(i, i + itemsPerRow).join(', '); // 每组最多三个
                                    rows.push(row);
                                }
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
            var cacheKey = location.pathname;
            var savedParams = sessionStorage.getItem('searchParams_' + cacheKey);
            if (savedParams) {
                try {
                    savedParams = JSON.parse(savedParams);
                    for (var key in savedParams) {
                        var field = $('[name="' + key + '"]');
                        if (field.length) {
                            var value = savedParams[key];
                            if (field.is('select')) {
                                field.val(value);                     // 设值
                                if ($.fn.selectpicker && field.hasClass('selectpicker')) {
                                    field.selectpicker('refresh');
                                }      // 更新 selectpicker UI
                                field.trigger('change');              // 触发事件（有些表格依赖）
                            } else {
                                field.val(value);                     // 普通 input
                            }
                        }
                    }

                    // 延迟触发一次搜索，确保 DOM 和 Table 初始化后再刷新
                    setTimeout(function () {
                        $('.form-commonsearch').submit();
                    }, 300);
                    sessionStorage.removeItem('searchParams_' + cacheKey);
                } catch (e) {
                    console.warn("恢复搜索参数失败", e);
                }
            }

            $(document).on("click", ".btn-magic, .btn-primary", function () {

                var searchParams = {};
                $('form.form-commonsearch input, form.form-commonsearch select').each(function () {
                    var name = $(this).attr('name');
                    var val = $(this).val();
                    if (val) {
                        searchParams[name] = val;
                    }
                });

                sessionStorage.setItem('searchParams_' + cacheKey, JSON.stringify(searchParams));
            });
            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        get_log_list: function () {
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
                        {field: 'obj_id', title: "计划id"},
                        {field: 'operator', title: "操作人"},
                        {field: 'type', title: "处理类型"},
                        {
                            field: 'contents',
                            title: "操作记录",
                            formatter: function (value) {
                                if (!value) return '';
                                const items = value.split(";");
                                return items.filter(item => item.trim()).join('<br>');
                            }
                        },
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