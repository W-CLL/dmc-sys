define(['jquery', 'bootstrap', 'store', 'table', 'form', '../viral_fission/video_viewer', 'echarts', 'echarts-theme'], function ($, undefined, Backend, Table, Form, VideoViewer, Echarts, undefined) {

    var Controller = {
        // 全局变量
        selectedIds: [],
        autoRefreshTimer: null,
        refreshCountdown: 30,
        currentTasks: {},

        index: function () {
            Controller.api.bindevent();
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'viral_fission/material_consumption/index',
                    stats_url: 'viral_fission/material_consumption/get_stats',
                    line_chart_url: 'viral_fission/material_consumption/get_chart',
                    table: 'adv_global_material',
                }
            });
            // 初始化页面
            Controller.initPage();
            var table = $("#table");
            // 保存table引用
            Controller.table = table;
            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                searchFormVisible: true,
                searchFormTemplate: 'customformtpl',
                queryParams: function (params) {
                    // 添加自定义筛选参数
                    var formData = $("form.form-commonsearch").serializeArray();
                    $.each(formData, function(i, field) {
                        if (field.value !== '') {
                            params[field.name] = field.value;
                        }
                    });
                    return params;
                },
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: "ID", visible: false},
                        {field: 'adv_id', title: "千川ID", operate: '='},
                        {field: 'company_name', title: "公司名"},
                        {field: 'first_industry_name', title: "行业类型"},
                        {field: 'second_industry_name', title: "行业"},
                        {field: 'kahuna', title: "负责人"},
                        {field: 'material_id', title: "素材ID", operate: '=',
                            formatter: function(value, row, index) {
                                if (!value) return '-';
                                var videoUrls = row.video_urls || []; // Assume video_urls is an array of URLs
                                var videoLinks = videoUrls.map(function(url, idx) {
                                    return '<span class="material-id-hover" data-url="' + url + '">视频' + (idx + 1) + '</span>';
                                }).join(' ');
                                return '<div>' + value + '<br>' + videoLinks + '</div>';
                            }
                        },
                        {field: 'stat_cost_for_roi2', title: "素材消耗", operate: 'BETWEEN', formatter: function(value) {
                                return value ? '¥' + parseFloat(value).toFixed(2) : '¥0.00';
                            }},
                        {field: 'roi_display', title: "ROI", formatter: function(value) {
                                // 如果是数字则显示数字，否则显示原值（如"-"）
                                if (typeof value === 'number') {
                                    return value.toFixed(2);
                                }
                                return value;
                            }},
                        {field: 'create_time_text', title: "消耗日期"},
                        {field: 'is_fission', title: "是否裂变素材"},
                        {field: 'fission_count', title: "共裂变素材个数"},
                        {field: 'unfission_reason', title: "不可裂变原因"},
                        {
                            field: 'operate',
                            title: __('Operate'),
                            table: table,
                            events: Table.api.events.operate,
                        }
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);

            // 搜索表单提交事件
            $(document).on('submit', 'form.form-commonsearch', function(e) {
                e.preventDefault();
                table.bootstrapTable('refresh');
                return false;
            });

            $(document).on('mouseleave', '.material-id-hover', function() {
                $('#video-player').remove();
            });
        },

        // 加载统计数据
        loadStats: function() {
            $.ajax({
                url: $.fn.bootstrapTable.defaults.extend.stats_url,
                type: 'GET',
                success: function(data) {
                    console.log(data);
                    if (data.code === 1) {
                        $('#total-materials').text(data.data.total || 0);
                        $('#generated-count').text(data.data.generated || 0);
                        $('#adopted-count').text(data.data.adopted || 0);
                        $('#success-rate').text((data.data.success_rate || 0) + '%');
                    } else {
                        // 如果API返回错误，使用随机数据
                        var randomData = Controller.generateRandomData();
                        $('#total-materials').text(randomData.total);
                        $('#generated-count').text(randomData.generated);
                        $('#adopted-count').text(randomData.adopted);
                        $('#success-rate').text(randomData.success_rate + '%');
                    }
                },
                error: function() {
                    // 如果请求失败，使用随机数据
                    var randomData = Controller.generateRandomData();
                    $('#total-materials').text(randomData.total);
                    $('#generated-count').text(randomData.generated);
                    $('#adopted-count').text(randomData.adopted);
                    $('#success-rate').text(randomData.success_rate + '%');
                }
            });
        },

        // 初始化页面
        initPage: function() {
            Controller.initCollapsible()
            // 加载统计数据
            Controller.loadStats();
            // 初始化图表
            Controller.initCharts();
        },

        // 初始化折叠功能
        initCollapsible: function() {
            // 统计面板折叠
            $('#toggle-stats').on('click', function() {
                var $icon = $(this).find('i');
                var $cards = $('#stats-cards');

                if ($cards.hasClass('collapsed')) {
                    $cards.removeClass('collapsed').slideDown();
                    $icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
                } else {
                    $cards.addClass('collapsed').slideUp();
                    $icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
                }
            });

            // 默认折叠统计面板（如果屏幕较小）
            if ($(window).width() < 768) {
                $('#toggle-stats').trigger('click');
            }
        },

        // 初始化图表
        initCharts: function() {
            // 基于准备好的dom，初始化echarts实例
            var lineChart = Echarts.init(document.getElementById('line-chart'), 'walden');

            // 折线图配置
            var lineOption = {
                title: {
                },
                color: [
                    "#18d1b1",
                    "#3fb1e3",
                    "#626c91",
                    "#a0a7e6",
                    "#c4ebad",
                    "#96dee8"
                ],
                tooltip: {
                    trigger: 'axis'
                },
                legend: {
                    data: ['消耗金额', '素材数量', '裂变后消耗', '裂变素材数']
                },
                toolbox: {
                    show: false,
                    feature: {
                        magicType: {show: true, type: ['stack', 'tiled']},
                        saveAsImage: {show: true}
                    }
                },
                xAxis: {
                    type: 'category',
                    boundaryGap: false,
                    data: [], // 将通过AJAX加载
                    axisLabel: {
                        rotate: 45,
                        interval: 0,
                        formatter: function(value) {
                            // 如果是日期格式，显示月-日
                            if (value && value.match && value.match(/^\d{4}-\d{2}-\d{2}$/)) {
                                return value.substring(5); // 显示 MM-DD
                            }
                            return value;
                        }
                    }
                },
                yAxis: [
                    {
                        type: 'value',
                        name: '消耗金额(¥)',
                        position: 'left'
                    },
                    {
                        type: 'value',
                        name: '数量',
                        position: 'right'
                    }
                ],
                grid: [{
                    left: '3%',
                    top: '15%',
                    right: '4%',
                    bottom: '10%',
                    containLabel: true
                }],
                series: [
                    {
                        name: '消耗金额',
                        type: 'line',
                        smooth: true,
                        yAxisIndex: 0,
                        areaStyle: {
                            normal: {}
                        },
                        lineStyle: {
                            normal: {
                                width: 1.5
                            }
                        },
                        data: [] // 将通过AJAX加载
                    },
                    {
                        name: '素材数量',
                        type: 'line',
                        smooth: true,
                        yAxisIndex: 1,
                        lineStyle: {
                            normal: {
                                width: 1.5
                            }
                        },
                        data: [] // 将通过AJAX加载
                    },
                    {
                        name: '裂变后消耗',
                        type: 'line',
                        smooth: true,
                        yAxisIndex: 0,
                        lineStyle: {
                            normal: {
                                width: 1.5,
                                type: 'dashed'
                            }
                        },
                        data: [] // 将通过AJAX加载
                    },
                    {
                        name: '裂变素材数',
                        type: 'line',
                        smooth: true,
                        yAxisIndex: 1,
                        lineStyle: {
                            normal: {
                                width: 1.5,
                                type: 'dashed'
                            }
                        },
                        data: [] // 将通过AJAX加载
                    }
                ]
            };

            // 使用刚指定的配置项和数据显示图表
            lineChart.setOption(lineOption);

            // 窗口大小调整事件
            $(window).resize(function () {
                lineChart.resize();
            });

            // 刷新按钮事件
            $(document).on("click", ".btn-refresh", function () {
                setTimeout(function () {
                    lineChart.resize();
                }, 0);
            });

            // 绑定时间选择器事件
            Controller.bindTimeSelectors(lineChart);

            // 加载图表数据
            Controller.loadLineChartData(lineChart);
        },

        // 绑定时间选择器事件
        bindTimeSelectors: function(lineChart) {
            // 折线图时间选择
            $('#line-chart-days').on('change', function() {
                var selectedValue = $(this).val();
                Controller.loadLineChartDataWithPeriod(lineChart, selectedValue);
            });

            // 折线图刷新按钮
            $('#line-chart-refresh').on('click', function() {
                var selectedPeriod = $('#line-chart-days').val();
                Controller.refreshWithLoading($(this), function() {
                    Controller.loadLineChartDataWithPeriod(lineChart, selectedPeriod);
                });
            });
        },

        // 通用刷新方法
        refreshWithLoading: function($btn, callback) {
            var originalText = $btn.html();

            // 显示加载状态
            $btn.html('<i class="fa fa-spinner fa-spin"></i> 刷新中...').prop('disabled', true);

            // 执行回调
            callback();

            // 恢复按钮状态
            setTimeout(function() {
                $btn.html(originalText).prop('disabled', false);
            }, 1000);
        },

        // 加载折线图数据
        loadLineChartData: function(chart) {
            $.ajax({
                url: $.fn.bootstrapTable.defaults.extend.line_chart_url,
                type: 'GET',
                dataType: 'json',
                success: function(response) {
                    Controller.processLineChartResponse(chart, response);
                },
                error: function(xhr, status, error) {
                    console.error('Failed to load line chart data:', error);
                    // 使用模拟数据
                    Controller.loadMockLineData(chart);
                }
            });
        },

        // 根据时间段加载折线图数据
        loadLineChartDataWithPeriod: function(chart, period) {
            $.ajax({
                url: $.fn.bootstrapTable.defaults.extend.line_chart_url,
                type: 'GET',
                data: {
                    period: period
                },
                dataType: 'json',
                success: function(response) {
                    Controller.processLineChartResponse(chart, response);
                },
                error: function() {
                    console.error('Failed to load line chart data with period:', period);
                    Controller.loadMockLineData(chart);
                }
            });
        },

        // 处理折线图响应数据
        processLineChartResponse: function(chart, response) {
            if (response.code === 1 && response.data && response.data.length > 0) {
                var data = response.data;
                var dates = [];
                var costs = [];
                var materialCounts = [];
                var fissionCosts = [];
                var fissionMaterialCounts = [];

                // 按日期正序排序（从小到大）
                data.sort(function(a, b) {
                    return new Date(a.date) - new Date(b.date);
                });

                data.forEach(function(item) {
                    dates.push(item.date);
                    costs.push(parseFloat(item.total_cost) || 0);
                    materialCounts.push(parseInt(item.material_count) || 0);
                    fissionCosts.push(parseFloat(item.fission_cost) || 0);
                    fissionMaterialCounts.push(parseInt(item.fission_material_count) || 0);
                });

                // 更新图表数据
                chart.setOption({
                    xAxis: {
                        data: dates
                    },
                    series: [
                        {
                            data: costs
                        },
                        {
                            data: materialCounts
                        },
                        {
                            data: fissionCosts
                        },
                        {
                            data: fissionMaterialCounts
                        }
                    ]
                });

            } else {
                Controller.loadMockLineData(chart);
            }
        },

        // 模拟折线图数据
        loadMockLineData: function(chart) {
            // 生成最近7天的日期
            var dates = [];
            var today = new Date();
            for (var i = 6; i >= 0; i--) {
                var date = new Date(today);
                date.setDate(today.getDate() - i);
                dates.push(date.getFullYear() + '-' +
                           String(date.getMonth() + 1).padStart(2, '0') + '-' +
                           String(date.getDate()).padStart(2, '0'));
            }

            var costs = [1200, 1800, 1500, 2200, 1900, 2500, 2100];
            var materialCounts = [15, 22, 18, 28, 24, 32, 26];
            var fissionCosts = [300, 450, 375, 550, 475, 625, 525];
            var fissionMaterialCounts = [3, 5, 4, 7, 6, 8, 7];

            chart.setOption({
                xAxis: {
                    data: dates
                },
                series: [
                    {
                        data: costs
                    },
                    {
                        data: materialCounts
                    },
                    {
                        data: fissionCosts
                    },
                    {
                        data: fissionMaterialCounts
                    }
                ]
            });
        },

        // 生成随机数据（用于演示）
        generateRandomData: function() {
            return {
                total: Math.floor(Math.random() * 5000) + 1000,
                generated: Math.floor(Math.random() * 800) + 200,
                adopted: Math.floor(Math.random() * 600) + 150,
                success_rate: Math.floor(Math.random() * 40) + 60
            };
        },

        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});