define(['jquery', 'bootstrap', 'company', 'table', 'form'], function ($, undefined, Company, Table, Form) {

    var Controller = {
        index: function () {
            Controller.api.bindevent();
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'operate_monitor/cs_queue_exe_state/index' + location.search,
                    table: 'queue_record',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                // ... 其他配置 ...
                search: false, // 禁用默认搜索
                commonSearch: false, // 启用普通表单搜索
                searchFormVisible: true, // 控制搜索栏是否显示在页面上
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'update_time',
                pageSize: 10,
                pageList: [10, 15, 20],
                fixedColumns: true,
                fixedRightNumber: 1,
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('Id'), visible: false},
                        {
                            field: 'job_id',
                            title: "任务id",
                            operate: false,
                            searchList: Config.searchList,
                            formatter: Table.api.formatter.label
                        },
                        {
                            field: 'class_name',
                            title: "任务类名",
                            align: 'left',
                            formatter: function (value, row, index) {
                                return value.toString().replace(/(&|&amp;)nbsp;/g, '&nbsp;');
                            }
                        },
                        {field: 'job_name', title: "任务名称"},
                        {field: 'company_id', title: "千川id"},
                        // {
                        //     field: 'job_data', title: "请求参数", width: 130, align: 'left',
                        //     formatter: function (value, row, index, field) {
                        //         return "<span style='display: block;overflow: hidden;text-overflow: ellipsis;white-space: nowrap;' title='" + row.job_data + "'>" + value + "</span>";
                        //     },
                        //     cellStyle: function (value, row, index, field) {
                        //         return {
                        //             css: {
                        //                 "white-space": "nowrap",
                        //                 "text-overflow": "ellipsis",
                        //                 "overflow": "hidden",
                        //                 "max-width": "150px"
                        //             }
                        //         };
                        //     }
                        // },
                        {
                            field: 'msg',
                            title: "执行信息",
                            width: 130,
                            align: 'left',
                            formatter: function (value, row, index, field) {
                                function decodeHtmlEntities(str) {
                                    // 检查 str 是否为 null 或 undefined
                                    if (str == null) {
                                        return ''; // 返回空字符串或默认值
                                    }
                                    // 递归删除 {} 及其内容
                                    while (/\{[^{}]*\}/g.test(str)) {
                                        str = str.replace(/\{[^{}]*\}/g, '').trim();
                                    }
                                    // 仅显示前 20 个字符
                                    return str.substring(0, 20);
                                }
                                const decodedValue = decodeHtmlEntities(value);
                                return `<span style="display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${value}">${decodedValue}</span>`;
                            },
                            cellStyle: function (value, row, index, field) {
                                return {
                                    css: {
                                        "white-space": "nowrap",
                                        "text-overflow": "ellipsis",
                                        "overflow": "hidden",
                                        "max-width": "150px"
                                    }
                                };
                            }
                        },
                        {field: 'remark', title: "备注"},
                        {field: 'status', title: "状态", formatter: function(value,row,index) {
                                if (row.status == 1){
                                    return "成功"
                                }else if (row.status == 2){
                                    return "失败"
                                }else{
                                    return "等待中"
                                }
                            }, operate: 'LIKE'},
                        {field: 'create_time', title: "创建时间", formatter: Table.api.formatter.datetime},
                        {field: 'update_time', title: "更新时间", formatter: Table.api.formatter.datetime},
                    ]
                ],
                queryParams:function (params) {
                    let time_data = document.getElementById('dateRange').value.split(' - ');
                    params.start_date = time_data[0];
                    params.end_date = time_data[1];
                    params.cs = document.getElementById('cs').value;
                    params.status = document.getElementById('status').value;
                    params.adv_id = document.getElementById('adv_id').value;
                    params.job_name = document.getElementById('job_name').value;
                    params.code_type = document.getElementById('code_type').value;
                    return params;
                }
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});
