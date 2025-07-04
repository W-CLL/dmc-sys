<?php

namespace app\common\controller;

use think\Cache;

/**
 * 计划名称修改规则管理器
 * 实现多种修改规则的循环使用：第一次修改，第二次还原，每次修改规则不同
 */
class NameRuleManager
{
    /**
     * 修改规则配置
     */
    private static $rules = [
        // 规则1：时间戳标记
        'timestamp' => [
            'name' => '时间戳标记',
            'modify_pattern' => '/\(\.\d+_\d+\.\)/',
            'modify_format' => '(.{md_His}.)',
            'restore_pattern' => '/\(\.\d+_\d+\.\)/',
        ],

        // 规则2：序号标记
        'sequence' => [
            'name' => '序号标记',
            'modify_pattern' => '/\[\d+\]/',
            'modify_format' => '[{sequence}]',
            'restore_pattern' => '/\[\d+\]/',
        ],

        // 规则3：字母标记
        'letter' => [
            'name' => '字母标记',
            'modify_pattern' => '/\{[A-Z]+\}/',
            'modify_format' => '{{letter}}',
            'restore_pattern' => '/\{[A-Z]+\}/',
        ],

        // 规则4：下划线标记
        'underscore' => [
            'name' => '下划线标记',
            'modify_pattern' => '/_[a-z]+$/',
            'modify_format' => '_{underscore}',
            'restore_pattern' => '/_[a-z]+$/',
        ],

        // 规则5：数字点号标记
        'dot' => [
            'name' => '数字点号标记',
            'modify_pattern' => '/\d\.$/',      // 数字+点号
            'modify_format' => '{number_dot}',
            'restore_pattern' => '/\d\.$/',     // 数字+点号
        ],
    ];

    /**
     * 获取当前应该使用的规则
     * @param string $advId 广告主ID
     * @param string $objId 计划ID
     * @return array
     */
    public static function getCurrentRule($advId, $objId)
    {
        $cacheKey = "name_rule_{$advId}_{$objId}";
        $currentRuleIndex = Cache::get($cacheKey, 0);
        
        $ruleKeys = array_keys(self::$rules);
        $ruleKey = $ruleKeys[$currentRuleIndex % count($ruleKeys)];
        
        return [
            'key' => $ruleKey,
            'rule' => self::$rules[$ruleKey],
            'index' => $currentRuleIndex
        ];
    }

    /**
     * 更新规则索引（切换到下一个规则）
     * @param string $advId 广告主ID
     * @param string $objId 计划ID
     */
    public static function updateRuleIndex($advId, $objId)
    {
        $cacheKey = "name_rule_{$advId}_{$objId}";
        $currentIndex = Cache::get($cacheKey, 0);
        $newIndex = $currentIndex + 1;
        
        // 缓存7天
        Cache::set($cacheKey, $newIndex, 86400 * 7);
        
        return $newIndex;
    }

    /**
     * 检查计划名称是否已被修改
     * @param string $name 计划名称
     * @return array [是否已修改, 匹配的规则key, 匹配的内容]
     */
    public static function checkNameModified($name)
    {
        foreach (self::$rules as $key => $rule) {
            if (preg_match($rule['modify_pattern'], $name, $matches)) {
                return [true, $key, $matches[0] ?? ''];
            }
        }
        return [false, null, ''];
    }

    /**
     * 生成修改后的名称
     * @param string $originalName 原始名称
     * @param array $rule 规则配置
     * @param string $advId 广告主ID
     * @param string $objId 计划ID
     * @return string
     */
    public static function generateModifiedName($originalName, $rule, $advId, $objId)
    {
        $format = $rule['modify_format'];
        
        switch ($rule['key'] ?? '') {
            case 'timestamp':
                $marker = '(.' . date('md_His') . '.)';
                break;

            case 'sequence':
                $sequence = self::getSequenceNumber($advId, $objId);
                $marker = "[{$sequence}]";
                break;

            case 'letter':
                $letter = self::getRandomLetter();
                $marker = "{{$letter}}";
                break;

            case 'underscore':
                $underscore = self::getRandomUnderscore();
                $marker = "_{$underscore}";
                return $originalName . $marker; // 下划线规则添加到末尾

            case 'dot':
                // 使用随机数字+点号的标记，避免与原名称冲突
                $randomNumber = rand(1, 9);
                $marker = $randomNumber . '.'; // 数字+点号，独特且不易冲突
                return $originalName . $marker; // 点号规则添加到末尾

            default:
                $marker = "(." . date('md_His') . ".)";
        }
        
        return $originalName . $marker;
    }

    /**
     * 还原名称（移除修改标记）
     * @param string $modifiedName 已修改的名称
     * @param array $rule 规则配置
     * @return string
     */
    public static function restoreName($modifiedName, $rule)
    {
        return preg_replace($rule['restore_pattern'], '', $modifiedName);
    }

    /**
     * 获取序号
     * @param string $advId 广告主ID
     * @param string $objId 计划ID
     * @return int
     */
    private static function getSequenceNumber($advId, $objId)
    {
        $cacheKey = "sequence_{$advId}_{$objId}";
        $sequence = Cache::get($cacheKey, 0) + 1;
        Cache::set($cacheKey, $sequence, 86400); // 缓存1天
        return $sequence;
    }

    /**
     * 获取随机字母
     * @return string
     */
    private static function getRandomLetter()
    {
        $letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
        $count = rand(1, 2);
        $result = '';
        for ($i = 0; $i < $count; $i++) {
            $result .= $letters[array_rand($letters)];
        }
        return $result;
    }

    /**
     * 获取随机下划线后缀
     * @return string
     */
    private static function getRandomUnderscore()
    {
        $suffixes = ['new', 'opt', 'pro', 'adv', 'test', 'run', 'go', 'up', 'top', 'max'];
        return $suffixes[array_rand($suffixes)];
    }

    /**
     * 获取所有规则信息（用于调试）
     * @return array
     */
    public static function getAllRules()
    {
        return self::$rules;
    }

    /**
     * 重置规则索引（用于测试）
     * @param string $advId 广告主ID
     * @param string $objId 计划ID
     */
    public static function resetRuleIndex($advId, $objId)
    {
        $cacheKey = "name_rule_{$advId}_{$objId}";
        Cache::rm($cacheKey);
    }

    /**
     * 获取规则使用统计
     * @param string $advId 广告主ID
     * @param string $objId 计划ID
     * @return array
     */
    public static function getRuleStats($advId, $objId)
    {
        $cacheKey = "name_rule_{$advId}_{$objId}";
        $currentIndex = Cache::get($cacheKey, 0);
        $ruleKeys = array_keys(self::$rules);
        
        return [
            'total_modifications' => $currentIndex,
            'current_rule_index' => $currentIndex % count($ruleKeys),
            'current_rule_key' => $ruleKeys[$currentIndex % count($ruleKeys)],
            'next_rule_key' => $ruleKeys[($currentIndex + 1) % count($ruleKeys)],
        ];
    }
}
