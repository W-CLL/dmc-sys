define(['jquery', 'bootstrap', 'backend', 'table', 'form', '../viral_fission/video_viewer','echarts', 'echarts-theme'], 
    function ($, undefined, Backend, Table, Form, VideoViewer, Echarts, undefined) {

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
                        {field: 'store_name', title: "商户名称"},
                        {field: 'material_id', title: "素材ID", operate: '=',
                            formatter: function(value, row, index) {
                                if (!value) return '-';
                                var videoUrls = row.video_urls || [];
                                var videoLinks = videoUrls.map(function(url, idx) {
                                    return '<span class="material-id-hover" data-url="' + url + '">视频' + (idx + 1) + '</span>';
                                }).join(' ');
                                return '<div>' + value + '<br>' + videoLinks + '</div>';
                            }
                        },
                        {field: 'stat_cost_for_roi2', title: "素材消耗", operate: 'BETWEEN', formatter: function(value) {
                            return value ? '¥' + parseFloat(value).toFixed(2) : '¥0.00';
                        }},
                        {field: 'total_pay_order_count_for_roi2', title: "单量"},
                        {field: 'total_prepay_and_pay_order_roi2', title: "整体支付ROI"},
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
        dashboard: function () {
      

            // 初始化统计数据
            Controller.loadStatsData();

            // 基于准备好的dom，初始化echarts实例 - 完全参考dashboard.js
            var lineChart = Echarts.init(document.getElementById('line-chart'), 'walden');
            var pieChart = Echarts.init(document.getElementById('pie-chart'), 'walden');


            // 折线图配置 - 参考dashboard.js结构
            var lineOption = {
                title: {
                    // text: '消耗趋势分析',
                    // left: 'center' 
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

            // 饼图配置
            var pieOption = {
                title: {
                    // text: '公司消耗占比',
                    // left: 'center'
                },
                color: [
                    "#18d1b1",
                    "#3fb1e3",
                    "#626c91",
                    "#a0a7e6",
                    "#c4ebad",
                    "#96dee8",
                    "#ffc658",
                    "#ff7c7c",
                    "#8dd1e1",
                    "#d87a80"
                ],
                tooltip: {
                    trigger: 'item',
                    formatter: function(params) {
                        var data = params.data;
                        var fullName = data.fullName || params.name;
                        var fissionCost = parseFloat(data.fissionCost || 0);
                        var totalCost = parseFloat(data.value || 0);
                        var fissionPercentage = totalCost > 0 ? ((fissionCost / totalCost) * 100).toFixed(2) : '0.00';
                        
                        return params.seriesName + '<br/>' +
                               fullName + '<br/>' +
                               '总消耗: ¥' + totalCost.toFixed(2) + '<br/>' +
                               '裂变消耗: ¥' + fissionCost.toFixed(2) + '<br/>' +
                               '裂变占比: ' + fissionPercentage + '%';
                    }
                },
                legend: {
                    orient: 'vertical',
                    left: 'left',
                    top: 'middle',
                    itemWidth: 14,
                    itemHeight: 14,
                    textStyle: {
                        fontSize: 12
                    },
                    data: [] // 将通过AJAX加载
                },
                series: [
                    {
                        name: '消耗占比',
                        type: 'pie',
                        radius: ['45%', '75%'],
                        center: ['65%', '50%'],
                        avoidLabelOverlap: false,
                        label: {
                            show: true,
                            position: 'outside',
                            formatter: function(params) {
                                // 获取数据项
                                var data = params.data;
                                var totalCost = parseFloat(data.value || 0);
                                var fissionCost = parseFloat(data.fissionCost || 0);
                                // 显示总消耗和裂变消耗
                                return '总:¥' + totalCost.toFixed(0) + '\n裂变:¥' + fissionCost.toFixed(0);
                            },
                            fontSize: 11
                        },
                        emphasis: {
                            label: {
                                show: true,
                                fontSize: '14',
                                fontWeight: 'bold'
                            },
                            itemStyle: {
                                shadowBlur: 10,
                                shadowOffsetX: 0,
                                shadowColor: 'rgba(0, 0, 0, 0.5)'
                            }
                        },
                        labelLine: {
                            show: true,
                            length: 15,
                            length2: 10
                        },
                        data: [] // 将通过AJAX加载
                    }
                ]
            };

            // 使用刚指定的配置项和数据显示图表 - 完全参考dashboard.js
            lineChart.setOption(lineOption);
            pieChart.setOption(pieOption);

            // 窗口大小调整事件 - 完全参考dashboard.js
            $(window).resize(function () {
                lineChart.resize();
                pieChart.resize();
            });

            // 刷新按钮事件 - 完全参考dashboard.js
            $(document).on("click", ".btn-refresh", function () {
                setTimeout(function () {
                    lineChart.resize();
                    pieChart.resize();
                }, 0);
            });

            // 绑定时间选择器事件
            Controller.bindTimeSelectors(lineChart, pieChart);

            // 加载图表数据
            Controller.loadLineChartData(lineChart);
            Controller.loadPieChartData(pieChart);

            // 加载公司消耗详情表格数据
            Controller.loadCompanyStatsTable();
        },

        // 加载统计数据
        loadStatsData: function() {
            $.ajax({
                url: 'viral_fission/material_consumption/getStats',
                type: 'GET',
                dataType: 'json',
                success: function(response) {
                
                    if (response.code === 1) {
                        var data = response.data;
                        // 根据后端返回的字段名更新
                        $('#total-materials').text(data.total || 0);
                        $('#generated-count').text(data.generated || 0);
                        $('#adopted-count').text(data.adopted || 0);
                        $('#success-rate').text((data.success_rate || 0) + '%');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Failed to load stats data:', error);
                    // 使用默认值
                    $('#total-materials').text('0');
                    $('#generated-count').text('0');
                    $('#adopted-count').text('0');
                    $('#success-rate').text('0%');
                }
            });
        },

        // 加载折线图数据
        loadLineChartData: function(chart) {
            $.ajax({
                url: 'viral_fission/material_consumption/getLineChartData',
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

        // 加载饼图数据
        loadPieChartData: function(chart) {
            $.ajax({
                url: 'viral_fission/material_consumption/getPieChartData',
                type: 'GET',
                dataType: 'json',
                success: function(response) {
                    Controller.processPieChartResponse(chart, response);
                },
                error: function(xhr, status, error) {
                    // 使用模拟数据
                    Controller.loadMockPieData(chart);
                }
            });
        },

        // 处理饼图响应数据
        processPieChartResponse: function(chart, response) {
      
            if (response.code === 1 && response.data && response.data.length > 0) {
                var data = response.data;
                var pieData = [];
                var legendData = [];

       

                // 按消耗金额排序，取前10个
                data.sort(function(a, b) {
                    var costA = parseFloat(a.total_cost || a.value || a.cost || 0);
                    var costB = parseFloat(b.total_cost || b.value || b.cost || 0);
                    return costB - costA; // 降序排列
                });

                // 只取前10个公司
                var topCompanies = data.slice(0, 10);

                topCompanies.forEach(function(item, index) {
                 

                    // 尝试多种可能的字段名
                    var fullCompanyName = item.company_name || item.name || ('公司' + (index + 1));
                    var totalCost = parseFloat(item.total_cost || item.value || item.cost || 0);
                    var fissionCost = parseFloat(item.fission_cost || 0);

                    // 处理公司名称显示，确保不会太长
                    var displayName = fullCompanyName;
                    if (displayName && displayName.length > 12) {
                        displayName = displayName.substring(0, 12) + '...';
                    }

         

                    if (totalCost > 0) { // 只显示有消耗的公司
                        pieData.push({
                            name: displayName,
                            value: totalCost,
                            // 保存原始完整名称用于tooltip
                            fullName: fullCompanyName,
                            // 传递裂变消耗用于tooltip显示
                            fissionCost: fissionCost
                        });
                        legendData.push(displayName);
                    }
                });

           

                if (pieData.length > 0) {
                    // 更新图表数据
                    chart.setOption({
                        legend: {
                            data: legendData
                        },
                        series: [{
                            data: pieData
                        }]
                    });

                
                } else {
                 
                    Controller.loadMockPieData(chart);
                }
            } else {

                Controller.loadMockPieData(chart);
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

        // 模拟饼图数据
        loadMockPieData: function(chart) {
            var pieData = [
                { name: 'A公司', value: 35 },
                { name: 'B公司', value: 25 },
                { name: 'C公司', value: 20 },
                { name: 'D公司', value: 12 },
                { name: '其他', value: 8 }
            ];
            var legendData = ['A公司', 'B公司', 'C公司', 'D公司', '其他'];

            chart.setOption({
                legend: {
                    data: legendData
                },
                series: [{
                    data: pieData
                }]
            });

           
        },

        // 公司消耗详情表格分页数据
        companyTableData: [],
        currentPage: 1,
        pageSize: 10,
        totalPages: 0,

        // 加载公司消耗详情表格数据
        loadCompanyStatsTable: function(page) {
            page = page || 1;
            Controller.currentPage = page;

            $.ajax({
                url: 'viral_fission/material_consumption/getPieChartData',
                type: 'GET',
                dataType: 'json',
                success: function(response) {
                    Controller.processCompanyTableResponse(response);
                },
                error: function(xhr, status, error) {
                    console.error('Failed to load company stats table:', error);
                    $('#company-stats-tbody').html('<tr><td colspan="7" class="text-center">数据加载失败</td></tr>');
                    $('#company-table-pagination').empty();
                }
            });
        },

        // 处理表格响应数据
        processCompanyTableResponse: function(response) {
   
            if (response.code === 1 && response.data && response.data.length > 0) {
                var data = response.data;

                // 按消耗金额排序
                data.sort(function(a, b) {
                    var costA = parseFloat(a.total_cost || a.value || a.cost || 0);
                    var costB = parseFloat(b.total_cost || b.value || b.cost || 0);
                    return costB - costA;
                });

                // 保存完整数据
                Controller.companyTableData = data;
                Controller.totalPages = Math.ceil(data.length / Controller.pageSize);

                // 渲染当前页数据
                Controller.renderCompanyTablePage();

                // 渲染分页控件
                Controller.renderPagination();

      
            } else {
                Controller.companyTableData = [];
                Controller.totalPages = 0;
                $('#company-stats-tbody').html('<tr><td colspan="7" class="text-center">暂无数据</td></tr>');
                $('#company-table-pagination').empty();
            }
        },

        // 渲染表格当前页数据
        renderCompanyTablePage: function() {
            var data = Controller.companyTableData;
            var tbody = $('#company-stats-tbody');
            tbody.empty();

            var totalCost = 0;
            data.forEach(function(item) {
                totalCost += parseFloat(item.total_cost || item.value || item.cost || 0);
            });

            var startIndex = (Controller.currentPage - 1) * Controller.pageSize;
            var endIndex = Math.min(startIndex + Controller.pageSize, data.length);
            var pageData = data.slice(startIndex, endIndex);

            pageData.forEach(function(item, index) {
                var companyName = item.company_name || item.name || ('公司' + (startIndex + index + 1));
                var cost = parseFloat(item.total_cost || item.value || item.cost || 0);
                var fissionCost = parseFloat(item.fission_cost || 0);
                var nonFissionCost = cost - fissionCost;
                var percentage = cost > 0 ? ((fissionCost / cost) * 100).toFixed(2) : '0.00';
                var materialCount = parseInt(item.material_count || 0);
                var avgCost = materialCount > 0 ? (cost / materialCount).toFixed(2) : '0.00';

                var row = '<tr>' +
                    '<td>' + companyName + '</td>' +
                    '<td>¥' + cost.toFixed(2) + '</td>' +
                    '<td>¥' + fissionCost.toFixed(2) + '</td>' +
                    '<td>¥' + nonFissionCost.toFixed(2) + '</td>' +
                    '<td>' + percentage + '%</td>' +
                    '<td>' + materialCount + '</td>' +
                    '<td>¥' + avgCost + '</td>' +
                    '</tr>';
                tbody.append(row);
            });
        },

        // 渲染分页控件
        renderPagination: function() {
            var pagination = $('#company-table-pagination');
            if (!pagination.length) {
                // 如果分页容器不存在，创建一个
                $('#company-stats-table').after('<div id="company-table-pagination" class="text-center" style="margin-top: 15px;"></div>');
                pagination = $('#company-table-pagination');
            }

            pagination.empty();

            // 总是显示统计信息
            var statsHtml = '<div style="margin-bottom: 10px; color: #666;">共 ' + Controller.companyTableData.length + ' 条记录';
            if (Controller.totalPages > 1) {
                statsHtml += '，第 ' + Controller.currentPage + '/' + Controller.totalPages + ' 页';
            }
            statsHtml += '</div>';

            if (Controller.totalPages <= 1) {
                pagination.html(statsHtml);
                return;
            }

            var paginationHtml = statsHtml + '<ul class="pagination pagination-sm" style="margin: 0; justify-content: center;">';

            // 上一页
            if (Controller.currentPage > 1) {
                paginationHtml += '<li><a href="#" data-page="' + (Controller.currentPage - 1) + '">上一页</a></li>';
            } else {
                paginationHtml += '<li class="disabled"><span>上一页</span></li>';
            }

            // 页码
            var startPage = Math.max(1, Controller.currentPage - 2);
            var endPage = Math.min(Controller.totalPages, Controller.currentPage + 2);

            // 如果不是从第1页开始，显示第1页和省略号
            if (startPage > 1) {
                paginationHtml += '<li><a href="#" data-page="1">1</a></li>';
                if (startPage > 2) {
                    paginationHtml += '<li class="disabled"><span>...</span></li>';
                }
            }

            for (var i = startPage; i <= endPage; i++) {
                var activeClass = i === Controller.currentPage ? ' class="active"' : '';
                paginationHtml += '<li' + activeClass + '><a href="#" data-page="' + i + '">' + i + '</a></li>';
            }

            // 如果不是到最后一页，显示省略号和最后一页
            if (endPage < Controller.totalPages) {
                if (endPage < Controller.totalPages - 1) {
                    paginationHtml += '<li class="disabled"><span>...</span></li>';
                }
                paginationHtml += '<li><a href="#" data-page="' + Controller.totalPages + '">' + Controller.totalPages + '</a></li>';
            }

            // 下一页
            if (Controller.currentPage < Controller.totalPages) {
                paginationHtml += '<li><a href="#" data-page="' + (Controller.currentPage + 1) + '">下一页</a></li>';
            } else {
                paginationHtml += '<li class="disabled"><span>下一页</span></li>';
            }

            paginationHtml += '</ul>';

            pagination.html(paginationHtml);

            // 绑定分页点击事件
            pagination.find('a').click(function(e) {
                e.preventDefault();
                var page = parseInt($(this).data('page'));
                if (page && page !== Controller.currentPage) {
                    Controller.currentPage = page;
                    Controller.renderCompanyTablePage();
                    Controller.renderPagination();
                }
            });
        },

        // 绑定时间选择器事件
        bindTimeSelectors: function(lineChart, pieChart) {
            // 折线图时间选择
            $('#line-chart-days').on('change', function() {
                var selectedValue = $(this).val();
             

                // 只影响折线图和表格数据
                Controller.loadLineChartDataWithPeriod(lineChart, selectedValue);
                Controller.loadCompanyStatsTableWithPeriod(selectedValue);
            });

            // 饼图时间选择
            $('#pie-chart-days').on('change', function() {
                var selectedValue = $(this).val();
       

                // 只影响饼图数据
                Controller.loadPieChartDataWithPeriod(pieChart, selectedValue);
            });

            // 折线图刷新按钮
            $('#line-chart-refresh').on('click', function() {
                var selectedPeriod = $('#line-chart-days').val();
       

                Controller.refreshWithLoading($(this), function() {
                    Controller.loadLineChartDataWithPeriod(lineChart, selectedPeriod);
                    Controller.loadCompanyStatsTableWithPeriod(selectedPeriod);
                });
            });

            // 饼图刷新按钮
            $('#pie-chart-refresh').on('click', function() {
                var selectedPeriod = $('#pie-chart-days').val();

                Controller.refreshWithLoading($(this), function() {
                    Controller.loadPieChartDataWithPeriod(pieChart, selectedPeriod);
                });
            });
        },

        // 通用刷新方法
        refreshWithLoading: function($btn, callback) {
            var originalText = $btn.html();

            // 显示加载状态
            $btn.html('<i class="fa fa-spinner fa-spin"></i> 刷新中...').prop('disabled', true);

            // 先清除缓存
            $.ajax({
                url: 'viral_fission/material_consumption/clearCache',
                type: 'POST',
                success: function() {
          
                    callback();
                },
                error: function() {
                
                    callback();
                },
                complete: function() {
                    // 恢复按钮状态
                    setTimeout(function() {
                        $btn.html(originalText).prop('disabled', false);
                    }, 1000);
                }
            });
        },

        // 根据时间段加载折线图数据
        loadLineChartDataWithPeriod: function(chart, period) {
            $.ajax({
                url: 'viral_fission/material_consumption/getLineChartData',
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

        // 根据时间段加载饼图数据
        loadPieChartDataWithPeriod: function(chart, period) {
            $.ajax({
                url: 'viral_fission/material_consumption/getPieChartData',
                type: 'GET',
                data: {
                    period: period
                },
                dataType: 'json',
                success: function(response) {
                    Controller.processPieChartResponse(chart, response);
                },
                error: function() {
                    console.error('Failed to load pie chart data with period:', period);
                    Controller.loadMockPieData(chart);
                }
            });
        },

        // 根据时间段加载表格数据
        loadCompanyStatsTableWithPeriod: function(period) {
            Controller.currentPage = 1; // 重置到第一页

            $.ajax({
                url: 'viral_fission/material_consumption/getPieChartData',
                type: 'GET',
                data: {
                    period: period
                },
                dataType: 'json',
                success: function(response) {
                    Controller.processCompanyTableResponse(response);
                },
                error: function() {
                    console.error('Failed to load company stats table with period:', period);
                    $('#company-stats-tbody').html('<tr><td colspan="7" class="text-center">数据加载失败</td></tr>');
                    $('#company-table-pagination').empty();
                }
            });
        },

        // 初始化页面
        initPage: function() {
            // 页面初始化完成
        },

        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});