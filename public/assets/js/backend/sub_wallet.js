define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            Controller.api.bindevent();
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'sub_wallet/index',
                    edit_url: 'sub_wallet/edit',
                    table: 'sub_wallet',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                // ... 其他配置 ...
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                fixedColumns: true,
                fixedRightNumber: 1,
                searchFormVisible: true,
                searchFormTemplate: 'customformtpl',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('Id'),visible: false},
                        {field: 'sub_wallet_id', title: "子钱包ID"},
                        {field: 'store.username', title: "绑定用户名"},
                        {field: 'sub_wallet_name', title: "子钱包名称"},
                        {field: 'sub_wallet_type', title: "子钱包类型", formatter: function(value,row,index) {
                                if (row.sub_wallet_type == 1){
                                    return "公"
                                }else if (row.sub_wallet_type == 2){
                                    return "私"
                                }else{
                                    return "未绑定"
                                }
                            }, operate: 'LIKE'},
                        {field: 'main_wallet_id', title: "父钱包ID"},
                        {field: 'adv_cnt', title: "子钱包adv数量"},
                        {field: 'create_time', title:"子钱包创建时间" ,formatter: Table.api.formatter.datetime},
                        {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate},
                    ]
                ],
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
            table.on('check-all.bs.table',function (e, rows) {
                // 点击全选触发事件
                var select_total = 0;
                for (i = 0;i<rows.length;i++) {
                    select_total = select_total + 1;
                }
                $("#select_total").text(select_total);
            })

            table.on('uncheck-all.bs.table',function (e, rows) {
                // 点击反选触发事件
                $("#select_total").text("0");
            })

            table.on('check.bs.table',function (e, row) {
                // 勾选某一行触发事件
                var select_total = parseInt($("#select_total").text()) + 1;
                $("#select_total").text(select_total);
            })

            table.on('uncheck.bs.table',function (e, row) {
                // 反选某一行触发事件
                var select_total = parseInt($("#select_total").text()) - 1;
                $("#select_total").text(select_total);
            })

            table.on('post-body.bs.table',function (e, row) {
                $("#select_total").text("0");
            })


            /* 获取选中的id */
            function getIdSelections() {
                return $.map($("#table").bootstrapTable('getSelections'), function(row) {
                    return row.id
                });
            }
            $(".btn-changeteacher").on('click',function(){
                var checkids = [];
                checkids = getIdSelections();
                layer.open({
                    type: 2,
                    area: ['680px', '520px'],
                    content: 'batch_binding',
                    fixed: false, // 不固定
                    maxmin: true,
                    shadeClose: true,
                    btn: ['提交', '取消'],
                    btnAlign: 'c',
                    yes: function(index, layero){
                        var body = layer.getChildFrame('body', index);
                        var store_id = body.find("select[name='store_id']")[0].value;
                        var wallet_type = body.find("input[name='wallet_type']:checked").val();
                        console.log(store_id)
                        Fast.api.ajax({
                            url: 'sub_wallet/batch_binding',
                            data: {
                                store_id: store_id,
                                wallet_type: wallet_type,
                                wallet_ids: checkids.join(','),
                            }
                        }, function (data, ret) {
                            table.bootstrapTable('refresh', {});
                            Layer.close(index);
                        });
                    }
                });
            })
        },
        edit: function () {
            Controller.api.bindevent();
        },
        bind_by_sub_wallet_id: function () {
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
