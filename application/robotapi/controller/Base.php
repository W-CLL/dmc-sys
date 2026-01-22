<?php

namespace app\robotapi\controller;

use think\Cache;
use think\Db;
use think\Controller;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\DbException;
use think\Request;


class Base extends Controller
{
    protected $service;

    public function __construct(Request $request = null)
    {
        parent::__construct($request);

        // 自动绑定 service 类
        $serviceName = basename(str_replace('\\', '/', static::class));
        $serviceClass = "app\\robotapi\\service\\{$serviceName}";
        if (!class_exists($serviceClass)) {
            return build_json(50000, [], 'service error');
        }

        $this->service = new $serviceClass();

    }

    protected function _initialize()
    {
        $this->writeLog();
    }

    protected function check($account, $encrypted_data)
    {
        $info = Db::name("external_accounts")->where(["platform" => 'robot_api', "account" => $account])->find();
        if(!$info){
            return false;
        }
        if(decrypt($encrypted_data, $account)){
            return true;
        }
        return false;
    }


    /**
     * 请求频率限制
     * @param $account mixed 账号
     * @param string $action string 调起方法
     * @param int $maxRequests int 最大请求数
     * @param int $windowSeconds int 时间窗口（秒）
     * @return bool
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     * @throws DbException
     */
    protected function rateLimit($account, string $action, int $maxRequests = 10, int $windowSeconds = 60) : bool
    {
        $key = "rate_limit:".$account.":".$action;
        $redis = Cache::store('redis')->handler();

        // 获取当前时间戳
        $currentTime = time();
        $windowStart = $currentTime - $windowSeconds;

        // 删除过期请求记录
        $redis->zRemRangeByScore($key, 0, $windowStart);

        // 获取当前请求数
        $requestCount = $redis->zCard($key);

        if ($requestCount >= $maxRequests) {
            return false; // 超出限制
        }

        // 添加当前请求时间到有序集合
        $redis->zAdd($key, $currentTime, $currentTime);
        $redis->expire($key, $windowSeconds); // 设置过期时间

        return true;
    }


    /**
     * 处理请求
     * @param int $validationType int 参数验证类型
     * @param string $serviceMethod string service 方法名
     * @param string $successMsg string 成功提示信息
     * @return string json
     */
    protected function handleRequest($validationType, $serviceMethod, $successMsg = '')
    {
        // 1. 获取账户和加密数据
        $method = $this->request->method(); // 获取当前请求方法（POST/PUT/DELETE）
        $account = $this->request->{$method}('account');
        $encrypted_data = $this->request->{$method}('data');

        // 2. 请求频率限制
        if (!$this->rateLimit($account, __FUNCTION__)) {
            return build_json(40029, [], '请求过多');
        }

        // 3. 账户身份验证
        if (!$this->check($account, $encrypted_data)) {
            return build_json(40002, [], '帐户或encrypted_data不正确');
        }

        // 4. 解密数据
        $data = json_decode(decrypt($encrypted_data, $account), true);

        // 5. 参数验证
        $validate = $this->service->validateParam($data, $validationType);
        if ($validate !== true) {
            return build_json(201, [], $validate[1]);
        }

        // 6. 调用 service 方法
        $res = $this->service->{$serviceMethod}($data);
        if ($res === false) {
            return build_json(50000, [], '操作过程出现问题');
        }

        // 7. 返回成功响应
        $dataResponse = is_array($res) ? encryption($res, $account) : [];
        return build_json(200, $dataResponse, $successMsg);
    }




    /**
     * 写入日志
     */
    private function writeLog()
    {
        $calledClass = get_class($this);
        $controller = $this->request->controller(); // 控制器名
        $action = $this->request->action();         // 方法名
        $url = $this->request->url();               // 请求的URL
        $method = $this->request->method();         // 请求方法
        $params = json_encode($this->request->param()); // 请求参数

        // 优化获取真实 IP 的方式
        $ip = $this->request->ip() ?: $this->request->server('REMOTE_ADDR');

        // 尝试从 HTTP_X_FORWARDED_FOR 获取真实 IP（适用于反向代理）
        if ($this->request->server('HTTP_CLIENT_IP')) {
            $ip = $this->request->server('HTTP_CLIENT_IP');
        } elseif ($this->request->server('HTTP_X_FORWARDED_FOR')) {
            $ip = $this->request->server('HTTP_X_FORWARDED_FOR');
        } elseif ($this->request->server('REMOTE_ADDR')) {
            $ip = $this->request->server('REMOTE_ADDR');
        }

        // 如果有多个IP，取第一个（防止伪造）
        if (strpos($ip, ',') !== false) {
            $ip = explode(',', $ip)[0];
        }

        $ip = trim($ip);

        // 得考虑并发情况
        return Db::name('request_logs')->insert([
            'namespace' => $calledClass,
            'controller' => $controller,
            'action' => $action,
            'ip' => $ip,
            'url' => $url,
            'method' => $method,
            'params' => $params,
            'time' => time()
        ]);
    }
}