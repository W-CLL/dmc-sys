define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'more/write_off_receipt/index',
                    add_url: 'more/write_off_receipt/add',
                    del_url: 'more/write_off_receipt/del',
                    multi_url: 'more/write_off_receipt/multi',
                    table: 'receipt_use_log',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('Id')},
                        {field: 'receipt_no', title: __('回单号'), align: 'left'},
                        {field: 'image_path', title: __('回单图片'), align: 'center', formatter: Controller.formatter.image},
                        {field: 'admin_name', title: __('操作员')},
                        {field: 'create_time', title: __('创建时间'), operate:'RANGE', addclass:'datetimerange', formatter: Table.api.formatter.datetime},
                        {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        add: function () {
            Controller.api.bindevent();
        },
        upload: function () {
            // 绑定文件上传相关事件
            Controller.api.bindUploadEvents();
            
            // 获取表单对象
            var $form = $("#upload-form");
            if (!$form || $form.length === 0) {
                $form = $("form[role=form]");
            }
            
            // 使用原生JavaScript处理表单提交，确保文件能正确上传
            $form.on('submit', function(e) {
                e.preventDefault(); // 阻止默认提交
                
                var $this = $(this);
                
                // 检查是否选择了文件
                var fileInput = $this.find('input[type=file]')[0];
                if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                    if (typeof Toastr !== 'undefined') {
                        Toastr.error("请选择要上传的回单图片");
                    } else {
                        alert("请选择要上传的回单图片");
                    }
                    return false;
                }
                
                // 禁用提交按钮，防止重复提交
                var submitBtn = $this.find('button[type=submit]');
                if (submitBtn && submitBtn.length > 0) {
                    submitBtn.prop('disabled', true);
                    submitBtn.text('上传中...');
                }
                
                // 使用FormData处理表单数据
                var formData = new FormData(this);
                
                // 发起Ajax请求
                $.ajax({
                    url: $this.attr('action') || window.location.href,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(ret) {
                        if (ret.code === 1) {
                            // 成功处理
                            if (typeof Toastr !== 'undefined') {
                                Toastr.success(ret.msg || "上传成功");
                            } else {
                                alert(ret.msg || "上传成功");
                            }
                            setTimeout(function() {
                                // 关闭弹窗或刷新表格
                                if (typeof parent !== 'undefined' && parent.layer) {
                                    parent.$("#table").bootstrapTable('refresh');
                                    parent.layer.closeAll();
                                } else {
                                    window.location.href = ret.url || history.back();
                                }
                            }, 1500);
                        } else {
                            // 错误处理
                            var errorMsg = ret.msg || "上传失败";
                            if (typeof Toastr !== 'undefined') {
                                Toastr.error(errorMsg);
                            } else {
                                alert(errorMsg);
                            }
                            
                            // 重新启用提交按钮
                            if (submitBtn && submitBtn.length > 0) {
                                submitBtn.prop('disabled', false);
                                submitBtn.text('上传并识别');
                            }
                        }
                    },
                    error: function(xhr, status, error) {
                        var errorMsg = "请求失败，请检查网络连接";
                        if (xhr.responseJSON && xhr.responseJSON.msg) {
                            errorMsg = xhr.responseJSON.msg;
                        }
                        
                        if (typeof Toastr !== 'undefined') {
                            Toastr.error(errorMsg);
                        } else {
                            alert(errorMsg);
                        }
                        
                        // 重新启用提交按钮
                        if (submitBtn && submitBtn.length > 0) {
                            submitBtn.prop('disabled', false);
                            submitBtn.text('上传并识别');
                        }
                    }
                });
            });
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            },
            bindUploadEvents: function() {
                // 文件上传美化交互
                var uploadBox = document.querySelector('.file-upload-box');
                var fileInput = document.querySelector('.file-input');
                var fileInfo = document.getElementById('file-info');
                var fileName = document.querySelector('.file-name');
                var fileSize = document.querySelector('.file-size');
                
                // 文件选择事件
                if (fileInput) {
                    fileInput.addEventListener('change', function() {
                        var file = this.files[0];
                        if (file) {
                            Controller.api.updateFileInfo(file);
                        } else {
                            // 文件被清除时的处理
                            Controller.api.clearFileInfo();
                        }
                    });
                }
                
                // 点击上传区域时触发文件选择
                if (uploadBox) {
                    uploadBox.addEventListener('click', function(e) {
                        // 防止事件冒泡
                        e.stopPropagation();
                        // 触发文件输入框
                        fileInput.click();
                    });
                }
                
                // 防止点击文件输入框时重复触发点击事件
                if (fileInput) {
                    fileInput.addEventListener('click', function(e) {
                        e.stopPropagation();
                    });
                }
            },
            updateFileInfo: function(file) {
                var fileInfo = document.getElementById('file-info');
                var fileName = document.querySelector('.file-name');
                var fileSize = document.querySelector('.file-size');
                
                if (file) {
                    fileName.textContent = file.name;
                    fileSize.textContent = Controller.api.formatFileSize(file.size);
                    fileInfo.style.display = 'flex';
                    
                    // 添加图片预览功能
                    if (file.type.startsWith('image/')) {
                        var reader = new FileReader();
                        reader.onload = function(e) {
                            var previewImage = document.getElementById('preview-image');
                            previewImage.src = e.target.result;
                            document.getElementById('image-preview').style.display = 'block';
                        };
                        reader.readAsDataURL(file);
                    }
                } else {
                    Controller.api.clearFileInfo();
                }
            },
            clearFileInfo: function() {
                var fileInfo = document.getElementById('file-info');
                fileInfo.style.display = 'none';
                document.getElementById('image-preview').style.display = 'none';
                document.getElementById('preview-image').src = '';
            },
            formatFileSize: function(bytes) {
                if (bytes === 0) return '0 Bytes';
                var k = 1024;
                var sizes = ['Bytes', 'KB', 'MB', 'GB'];
                var i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            }
        },
        formatter: {
            image: function (value, row, index) {
                if (value) {
                    return '<a href="' + value + '" target="_blank"><img src="' + value + '" alt="回单图片" style="max-height:50px;max-width:100px"></a>';
                }
                return '';
            }
        }
    };
    return Controller;
});