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
            Form.api.bindevent($("form[role=form]"), function(data) {
                // 提交成功后的回调处理
                console.log("表单提交成功");
            }, function(data, ret) {
                // 提交失败后的回调处理
                console.log("表单提交失败");
                console.log(ret);
            }, function(success, error, $form) {
                // 表单提交前确保使用正确的enctype
                // 检查$form是否存在且长度大于0
                if ($form && $form.length > 0) {
                    // 确保表单使用正确的enctype
                    $form.attr('enctype', 'multipart/form-data');
                    
                    // 手动触发表单验证以确保enctype设置生效
                    $form[0].enctype = 'multipart/form-data';
                    
                    // 添加额外的日志记录
                    console.log("设置表单enctype为multipart/form-data");
                }
                
                // 不再在这里处理文件上传，完全由HTML中的原生JavaScript处理
                // 返回false以完全阻止FastAdmin的默认提交行为
                return false;
            });
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };

    return Controller;
});