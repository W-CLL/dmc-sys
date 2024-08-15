define(['jquery', 'bootstrap', 'company', 'table', 'form'], function ($, undefined, Company, Table, Form) {

    var Controller = {
        index: function () {
            Controller.api.bindevent();
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'financial_staff/index' + location.search,
                    multi_url: "financial_staff/multi",
                    table: 'financial_staff',

                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                // ... 其他配置 ...
                search: false, // 禁用默认搜索
                commonSearch: false, // 启用普通表单搜索
                searchFormVisible: true, // 控制搜索栏是否显示在页面上
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                fixedColumns: true,
                fixedRightNumber: 1,
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('Id'),visible: false},
                        {field: 'name', title: "名称"},
                        {field: 'state', title: "状态", formatter: function(value,row,index) {
                            if (row.state == 1){
                                return "通知"
                            }else{
                                return "不通知"
                            }
                            }, operate: 'LIKE'},
                        {
                            field: 'state',
                            title: "状态",
                            align: 'center',
                            table: table,
                            formatter: Table.api.formatter.toggle
                        },
                        // {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate},
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);


            $(".btn-list-save").on('click',function(){
                layer.confirm('你确定要更新财务数据吗?更新完成后通知选项需重新设置', {icon: 3, title:'提示'}, function(index){
                    //do something
                    Fast.api.ajax({
                        url: 'financial_staff/list_save',
                    }, function (data, ret) {
                        table.bootstrapTable('refresh', {});
                        layer.close(index);
                    });
                });

                // layer.open({
                //     type: 2,
                //     content: '你确定要更新财务数据吗?更新完成后通知选项需重新设置',
                //     fixed: false, // 不固定
                //     maxmin: true,
                //     shadeClose: true,
                //     btn: ['提交', '取消'],
                //     btnAlign: 'c',
                //     yes: function(index, layero){
                //         var token = body.find("input[name='__token__']")[0].value;
                //
                //         Fast.api.ajax({
                //             url: '',
                //             data: {
                //                 __token__ : token,
                //                 store_id: store_id,
                //                 account_type: account_type,
                //                 company_ids: checkids.join(','),
                //             }
                //         }, function (data, ret) {
                //             table.bootstrapTable('refresh', {});
                //             Layer.close(index);
                //         });
                //     }
                // });
            })
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});
