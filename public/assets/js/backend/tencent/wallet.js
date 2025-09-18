define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'tencent/wallet/index',
                    edit_url: 'tencent/wallet/edit',
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
                        {field: 'id', title: __('Id'), operate: false},
                        {field: 'store.username', title: __('商户名称'), operate: 'LIKE'},
                        {field: 'sub_wallet_id', title: __('钱包id')},
                        {field: 'sub_wallet_name', title: __('钱包名称'), operate: 'LIKE'},
                        {field: 'wallet_type', title: __('钱包类型'), formatter: function(value, row, index) {
                            switch(value) {
                                case 0: return '未绑定';
                                case 1: return '公账';
                                case 2: return '私账';
                                default: return '未知';
                            }
                        }, searchList: {0: '未绑定', 1: '公账', 2: '私账'}},
                        {field: 'discount_percentage', title: __('折扣比例'), formatter: function(value, row, index) {
                            // 如果折扣百分比为0，则显示"不适用"
                            if (parseFloat(value) == 0) {
                                return '不适用';
                            }
                            return value + '%';
                        }},
                        {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);

            // 批量绑定功能
            table.on('check.bs.table uncheck.bs.table check-all.bs.table uncheck-all.bs.table', function () {
                var ids = Table.api.selectedids(table);
                $(".btn-changeteacher").toggleClass('disabled', !ids.length);
                $("#select_total").text(ids.length);
            });

            /* 获取选中的id */
            function getIdSelections() {
                return $.map($("#table").bootstrapTable('getSelections'), function (row) {
                    return row.id
                });
            }
            
            // 批量绑定按钮点击事件
            $(".btn-changeteacher").on('click', function () {
                var checkids = getIdSelections();
                if (checkids.length === 0) {
                    Toastr.error("请选择要操作的记录");
                    return;
                }
                
                var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
                var width = isMobile ? '80%' : '40%';
                var height = '60%';
                
                layer.open({
                    type: 2,
                    area: [width, height],
                    content: 'wallet/batch_binding',
                    fixed: false,
                    maxmin: true,
                    shadeClose: true,
                    btn: ['提交', '取消'],
                    btnAlign: 'c',
                    yes: function (index, layero) {
                        var iframeWin = window[layero.find('iframe')[0]['name']];
                        var store_id = iframeWin.$("select[name='store_id']").val();
                        var wallet_type = iframeWin.$("select[name='wallet_type']").val();
                        var discount_percentage = iframeWin.$("input[name='discount_percentage']").val();
                        var token = iframeWin.$("input[name='__token__']").val();
                        
                        Fast.api.ajax({
                            url: 'tencent/wallet/batch_binding',
                            data: {
                                __token__: token,
                                store_id: store_id,
                                wallet_type: wallet_type,
                                discount_percentage: discount_percentage,
                                ids: checkids.join(','),
                            }
                        }, function (data, ret) {
                            table.bootstrapTable('refresh', {});
                            Layer.close(index);
                        }, function (data, ret) {
                            // 错误处理
                            console.log(ret);
                        });
                    }
                });
            });
            
            // 添加根据钱包ID绑定按钮
            $("#toolbar").prepend('<a href="javascript:;" class="btn btn-success btn-bindbyid" title="根据ID绑定"><i class="fa fa-link"></i> 根据ID绑定</a> ');
            
            // 根据ID绑定按钮点击事件
            $(".btn-bindbyid").on('click', function () {
                var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
                var width = isMobile ? '80%' : '40%';
                var height = '60%';
                
                layer.open({
                    type: 2,
                    area: [width, height],
                    content: 'wallet/bind_by_wallet_id',
                    fixed: false,
                    maxmin: true,
                    shadeClose: true,
                    title: '根据钱包ID绑定',
                    btn: ['提交', '取消'],
                    btnAlign: 'c',
                    yes: function (index, layero) {
                        var iframeWin = window[layero.find('iframe')[0]['name']];
                        var store_id = iframeWin.$("select[name='store_id']").val();
                        var discount_percentage = iframeWin.$("input[name='discount_percentage']").val();
                        var public_wallet_id = iframeWin.$("textarea[name='public_wallet_id']").val();
                        var private_wallet_id = iframeWin.$("textarea[name='private_wallet_id']").val();
                        var token = iframeWin.$("input[name='__token__']").val();
                        
                        Fast.api.ajax({
                            url: 'tencent/wallet/bind_by_wallet_id',
                            data: {
                                __token__: token,
                                store_id: store_id,
                                discount_percentage: discount_percentage,
                                public_wallet_id: public_wallet_id,
                                private_wallet_id: private_wallet_id
                            }
                        }, function (data, ret) {
                            table.bootstrapTable('refresh', {});
                            Layer.close(index);
                        }, function (data, ret) {
                            // 错误处理
                            console.log(ret);
                        });
                    }
                });
            });
        },
        edit: function () {
            Controller.api.bindevent();
        },
        batch_binding: function () {
            Controller.api.bindevent();
        },
        bind_by_wallet_id: function () {
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