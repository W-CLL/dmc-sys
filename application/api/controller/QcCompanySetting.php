<?php

namespace app\api\controller;

use app\admin\model\Company;
use app\admin\model\CompanySetting;
use app\common\controller\Api;


/**
 * 广告投放数据相关定时任务类
 */
class QcCompanySetting extends Api
{

    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';

    /**
     * 同步客户公司设置到设置表
     * @return void
     * @throws \Exception
     */
    public function syncCompanyNameToCompanySetting()
    {
        $companyModel = new Company();
        $companySettingModel = new CompanySetting();
        $companyNames = $companySettingModel->column('company_name');
        $companyNameList = $companyModel->where(['company_name' => ['not in', $companyNames]])->group('company_name')->column('company_name');
        $insert = [];
        if ($companyNameList) {
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
        }
    }

}