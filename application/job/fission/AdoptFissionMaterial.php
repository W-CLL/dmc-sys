<?php

namespace app\job\fission;

use app\common\model\Queue;
use app\common\model\viral_fission\FissionDeriveMaterial;
use app\common\model\viral_fission\FissionMaterialTask;
use jlqc\FundManagement;
use think\Exception;

class AdoptFissionMaterial extends BaseJob
{

    public function __construct()
    {
        $this->queueRecordModelName = '\app\common\model\viral_fission\FissionQueue';
    }

    protected function getJobName(): string
    {
        return "采纳裂变素材";
    }

    protected function getQueueName(): string
    {
        return 'adoptFissionMaterial';
    }

    /**
     * @throws Exception
     */
    protected function doJob($data)
    {
        $adv_id = $data['adv_id'];

        // 检查广告主是否在黑名单中
        if ($this->isAdvInBlacklist($adv_id)) {
            echo "跳过黑名单广告主: {$adv_id}\n";
            return true;
        }

        $derive_model = new FissionDeriveMaterial();
        $param = [
            'advertiser_id' => (int)$adv_id,
            'video_ids' =>array_values( $data['video_id'])
        ];

        $res = FundManagement::adopt_material($param);
        if ($res['message'] == "OK" && $res['data']['results']) {
           foreach ($res['data']['results'] as $item){
               $material_info = $item['material_info'];
                $update_data = [
                    'material_info'=>json_encode($material_info),
                    'adopt_video_url'=>$material_info["video_url"],
                    'adopt_material_id'=>$material_info["material_id"],
                    'adopt_status_code'=>$item["status_code"],
                    'adopt_status_message'=>$item['status_message'],
                ];
               $result = FundManagement::upload_image([
                    'advertiser_id' => (int)$adv_id,
                    'upload_type' => 'UPLOAD_BY_URL',
                    'image_url' => $material_info['cover_url']
                ]);
               if($result['message'] == "OK" && $result['code'] == 0){
                   $update_data['adopt_cover_id'] = $result['data']['id'];
               }
             $derive_model->where(['video_id'=>$item['video_id'],'adv_id'=>$adv_id])->update($update_data);

           }
           return true;
        }else{
            if($res == "接口异常"){
                \think\Queue::push('app\job\fission\AdoptFissionMaterial', [
                    'adv_id' => $adv_id,
                    'video_id' => $data['video_id'],
                ], 'adoptFissionMaterial');
                return true;
            }else{
                throw new Exception($res['message']." 请求id:".$res['request_id']);
            }
        }
    }

    /**
     * 检查广告主是否在黑名单中
     * @param string $advId 广告主ID
     * @return bool
     */
    private function isAdvInBlacklist($advId): bool
    {
        if (empty($advId)) {
            return false;
        }

        // 获取黑名单公司列表
        $blackCompanyList = $this->getBlackCompanyList();
        if (empty($blackCompanyList)) {
            return false;
        }

        // 获取广告主对应的公司名称
        $companyName = $this->getCompanyNameByAdvId($advId);
        if (empty($companyName)) {
            return false;
        }

        return in_array($companyName, $blackCompanyList);
    }

    /**
     * 获取黑名单公司列表
     * @return array
     */
    private function getBlackCompanyList(): array
    {
        $config_file_path = APP_PATH . 'api/controller/fission/black_company_config_fission.php';

        if (file_exists($config_file_path)) {
            try {
                $black_company_list = include $config_file_path;
                if (is_array($black_company_list) && !empty($black_company_list)) {
                    return $black_company_list;
                }
            } catch (\Exception $e) {
                echo "读取黑名单配置文件失败: " . $e->getMessage() . "\n";
            }
        }

        return [];
    }

    /**
     * 根据广告主ID获取公司名称
     * @param string $advId 广告主ID
     * @return string
     */
    private function getCompanyNameByAdvId($advId): string
    {
        try {
            $company = \think\Db::name('company')
                ->where('advertiser_id', $advId)
                ->value('company_name');

            return $company ?: '';
        } catch (\Exception $e) {
            echo "获取公司名称失败: " . $e->getMessage() . "\n";
            return '';
        }
    }
}