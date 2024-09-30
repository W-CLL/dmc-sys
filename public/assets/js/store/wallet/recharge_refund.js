define(['jquery', 'bootstrap', 'store', 'table', 'form'], function ($, undefined, Store, Table, Form) {

    var Controller = {
        index: function () {
            var account_type = 0;

            Controller.api.bindevent();
            // 给表单绑定事件
            Form.api.bindevent($("#edit-form"), function () {
                setTimeout(function () {
                    location.reload();
                }, 1500);
                return true;
            });
            $('#advertiser_id').on('change', function() {
                if (this.value == 0){
                    $('#qc_money').text(0)
                    $('#account_type').text('')
                }else{
                    $.ajax({
                        url: 'wallet/recharge_refund/get_qc_money',
                        dataType: 'json',
                        data: {advertiser_id: this.value},
                        cache: false,
                        success: function (ret) {
                            if (ret.code){
                                $('#qc_total_money').text(ret.data.total_money)
                                $('#qc_grant_money').text(ret.data.grant_balance)
                                $('#qc_money').text(ret.data.money)
                                $('#account_type').text(ret.data.account_type == 1?'公':'私')
                                account_type = ret.data.account_type
                                calculate_deductions(account_type)
                            }else{
                                Toastr.error(__(ret.msg));
                            }
                        }, error: function () {
                            Toastr.error(__('Network error'));
                        }
                    });
                }
            });

            $('input[name="transaction_type"]').on('change', function() {
                calculate_deductions(account_type)
            });


            $("#money").on('input', function() {
                calculate_deductions(account_type)
            });
            function calculate_deductions(type) {
                var money = $("#money").val()
                if (money > 0){
                    var discount_percentage;
                    if (type == 1){
                         discount_percentage = $("#public_discount_percentage").text()
                    }else if (type == 2){
                         discount_percentage = $("#private_discount_percentage").text()
                    }else{
                        return ;
                    }

                    let transaction_type = $('input[name="transaction_type"]:checked').val();
                    let deduction_money = (money * 100) / discount_percentage * 100 / 10000

                    var actual_money = parseFloat(deduction_money.toFixed(2))
                    if (transaction_type == 1){
                        $("#deduction").text("转入此金额将扣除您钱包" + actual_money + "元")
                    }else{
                        // 发起ajax获取actual_money值
                        $.ajax({
                            url: 'wallet/recharge_refund/get_actual_money',
                            dataType: 'json',
                            data: {advertiser_id: $('#advertiser_id').val(), money: money},
                            cache: false,
                            success: function (ret) {
                                if (ret.code){
                                    actual_money = ret.data.actual_money
                                }else{
                                    Toastr.error(__(ret.msg));
                                }
                            }
                        })
                        $("#deduction").text("转出此金额将增加您钱包" + actual_money + "元")
                    }
                    return ;
                }
                 $("#deduction").text("");
            }

        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});
