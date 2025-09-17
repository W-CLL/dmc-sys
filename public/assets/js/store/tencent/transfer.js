define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'tencent/transfer/index',
                    transfer_url: 'tencent/transfer/transfer',
                    table: 'tencent_account',
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
                        {field: 'id', title: __('Id')},
                        {field: 'account_id', title: __('子客id')},
                        {field: 'name', title: __('Name')},
                        {field: 'account_type', title: __('账户类型'), formatter: function (value, row, index) {
                            switch (value) {
                                case 1:
                                    return '公账';
                                case 2:
                                    return '私账';
                                default:
                                    return '未设置';
                            }
                        }},
                        {field: 'status', title: __('Status'), formatter: function (value, row, index) {
                            const statusMap = {
                                1: {text: '有效', class: 'success'},
                                2: {text: '待审核', class: 'info'},
                                3: {text: '审核不通过', class: 'danger'},
                                4: {text: '封禁', class: 'danger'},
                                5: {text: '待接受', class: 'warning'},
                                6: {text: '待激活', class: 'warning'},
                                7: {text: '暂停', class: 'warning'},
                                8: {text: '广告主资料准备', class: 'info'},
                                9: {text: '删除', class: 'danger'},
                                10: {text: '临时冻结', class: 'warning'},
                                11: {text: '未注册', class: 'default'}
                            };
                            
                            const status = statusMap[value] || {text: '未知状态', class: 'default'};
                            return '<span class="label label-' + status.class + '">' + status.text + '</span>';
                        }},
                        {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: function (value, row, index) {
                            var table = this.table;
                            var that = this;
                            // 获取操作按钮HTML
                            var html = Table.api.formatter.operate.call(that, value, row, index);
                                html = '<a href="javascript:;" class="btn btn-xs btn-success btn-transfer" data-id="' + row.id + '">转账</a> ' + html;
                            return html;
                        }}
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
            
            // 绑定转账按钮事件
            $(document).on('click', '.btn-transfer', function () {
                var ids = $(this).data('id');
                var url = Table.api.replaceurl($('#table').data('operate-transfer') ? 'tencent/transfer/transfer' : '', {ids: ids}, $('#table'));
                if ($('#table').data('operate-transfer')) {
                    Fast.api.open(url, '转账', $(this).data() || {});
                }
            });
        },
        transfer: function () {
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