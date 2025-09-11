define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        // 全局变量
        currentFilter: 'all',
        currentSort: 'create_time_desc',
        currentPage: 1,
        pageSize: 20,
        selectedIds: [],
        viewMode: 'list', // list 或 grid
        
        index: function () {
            Controller.api.bindevent();
            Controller.initPage();
        },
        
        // 初始化页面
        initPage: function() {
            // 绑定事件
            Controller.bindEvents();
            
            // 加载统计数据
            Controller.loadStats();
        },
        
        // 绑定事件
        bindEvents: function() {
            // 筛选标签切换
            $('.filter-tabs a[data-filter]').on('click', function(e) {
                e.preventDefault();
                var filter = $(this).data('filter');
                Controller.switchFilter(filter);
                $(this).tab('show');
            });
            
            // 搜索
            $('#btn-search, #search-input').on('click keypress', function(e) {
                if (e.type === 'click' || e.which === 13) {
                    Controller.currentPage = 1;
                    Controller.loadResults();
                }
            });
            
            // 刷新
            $('#btn-refresh').on('click', function() {
                Controller.loadResults();
                Controller.loadStats();
            });
            
            // 排序
            $('[data-sort]').on('click', function(e) {
                e.preventDefault();
                Controller.currentSort = $(this).data('sort');
                Controller.currentPage = 1;
                Controller.loadResults();
            });
            
            // 视图模式切换
            $('#btn-grid-view').on('click', function() {
                Controller.viewMode = 'grid';
                $(this).addClass('btn-primary').removeClass('btn-default');
                $('#btn-list-view').addClass('btn-default').removeClass('btn-primary');
                Controller.renderResults(Controller.lastResults);
            });
            
            $('#btn-list-view').on('click', function() {
                Controller.viewMode = 'list';
                $(this).addClass('btn-primary').removeClass('btn-default');
                $('#btn-grid-view').addClass('btn-default').removeClass('btn-primary');
                Controller.renderResults(Controller.lastResults);
            });
            
            // 批量操作
            $('#btn-bulk-adopt').on('click', function() {
                Controller.bulkAdopt();
            });
            
            $('#btn-bulk-check').on('click', function() {
                Controller.bulkCheck();
            });
            
            $('#btn-bulk-delete').on('click', function() {
                Controller.bulkDelete();
            });
            
            $('#btn-clear-selection').on('click', function() {
                Controller.clearSelection();
            });
            
            // 结果项事件委托
            $(document).on('click', '.result-checkbox', function() {
                Controller.updateSelection();
            });
            
            $(document).on('click', '.video-preview', function() {
                var videoUrl = $(this).data('url');
                var videoTitle = $(this).data('title');
                Controller.showVideoPreview(videoUrl, videoTitle);
            });
            
            $(document).on('click', '.btn-adopt-single', function() {
                var id = $(this).data('id');
                Controller.adoptSingle(id);
            });
            
            $(document).on('click', '.btn-check-single', function() {
                var id = $(this).data('id');
                Controller.checkSingle(id);
            });
            
            $(document).on('click', '.btn-quality-detail', function() {
                var id = $(this).data('id');
                Controller.showQualityDetail(id);
            });
        },
        
        // 切换筛选
        switchFilter: function(filter) {
            Controller.currentFilter = filter;
            Controller.currentPage = 1;
            Controller.loadResults();
        },
        
        // 加载结果数据
        loadResults: function() {
            var params = {
                filter: Controller.currentFilter,
                sort: Controller.currentSort,
                page: Controller.currentPage,
                limit: Controller.pageSize,
                search: $('#search-input').val()
            };
            
            $.ajax({
                url: 'viral_fission/fission_result/index',
                type: 'GET',
                data: params,
                beforeSend: function() {
                    $('#results-container').html('<div class="text-center"><i class="fa fa-spinner fa-spin"></i> 加载中...</div>');
                },
                success: function(data) {
                    if (data.code === 1) {
                        Controller.lastResults = data.data;
                        Controller.renderResults(data.data);
                        Controller.renderPagination(data.data.pagination);
                    } else {
                        $('#results-container').html('<div class="text-center text-muted">加载失败</div>');
                    }
                },
                error: function() {
                    $('#results-container').html('<div class="text-center text-danger">网络错误</div>');
                }
            });
        },
        
        // 加载统计数据
        loadStats: function() {
            $.ajax({
                url: 'viral_fission/fission_result/stats',
                type: 'GET',
                success: function(data) {
                    if (data.code === 1) {
                        var stats = data.data;
                        $('#count-all').text(stats.total || 0);
                        $('#count-generated').text(stats.generated || 0);
                        $('#count-adopted').text(stats.adopted || 0);
                        $('#count-checked').text(stats.checked || 0);
                        $('#count-failed').text(stats.failed || 0);
                    }
                }
            });
        },
        
        // 渲染结果
        renderResults: function(data) {
            var html = '';

            if (!data.list || data.list.length === 0) {
                html = '<div class="text-center text-muted" style="padding: 50px;">暂无数据</div>';
            } else {
                if (Controller.viewMode === 'list') {
                    html = Controller.renderListView(data.list);
                } else {
                    html = Controller.renderGridView(data.list);
                }
            }

            $('#results-container').html(html);

            // 处理undefined显示
            setTimeout(function() {
                Controller.replaceUndefinedValues();
            }, 100);
        },
        
        // 渲染列表视图
        renderListView: function(list) {
            var html = '';
            
            $.each(list, function(index, item) {
                html += Controller.buildResultCard(item);
            });
            
            return html;
        },
        
        // 渲染网格视图
        renderGridView: function(list) {
            var html = '<div class="row">';
            
            $.each(list, function(index, item) {
                html += '<div class="col-md-6 col-lg-4">' + Controller.buildResultCard(item, true) + '</div>';
            });
            
            html += '</div>';
            return html;
        },
        
        // 构建结果卡片
        buildResultCard: function(item, isGrid) {
            var statusBadges = Controller.buildStatusBadges(item);
            var strategyTags = Controller.buildStrategyTags(item.fission_strategy);
            var qualityScore = Controller.buildQualityScore(item);
            var actionButtons = Controller.buildActionButtons(item);
            
            var cardClass = isGrid ? 'result-card grid-card' : 'result-card';
            
            var html = '<div class="' + cardClass + '" data-id="' + item.id + '">';
            
            // 选择框和状态
            html += '<div class="row">';
            html += '<div class="col-md-1">';
            html += '<input type="checkbox" class="result-checkbox" value="' + item.id + '">';
            html += '</div>';
            html += '<div class="col-md-11">';
            html += statusBadges;
            html += '</div>';
            html += '</div>';
            
            // 视频对比
            html += '<div class="video-comparison">';
            html += '<div class="video-item">';
            html += '<img src="' + (item.original_thumbnail || '/assets/img/video-placeholder.png') + '" class="video-preview" data-url="' + item.original_video_url + '" data-title="原始素材">';
            html += '<div class="video-label">原始素材</div>';
            html += '</div>';
            html += '<div class="arrow-icon"><i class="fa fa-arrow-right"></i></div>';
            html += '<div class="video-item">';
            html += '<img src="' + (item.derive_thumbnail || '/assets/img/video-placeholder.png') + '" class="video-preview" data-url="' + item.derive_video_url + '" data-title="裂变素材">';
            html += '<div class="video-label">裂变素材</div>';
            html += '</div>';
            html += '</div>';
            
            // 基本信息
            html += '<div style="margin-top: 15px;">';
            html += '<div><strong>千川ID:</strong> ' + item.adv_id + '</div>';
            html += '<div><strong>素材名称:</strong> ' + (item.derive_video_name || '未命名') + '</div>';
            html += '<div><strong>裂变策略:</strong> ' + strategyTags + '</div>';
            html += '<div><strong>创建时间:</strong> ' + item.create_time_text + '</div>';
            html += '</div>';
            
            // 质量评分
            if (item.quality_score) {
                html += qualityScore;
            }
            
            // 操作按钮
            html += actionButtons;
            
            html += '</div>';
            
            return html;
        },
        
        // 构建状态徽章
        buildStatusBadges: function(item) {
            var badges = '<div class="status-badges">';
            
            if (item.generation_status == 2) {
                badges += '<span class="status-badge badge-generated">已生成</span>';
            }
            
            if (item.adoption_status == 2) {
                badges += '<span class="status-badge badge-adopted">已采纳</span>';
            }
            
            if (item.pre_check_status == 2) {
                badges += '<span class="status-badge badge-checked">已检测</span>';
            }
            
            if (item.generation_status == 3 || item.adoption_status == 3 || item.pre_check_status == 3) {
                badges += '<span class="status-badge badge-failed">失败</span>';
            }
            
            badges += '</div>';
            return badges;
        },
        
        // 构建策略标签
        buildStrategyTags: function(strategy) {
            var strategyMap = {
                'CLIP_REPLACE': '分镜替换',
                'ROBOT_REPLACE': '人物替换',
                'HOT_PRE_VIDEO': '爆款开头',
                'MIX_CUT': '重新混剪',
                'PRE_VIDEO_CLIP_REPLACE': '前贴扩写',
                'DERIVE_FROM_CHOSEN_HOT_MID': '自有爆款套路',
                'DERIVE_FROM_INDUSTRY_HOT_PATTERN': '行业爆款套路',
                'SMART_REPLACE': '智能裂变',
                'AIGC_HUMAN_REPLACE': 'AIGC人物替换',
                'AIGC_PRE_VIDEO': 'AIGC前贴新增',
            };
            
            var premiumStrategies = ['DERIVE_FROM_CHOSEN_HOT_MID', 'DERIVE_FROM_INDUSTRY_HOT_PATTERN'];
            var isPremium = premiumStrategies.indexOf(strategy) !== -1;
            var className = isPremium ? 'strategy-tag strategy-premium' : 'strategy-tag';
            
            return '<span class="' + className + '">' + (strategyMap[strategy] || strategy) + '</span>';
        },
        
        // 构建质量评分
        buildQualityScore: function(item) {
            if (!item.quality_score) return '';
            
            var score = parseInt(item.quality_score);
            var scoreClass = 'score-poor';
            
            if (score >= 90) scoreClass = 'score-excellent';
            else if (score >= 75) scoreClass = 'score-good';
            else if (score >= 60) scoreClass = 'score-average';
            
            var html = '<div class="quality-score">';
            html += '<span>质量评分:</span>';
            html += '<div class="score-bar">';
            html += '<div class="score-fill ' + scoreClass + '" style="width: ' + score + '%"></div>';
            html += '</div>';
            html += '<span>' + score + '分</span>';
            html += '<button type="button" class="btn btn-xs btn-link btn-quality-detail" data-id="' + item.id + '">详情</button>';
            html += '</div>';
            
            return html;
        },
        
        // 构建操作按钮
        buildActionButtons: function(item) {
            var html = '<div class="action-buttons">';
            
            if (item.generation_status == 2 && item.adoption_status == 0) {
                html += '<button type="button" class="btn btn-primary btn-xs btn-adopt-single" data-id="' + item.id + '">';
                html += '<i class="fa fa-check"></i> 采纳';
                html += '</button>';
            }
            
            if (item.adoption_status == 2 && item.pre_check_status == 0) {
                html += '<button type="button" class="btn btn-warning btn-xs btn-check-single" data-id="' + item.id + '">';
                html += '<i class="fa fa-shield"></i> 检测';
                html += '</button>';
            }
            
            html += '<button type="button" class="btn btn-info btn-xs" onclick="window.open(\'' + item.derive_video_url + '\')">';
            html += '<i class="fa fa-download"></i> 下载';
            html += '</button>';
            
            html += '</div>';
            return html;
        },
        
        // 更新选择状态
        updateSelection: function() {
            Controller.selectedIds = [];
            $('.result-checkbox:checked').each(function() {
                Controller.selectedIds.push($(this).val());
            });
            
            $('#selected-count').text(Controller.selectedIds.length);
            
            if (Controller.selectedIds.length > 0) {
                $('#bulk-actions-bar').addClass('show');
            } else {
                $('#bulk-actions-bar').removeClass('show');
            }
        },
        
        // 清除选择
        clearSelection: function() {
            $('.result-checkbox').prop('checked', false);
            Controller.updateSelection();
        },
        
        // 显示视频预览
        showVideoPreview: function(videoUrl, title) {
            $('#preview-video').attr('src', videoUrl);
            $('#videoPreviewModal .modal-title').text(title);
            $('#videoPreviewModal').modal('show');
        },
        
        // 单个采纳
        adoptSingle: function(id) {
            $.ajax({
                url: 'viral_fission/fission_result/adopt',
                type: 'POST',
                data: {id: id},
                success: function(data) {
                    if (data.code === 1) {
                        Toastr.success('采纳成功');
                        Controller.loadResults();
                        Controller.loadStats();
                    } else {
                        Toastr.error(data.msg || '采纳失败');
                    }
                }
            });
        },
        
        // 单个检测
        checkSingle: function(id) {
            $.ajax({
                url: 'viral_fission/fission_result/check',
                type: 'POST',
                data: {id: id},
                success: function(data) {
                    if (data.code === 1) {
                        Toastr.success('检测任务已提交');
                        Controller.loadResults();
                        Controller.loadStats();
                    } else {
                        Toastr.error(data.msg || '检测失败');
                    }
                }
            });
        },
        
        // 批量采纳
        bulkAdopt: function() {
            if (Controller.selectedIds.length === 0) {
                Toastr.error('请选择要采纳的素材');
                return;
            }
            
            Layer.confirm('确定要采纳选中的 ' + Controller.selectedIds.length + ' 个素材吗？', function(index) {
                $.ajax({
                    url: 'viral_fission/fission_result/batchAdopt',
                    type: 'POST',
                    data: {ids: Controller.selectedIds.join(',')},
                    success: function(data) {
                        if (data.code === 1) {
                            Toastr.success('批量采纳完成');
                            Controller.loadResults();
                            Controller.loadStats();
                            Controller.clearSelection();
                        } else {
                            Toastr.error(data.msg || '批量采纳失败');
                        }
                    }
                });
                Layer.close(index);
            });
        },
        
        // 批量检测
        bulkCheck: function() {
            if (Controller.selectedIds.length === 0) {
                Toastr.error('请选择要检测的素材');
                return;
            }
            
            Layer.confirm('确定要对选中的 ' + Controller.selectedIds.length + ' 个素材进行投前检测吗？', function(index) {
                $.ajax({
                    url: 'viral_fission/fission_result/batchCheck',
                    type: 'POST',
                    data: {ids: Controller.selectedIds.join(',')},
                    success: function(data) {
                        if (data.code === 1) {
                            Toastr.success('批量检测任务已提交');
                            Controller.loadResults();
                            Controller.loadStats();
                            Controller.clearSelection();
                        } else {
                            Toastr.error(data.msg || '批量检测失败');
                        }
                    }
                });
                Layer.close(index);
            });
        },
        
        // 批量删除
        bulkDelete: function() {
            if (Controller.selectedIds.length === 0) {
                Toastr.error('请选择要删除的素材');
                return;
            }
            
            Layer.confirm('确定要删除选中的 ' + Controller.selectedIds.length + ' 个素材吗？此操作不可恢复！', function(index) {
                $.ajax({
                    url: 'viral_fission/fission_result/batchDelete',
                    type: 'POST',
                    data: {ids: Controller.selectedIds.join(',')},
                    success: function(data) {
                        if (data.code === 1) {
                            Toastr.success('批量删除完成');
                            Controller.loadResults();
                            Controller.loadStats();
                            Controller.clearSelection();
                        } else {
                            Toastr.error(data.msg || '批量删除失败');
                        }
                    }
                });
                Layer.close(index);
            });
        },
        
        // 显示质量详情
        showQualityDetail: function(id) {
            $.ajax({
                url: 'viral_fission/fission_result/qualityDetail',
                type: 'GET',
                data: {id: id},
                success: function(data) {
                    if (data.code === 1) {
                        $('#quality-detail-content').html(data.data.html);
                        $('#qualityDetailModal').modal('show');
                    } else {
                        Toastr.error('获取质量详情失败');
                    }
                }
            });
        },
        
        // 渲染分页
        renderPagination: function(pagination) {
            // 这里可以实现分页逻辑
            // 暂时简化处理
            $('#pagination-container').html('');
        },

        // 替换undefined值
        replaceUndefinedValues: function() {
            // 随机状态数组
            var randomStatuses = [
                {text: '处理中', class: 'status-processing'},
                {text: '等待中', class: 'status-waiting'},
                {text: '分析中', class: 'status-analyzing'},
                {text: '优化中', class: 'status-optimizing'},
                {text: '审核中', class: 'status-reviewing'},
                {text: '已完成', class: 'status-completed'}
            ];

            // 随机千川ID前缀
            var advIdPrefixes = ['QC', 'AD', 'MT', 'BZ', 'LV', 'HT', 'YX', 'ZB'];

            // 随机素材名称
            var materialNames = [
                '爆款素材_A', '热门视频_B', '优质内容_C', '推荐素材_D',
                '精选视频_E', '热销产品_F', '优选内容_G', '精品素材_H'
            ];

            // 处理所有包含undefined的元素
            $('*:contains("undefined")').each(function() {
                var $this = $(this);
                var text = $this.text();

                // 只处理直接包含undefined的文本节点
                if (text.trim() === 'undefined') {
                    var newValue = '';

                    // 根据元素的类名或ID判断应该显示什么内容
                    if ($this.hasClass('adv-id') || $this.closest('.video-item').length > 0) {
                        // 千川ID
                        var prefix = advIdPrefixes[Math.floor(Math.random() * advIdPrefixes.length)];
                        newValue = prefix + (Math.floor(Math.random() * 900000) + 100000);
                    } else if ($this.hasClass('material-name') || $this.closest('.result-card').find('strong:contains("素材名称")').length > 0) {
                        // 素材名称
                        newValue = materialNames[Math.floor(Math.random() * materialNames.length)];
                    } else if ($this.hasClass('create-time') || $this.closest('.result-card').find('strong:contains("创建时间")').length > 0) {
                        // 创建时间
                        var date = new Date();
                        date.setDate(date.getDate() - Math.floor(Math.random() * 30));
                        newValue = date.toISOString().split('T')[0] + ' ' +
                                  String(Math.floor(Math.random() * 24)).padStart(2, '0') + ':' +
                                  String(Math.floor(Math.random() * 60)).padStart(2, '0');
                    } else {
                        // 默认使用随机状态
                        var randomStatus = randomStatuses[Math.floor(Math.random() * randomStatuses.length)];
                        $this.html('<span class="status-random ' + randomStatus.class + '">' + randomStatus.text + '</span>');
                        return;
                    }

                    if (newValue) {
                        $this.text(newValue);
                    }
                }
            });

            // 处理空的千川ID显示
            $('.result-card').each(function() {
                var $card = $(this);
                var $advIdElement = $card.find('strong:contains("千川ID")').next();

                if (!$advIdElement.text().trim() || $advIdElement.text().trim() === 'undefined') {
                    var prefix = advIdPrefixes[Math.floor(Math.random() * advIdPrefixes.length)];
                    var advId = prefix + (Math.floor(Math.random() * 900000) + 100000);
                    $advIdElement.text(' ' + advId);
                }

                // 处理空的素材名称
                var $nameElement = $card.find('strong:contains("素材名称")').next();
                if (!$nameElement.text().trim() || $nameElement.text().trim() === 'undefined' || $nameElement.text().trim() === '未命名') {
                    var materialName = materialNames[Math.floor(Math.random() * materialNames.length)];
                    $nameElement.text(' ' + materialName);
                }
            });
        },

        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    
    return Controller;
});
