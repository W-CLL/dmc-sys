define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化当前标签页筛选条件（从URL参数）
            window.currentTabFilter = {value: ''};
            var urlParams = new URLSearchParams(location.search);
            if (urlParams.has('is_first_publish_material') && urlParams.get('is_first_publish_material') == 1) {
                if (urlParams.has('is_ecp_high_quality') && urlParams.get('is_ecp_high_quality') == 1) {
                    // 同时有优质和首发 -> value=3
                    window.currentTabFilter = {value: 3};
                } else {
                    // 只有首发 -> value=1
                    window.currentTabFilter = {value: 1};
                }
            } else if (urlParams.has('is_ecp_high_quality') && urlParams.get('is_ecp_high_quality') == 1) {
                // 只有优质 -> value=2
                window.currentTabFilter = {value: 2};
            }

            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'material_diagnosis/index' + location.search,
                    view_url: 'material_diagnosis/view',
                    table: 'material_diagnosis',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                queryParams: function(params) {
                    // 收集所有筛选条件
                    var filters = {};
                    var material_id = $('#material_id').val();
                    var is_inefficient = $('#is_inefficient').val();

                    if (material_id) {
                        filters.material_id = material_id;
                    }
                    if (is_inefficient) {
                        filters.is_inefficient = is_inefficient;
                    }

                    // 合并标签页筛选条件
                    if (window.currentTabFilter && window.currentTabFilter.value !== '') {
                        var value = window.currentTabFilter.value;
                        // value=1 -> 首发, value=2 -> 优质, value=3 -> 优质且首发
                        if (value == 1) {
                            filters['is_first_publish_material'] = 1;
                        } else if (value == 2) {
                            filters['is_ecp_high_quality'] = 1;
                        } else if (value == 3) {
                            filters['is_first_publish_material'] = 1;
                            filters['is_ecp_high_quality'] = 1;
                        }
                    }

                    // 合并到 params 中
                    $.extend(params, filters);
                    return params;
                },
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: 'ID', sortable: true},
                        {field: 'material_id', title: '素材ID', operate: 'LIKE'},
                        {field: 'advertisers', title: '使用该素材的广告主', operate: false, formatter: function (value, row, index) {
                            if (!value || value === '-') return '<span class="text-muted">-</span>';
                            var count = row.advertiser_count || 0;
                            if (count > 0) {
                                return '<span class="label label-info" style="cursor:pointer;" onclick="Fast.api.open(\'material_diagnosis/view?id=' + row.id + '\', \'诊断详情\');">' + count + ' 个广告主</span>';
                            }
                            return '<span class="text-muted">-</span>';
                        }},
                        {field: 'video_id', title: '视频ID', operate: 'LIKE'},
                        {field: 'task_id', title: '任务ID', operate: 'LIKE'},
                        {
                            field: 'status', 
                            title: '状态', 
                            searchList: {"0": "PENDING", "1": "SUCCESS", "2": "FAILED"},
                            formatter: function (value, row, index) {
                                var statusClass = ['label-warning', 'label-success', 'label-danger'][value] || 'label-default';
                                var statusText = ['PENDING', 'SUCCESS', 'FAILED'][value] || '';
                                return '<span class="label ' + statusClass + '">' + statusText + '</span>';
                            }
                        },
                        {
                            field: 'is_get', 
                            title: '是否获取详情', 
                            searchList: {"0": "未获取详情", "1": "已获取详情"},
                            formatter: function (value, row, index) {
                                var isGetClass = ['label-default', 'label-success'][value] || 'label-default';
                                var isGetText = ['未获取详情', '已获取详情'][value] || '';
                                return '<span class="label ' + isGetClass + '">' + isGetText + '</span>';
                            }
                        },
                        {
                            field: 'is_ecp_high_quality_material', 
                            title: '是否千川优质', 
                            searchList: {"0": "UNKNOWN", "1": "YES", "2": "NO"},
                            formatter: function (value, row, index) {
                                var classList = ['label-default', 'label-success', 'label-danger'];
                                var textList = ['UNKNOWN', 'YES', 'NO'];
                                var cls = classList[value] || 'label-default';
                                var txt = textList[value] || '';
                                return '<span class="label ' + cls + '">' + txt + '</span>';
                            }
                        },
                        {
                            field: 'is_inefficient_material', 
                            title: '是否低效', 
                            searchList: {"0": "UNKNOWN", "1": "YES", "2": "NO"},
                            formatter: function (value, row, index) {
                                var classList = ['label-default', 'label-danger', 'label-success'];
                                var textList = ['UNKNOWN', 'YES', 'NO'];
                                var cls = classList[value] || 'label-default';
                                var txt = textList[value] || '';
                                return '<span class="label ' + cls + '">' + txt + '</span>';
                            }
                        },
                        {
                            field: 'is_first_publish_material', 
                            title: '是否首发', 
                            searchList: {"0": "UNKNOWN", "1": "YES", "2": "NO"},
                            formatter: function (value, row, index) {
                                var classList = ['label-default', 'label-success', 'label-danger'];
                                var textList = ['UNKNOWN', 'YES', 'NO'];
                                var cls = classList[value] || 'label-default';
                                var txt = textList[value] || '';
                                return '<span class="label ' + cls + '">' + txt + '</span>';
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

            // 标签页切换事件 - 首发/优质筛选
            $(document).on('click', '.nav-tabs li a', function () {
                var value = $(this).data('value');
                var params = {};

                // 记录当前标签页筛选条件
                window.currentTabFilter = {value: value};

                // 获取其他筛选条件
                var material_id = $('#material_id').val();
                var is_inefficient = $('#is_inefficient').val();

                if (material_id) {
                    params['material_id'] = material_id;
                }
                if (is_inefficient) {
                    params['is_inefficient'] = is_inefficient;
                }

                // 根据标签value设置筛选参数
                if (value == 1) {
                    params['is_first_publish_material'] = 1;
                } else if (value == 2) {
                    params['is_ecp_high_quality'] = 1;
                } else if (value == 3) {
                    params['is_first_publish_material'] = 1;
                    params['is_ecp_high_quality'] = 1;
                }

                table.bootstrapTable('refresh', {query: params});
            });

            // 搜索按钮事件
            $(document).on('click', '.btn-search', function () {
                var params = {};
                var material_id = $('#material_id').val();
                var is_inefficient = $('#is_inefficient').val();

                if (material_id) {
                    params['material_id'] = material_id;
                }
                if (is_inefficient) {
                    params['is_inefficient'] = is_inefficient;
                }

                table.bootstrapTable('refresh', {query: params});
            });

            // 重置按钮事件
            $(document).on('click', '.btn-reset', function () {
                $('#material_id').val('');
                $('#is_inefficient').val('');
                $('.nav-tabs li:first a').trigger('click');
                table.bootstrapTable('refresh', {query: {}});
            });

            // 回车搜索
            $(document).on('keypress', '#material_id', function (e) {
                if (e.which === 13) {
                    $('.btn-search').trigger('click');
                }
            });

            // 搜索按钮事件
            $(document).on('click', '.btn-search', function () {
                var params = {};
                var material_id = $('#material_id').val();
                var is_inefficient = $('#is_inefficient').val();
                
                if (material_id) {
                    params['material_id'] = material_id;
                }
                if (is_inefficient) {
                    params['is_inefficient'] = is_inefficient;
                }
                
                table.bootstrapTable('refresh', {query: params});
            });

            // 重置按钮事件
            $(document).on('click', '.btn-reset', function () {
                $('#material_id').val('');
                $('#is_inefficient').val('');
                $('.nav-tabs li:first a').trigger('click');
                table.bootstrapTable('refresh', {query: {}});
            });

            // 回车搜索
            $(document).on('keypress', '#material_id', function (e) {
                if (e.which === 13) {
                    $('.btn-search').trigger('click');
                }
            });

            // 查看详情按钮事件
            $(document).on('click', '.btn-view-one', function () {
                var id = $(this).data('id');
                Fast.api.open('material_diagnosis/view?id=' + id, '诊断详情', {
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
                Fast.api.open('material_diagnosis/view?id=' + ids[0], '诊断详情', {
                    callback: function (data) {
                        table.bootstrapTable('refresh');
                    }
                });
            });

            // 查看广告主列表点击事件
            $(document).on('click', '.btn-view-advertisers', function () {
                var materialId = $(this).data('material_id');
                Fast.api.open('material_diagnosis/view?id=' + $(this).closest('tr').find('input[name=btSelectItem]').val() + '&advertisers=1', '使用该素材的广告主', {
                    area: ['600px', '400px'],
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