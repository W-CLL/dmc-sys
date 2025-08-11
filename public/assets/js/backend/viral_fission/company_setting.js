define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            Controller.api.bindevent();

            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'viral_fission/company_setting/index',
                    setting_url: 'viral_fission/company_setting/setting',
                    batch_setting_url: 'viral_fission/company_setting/batchSetting',
                    table: 'company',
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
                        {field: 'company_name', title: "公司名称", operate: 'LIKE'},
                        {field: 'username', title: "绑定商户", operate: 'LIKE'},
                        {field: 'qc_account_count', title: "千川账户数量", operate: false},
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
                                    url: 'viral_fission/company_setting/setting'
                                }
                            ]
                        }
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
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
                            url: 'viral_fission/company_setting/batch_setting',
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

        batch_setting: function () {
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
