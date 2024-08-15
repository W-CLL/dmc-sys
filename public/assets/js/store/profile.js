define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'upload'], function ($, undefined, Backend, Table, Form, Upload) {

    var Controller = {
        index: function () {

            // 给表单绑定事件
            Form.api.bindevent($("#update-form"), function () {
                $("input[name='password']").val('');
                $("input[name='passwords']").val('');
                return true;
            });
        },
    };
    return Controller;
});
