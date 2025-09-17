define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'store/tencent/index',
                    add_url: 'store/tencent/add',
                    edit_url: 'store/tencent/edit',
                    table: 'store',
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
                        {field: 'id', title: __('StoreId')},
                        {field: 'username', title: __('Username')},
                        {field: 'tencent.public_money_tencent', title: __('公账钱包'), formatter: function (value, row, index) {
                            // 处理空值情况
                            if (value === undefined || value === null || value === '') {
                                return '0.00 元';
                            }
                            return parseFloat(value).toFixed(2) + ' 元';
                        }},
                        {field: 'tencent.private_money_tencent', title: __('私有钱包'), formatter: function (value, row, index) {
                            // 处理空值情况
                            if (value === undefined || value === null || value === '') {
                                return '0.00 元';
                            }
                            return parseFloat(value).toFixed(2) + ' 元';
                        }},
                        {field: 'tencent.public_credit_limit_tencent', title: __('授信额度(公)'), formatter: function (value, row, index) {
                            // 处理空值情况
                            if (value === undefined || value === null || value === '') {
                                return '0.00 元';
                            }
                            return parseFloat(value).toFixed(2) + ' 元';
                        }},
                        {field: 'tencent.private_credit_limit_tencent', title: __('授信额度(私)'), formatter: function (value, row, index) {
                            // 处理空值情况
                            if (value === undefined || value === null || value === '') {
                                return '0.00 元';
                            }
                            return parseFloat(value).toFixed(2) + ' 元';
                        }},
                        {field: 'tencent.public_spending_credit_limit_tencent', title: __('已使用授信额度(公)'), formatter: function (value, row, index) {
                            // 处理空值情况
                            if (value === undefined || value === null || value === '') {
                                return '0.00 元';
                            }
                            return parseFloat(value).toFixed(2) + ' 元';
                        }},
                        {field: 'tencent.private_spending_credit_limit_tencent', title: __('已使用授信额度(私)'), formatter: function (value, row, index) {
                            // 处理空值情况
                            if (value === undefined || value === null || value === '') {
                                return '0.00 元';
                            }
                            return parseFloat(value).toFixed(2) + ' 元';
                        }},
                        {field: 'tencent.public_discount_percentage_tencent', title: __('折扣百分比(公)'), formatter: function (value, row, index) {
                            // 处理空值情况
                            if (value === undefined || value === null || value === '') {
                                return '0.0000 %';
                            }
                            return parseFloat(value).toFixed(4) + ' %';
                        }},
                        {field: 'tencent.private_discount_percentage_tencent', title: __('折扣百分比(私)'), formatter: function (value, row, index) {
                            // 处理空值情况
                            if (value === undefined || value === null || value === '') {
                                return '0.0000 %';
                            }
                            return parseFloat(value).toFixed(4) + ' %';
                        }},
                        {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        add: function () {
            // 初始化表单
            Form.api.bindevent($("form[role=form]"));
        },
        edit: function () {
            // 初始化表单
            Form.api.bindevent($("form[role=form]"));
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };

    return Controller;
});