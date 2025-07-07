<?php
/**
 * 动态比例控制配置
 * 用于控制公司操作次数的动态计算，避免比例无限上升
 */

return [
    // 全域推广配置
    'global' => [
        'max_percentage' => 400,        // 最大比例限制（400%）
        'trigger_percentage' => 200,    // 触发动态计算的比例（200%）
        'min_daily_add' => 5,          // 最小每日添加量
        'max_daily_add' => 20,         // 最大每日添加量
        'conservative_factor' => 1.0,   // 保守系数（1.0 = 正常）
        'min_active_operations' => 1,   // 达到上限时的最小活跃度操作次数
        'max_active_operations' => 20,  // 达到上限时的最大活跃度操作次数
    ],
    
    // RPA任务配置（更保守）
    'rpa' => [
        'max_percentage' => 400,        // 最大比例限制（400%）
        'trigger_percentage' => 200,    // 触发动态计算的比例（200%）
        'min_daily_add' => 3,          // 最小每日添加量（比全域少）
        'max_daily_add' => 15,         // 最大每日添加量（比全域少）
        'conservative_factor' => 0.8,   // 保守系数（0.8 = 更保守）
        'min_active_operations' => 1,   // 达到上限时的最小活跃度操作次数
        'max_active_operations' => 15,  // 达到上限时的最大活跃度操作次数（比全域少）
    ],
    
    // 标准推广配置
    'standard' => [
        'max_percentage' => 350,        // 最大比例限制（350%，比全域低）
        'trigger_percentage' => 180,    // 触发动态计算的比例（180%）
        'min_daily_add' => 3,          // 最小每日添加量
        'max_daily_add' => 12,         // 最大每日添加量
        'conservative_factor' => 0.9,   // 保守系数（0.9 = 稍保守）
        'min_active_operations' => 1,   // 达到上限时的最小活跃度操作次数
        'max_active_operations' => 12,  // 达到上限时的最大活跃度操作次数（比全域少）
    ],
    
    // 特殊公司配置（可以覆盖默认配置）
    'special_companies' => [
        // 示例：某些公司需要更严格的控制
        // '公司名称' => [
        //     'max_percentage' => 300,
        //     'min_daily_add' => 2,
        //     'max_daily_add' => 8,
        // ],
    ],
    
    // 活跃度策略配置
    'activity_strategy' => [
        'enable_min_activity' => true,     // 是否启用最小活跃度策略
        'activity_description' => '即使达到最大比例限制，也要保持账户活跃度，避免被标记为异常',
        'random_factor' => true,            // 是否使用随机因子
        'time_based_adjustment' => false,   // 是否基于时间调整（未来功能）
    ],

    // 保底策略配置
    'fallback_strategy' => [
        'enable' => true,                   // 启用保底策略
        'description' => '当理想添加量为0时，根据剩余空间提供保底操作次数',

        // 全域任务保底策略
        'global' => [
            'high_space_threshold' => 50,   // 高剩余空间阈值（%）
            'medium_space_threshold' => 20, // 中等剩余空间阈值（%）
            'high_space_divisor' => 50,     // 高空间时的除数
            'medium_space_divisor' => 20,   // 中等空间时的除数
            'low_space_divisor' => 10,      // 低空间时的除数
            'medium_space_multiplier' => 2, // 中等空间时的倍数
        ],

        // RPA任务保底策略（更保守）
        'rpa' => [
            'high_space_threshold' => 50,   // 高剩余空间阈值（%）
            'medium_space_threshold' => 20, // 中等剩余空间阈值（%）
            'high_space_divisor' => 60,     // 高空间时的除数（更保守）
            'medium_space_divisor' => 25,   // 中等空间时的除数（更保守）
            'low_space_divisor' => 15,      // 低空间时的除数（更保守）
            'medium_space_multiplier' => 1, // 中等空间时的倍数（更保守）
        ],
    ],

    // 调试配置
    'debug' => [
        'enable_logging' => true,       // 是否启用详细日志
        'log_threshold' => 300,         // 当比例超过此值时记录详细日志
        'alert_threshold' => 350,       // 当比例超过此值时发出警告
        'log_activity_operations' => true, // 是否记录活跃度操作
    ],
    
    // 安全配置
    'safety' => [
        'emergency_stop_percentage' => 500,  // 紧急停止比例（500%）
        'daily_max_operations' => 1000,      // 单个广告主每日最大操作次数
        'company_max_operations' => 5000,    // 单个公司每日最大操作次数
    ],

    // 新策略配置
    'new_strategy' => [
        'description' => '分层控制策略：<200%正常追加，200%-400%检查操作空间，400%-600%保持活跃度，>600%停止操作',
        'normal_threshold' => 200,          // 正常追加阈值（%）
        'dynamic_threshold' => 400,         // 动态计算阈值（%）
        'activity_threshold' => 600,        // 活跃度阈值（%）
        'min_space_threshold' => 10,        // 最小操作空间阈值（%）
        'enable' => true,                   // 是否启用新策略
    ],

    // 执行控制配置
    'execution_control' => [
        'enable' => true,                   // 是否启用执行控制
        'description' => '防止重复执行和控制执行频率',
        'min_interval_hours' => 6,          // 最小执行间隔（小时）
        'daily_execution_limit' => 1,       // 每日最大执行次数
        'lunch_time_limit' => 10,           // 饭点时间最大任务数
    ],

    // 饭点时间控制配置
    'meal_time_control' => [
        'enable' => true,                   // 是否启用饭点时间控制
        'description' => '在饭点时间降低任务执行频率，模拟人的行为',

        // 饭点时间段配置
        'time_periods' => [
            // 午饭时间
            'lunch' => [
                'name' => '午饭时间',
                'start_hour' => 12,         // 开始时间12点
                'start_minute' => 0,        // 开始分钟0分
                'end_hour' => 13,           // 结束时间13点
                'end_minute' => 30,         // 结束分钟30分
                'enabled' => true,          // 是否启用
            ],
            // 晚饭时间
            'dinner' => [
                'name' => '晚饭时间',
                'start_hour' => 18,         // 开始时间18点
                'start_minute' => 0,        // 开始分钟0分
                'end_hour' => 19,           // 结束时间19点
                'end_minute' => 30,         // 结束分钟30分
                'enabled' => true,          // 是否启用
            ],
            // 宵夜时间
            'yexiao' => [
                'name' => '宵夜时间',
                'start_hour' => 23,         // 开始时间23点
                'start_minute' => 30,        // 开始分钟30分
                'end_hour' => 01,           // 结束时间01点
                'end_minute' => 30,         // 结束分钟30分
                'enabled' => true,          // 是否启用
            ],
        ],

        // Job执行层面的控制
        'max_tasks_per_minute' => 2,        // 饭点时间每分钟最多执行任务数
        'extra_delay_seconds' => 30,        // 饭点时间额外延时秒数
        'skip_probability' => 70,           // 饭点时间跳过执行的概率（%）
        'min_skip_delay' => 300,            // 跳过时最小延时秒数（5分钟）
        'max_skip_delay' => 900,            // 跳过时最大延时秒数（15分钟）

        // 任务生成层面的控制（保留原有功能）
        'min_tasks' => 1,                   // 饭点时间最少任务数
        'max_tasks' => 10,                  // 饭点时间最多任务数
        'random_selection' => true,         // 是否随机选择任务
    ],
];
