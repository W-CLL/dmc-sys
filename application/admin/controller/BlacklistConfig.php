<?php

namespace app\admin\controller;

use app\common\controller\Backend;
use think\Exception;
use think\Request;

/**
 * 黑名单配置管理
 *
 * @icon fa fa-list
 * @remark 管理黑名单公司配置文件，支持编辑、保存和自动去重
 */
class BlacklistConfig extends Backend
{
//    protected $noNeedRight = ['index', 'save'];

    public function _initialize()
    {
        parent::_initialize();
    }

    /**
     * 配置管理首页
     */
    public function index()
    {
        if ($this->request->isPost()) {
            return $this->save();
        }

        // 读取两个配置文件
        $configs = $this->getConfigs();
        
        // 确保数据正确传递
        $this->view->assign('configs', $configs);
        return $this->view->fetch();
    }

    /**
     * 获取配置数据（AJAX接口）
     */
    public function getConfigsAjax()
    {
        $configs = [];

        // 读取标准黑名单配置
        $standardFile = APP_PATH . 'api/controller/fission/black_company_config.php';
        if (file_exists($standardFile)) {
            $standardConfig = include $standardFile;
            $configs['standard'] = [
                'title' => '不愿意推送到计划公司名单',
                'file' => 'black_company_config.php',
                'data' => is_array($standardConfig) ? $standardConfig : [],
                'count' => is_array($standardConfig) ? count($standardConfig) : 0,
                'display_data' => is_array($standardConfig) ? implode('，', $standardConfig) : ''
            ];
        } else {
            $configs['standard'] = [
                'title' => '不愿意推送到计划公司名单',
                'file' => 'black_company_config.php',
                'data' => [],
                'count' => 0,
                'display_data' => ''
            ];
        }

        // 读取裂变黑名单配置
        $fissionFile = APP_PATH . 'api/controller/fission/black_company_config_fission.php';
        if (file_exists($fissionFile)) {
            $fissionConfig = include $fissionFile;
            $configs['fission'] = [
                'title' => '不愿意推送素材公司名单',
                'file' => 'black_company_config_fission.php',
                'data' => is_array($fissionConfig) ? $fissionConfig : [],
                'count' => is_array($fissionConfig) ? count($fissionConfig) : 0,
                'display_data' => is_array($fissionConfig) ? implode('，', $fissionConfig) : ''
            ];
        } else {
            $configs['fission'] = [
                'title' => '不愿意推送素材公司名单',
                'file' => 'black_company_config_fission.php',
                'data' => [],
                'count' => 0,
                'display_data' => ''
            ];
        }

        $this->success('获取成功', null, ['configs' => $configs]);
    }

    /**
     * 保存配置
     */
    public function save()
    {
        if (!$this->request->isPost()) {
            $this->error('非法请求');
        }

        $data = $this->request->post();
        
        try {
            // 处理标准黑名单配置
            if (isset($data['standard_config'])) {
                $this->saveConfig('black_company_config.php', $data['standard_config']);
            }

            // 处理裂变黑名单配置
            if (isset($data['fission_config'])) {
                $this->saveConfig('black_company_config_fission.php', $data['fission_config']);
            }

            $this->success('配置保存成功');
        } catch (Exception $e) {
            $this->error('保存失败：' . $e->getMessage());
        }
    }

    /**
     * 获取配置文件内容
     */
    private function getConfigs()
    {
        $configs = [];
        
        // 读取标准黑名单配置
        $standardFile = APP_PATH . 'api/controller/fission/black_company_config.php';
        if (file_exists($standardFile)) {
            $standardConfig = include $standardFile;
            $configs['standard'] = [
                'title' => '不愿意推送到计划公司名单',
                'file' => 'black_company_config.php',
                'data' => is_array($standardConfig) ? $standardConfig : [],
                'count' => is_array($standardConfig) ? count($standardConfig) : 0,
                'display_data' => is_array($standardConfig) ? implode('，', $standardConfig) : ''
            ];
        } else {
            $configs['standard'] = [
                'title' => '不愿意推送到计划公司名单',
                'file' => 'black_company_config.php',
                'data' => [],
                'count' => 0,
                'display_data' => ''
            ];
        }

        // 读取裂变黑名单配置
        $fissionFile = APP_PATH . 'api/controller/fission/black_company_config_fission.php';
        if (file_exists($fissionFile)) {
            $fissionConfig = include $fissionFile;
            $configs['fission'] = [
                'title' => '不愿意推送素材公司名单',
                'file' => 'black_company_config_fission.php',
                'data' => is_array($fissionConfig) ? $fissionConfig : [],
                'count' => is_array($fissionConfig) ? count($fissionConfig) : 0,
                'display_data' => is_array($fissionConfig) ? implode('，', $fissionConfig) : ''
            ];
        } else {
            $configs['fission'] = [
                'title' => '不愿意推送素材公司名单',
                'file' => 'black_company_config_fission.php',
                'data' => [],
                'count' => 0,
                'display_data' => ''
            ];
        }

        return $configs;
    }

