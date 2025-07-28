define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            Controller.api.bindevent();

            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'viral_fission/account_setting/index',
                    setting_url: 'viral_fission/account_setting/setting',
                    batch_setting_url: 'viral_fission/account_setting/batchSetting',
                    table: 'qc_obj',
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
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: "ID", visible: false},
                        {field: 'name', title: "账户名称", operate: 'LIKE'},
                        {field: 'advertiser_id', title: "账户ID", operate: '='},
                        {field: 'company_name', title: "公司名", operate: 'LIKE'},
                        {
                            field: 'fission_rules',
                            title: "裂变规则",
                            operate: false,
                            width: 420,
                            formatter: function (value, row, index) {
                                if (!value || (!value.fission_strategies && !value.time_rules)) {
                                    return '<div class="text-center text-muted" style="padding: 15px;">未设置规则</div>';
                                }

                                var html = '<div class="fission-strategies-container" style="padding: 10px; background: #f8f9fa; border-radius: 4px; line-height: 1.6;">';
                                if (value.cost_rules) {
                                    let timeRulesArray = Object.keys(value.cost_rules).map(key => value.cost_rules[key]);
                                    if (timeRulesArray.length > 0) {
                                        html += '<div style="margin-bottom: 6px;"><strong>时间条件：</strong></div>';
                                        timeRulesArray.forEach(function(timeRule, idx) {
                                            let timeDimensionText = getTimeDimensionText(timeRule.time_dimension); // 获取时间维度文本
                                            let timeRuleHtml = '<div style="margin-bottom: 4px;">';
                                            timeRuleHtml += '时间:' + timeDimensionText + ',消耗:' + timeRule.threshold + ',素材roi:' + timeRule.roi + ',成交单量:' + timeRule.order_count +'</div>';
                                            html += timeRuleHtml;
                                        });
                                    }
                                }


                                // 裂变策略
                                if (value.fission_strategies && value.fission_strategies.length > 0) {
                                    let strategies = value.fission_strategies;
                                    let strategyHtml = '<div class="fission-strategies-text" style="margin-bottom: 6px;"><strong>裂变策略：</strong>' + strategies.join('，') + '</div>';
                                    html += strategyHtml;
                                }
                                // 时间规则


                                html += '</div>';
                                return html;
                            }

                        },
                        {
                            field: 'operate',
                            title: __('Operate'),
                            table: table,
                            events: Table.api.events.operate,
                            formatter: Table.api.formatter.operate,
                            buttons: [
                                {
                                    name: 'setting',
                                    title: '设置',
                                    classname: 'btn btn-xs btn-primary btn-dialog',
                                    icon: 'fa fa-cog',
                                    url: 'viral_fission/account_setting/setting'
                                }
                            ]
                        }
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);

            // 表格加载完成后修改表头并绑定事件
            table.on('post-header.bs.table', function() {

                Controller.api.addHelpIcon();
            });

            table.on('post-body.bs.table', function() {

                Controller.api.addHelpIcon();
            });

            table.on('refresh.bs.table', function() {

                setTimeout(function() {
                    Controller.api.addHelpIcon();
                }, 100);
            });

            // 延迟添加问号图标，确保表格完全加载
            setTimeout(function() {

                Controller.api.addHelpIcon();
            }, 500);

            // 再次延迟尝试，以防第一次失败
            setTimeout(function() {

                Controller.api.addHelpIcon();
            }, 1500);

            // 最后一次尝试
            setTimeout(function() {

                Controller.api.addHelpIcon();
            }, 3000);


            function getTimeDimensionText(timeDimension) {
                switch (timeDimension) {
                    case "1":
                        return "当天";
                    case "2":
                        return "近2天";
                    case "3":
                        return "近3天";
                    case "4":
                        return "近5天";
                    case "5":
                        return "近7天";
                    // 可以继续添加其他情况
                    default:
                        return "未知";
                }
            }
            $(".btn-batch-setting").on('click', function () {
                var ids = Table.api.selectedids(table);
                var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent); // 判断是否为移动端
                var width = isMobile ? '80%' : '40%'; // 手机端80%，电脑端40%
                var height = '60%'; // 高度统一为60%
                layer.open({
                    type: 2,
                    area: [width, height],
                    content: 'batch_setting',
                    fixed: false, // 不固定
                    maxmin: true,
                    shadeClose: true,
                    btn: ['提交', '取消'],
                    btnAlign: 'c',
                    yes: function (index, layero) {
                        var body = layer.getChildFrame('body', index);
                        var token = body.find("input[name='__token__']")[0].value;
                        var formData = body.find("form").serialize();
                        Fast.api.ajax({
                            url: 'viral_fission/account_setting/batch_setting',
                            data: {
                                __token__: token,
                                ids: ids.join(','),
                                cost_rules: formData
                            }
                        }, function (data, ret) {
                            table.bootstrapTable('refresh', {});
                            Layer.close(index);
                        });
                    }
                });
            })

            // 监听复选框变化
            table.on('check.bs.table uncheck.bs.table check-all.bs.table uncheck-all.bs.table', function () {
                var ids = Table.api.selectedids(table);
                $('#select_total').text(ids.length);
                if (ids.length > 0) {
                    $('.btn-batch-setting').removeClass('btn-disabled disabled');
                } else {
                    $('.btn-batch-setting').addClass('btn-disabled disabled');
                }
            });
        },
        
        setting: function () {
            Controller.api.bindevent();
        },
        batch_setting: function () {
            Controller.api.bindevent();
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            },

            addHelpIcon: function() {


                // 尝试多种选择器来找到表头
                var selectors = [
                    '.fixed-table-header th[data-field="fission_rules"]',
                    '.bootstrap-table .fixed-table-header th[data-field="fission_rules"]',
                    'th[data-field="fission_rules"]',
                    '.fixed-table-header th:contains("裂变规则")',
                    '.bootstrap-table th:contains("裂变规则")',
                    '.fixed-table-header-columns th[data-field="fission_rules"]',
                    'table th[data-field="fission_rules"]'
                ];

                var $header = null;
                for (var i = 0; i < selectors.length; i++) {
                    $header = $(selectors[i]);

                    if ($header.length > 0) {

                        break;
                    }
                }

                // 如果还是找不到，尝试更通用的方法
                if (!$header || $header.length === 0) {

                    $('th').each(function() {
                        var text = $(this).text().trim();

                        if (text === '裂变规则' || text.indexOf('裂变规则') !== -1) {
                            $header = $(this);

                            return false; // 跳出循环
                        }
                    });
                }

                if ($header && $header.length > 0) {
                    if ($header.find('.fission-rules-help').length === 0) {

                        var currentText = $header.text().trim();
                        $header.html(currentText + ' <i class="fa fa-question-circle text-info fission-rules-help" style="cursor: pointer; margin-left: 5px;" title="裂变规则设置说明：有设置到账户规则的按照账户规则，没有则按照账户所属的主体（公司）设定的规则"></i>');
                        this.bindHelpIconEvents();

                    }
                }
            },

            bindHelpIconEvents: function() {
                // 绑定裂变规则说明点击事件
                $(document).off('click', '.fission-rules-help').on('click', '.fission-rules-help', function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    Layer.alert(
                        '<div style="padding: 15px; line-height: 1.6;">' +
                        '<h4 style="margin-top: 0; color: #333;"><i class="fa fa-info-circle text-info"></i> 裂变规则设置说明</h4>' +
                        '<div style="background: #f8f9fa; padding: 12px; border-radius: 4px; margin: 10px 0;">' +
                        '<p style="margin: 0 0 8px 0;"><strong>规则优先级：</strong></p>' +
                        '<p style="margin: 0 0 8px 0;">1. 如果账户有单独设置规则，则按照<span style="color: #007bff; font-weight: 500;">账户规则</span>执行</p>' +
                        '<p style="margin: 0;">2. 如果账户没有设置规则，则按照账户所属的<span style="color: #28a745; font-weight: 500;">主体（公司）规则</span>执行</p>' +
                        '</div>' +
                        '<div style="font-size: 12px; color: #6c757d;">' +
                        '<i class="fa fa-lightbulb-o"></i> 提示：账户级别的规则设置可以覆盖公司级别的默认规则，实现更精细化的管理。' +
                        '</div>' +
                        '</div>',
                        {
                            title: '裂变规则说明',
                            area: ['500px', '320px']
                        }
                    );
                });

                // 优化问号图标的悬停效果
                $(document).off('mouseenter mouseleave', '.fission-rules-help')
                    .on('mouseenter', '.fission-rules-help', function() {
                        $(this).removeClass('text-info').addClass('text-primary');
                    })
                    .on('mouseleave', '.fission-rules-help', function() {
                        $(this).removeClass('text-primary').addClass('text-info');
                    });
            }
        }
    };
    return Controller;
});
