define(['jquery', 'bootstrap', 'backend', 'toastr', 'fast'], function ($, undefined, Backend, Toastr, Fast) {

    var Controller = {
        // 存储当前公司列表数据
        standardCompanies: [],
        fissionCompanies: [],

        index: function () {
            // 初始化
            Controller.init();

            // 初始化公司列表数据
            Controller.initCompanyLists();

            // 绑定事件
            Controller.bindEvents();

            // 初始化标签页
            Controller.initTabs();

            // 更新统计（确保在数据加载后执行）
            setTimeout(function() {
                Controller.updateStats();
            }, 100);
        },

        init: function() {
            // 初始化提示
            $('[data-toggle="tooltip"]').tooltip();
        },

        initTabs: function() {
            // 标签页切换时更新统计
            $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
                Controller.updateStats();
            });
        },

        initCompanyLists: function() {
            // 从页面获取初始数据
            Controller.loadCompanyData();
        },

        loadCompanyData: function() {
            // 初始化数组
            Controller.standardCompanies = [];
            Controller.fissionCompanies = [];

            // 从页面的公司列表中加载数据
            $('#standard-company-list .company-item').each(function() {
                var company = $(this).data('company');
                if (company) {
                    Controller.standardCompanies.push(company);
                }
            });

            $('#fission-company-list .company-item').each(function() {
                var company = $(this).data('company');
                if (company) {
                    Controller.fissionCompanies.push(company);
                }
            });

            // 如果DOM中没有数据，尝试从后端获取
            if (Controller.standardCompanies.length === 0 && Controller.fissionCompanies.length === 0) {
                Controller.loadDataFromBackend();
            } else {
                // 如果有数据，直接更新统计
                Controller.updateStats();
            }
        },

        loadDataFromBackend: function() {
            // 通过AJAX获取当前配置数据
            Fast.api.ajax({
                url: Fast.api.fixurl("blacklist_config/getConfigsAjax"),
                type: 'GET'
            }, function(data) {
                if (data && data.configs) {
                    if (data.configs.standard && data.configs.standard.data) {
                        Controller.standardCompanies = data.configs.standard.data;
                    }
                    if (data.configs.fission && data.configs.fission.data) {
                        Controller.fissionCompanies = data.configs.fission.data;
                    }

                    // 渲染列表和更新统计
                    Controller.renderCompanyList('standard');
                    Controller.renderCompanyList('fission');
                    Controller.updateStats();
                }
            }, function() {
                // 如果获取失败，使用空数组
                Controller.standardCompanies = [];
                Controller.fissionCompanies = [];
                Controller.updateStats();
            });
        },
        
        bindEvents: function() {
            // 新增公司按钮
            $('#add-standard-company').on('click', function() {
                Controller.addCompany('standard');
            });

            $('#add-fission-company').on('click', function() {
                Controller.addCompany('fission');
            });

            // 批量导入按钮
            $('#import-standard-btn').on('click', function() {
                Controller.importCompanies('standard');
            });

            $('#import-fission-btn').on('click', function() {
                Controller.importCompanies('fission');
            });

            // 保存配置按钮
            $('#save-standard-config').on('click', function() {
                Controller.saveConfig('standard');
            });

            $('#save-fission-config').on('click', function() {
                Controller.saveConfig('fission');
            });

            // 删除公司按钮（事件委托）
            $(document).on('click', '.remove-company', function() {
                Controller.removeCompany($(this));
            });

            // 清空所有按钮
            $('#clear-all-standard').on('click', function() {
                Controller.clearAllCompanies('standard');
            });

            $('#clear-all-fission').on('click', function() {
                Controller.clearAllCompanies('fission');
            });

            // 导出列表按钮
            $('#export-standard').on('click', function() {
                Controller.exportList('standard');
            });

            $('#export-fission').on('click', function() {
                Controller.exportList('fission');
            });

            // 搜索功能
            $('#search-standard').on('input', function() {
                Controller.searchCompanies('standard', $(this).val());
            });

            $('#search-fission').on('input', function() {
                Controller.searchCompanies('fission', $(this).val());
            });

            // 回车键添加公司
            $('#new-standard-company').on('keypress', function(e) {
                if (e.which === 13) {
                    Controller.addCompany('standard');
                }
            });

            $('#new-fission-company').on('keypress', function(e) {
                if (e.which === 13) {
                    Controller.addCompany('fission');
                }
            });

            // 文本框自动调整高度
            $(document).on('input', '.config-textarea', function() {
                Controller.autoResize(this);
            });
        },

        // 添加单个公司
        addCompany: function(type) {
            var inputId = '#new-' + type + '-company';
            var companyName = $(inputId).val().trim();

            if (!companyName) {
                Toastr.warning('请输入公司名称');
                return;
            }

            var companies = type === 'standard' ? Controller.standardCompanies : Controller.fissionCompanies;

            if (companies.indexOf(companyName) !== -1) {
                Toastr.warning('该公司已存在');
                return;
            }

            companies.push(companyName);
            companies.sort();

            Controller.renderCompanyList(type);
            Controller.updateStats();

            $(inputId).val('');
            Toastr.success('添加成功');
        },

        // 批量导入公司
        importCompanies: function(type) {
            var textareaId = '#import-' + type + '-text';
            var content = $(textareaId).val().trim();

            if (!content) {
                Toastr.warning('请输入要导入的公司名称');
                return;
            }

            var newCompanies = Controller.processLines(content);
            var companies = type === 'standard' ? Controller.standardCompanies : Controller.fissionCompanies;
            var addedCount = 0;

            newCompanies.forEach(function(company) {
                if (companies.indexOf(company) === -1) {
                    companies.push(company);
                    addedCount++;
                }
            });

            companies.sort();

            Controller.renderCompanyList(type);
            Controller.updateStats();

            $(textareaId).val('');
            Toastr.success('成功导入 ' + addedCount + ' 个公司');
        },

        // 删除公司
        removeCompany: function($btn) {
            var type = $btn.data('type');
            var company = $btn.data('company');
            var companies = type === 'standard' ? Controller.standardCompanies : Controller.fissionCompanies;

            var index = companies.indexOf(company);
            if (index !== -1) {
                companies.splice(index, 1);
                Controller.renderCompanyList(type);
                Controller.updateStats();
                Toastr.success('删除成功');
            }
        },

        // 清空所有公司
        clearAllCompanies: function(type) {
            var typeName = type === 'standard' ? '不愿意推送到计划公司名单' : '不愿意推送素材公司名单';

            Fast.api.confirm('确定要清空所有' + typeName + '吗？', function() {
                if (type === 'standard') {
                    Controller.standardCompanies = [];
                } else {
                    Controller.fissionCompanies = [];
                }

                Controller.renderCompanyList(type);
                Controller.updateStats();
                Toastr.success('清空成功');
            });
        },

        // 导出列表
        exportList: function(type) {
            var companies = type === 'standard' ? Controller.standardCompanies : Controller.fissionCompanies;
            var typeName = type === 'standard' ? '不愿意推送到计划公司名单' : '不愿意推送素材公司名单';

            if (companies.length === 0) {
                Toastr.warning('列表为空，无法导出');
                return;
            }

            var content = companies.join('\n');
            var blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
            var url = window.URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = typeName + '_' + new Date().toISOString().slice(0, 10) + '.txt';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);

            Toastr.success('导出成功');
        },

        // 搜索公司
        searchCompanies: function(type, keyword) {
            var listId = '#' + type + '-company-list';
            var $items = $(listId + ' .company-item');

            if (!keyword) {
                $items.show();
                return;
            }

            keyword = keyword.toLowerCase();
            $items.each(function() {
                var companyName = $(this).find('.company-name').text().toLowerCase();
                if (companyName.indexOf(keyword) !== -1) {
                    $(this).show();
                    // 高亮搜索关键词
                    var highlightedText = $(this).find('.company-name').text().replace(
                        new RegExp(keyword, 'gi'),
                        '<span class="search-highlight">$&</span>'
                    );
                    $(this).find('.company-name').html(highlightedText);
                } else {
                    $(this).hide();
                }
            });
        },

        // 渲染公司列表
        renderCompanyList: function(type) {
            var companies = type === 'standard' ? Controller.standardCompanies : Controller.fissionCompanies;
            var listId = '#' + type + '-company-list';
            var $list = $(listId);

            if (companies.length === 0) {
                $list.html('<div class="text-muted text-center">暂无公司名单</div>');
                return;
            }

            var html = '';
            companies.forEach(function(company, index) {
                html += '<div class="company-item" data-company="' + company + '">';
                html += '<span class="company-index">' + (index + 1) + '.</span>';
                html += '<span class="company-name">' + company + '</span>';
                html += '<button type="button" class="btn btn-xs btn-danger pull-right remove-company" data-type="' + type + '" data-company="' + company + '">';
                html += '<i class="fa fa-times"></i>';
                html += '</button>';
                html += '</div>';
            });

            $list.html(html);
        },

        // 保存配置
        saveConfig: function(type) {
            var companies = type === 'standard' ? Controller.standardCompanies : Controller.fissionCompanies;
            var typeName = type === 'standard' ? '不愿意推送到计划公司名单' : '不愿意推送素材公司名单';

            if (companies.length === 0) {
                Toastr.warning('公司列表为空，无法保存');
                return;
            }

            var data = {};
            data[type + '_config'] = companies.join('\n');

            // 显示加载状态
            var $btn = $('#save-' + type + '-config');
            var originalText = $btn.html();
            $btn.html('<i class="fa fa-spinner fa-spin"></i> 保存中...').prop('disabled', true);

            Fast.api.ajax({
                url: Fast.api.fixurl("blacklist_config/index"),
                type: 'POST',
                data: data
            }, function() {
                Toastr.success(typeName + '保存成功！');
                // 更新统计信息
                Controller.updateStats();

                // 1秒后刷新页面以显示最新数据
                setTimeout(function() {
                    location.reload();
                }, 1000);
            }, function(data, ret) {
                Toastr.error(ret.msg || typeName + '保存失败');
            }, function() {
                // 恢复按钮状态
                $btn.html(originalText).prop('disabled', false);
            });
        },

        saveStandardConfig: function() {
            Controller.saveConfig('standard');
        },

        saveFissionConfig: function() {
            Controller.saveConfig('fission');
        },
        
        previewStandardConfig: function() {
            var standardConfig = $('#standard_config').val();
            var standardLines = Controller.processLines(standardConfig);
            var standardDeduped = Controller.deduplicateAndSort(standardLines);

            $('#preview-standard-content').text(standardDeduped.join('\n'));
            $('#preview-standard-count').text(standardDeduped.length);
            $('#preview-fission-content').text('');
            $('#preview-fission-count').text(0);

            $('#preview-modal').modal('show');
        },

        previewFissionConfig: function() {
            var fissionConfig = $('#fission_config').val();
            var fissionLines = Controller.processLines(fissionConfig);
            var fissionDeduped = Controller.deduplicateAndSort(fissionLines);

            $('#preview-standard-content').text('');
            $('#preview-standard-count').text(0);
            $('#preview-fission-content').text(fissionDeduped.join('\n'));
            $('#preview-fission-count').text(fissionDeduped.length);

            $('#preview-modal').modal('show');
        },

        previewAllConfigs: function() {
            var standardConfig = $('#standard_config').val();
            var fissionConfig = $('#fission_config').val();

            // 处理标准配置
            var standardLines = Controller.processLines(standardConfig);
            var standardDeduped = Controller.deduplicateAndSort(standardLines);

            // 处理裂变配置
            var fissionLines = Controller.processLines(fissionConfig);
            var fissionDeduped = Controller.deduplicateAndSort(fissionLines);

            // 更新预览内容
            $('#preview-standard-content').text(standardDeduped.join('\n'));
            $('#preview-standard-count').text(standardDeduped.length);

            $('#preview-fission-content').text(fissionDeduped.join('\n'));
            $('#preview-fission-count').text(fissionDeduped.length);

            // 显示模态框
            $('#preview-modal').modal('show');
        },
        
        applyPreview: function() {
            var standardContent = $('#preview-standard-content').text();
            var fissionContent = $('#preview-fission-content').text();
            
            $('#standard_config').val(standardContent);
            $('#fission_config').val(fissionContent);
            
            $('#preview-modal').modal('hide');
            
            // 更新统计
            Controller.updateStats();
            
            Toastr.success('预览结果已应用到配置中');
        },
        
        clearStandardConfig: function() {
            Fast.api.confirm("确定要清空标准推广黑名单配置吗？此操作不可恢复！", function() {
                $('#standard_config').val('');
                Controller.updateStats();
                Toastr.success('标准推广黑名单配置已清空');
            });
        },

        clearFissionConfig: function() {
            Fast.api.confirm("确定要清空裂变推广黑名单配置吗？此操作不可恢复！", function() {
                $('#fission_config').val('');
                Controller.updateStats();
                Toastr.success('裂变推广黑名单配置已清空');
            });
        },

        clearAllConfigs: function() {
            Fast.api.confirm("确定要清空所有配置吗？此操作不可恢复！", function() {
                $('#standard_config').val('');
                $('#fission_config').val('');
                Controller.updateStats();
                Toastr.success('所有配置已清空');
            });
        },

        downloadBackup: function() {
            var backupStandard = $('#backup-standard').is(':checked');
            var backupFission = $('#backup-fission').is(':checked');

            if (!backupStandard && !backupFission) {
                Toastr.warning('请至少选择一个配置文件进行备份');
                return;
            }

            var backupData = {};
            var timestamp = new Date().toISOString().slice(0, 19).replace(/:/g, '-');

            if (backupStandard) {
                backupData.standard = {
                    filename: 'black_company_config.php',
                    content: $('#standard_config').val(),
                    count: Controller.processLines($('#standard_config').val()).length
                };
            }

            if (backupFission) {
                backupData.fission = {
                    filename: 'black_company_config_fission.php',
                    content: $('#fission_config').val(),
                    count: Controller.processLines($('#fission_config').val()).length
                };
            }

            // 创建下载链接
            var dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(backupData, null, 2));
            var downloadAnchorNode = document.createElement('a');
            downloadAnchorNode.setAttribute("href", dataStr);
            downloadAnchorNode.setAttribute("download", "blacklist_config_backup_" + timestamp + ".json");
            document.body.appendChild(downloadAnchorNode);
            downloadAnchorNode.click();
            downloadAnchorNode.remove();

            $('#backup-modal').modal('hide');
            Toastr.success('配置备份下载成功');
        },

        importData: function() {
            var importText = $('#import-text').val().trim();
            var importTarget = $('#import-target').val();

            if (!importText) {
                Toastr.warning('请输入要导入的数据');
                return;
            }

            // 处理多种分隔符
            var lines = importText.split(/[,;\n\r]+/);
            var companies = [];

            for (var i = 0; i < lines.length; i++) {
                var line = lines[i].trim();
                if (line) {
                    companies.push(line);
                }
            }

            if (companies.length === 0) {
                Toastr.warning('没有找到有效的公司名称');
                return;
            }

            // 去重并排序
            companies = Controller.deduplicateAndSort(companies);

            // 导入到目标配置
            var targetTextarea = importTarget === 'standard' ? '#standard_config' : '#fission_config';
            var existingContent = $(targetTextarea).val();
            var existingLines = Controller.processLines(existingContent);

            // 合并现有内容和新导入的内容
            var allCompanies = existingLines.concat(companies);
            allCompanies = Controller.deduplicateAndSort(allCompanies);

            $(targetTextarea).val(allCompanies.join('\n'));
            $('#import-text').val('');

            Controller.updateStats();

            var targetName = importTarget === 'standard' ? '不愿意推送到计划公司名单' : '不愿意推送素材公司名单';
            Toastr.success('成功导入 ' + companies.length + ' 条数据到' + targetName);
        },
        
        processLines: function(content) {
            if (!content) return [];

            // 支持多种分隔符：换行、逗号、分号、中文逗号、中文分号
            var lines = content.split(/[\n\r,;，；]+/);
            return lines.map(function(line) {
                return line.trim();
            }).filter(function(line) {
                return line.length > 0;
            });
        },
        
        deduplicateAndSort: function(lines) {
            // 去重
            var unique = [];
            var seen = {};
            
            for (var i = 0; i < lines.length; i++) {
                var line = lines[i];
                if (!seen[line]) {
                    seen[line] = true;
                    unique.push(line);
                }
            }
            
            // 排序
            return unique.sort();
        },
        
        updateStats: function() {
            // 使用内存中的数据进行统计
            var standardCount = Controller.standardCompanies.length;
            var fissionCount = Controller.fissionCompanies.length;
            var totalCount = standardCount + fissionCount;

            // 更新顶部统计卡片
            $('#standard-total').text(standardCount);
            $('#fission-total').text(fissionCount);
            $('#total-all').text(totalCount);

            // 更新标签页徽章
            $('#standard-badge').text(standardCount);
            $('#fission-badge').text(fissionCount);

            // 更新详情页计数
            $('#standard-count').text(standardCount);
            $('#fission-count').text(fissionCount);

            // 更新进度条
            var maxCount = Math.max(standardCount, fissionCount, 1);
            var standardPercent = maxCount > 0 ? (standardCount / maxCount) * 100 : 0;
            var fissionPercent = maxCount > 0 ? (fissionCount / maxCount) * 100 : 0;

            $('.info-box.bg-blue .progress-bar').css('width', standardPercent + '%');
            $('.info-box.bg-green .progress-bar').css('width', fissionPercent + '%');
        },
        
        autoResize: function(textarea) {
            // 自动调整文本框高度
            textarea.style.height = 'auto';
            textarea.style.height = (textarea.scrollHeight) + 'px';
        }
    };

    return Controller;
});
