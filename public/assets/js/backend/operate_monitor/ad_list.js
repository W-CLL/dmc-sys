define(['jquery', 'bootstrap', 'company', 'table', 'form'], function ($, undefined, Company, Table, Form) {

    var Controller = {
        index: function () {
            Controller.api.bindevent();
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'operate_monitor/ad_list/index' + location.search,
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                // ... 其他配置 ...
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'mon_cost',
                pageSize: 10,
                pageList: [10, 15, 20, 50, 100],
                exportDataType: 'basic',
                // fixedColumns: true,
                // fixedRightNumber: 1,
                columns: [
                    [
                        // {field: 'id', title: "ID"},
                        {field: 'adv_id', title: "广告主id"},
                        {field: 'company_name', title: "账户名"},
                        {field: 'total_num', title: "总次数[标]",sortable:true},
                        {field: 'company_num', title: "斑马次数[标]",sortable:true},
                        {field: 'cus_num', title: "客户次数[标]",sortable:true},
                        {field: 'product_promotion_count', title: "推商品次数[标]", sortable:true},
                        {field: 'live_promotion_count', title: "推直播间次数[标]", sortable:true},
                        {field: 'percentage', title: "[标准]百分比",sortable:true,formatter: function (value, row, index) {
                            let result = value.replace(/%/g, '');
                            if (result < 200) {
                                return '<span style="color:red;">' +value+'</span>';
                            }else{
                                return '<span style="color:green;">' +value+'</span>';
                            }
                            }},
                        {field: 'global_total_num', title: "总次数[全]",sortable:true},
                        {field: 'global_company_num', title: "斑马次数[全]",sortable:true},
                        {field: 'global_cus_num', title: "客户次数[全]",sortable:true},
                        {field: 'global_product_promotion_count', title: "推商品次数[全]", sortable:true},
                        {field: 'global_live_promotion_count', title: "推直播间次数[全]", sortable:true},
                        {field: 'global_percentage', title: "[全域]百分比",sortable:true,formatter: function (value, row, index) {
                                let result = value.replace(/%/g, '');
                                if (result < 200) {
                                    return '<span style="color:red;">' +value+'</span>';
                                }else{
                                    return '<span style="color:green;">' +value+'</span>';
                                }
                            }},
                        {field: 'stand_cost', title: "标准消耗",sortable:true},
                        {field: 'global_cost', title: "全域消耗",sortable:true},
                        {field: 'mon_cost', title: "总消耗",sortable:true},


                        {field: 'kahuna', title: "负责人"},
                    ]
                ],
                queryParams:function (params) {
                    let time_data = document.getElementById('dateRange').value.split(' - ');
                    params.start_date = time_data[0];
                    params.end_date = time_data[1];
                    params.kahuna = document.getElementById('kahuna').value;
                    params.advertiser_id = document.getElementById('advertiser_id').value;
                    return params;
                }
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        charge_page: function () {
            Controller.api.bindevent();
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'operate_monitor/ad_list/charge_page' + location.search,
                }
            });

            var table = $("#table");

            table.bootstrapTable({
                // ... 其他配置 ...
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'mon_cost',
                pageSize: 10,
                pageList: [10, 15, 20,50,100],
                // fixedColumns: true,
                // fixedRightNumber: 1,
                columns: [
                    [
                        // {field: 'id', title: "ID"},
                        {field: 'adv_id', title: "广告主id"},
                        {field: 'company_name', title: "账户名"},
                        {field: 'total_num', title: "总次数[标]",sortable:true},
                        {field: 'company_num', title: "斑马次数[标]",sortable:true},
                        {field: 'cus_num', title: "客户次数[标]",sortable:true},
                        {field: 'percentage', title: "[标准]百分比",sortable:true,formatter: function (value, row, index) {
                                let result = value.replace(/%/g, '');
                                if (result < 200) {
                                    return '<span style="color:red;">' +value+'</span>';
                                }else{
                                    return '<span style="color:green;">' +value+'</span>';
                                }
                            }},
                        {field: 'global_total_num', title: "总次数[全]",sortable:true},
                        {field: 'global_company_num', title: "斑马次数[全]",sortable:true},
                        {field: 'global_cus_num', title: "客户次数[全]",sortable:true},
                        {field: 'global_percentage', title: "[全域]百分比",sortable:true,formatter: function (value, row, index) {
                                let result = value.replace(/%/g, '');
                                if (result < 200) {
                                    return '<span style="color:red;">' +value+'</span>';
                                }else{
                                    return '<span style="color:green;">' +value+'</span>';
                                }
                            }},
                        {field: 'product_promotion_count', title: "推商品次数[标]", sortable:true},
                        {field: 'live_promotion_count', title: "推直播间次数[标]", sortable:true},
                        {field: 'global_product_promotion_count', title: "推商品次数[全]", sortable:true},
                        {field: 'global_live_promotion_count', title: "推直播间次数[全]", sortable:true},
                        {field: 'stand_cost', title: "标准消耗",sortable:true},
                        {field: 'global_cost', title: "全域消耗",sortable:true},
                        {field: 'mon_cost', title: "总消耗",sortable:true},


                        {field: 'kahuna', title: "负责人"},
                    ]
                ],
                queryParams:function (params) {
                    let time_data = document.getElementById('dateRange').value.split(' - ');
                    params.start_date = time_data[0];
                    params.end_date = time_data[1];
                    params.kahuna = document.getElementById('kahuna').value;
                    params.advertiser_id = document.getElementById('advertiser_id').value;
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