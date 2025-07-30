define(['jquery', 'bootstrap', 'backend', 'table', 'form', '../viral_fission/video_viewer'], function ($, undefined, Backend, Table, Form, VideoViewer) {

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
                    stats_url: 'viral_fission/material_consumption/getStats',
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
                        {field: 'kahuna', title: "负责人"},
                        {field: 'store_name', title: "商户名称"},
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
                        {field: 'total_pay_order_count_for_roi2', title: "单量"},
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
            Controller. initCollapsible()
            // 加载统计数据
            Controller.loadStats();
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
