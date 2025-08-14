define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'viral_fission/into_obj_record/index',
                    add_url: '',
                    edit_url: '',
                    del_url: '',
                    multi_url: '',
                    detail_url: 'viral_fission/into_obj_record/detail',
                    table: 'fission_into_obj_record',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                commonSearch: true,
                searchFormVisible: true,
                searchFormTemplate: 'customformtpl',
                // 固定最后一列
                fixedColumns: true,
                fixedRightNumber: 1,
                columns: [
                    [
                        {field: 'state', checkbox: true},
                        {field: 'id', title: 'ID', sortable: true},
                        {field: 'adv_id', title: '千川ID', sortable: true},
                        {field: 'obj_id', title: '计划ID', sortable: true},
                        {field: 'product_id', title: '商品ID', sortable: true},
                        {field: 'mid', title: '素材ID列表', formatter: Controller.api.formatter.mid},
                        {field: 'reason', title: '失败原因'},
                        {field: 'status', title: '状态', formatter: Controller.api.formatter.status, sortable: true},
                        {field: 'create_time', title: '创建时间', formatter: Controller.api.formatter.datetime, sortable: true},
                        {
                            field: 'operate', 
                            title: __('Operate'), 
                            table: table, 
                            events: Table.api.events.operate, 
                            formatter: Table.api.formatter.operate,
                            buttons: [
                                {
                                    name: 'detail',
                                    text: '详情',
                                    icon: 'fa fa-list',
                                    classname: 'btn btn-info btn-xs btn-detail btn-dialog',
                                    url: 'viral_fission/into_obj_record/detail'
                                }
                            ]
                        }
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        
        detail: function () {
            
        },
        
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            },
            formatter: {
                mid: function (value, row, index) {
                    if (!value) {
                        return '无';
                    }
                    
                    // 如果是数组形式的素材ID列表，进行格式化处理
                    if (value.indexOf(',') !== -1) {
                        var midArray = value.split(',');
                        if (midArray.length > 3) {
                            return '<span title="' + value + '">' + midArray.slice(0, 3).join(',') + '...(' + midArray.length + '个)</span>';
                        } else {
                            return value;
                        }
                    }
                    
                    // 如果内容较长，截取显示
                    if (value.length > 50) {
                        return '<span title="' + value + '">' + value.substring(0, 50) + '...</span>';
                    }
                    
                    return value;
                },
                
                status: function (value, row, index) {
                    switch (value) {
                        case 'success':
                            return '<span class="label label-success">成功</span>';
                        case 'failed':
                            return '<span class="label label-danger">失败</span>';
                        default:
                            return '<span class="label label-default">' + (value || '未知') + '</span>';
                    }
                },
                
                datetime: function (value, row, index) {
                    if (!value) {
                        return '';
                    }
                    
                    // 将时间戳转换为日期时间格式
                    var date = new Date(value * 1000);
                    var year = date.getFullYear();
                    var month = (date.getMonth() + 1).toString().padStart(2, '0');
                    var day = date.getDate().toString().padStart(2, '0');
                    var hours = date.getHours().toString().padStart(2, '0');
                    var minutes = date.getMinutes().toString().padStart(2, '0');
                    var seconds = date.getSeconds().toString().padStart(2, '0');
                    
                    // 兼容旧版本浏览器的padStart实现
                    function padStart(str, length, pad) {
                        str = str.toString();
                        while (str.length < length) {
                            str = pad + str;
                        }
                        return str;
                    }
                    
                    var month = padStart(date.getMonth() + 1, 2, '0');
                    var day = padStart(date.getDate(), 2, '0');
                    var hours = padStart(date.getHours(), 2, '0');
                    var minutes = padStart(date.getMinutes(), 2, '0');
                    var seconds = padStart(date.getSeconds(), 2, '0');
                    
                    return year + '-' + month + '-' + day + ' ' + hours + ':' + minutes + ':' + seconds;
                }
            }
        }
    };
    return Controller;
});