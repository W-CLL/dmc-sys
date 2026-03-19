define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'material_prequalification/index' + location.search,
                    view_url: 'material_prequalification/view',
                    table: 'material_prequalification',
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
                        {field: 'id', title: 'ID', sortable: true},
                        {field: 'material_id', title: '素材ID', operate: 'LIKE'},
                        {field: 'advertiser_id', title: '广告主ID', operate: 'LIKE'},
                        {
                            field: 'status', 
                            title: '状态', 
                            searchList: {"0": "等待推送", "1": "预审中", "2": "通过", "3": "驳回"},
                            formatter: function (value, row, index) {
                                var statusClass = ['label-default', 'label-warning', 'label-success', 'label-danger'][value] || 'label-default';
                                var statusText = ['等待推送', '预审中', '通过', '驳回'][value] || '';
                                return '<span class="label ' + statusClass + '">' + statusText + '</span>';
                            }
                        },
                        {field: 'filename', title: '视频名称', operate: 'LIKE'},
                        {field: 'video_id', title: '视频ID', operate: 'LIKE'},
                        {field: 'object_id', title: '审核对象ID', operate: 'LIKE'},
                        {field: 'reason_text', title: '审核建议', operate: 'LIKE', formatter: function (value, row, index) {
                            if (!value) return '<span class="text-muted">-</span>';
                            // 如果有审核建议，显示查看详情按钮
                            return '<a href="javascript:;" class="btn btn-xs btn-primary btn-view-one" data-id="' + row.id + '" title="查看详情">查看</a>';
                        }},
                        {
                            field: 'create_time', 
                            title: '创建时间', 
                            operate:'RANGE', 
                            addclass:'datetimerange', 
                            autocomplete:false, 
                            formatter: Table.api.formatter.datetime
                        },
                        {
                            field: 'update_time', 
                            title: '更新时间', 
                            operate:'RANGE', 
                            addclass:'datetimerange', 
                            autocomplete:false, 
                            formatter: Table.api.formatter.datetime
                        },
                        {
                            field: 'operate', 
                            title: '操作', 
                            table: table, 
                            events: Table.api.events.operate, 
                            formatter: function (value, row, index) {
                                var buttons = [];
                                if (Config.operateView) {
                                    buttons.push('<a href="javascript:;" class="btn btn-xs btn-success btn-view-one" data-id="' + row.id + '"><i class="fa fa-eye"></i> 查看</a>');
                                }
                                return buttons.join(' ');
                            }
                        }
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);

            // 查看详情按钮事件
            $(document).on('click', '.btn-view-one', function () {
                var id = $(this).data('id');
                Fast.api.open('material_prequalification/view?id=' + id, '预审详情', {
                    callback: function (data) {
                        table.bootstrapTable('refresh');
                    }
                });
            });

            // 工具栏查看按钮事件
            $(document).on('click', '.btn-view', function () {
                var ids = Table.api.selectedids(table);
                if (ids.length === 0) {
                    Layer.msg('请选择至少一条记录');
                    return false;
                }
                if (ids.length > 1) {
                    Layer.msg('只能查看一条记录');
                    return false;
                }
                Fast.api.open('material_prequalification/view?id=' + ids[0], '预审详情', {
                    callback: function (data) {
                        table.bootstrapTable('refresh');
                    }
                });
            });
        },
        view: function () {
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
