define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            Controller.api.bindevent();

            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'company/company/index',
                    edit_url: 'company/company/edit',
                    multi_url: 'company/company/multi',
                    transfer_records_url: "transfer_records/index",
                    table: 'company',
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
                        {field: 'id', title: "id", validator: false},
                        {field: 'advertiser_id', title: '千川id'},
                        {field: 'store.username', title: "绑定账号"},
                        {field: 'company_name', title: "公司名", operate: 'LIKE'},
                        {field: 'account_type', title: "账户类型", operate: 'LIKE', formatter: function(value,row,index) {
                                if (row.account_type == 1) {
                                    return "公"
                                } else {
                                    return "私"
                                }
                            }},
                        {field: 'discount_percentage', title: "特定比例", formatter: function(value,row,index) {
                                const discount_percentage = row.discount_percentage * 1;
                                if (discount_percentage == 0){
                                    return "不适用"
                                }else{
                                    return row.discount_percentage+"%";
                                }
                            }, operate: 'LIKE'},
                        {field: 'name', title: "账户名", operate: 'LIKE'},
                        {field: 'first_industry_name', title: "类别1", operate: 'LIKE'},
                        {field: 'second_industry_name', title: "类别2", operate: 'LIKE'},
                        {
                            field: 'operate', title: __('Operate'), buttons: [
                                {
                                    name: "transfer_records",
                                    text: "资金流水",//按钮名称
                                    classname: 'btn btn-xs btn-success btn-magic ',
                                    // classname: 'btn btn-xs btn-success btn-magic btn-dialog',
                                    icon: 'fa fa-magic',
                                    url: 'transfer_records/index',//指向控制器对应方法
                                    confirm: '查看当前用户资金流水',
                                    visible: function (row) {
                                        //返回true时按钮显示,返回false隐藏
                                        return true;
                                    }
                                },
                                {
                                    name: "query_no_grant",
                                    text: "非赠款消耗查询",//按钮名称
                                    classname: 'btn btn-xs btn-success btn-dialog ',
                                    // classname: 'btn btn-xs btn-success btn-magic btn-dialog',
                                    icon: 'fa fa-magic',
                                    url: 'company/company/query_grant',//指向控制器对应方法
                                    // confirm: '查询',
                                    visible: function (row) {
                                        //返回true时按钮显示,返回false隐藏
                                        return true;
                                    }
                                },
                            ], table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate
                        }
                    ]
                ],
                queryParams: function (params) {
                    params.is_binding = $("#is_binding").val()
                    params.is_set = $("#is_set").val()
                    return params;
                }
            });

            // 为表格绑定事件
            Table.api.bindevent(table);

            table.on('check-all.bs.table', function (e, rows) {
                // 点击全选触发事件
                var select_total = 0;
                for (i = 0; i < rows.length; i++) {
                    select_total = select_total + 1;
                }
                $("#select_total").text(select_total);
            })

            table.on('uncheck-all.bs.table', function (e, rows) {
                // 点击反选触发事件
                $("#select_total").text("0");
            })

            table.on('check.bs.table', function (e, row) {
                // 勾选某一行触发事件
                var select_total = parseInt($("#select_total").text()) + 1;
                $("#select_total").text(select_total);
            })

            table.on('uncheck.bs.table', function (e, row) {
                // 反选某一行触发事件
                var select_total = parseInt($("#select_total").text()) - 1;
                $("#select_total").text(select_total);
            })

            table.on('post-body.bs.table', function (e, row) {
                $("#select_total").text("0");
            })


            /* 获取选中的id */
            function getIdSelections() {
                return $.map($("#table").bootstrapTable('getSelections'), function (row) {
                    return row.id
                });
            }
            // 批量设置权限
            // 注意这里的元素你需要根据自己给<a>标签取的类名来获取，我的是btn-changeteacher，你可以在上一步看到。
            $(".btn-changeteacher").on('click', function () {
                var checkids = [];
                checkids = getIdSelections();
                var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent); // 判断是否为移动端
                var width = isMobile ? '80%' : '40%'; // 手机端80%，电脑端40%
                var height = '60%'; // 高度统一为60%
                layer.open({
                    type: 2,
                    area: [width, height],
                    content: 'company/batch_binding',
                    fixed: false, // 不固定
                    maxmin: true,
                    shadeClose: true,
                    btn: ['提交', '取消'],
                    btnAlign: 'c',
                    yes: function (index, layero) {
                        var body = layer.getChildFrame('body', index);
                        var store_id = body.find("select[name='store_id']")[0].value;
                        var account_type = body.find("select[name='account_type']")[0].value;
                        var discount_percentage = body.find("input[name='discount_percentage']")[0].value;
                        var token = body.find("input[name='__token__']")[0].value;
                        console.log(store_id)
                        Fast.api.ajax({
                            url: 'company/company/batch_binding',
                            data: {
                                __token__: token,
                                store_id: store_id,
                                account_type: account_type,
                                discount_percentage: discount_percentage,
                                company_ids: checkids.join(','),
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
        query_grant: function () {
            Controller.api.bindevent();
        },
        transfer_records: function () {
            Controller.api.bindevent();
        },
        batch_binding: function () {
            Controller.api.bindevent();
        },
        bind_by_qc_id: function () {
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