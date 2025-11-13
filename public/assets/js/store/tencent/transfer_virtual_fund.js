define(['jquery', 'bootstrap', 'store', 'table', 'form'], function ($, undefined, Store, Table, Form) {

    var Controller = {
        index: function () {

            Controller.api.bindevent();
            // 给表单绑定事件
            Form.api.bindevent($("#edit-form"), function () {
                setTimeout(function () {
                    location.reload();
                }, 1500);
                return true;
            });
            
            // 当选择发起方账户时，获取可操作余额
            $('#account_id_initiate').on('change', function() {
                if (this.value == 0){
                    $('#initiate_money').text(0)
                }else{
                    $.ajax({
                        url: 'tencent/transfer_virtual_fund/get_amount',
                        dataType: 'json',
                        data: {account_id: this.value},
                        cache: false,
                        success: function (ret) {
                            if (ret.code){
                                $('#initiate_money').text(ret.data.money)
                            }else{
                                Toastr.error(__(ret.msg));
                            }
                        }, error: function () {
                            Toastr.error(__('Network error'));
                        }
                    });
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