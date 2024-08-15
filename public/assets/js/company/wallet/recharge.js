define(['jquery', 'bootstrap', 'company', 'table', 'form'], function ($, undefined, Company, Table, Form) {

    var Controller = {
        index: function () {
            Controller.api.bindevent();
            $("#faupload-local").data("upload-success", function(data, ret){
                $.ajax({
                    url: 'wallet/recharge/get_image_info',
                    dataType: 'json',
                    data: {image: data.url},
                    cache: false,
                    success: function (ret) {
                        if (ret.code){
                            $("#money").text(ret.data.money);
                            $("#payee").text(ret.data.payee);
                            $("#order_num").val(ret.data.order_num)
                            $("#image_info").css("display","block")
                        }else{
                            Toastr.error(__('识别失败'));
                        }
                    }, error: function () {
                        Toastr.error(__('Network error'));
                    }
                });
            });
            // 给表单绑定事件
            Form.api.bindevent($("#edit-form"), function () {
                setTimeout(function () {
                    location.reload();
                }, 1500);
                return true;
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
