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
            $('#advertiser_id_initiate').on('change', function() {
                if (this.value == 0){
                    $('#initiate_money').text(0)
                }else{
                    $.ajax({
                        url: 'wallet/recharge_transfer/get_qc_money',
                        dataType: 'json',
                        data: {advertiser_id: this.value},
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
