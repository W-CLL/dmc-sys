define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            Controller.api.bindevent();
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'score/index/index' + location.search,
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                pageSize: 10,
                pageList: [10, 15, 20, 50],
                exportDataType: 'basic',
                columns: [
                    [
                        {field: 'id', title: "ID"},
                        {field: 'adv_id', title: "广告主id"},
                        {field: 'one_class_score', title: "一类违规",sortable:true},
                        {field: 'two_three_class_score', title: "二三类违规",sortable:true},
                        {field: 'operate', title: __('Operate'),
                            buttons: [{
                                name: 'info_list',
                                text: "违规记录",
                                title:"违规记录",
                               extend: "data-area: ['1000px', '600px']",
                                classname: 'btn btn-dialog  btn-warning',
                                url: 'score/index/score_list',
                            }],
                            table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
                    ]
                ],
                queryParams:function (params) {
                    params.advertiser_id = document.getElementById('account_id').value;
                    return params;
                }
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        score_list: function () {
            Controller.api.bindevent();
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'score/index/score_list/' + location.search,
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                // ... 其他配置 ...
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                pageSize: 10,
                pageList: [10, 15, 20, 50],
                exportDataType: 'basic',
                columns: [
                    [
                        {field: 'advertiser_id', title: "广告主id"},
                        {field: 'event_id', title: "违规单id"},
                        {field: 'ad_id', title: "计划id"},
                        {field: 'material_id', title: "素材id"},
                        {field: 'violation_evidence_img', title: "违规证据截图", events: Table.api.events.image, formatter: Table.api.formatter.image},
                        {field: 'score', title: "扣罚分值"},
                        {field: 'reject_reason', title: "拒绝理由",width: '200',cellStyle : function(value, row, index, field){
                                return {
                                    css: {
                                        "white-space": "initial",//单行省略必备
                                        "text-overflow": "ellipsis",//单行省略必备
                                        "overflow": "hidden",//单行省略必备
                                        "color": "#3172a6",
                                        "max-width":"200px"
                                    }
                                };
                            }},
                        {field: 'illegal_type_text', title: "违规类型"},
                        {field: 'create_time', title: "创建时间"},
                        {field: 'status_text', title: "状态"},
                    ]
                ],
                queryParams:function (params) {
                    // let time_data = document.getElementById('dateRange').value.split(' - ');
                    // params.start_date = time_data[0];
                    // params.end_date = time_data[1];
                    params.ids = document.getElementById('ids').value;
                    return params;
                }
            });

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
