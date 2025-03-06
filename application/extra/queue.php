<?php
use think\Env;
return [
    'connector'  => 'Redis',          // Redis 驱动
    'expire'     => 0,             // 任务的过期时间，默认为60秒; 若要禁用，则设置为 null
    'default'    => 'default',    // 默认的队列名称
    'host'       => Env::get('redis.host'),       // redis 主机ip
    'port'       => Env::get('redis.port'),        // redis 端口
    'password'   => Env::get('redis.password'),             // redis 密码
    'select'     => Env::get('redis.select'),          // 使用哪一个 db，默认为 db0
    'timeout'    => 0,          // redis连接的超时时间
    'persistent' => false,
    'retry_after' => 180,
];
