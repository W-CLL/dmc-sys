<?php

/**
 * 预审任务配置文件
 * 针对并发请求和风控重试优化
 */

return [
    // 数据库分块配置
    'database' => [
        'chunk_size' => 30, // 每次处理的数据量
    ],

    // HTTP请求配置
    'http' => [
        'max_concurrency' => 5,  // 最大并发数
        'request_timeout' => 10, // 请求超时时间（秒）
    ],

    // 重试配置
    'retry' => [
        'max_retries' => 5,           // 最大重试次数
        'max_risk_control_retries' => 3,  // 风控最大重试次数
        'risk_control_retry_delay' => 1000, // 风控重试初始延迟（毫秒）
        // 风控相关错误码（可根据实际API调整）
        'risk_control_codes' => [
            10003, // 请求过于频繁
            10004, // 风控拦截
            10005, // 风险操作
            10007, // 账号风控
            10008, // 素材风控
        ],
    ],
];
