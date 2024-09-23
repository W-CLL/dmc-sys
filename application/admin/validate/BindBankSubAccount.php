<?php

namespace app\admin\validate;

use think\Validate;

class BindBankSubAccount extends Validate
{
    protected $rule = [
        "bank" => "require|in:1",
        "busmod" => "require",
        "bbknbr" => "require|number|max:2",
        "accnbr" => "require",
        "dmanam" => "require",
        "ovrctl" => "require|in:Y,N,X",
        "bcktyp" => "require|in:Y,N,X",
        "clstyp" => "require|in:Y,N,X",
        "lmtflg" => "require|in:Y,N,X",
        "ballmt" => "checkLmtflg"
    ];

    protected $message = [
        "bank.require" => "请选择需要绑定的银行",
        "bank.in" => "非法银行类型",
        "busmod.require" => "请选择业务模式",
        "bbknbr.require" => "请输入分行号",
        "bbknbr.number" => "分行号必须为数字",
        "bbknbr.max" => "分行号长度最多为2",
        "accnbr.require" => "请输入结算号",
        "dmanam.require" => "请输入记账子单元名称",
        "ovrctl.require" => "请选择是否可透支",
        "ovrctl.in" => "是否可透支类型非法",
        "bcktyp.require" => "请选择支付失败退回方式",
        "bcktyp.in" => "支付失败退回方式类型非法",
        "clstyp.require" => "请选择余额非零时是否可关闭",
        "clstyp.in" => "余额非零时是否可关闭类型非法",
        "lmtflg.require" => "请选择是否设置收款限额",
        "lmtflg.in" => "是否设置收款限额类型非法",
        "ballmt.checkLmtflg" => "余额上限额度请输入大于0的数"
    ];

    protected $scene = [
        "zhaohang" => [
            'bank','busmod',"bbknbr",'accnbr','dmanam','ovrctl','bcktyp','clstyp','lmtflg','ballmt'
        ],
    ];

    protected function checkLmtflg($value,$rule = '',$data = '',$field = ''){
        if($data['lmtflg'] == 'Y'){
            if($value > 0){
                return true;
            }else{
                return false;
            }
        }else{
            return true;
        }
    }
}