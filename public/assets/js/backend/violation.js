define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'violation/index' + location.search,
                    view_url: 'violation/view',
                    table: 'violation',
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
                        {field: 'advertiser_id', title: '广告主ID', operate: 'LIKE'},
                        {field: 'ad_id', title: '广告ID', operate: 'LIKE'},
                        {field: 'material_id', title: '素材ID', operate: 'LIKE'},
                        {field: 'event_id', title: '违规单ID', operate: 'LIKE'},
                        {
                            field: 'type', 
                            title: '类型', 
                            searchList: {"1": "新增违规积分", "2": "更新违规积分"},
                            formatter: function (value, row, index) {
                                var typeClass = ['label-info', 'label-warning'][value - 1] || 'label-default';
                                var typeText = ['新增违规积分', '更新违规积分'][value - 1] || '';
                                return '<span class="label ' + typeClass + '">' + typeText + '</span>';
                            }
                        },
                        {
                            field: 'score', 
                            title: '扣罚分值', 
                            formatter: function (value, row, index) {
                                return '<span class="label label-danger">' + value + ' 分</span>';
                            }
                        },
                        {
                            field: 'status', 
                            title: '状态', 
                            searchList: {"1": "已申诉(失效)", "2": "申诉失败", "3": "申诉中", "4": "生效"},
                            formatter: function (value, row, index) {
                                var statusClass = ['label-default', 'label-success', 'label-warning', 'label-primary'][value] || 'label-default';
                                var statusText = ['已申诉(失效)', '申诉失败', '申诉中', '生效'][value] || '';
                                return '<span class="label ' + statusClass + '">' + statusText + '</span>';
                            }
                        },
                        {
                            field: 'illegal_type', 
                            title: '违规类型', 
                            searchList: {"1": "一类违规", "2": "二类违规"},
                            formatter: function (value, row, index) {
                                if (!value) return '<span class="text-muted">-</span>';
                                var illegalTypeClass = ['label-danger', 'label-warning'][value - 1] || 'label-default';
                                var illegalTypeText = ['一类违规', '二类违规'][value - 1] || '';
                                return '<span class="label ' + illegalTypeClass + '">' + illegalTypeText + '</span>';
                            }
                        },
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

            // 搜索按钮事件
            $(document).on('click', '.btn-search', function () {
                var params = {};
                var advertiser_id = $('#advertiser_id').val();
                var ad_id = $('#ad_id').val();
                var material_id = $('#material_id').val();
                
                if (advertiser_id) {
                    params['advertiser_id'] = advertiser_id;
                }
                if (ad_id) {
                    params['ad_id'] = ad_id;
                }
                if (material_id) {
                    params['material_id'] = material_id;
                }
                
                table.bootstrapTable('refresh', {query: params});
            });

            // 重置按钮事件
            $(document).on('click', '.btn-reset', function () {
                $('#advertiser_id').val('');
                $('#ad_id').val('');
                $('#material_id').val('');
                table.bootstrapTable('refresh', {query: {}});
            });

            // 回车搜索
            $(document).on('keypress', '#advertiser_id, #ad_id, #material_id', function (e) {
                if (e.which === 13) {
                    $('.btn-search').trigger('click');
                }
            });

            // 查看详情按钮事件
            $(document).on('click', '.btn-view-one', function () {
                var id = $(this).data('id');
                Fast.api.open('violation/view?id=' + id, '违规详情', {
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
                Fast.api.open('violation/view?id=' + ids[0], '违规详情', {
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