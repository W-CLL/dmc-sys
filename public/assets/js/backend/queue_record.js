define(['jquery', 'bootstrap', 'backend', 'table', 'form','bootstrap-table-fixed-columns'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            Controller.api.bindevent();
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'queue_record/index',
                    table: 'queue_record',
                },
            });

            var table = $("#table");
            var tableOptions = {
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                pagination: true,
                // commonSearch: false,
                // search: false,
                searchFormVisible: true,
                searchFormTemplate: 'customformtpl',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('Id'), visible: false},
                        {field: 'job_id', title: "任务id", operate: false, searchList: Config.searchList, formatter: Table.api.formatter.label},
                        {field: 'class_name', title:"任务类名", align: 'left', formatter:function (value, row, index) {
                                return value.toString().replace(/(&|&amp;)nbsp;/g, '&nbsp;');
                            }
                        },
                        {field: 'job_name', title: "任务名称"},
                        {field: 'msg', title: "执行信息",width:130,align:'left'},
                        {field: 'remark', title: "备注"},
                        {field: 'status_text', title: __('Status')},
                        {field: 'create_time', title:"创建时间" ,formatter: Table.api.formatter.datetime},
                        {field: 'operate', title: __('Operate'),
                            buttons:[{
                                name: "queue_records",
                                text: "重启",//按钮名称
                                classname: 'btn btn-xs btn-success  btn-ajax',
                                // classname: 'btn btn-xs btn-success btn-magic btn-dialog',
                                icon: '',
                                // options: {refresh: true},
                                url: 'queue_record/rebuildOne',//指向控制器对应方法
                                // confirm: '重启',
                                refresh: true,
                                visible: function (row) {
                                    //返回true时按钮显示,返回false隐藏
                                    if(row.status == 2){
                                        return true;
                                    }else{
                                        return false;
                                    }
                                },
                                // success:function (){
                                //     table.bootstrapTable('refresh');
                                // }
                            }],
                            table: table, events: Table.api.events.operate, formatter: Table.api.formatter.buttons}
                    ]
                ]
            };
            // 初始化表格
            table.bootstrapTable(tableOptions);
            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});
