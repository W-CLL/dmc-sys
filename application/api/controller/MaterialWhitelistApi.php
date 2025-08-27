<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\model\MaterialWhitelist;
use think\Exception;

/**
 * 素材追投白名单API接口
 */
class MaterialWhitelistApi extends Api
{
    protected $noNeedLogin = '*';
    protected $noNeedRight = '*';

    /**
     * 获取启用状态的白名单公司列表
     * @return \think\Response
     */
    public function getActiveCompanies()
    {
        try {
            $companies = MaterialWhitelist::getActiveCompanies();
            
            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => $companies,
                'count' => count($companies)
            ]);
        } catch (Exception $e) {
            return json([
                'code' => 1,
                'msg' => '获取白名单失败：' . $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * 检查公司是否在白名单中
     * @return \think\Response
     */
    public function checkCompany()
    {
        $companyName = $this->request->param('company_name', '');
        
        if (empty($companyName)) {
            return json([
                'code' => 1,
                'msg' => '公司名称不能为空',
                'data' => false
            ]);
        }

        try {
            $isWhitelisted = MaterialWhitelist::isWhitelisted($companyName);
            
            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => $isWhitelisted
            ]);
        } catch (Exception $e) {
            return json([
                'code' => 1,
                'msg' => '检查失败：' . $e->getMessage(),
                'data' => false
            ]);
        }
    }

    /**
     * 批量检查公司是否在白名单中
     * @return \think\Response
     */
    public function batchCheckCompanies()
    {
        $companies = $this->request->param('companies', []);
        
        if (empty($companies) || !is_array($companies)) {
            return json([
                'code' => 1,
                'msg' => '公司名称列表不能为空',
                'data' => []
            ]);
        }

        try {
            $whitelistCompanies = MaterialWhitelist::getActiveCompanies();
            $result = [];
            
            foreach ($companies as $company) {
                $result[$company] = in_array($company, $whitelistCompanies);
            }
            
            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => $result
            ]);
        } catch (Exception $e) {
            return json([
                'code' => 1,
                'msg' => '批量检查失败：' . $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * 获取白名单详细信息（包含状态、备注等）
     * @return \think\Response
     */
    public function getWhitelistDetails()
    {
        try {
            $list = MaterialWhitelist::where('status', 1)
                ->field('id,company_name,status,remark,create_time,update_time')
                ->order('create_time desc')
                ->select();
            
            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => $list,
                'count' => count($list)
            ]);
        } catch (Exception $e) {
            return json([
                'code' => 1,
                'msg' => '获取详细信息失败：' . $e->getMessage(),
                'data' => []
            ]);
        }
    }

    /**
     * 添加白名单公司（API方式）
     * @return \think\Response
     */
    public function addCompany()
    {
        if (!$this->request->isPost()) {
            return json([
                'code' => 1,
                'msg' => '请使用POST方法',
                'data' => false
            ]);
        }

        $companyName = $this->request->param('company_name', '');
        $remark = $this->request->param('remark', '');

        if (empty($companyName)) {
            return json([
                'code' => 1,
                'msg' => '公司名称不能为空',
                'data' => false
            ]);
        }

        try {
            // 检查是否已存在
            $existing = MaterialWhitelist::where('company_name', $companyName)->find();
            if ($existing) {
                // 更新现有记录为启用状态
                $existing->status = 1;
                $existing->remark = $remark;
                $existing->update_time = time();
                $result = $existing->save();
                
                return json([
                    'code' => 0,
                    'msg' => '公司已存在，已更新为启用状态',
                    'data' => $result !== false
                ]);
            } else {
                // 创建新记录
                $result = MaterialWhitelist::create([
                    'company_name' => $companyName,
                    'status' => 1,
                    'remark' => $remark,
                    'create_time' => time(),
                    'update_time' => time()
                ]);
                
                return json([
                    'code' => 0,
                    'msg' => '添加成功',
                    'data' => $result !== false
                ]);
            }
        } catch (Exception $e) {
            return json([
                'code' => 1,
                'msg' => '添加失败：' . $e->getMessage(),
                'data' => false
            ]);
        }
    }

    /**
     * 移除白名单公司（API方式）
     * @return \think\Response
     */
    public function removeCompany()
    {
        if (!$this->request->isPost()) {
            return json([
                'code' => 1,
                'msg' => '请使用POST方法',
                'data' => false
            ]);
        }

        $companyName = $this->request->param('company_name', '');

        if (empty($companyName)) {
            return json([
                'code' => 1,
                'msg' => '公司名称不能为空',
                'data' => false
            ]);
        }

        try {
            $result = MaterialWhitelist::where('company_name', $companyName)->delete();
            
            return json([
                'code' => 0,
                'msg' => $result > 0 ? '移除成功' : '公司不在白名单中',
                'data' => $result > 0
            ]);
        } catch (Exception $e) {
            return json([
                'code' => 1,
                'msg' => '移除失败：' . $e->getMessage(),
                'data' => false
            ]);
        }
    }
}
