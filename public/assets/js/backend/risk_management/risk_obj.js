define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Company, Table, Form) {

    var Controller = {
        index: function () {
            Controller.api.bindevent();
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'risk_management/risk_obj/index?adv_id=' + $("#adv_id").val(),
                    table: 'risk_obj_product',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                search: true, // 禁用默认搜索
                commonSearch: true, // 启用普通表单搜索
                searchFormVisible: true, // 控制搜索栏是否显示在页面上
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                fixedColumns: true,
                fixedRightNumber: 1,
                searchFormTemplate: 'search-tpl',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('Id'),visible: false},
                        {field: 'adv_id', title: "千川id"},
                        {field: 'obj_id', title: "计划id"},
                        {field: 'sys_tag_text', title: "标签"},
                        {field: 'key_words', title: "命中词"},
                        {field: 'product_ids', title: "商品id(只列举12个)",    formatter: function (value) {
                                if (!value) return '';
                                const ids = value.split(';').filter(Boolean);
                                const itemsPerRow = 3;
                                const rows = [];
                                for (let i = 0; i < ids.length; i += itemsPerRow) {
                                    const rowItems = ids.slice(i, i + itemsPerRow).map(id => {
                                        const url = `https://haohuo.jinritemai.com/ecommerce/trade/detail/index.html?id=${id}&channel_id=0&channel_type=0&origin_type=ecp_preview`;
                                        return `<a href="${url}" target="_blank">${id}</a>`;
                                    });
                                    rows.push(rowItems.join(', '));
                                }

                                // 使用 <br> 换行连接所有行
                                return rows.join('<br>');
                            }},
                        {field: 'status_text', title: "计划状态"},
                        {field: 'obj_create_time', title:"计划创建时间" ,formatter: Table.api.formatter.datetime},
                        {
                            field: 'operate', title: __('Operate'),
                            buttons: [
                                {
                                    name: 'obj_edit',
                                    text: "编辑",
                                    title: "编辑",
                                    classname: 'btn  btn-success btn-dialog ',
                                    url: 'risk_management/risk_obj/edit',
                                },
                                {
                                    name: 'obj_log_list',
                                    text: "跟进日志",
                                    title: "跟进日志",
                                    classname: 'btn btn-dialog btn-success',
                                    url: 'risk_management/risk_obj/get_log_list',
                                },
                            ],
                            table: table,
                            events: Table.api.events.operate,
                            formatter: Table.api.formatter.operate
                        }
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        get_log_list: function () {
            Controller.api.bindevent();
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'risk_management/risk_obj/get_log_list/' + location.search,
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