    /**
     * 保存配置到文件
     */
    private function saveConfig($filename, $content)
    {
        $filePath = APP_PATH . 'api/controller/fission/' . $filename;

        // 处理内容：支持多种分隔符（换行、逗号、分号）
        $content = str_replace(['，', '；'], [',', ';'], $content); // 将中文标点转换为英文标点
        $lines = preg_split('/[\n\r,;]+/', $content);
        $companies = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                // 将英文括号转换为中文括号
                $line = $this->normalizeCompanyName($line);
                $companies[] = $line;
            }
        }

        // 去重并排序
        $companies = array_unique($companies);
        sort($companies);

        // 生成PHP配置文件内容，确保每个公司名称作为数组的一个元素
        $phpContent = "<?php\n";
        $phpContent .= "/**\n";
        $phpContent .= " * 黑名单公司配置文件\n";
        $phpContent .= " * 返回不需要处理的公司名称数组\n";
        $phpContent .= " */\n\n";
        $phpContent .= "return [\n";

        foreach ($companies as $company) {
            // 确保每个公司名称单独占一行，作为数组的一个元素
            $phpContent .= "    '" . addslashes($company) . "',\n";
        }

        $phpContent .= "];\n";

        // 写入文件
        if (file_put_contents($filePath, $phpContent) === false) {
            throw new Exception("无法写入文件：{$filename}");
        }

        return true;
    }

    /**
     * 规范化公司名称 - 将英文括号转换为中文括号
     */
    private function normalizeCompanyName($companyName)
    {
        // 将英文括号转换为中文括号
        $companyName = str_replace(['(', ')'], ['（', '）'], $companyName);

        // 去除首尾空格
        $companyName = trim($companyName);

        return $companyName;
    }

    /**
     * 获取配置文件统计信息
     */
    public function getStats()
    {
        $configs = $this->getConfigs();

        $stats = [
            'standard_count' => $configs['standard']['count'],
            'fission_count' => $configs['fission']['count'],
            'total_count' => $configs['standard']['count'] + $configs['fission']['count']
        ];

        return json($stats);
    }

    /**
     * 测试页面 - 用于验证功能
     */
    public function test()
    {
        $configs = $this->getConfigs();

        echo "<h1>黑名单配置管理测试</h1>";
        echo "<h2>标准推广黑名单配置</h2>";
        echo "<p>文件：{$configs['standard']['file']}</p>";
        echo "<p>记录数：{$configs['standard']['count']}</p>";
        echo "<h3>前10条记录：</h3>";
        echo "<ul>";
        for ($i = 0; $i < min(10, count($configs['standard']['data'])); $i++) {
            echo "<li>" . htmlspecialchars($configs['standard']['data'][$i]) . "</li>";
        }
        echo "</ul>";

        echo "<h2>裂变推广黑名单配置</h2>";
        echo "<p>文件：{$configs['fission']['file']}</p>";
        echo "<p>记录数：{$configs['fission']['count']}</p>";
        echo "<h3>所有记录：</h3>";
        echo "<ul>";
        foreach ($configs['fission']['data'] as $company) {
            echo "<li>" . htmlspecialchars($company) . "</li>";
        }
        echo "</ul>";

        echo "<h2>测试去重功能</h2>";
        $testData = "公司A\n公司B\n公司A\n公司C\n公司B\n公司D";
        echo "<p>原始数据：</p>";
        echo "<pre>" . htmlspecialchars($testData) . "</pre>";

        $lines = explode("\n", $testData);
        $companies = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $companies[] = $line;
            }
        }
        $companies = array_unique($companies);
        sort($companies);

        echo "<p>去重排序后：</p>";
        echo "<pre>" . htmlspecialchars(implode("\n", $companies)) . "</pre>";

        echo "<h2>测试括号转换功能</h2>";
        $testBrackets = "北京科技(集团)有限公司\n上海网络(技术)公司\n深圳电子(商务)有限公司";
        echo "<p>原始数据（英文括号）：</p>";
        echo "<pre>" . htmlspecialchars($testBrackets) . "</pre>";

        $bracketLines = explode("\n", $testBrackets);
        $normalizedCompanies = [];
        foreach ($bracketLines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $normalizedCompanies[] = $this->normalizeCompanyName($line);
            }
        }

        echo "<p>转换后（中文括号）：</p>";
        echo "<pre>" . htmlspecialchars(implode("\n", $normalizedCompanies)) . "</pre>";

        echo "<p><a href='" . url('blacklist_config/index') . "'>返回管理页面</a></p>";
    }
}
