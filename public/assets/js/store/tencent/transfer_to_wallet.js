define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'tencent/transfer_to_wallet/index',
                    transfer_url: 'tencent/transfer_to_wallet/transfer',
                    table: 'tencent_share_wallet',
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
                        {field: 'sub_wallet_id', title: __('子钱包ID')},
                        {field: 'sub_wallet_name', title: __('子钱包名称')},
                        {field: 'wallet_type', title: __('钱包类型'), formatter: function (value, row, index) {
                            switch (value) {
                                case 1:
                                    return '公帐';
                                case 2:
                                    return '私账';
                            }
                        }},
                        {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: function (value, row, index) {
                            var table = this.table;
                            var that = this;
                            // 获取操作按钮HTML
                            var html = Table.api.formatter.operate.call(that, value, row, index);
                            html = '<a href="javascript:void(0);" class="btn btn-xs btn-success btn-transfer" data-id="' + row.id + '">转账</a> ' + html;
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
                var url = Table.api.replaceurl($('#table').data('operate-transfer') ? 'tencent/transfer_to_wallet/transfer' : '', {ids: ids}, $('#table'));
                if ($('#table').data('operate-transfer')) {
                    Fast.api.open(url, '钱包转账', $(this).data() || {});
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