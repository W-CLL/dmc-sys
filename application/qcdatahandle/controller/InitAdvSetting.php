<?php

namespace app\qcdatahandle\controller;

use app\admin\model\Company;
use think\Controller;
use app\admin\model\CompanySetting;

class InitAdvSetting extends Controller
{

    /**
     * 初始化一次公司设置，默认百分比是10
     * @return void
     * @throws \Exception
     */
    public function syncCompanyNameToCompanySetting()
    {
        $companyModel = new Company();
        $companyNameList = $companyModel->group('company_name')->column('company_name');
        $insert = [];
        foreach ($companyNameList as $item) {
            $insert[] = [
                'company_name' => $item,
                'percentage' => 10
            ];
        }
        if (!empty($insert)) {
            $companySettingModel = new CompanySetting();
            $res = $companySettingModel->saveAll($insert);
            if ($res) {
                echo "已全部处理";
            }

        }
        die;
    }
}