define(['jquery', 'bootstrap', 'store', 'table', 'form'], function ($, undefined, Store, Table, Form) {

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
                            if (ret.data.account_type == 1){
                                $("#account_type").text('公对公')
                            }else{
                                $("#account_type").text('私对私')
                            }
                            $("#image_info").css("display","block")
                        }else{
                            Toastr.error(ret.msg);
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
            }, function() {
                // 提交完成后重新启用按钮
                $(".btn-primary").removeClass("disabled");
            }, function() {
                // 提交前禁用按钮
                $(".btn-primary").addClass("disabled");
            });

            $('#type').on('change', function() {
                if (this.value == 1){
                    $('#type1').css('display','block')
                    $('#type2').css('display','none')
                }else{
                    $('#type1').css('display','none')
                    $('#type2').css('display','block')
                }
            })

            $("#example").on('click',function(){

                layer.open({
                    type: 1,
                    area: ['680px', '520px'],
                    content: "<h5 style='margin: 20px'>" +
                        "<p>需明确可见甲方付款人抬头、日期、金额以及乙方收款抬头、银行卡号、银行信息。</p>" +
                        "<p>图片打码是隐藏客户信息，上传请勿打码！以免系统识别不到！</p>" +
                        "<img style='width: 600px' src='/example/1718868742692.jpg'>"+
                        "<img style='width: 600px' src='/example/1718868827655.jpg'>"+
                        "</h5>" //这里content是一个普通的String
                });
            })
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});
