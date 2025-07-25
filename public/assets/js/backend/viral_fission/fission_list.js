define(['jquery', 'bootstrap', 'backend', 'table', 'form', '../viral_fission/video_viewer'], function ($, undefined, Backend, Table, Form, VideoViewer) {

    var Controller = {
        index: function () {
            Controller.api.bindevent();

            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'viral_fission/fission_list/index',
                    batch_precheck_url: 'viral_fission/fission_list/batchPreCheck',
                    batch_adopt_url: 'viral_fission/fission_list/batchAdopt',
                    single_precheck_url: 'viral_fission/fission_list/singlePreCheck',
                    table: 'adv_derive_material',
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
                pageList: [10, 15, 20,50,100],
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: "ID", visible: false},
                        {field: 'adv_id', title: "千川ID", operate: '='},
                        {field: 'strategy_description', title: "描述", operate: '='},
                        {field: 'old_material_id', title: "原素材ID", operate: '=',
                            formatter: function(value, row, index) {
                                if (!value) return '-';
                                var videoUrls = row.video_urls || []; // Assume video_urls is an array of URLs
                                var videoLinks = videoUrls.map(function(url, idx) {
                                    return '<span class="material-id-hover" data-url="' + url + '">视频' + (idx + 1) + '</span>';
                                }).join(' ');
                                return '<div>' + value + '<br>' + videoLinks + '</div>';
                            }},
                        {field: 'strategy_name', title: "裂变策略", operate: false},
                        {field: 'create_time', title: "生成时间", operate: 'RANGE', addclass: 'datetimerange', formatter: Table.api.formatter.datetime},
                        {
                            field: 'video_id',
                            title: "视频ID",
                            formatter: function(value, row, index) {
                                if (!value) return '-';
                                var videoUrls = row.video_urls || []; // Assume video_urls is an array of URLs
                                var videoLinks = videoUrls.map(function(url, idx) {
                                    return '<span class="material-id-hover" data-url="' + url + '">视频' + (idx + 1) + '</span>';
                                }).join(' ');
                                return '<div>' + value + '<br>' + videoLinks + '</div>';
                            }
                        },
                        {
                            field: 'adopt_status_message',
                            title: "采纳状态",
                            operate: false,
                            formatter: function(value, row, index) {
                                return value === "success" ? '<span class="label label-success">已采纳</span>' : '<span class="label label-danger">未采纳</span>';
                            }
                        },
                    ]
                ]
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
