define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'tencent/account/index',
                    edit_url: 'tencent/account/edit',
                    table: 'txgg_account',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                searchFormVisible: true,
                searchFormTemplate: 'customformtpl',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: "ID", sortable: true},
                        {field: 'account_id', title: '账户ID', sortable: true},
                        {field: 'name', title: "账户名称", operate: 'LIKE'},
                        {field: 'store.username', title: "绑定账号", operate: 'LIKE'},
                        {field: 'account_type', title: "账号类型", formatter: function(value, row, index) {
                                switch(value) {
                                    case 1: return "公账";
                                    case 2: return "私账";
                                    default: return "未设置";
                                }
                            }
                        },
                        {field: 'discount_percentage', title: "折扣比例", formatter: function(value, row, index) {
                                const discount_percentage = parseFloat(value);
                                if (discount_percentage == 0){
                                    return "不适用";
                                }else{
                                    return discount_percentage;
                                }
                            }
                        },
                        {field: 'status', title: "状态", formatter: function(value, row, index) {
                                switch(value) {
                                    case 1: return "有效";
                                    case 4: return "封禁";
                                    default: return "未知";
                                }
                            }
                        },
                        {field: 'create_time', title: "创建时间", formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange', sortable: true},
                        {field: 'update_time', title: "更新时间", formatter: Table.api.formatter.datetime, operate: 'RANGE', addclass: 'datetimerange', sortable: true},
                        {
                            field: 'operate', 
                            title: __('Operate'), 
                            table: table, 
                            events: Table.api.events.operate, 
                            formatter: Table.api.formatter.operate
                        }
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
                
                // 修改：使用正确的相对路径格式
                layer.open({
                    type: 2,
                    area: [width, height],
                    content: 'account/batch_binding', // 修改：去掉模块前缀的斜杠
                    fixed: false,
                    maxmin: true,
                    shadeClose: true,
                    btn: ['提交', '取消'],
                    btnAlign: 'c',
                    yes: function (index, layero) {
                        // 修复：使用正确的layer API获取iframe中的元素
                        var iframeWin = window[layero.find('iframe')[0]['name']];
                        var store_id = iframeWin.$("select[name='store_id']").val();
                        var account_type = iframeWin.$("select[name='account_type']").val();
                        var discount_percentage = iframeWin.$("input[name='discount_percentage']").val();
                        var token = iframeWin.$("input[name='__token__']").val();
                        
                        Fast.api.ajax({
                            url: 'tencent/account/batch_binding', // 修改：去掉模块前缀的斜杠
                            data: {
                                __token__: token,
                                store_id: store_id,
                                account_type: account_type,
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
            
            // 添加根据账户ID绑定按钮
            $("#toolbar").prepend('<a href="javascript:;" class="btn btn-success btn-bindbyid" title="根据ID绑定"><i class="fa fa-link"></i> 根据ID绑定</a> ');
            
            // 添加根据账户名称绑定按钮
            $("#toolbar").prepend('<a href="javascript:;" class="btn btn-info btn-bindbyname" title="根据名称绑定"><i class="fa fa-font"></i> 根据名称绑定</a> ');
            
            // 根据ID绑定按钮点击事件
            $(".btn-bindbyid").on('click', function () {
                var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
                var width = isMobile ? '80%' : '40%';
                var height = '60%';
                
                layer.open({
                    type: 2,
                    area: [width, height],
                    content: 'account/bind_by_account_id',
                    fixed: false,
                    maxmin: true,
                    shadeClose: true,
                    title: '根据账户ID绑定',
                    btn: ['提交', '取消'],
                    btnAlign: 'c',
                    yes: function (index, layero) {
                        var iframeWin = window[layero.find('iframe')[0]['name']];
                        var store_id = iframeWin.$("select[name='store_id']").val();
                        var discount_percentage = iframeWin.$("input[name='discount_percentage']").val();
                        var public_account_id = iframeWin.$("textarea[name='public_account_id']").val();
                        var private_account_id = iframeWin.$("textarea[name='private_account_id']").val();
                        var token = iframeWin.$("input[name='__token__']").val();
                        
                        Fast.api.ajax({
                            url: 'tencent/account/bind_by_account_id',
                            data: {
                                __token__: token,
                                store_id: store_id,
                                discount_percentage: discount_percentage,
                                public_account_id: public_account_id,
                                private_account_id: private_account_id
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
            
            // 根据名称绑定按钮点击事件
            $(".btn-bindbyname").on('click', function () {
                var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
                var width = isMobile ? '80%' : '40%';
                var height = '60%';
                
                layer.open({
                    type: 2,
                    area: [width, height],
                    content: 'account/bind_by_account_name',
                    fixed: false,
                    maxmin: true,
                    shadeClose: true,
                    title: '根据账户名称绑定',
                    btn: ['提交', '取消'],
                    btnAlign: 'c',
                    yes: function (index, layero) {
                        var iframeWin = window[layero.find('iframe')[0]['name']];
                        var store_id = iframeWin.$("select[name='store_id']").val();
                        var account_type = iframeWin.$("select[name='account_type']").val();
                        var discount_percentage = iframeWin.$("input[name='discount_percentage']").val();
                        var account_names = iframeWin.$("textarea[name='account_names']").val();
                        var token = iframeWin.$("input[name='__token__']").val();
                        
                        Fast.api.ajax({
                            url: 'tencent/account/bind_by_account_name',
                            data: {
                                __token__: token,
                                store_id: store_id,
                                account_type: account_type,
                                discount_percentage: discount_percentage,
                                account_names: account_names
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
        bind_by_account_id: function () {
            Controller.api.bindevent();
        },
        bind_by_account_name: function () {
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