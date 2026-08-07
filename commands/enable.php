<?php
/**
 * 技能启用命令 (enable/jifa)
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 功能：
 * - 查看当前启用的技能映射
 * - 启用/取消技能映射
 * - 列出所有可用的技能种类
 * 
 * 用法：
 *   enable                    # 查看当前启用的技能
 *   enable ?                  # 查看所有可用的技能种类
 *   enable dodge moshenbu     # 将dodge映射到moshenbu
 *   enable dodge none         # 取消dodge的映射
 */

require_once HELPER_PATH . 'SkillManager.php';

// 定义有效的技能类型及其描述
$VALID_TYPES = [
    "unarmed"    => "拳脚",
    "sword"      => "剑法",
    "blade"      => "刀法",
    "stick"      => "棍法",
    "staff"      => "杖法",
    "throwing"   => "暗器",
    "force"      => "内功",
    "parry"      => "招架",
    "dodge"      => "轻功",
    "spells"     => "法术",
    "whip"       => "鞭法",
    "spear"      => "枪法",
    "axe"        => "斧法",
    "mace"       => "锏法",
    "fork"       => "叉法",
    "rake"       => "钯法",
    "archery"    => "弓箭",
    "hammer"     => "锤法",
    "magic"      => "魔法",
    "literate"   => "读书写字",
    "buddhism"   => "佛法",
    "daoism"     => "道法",
    "taiyi"      => "太乙",
];

function cmd_enable(int $charId, string $param = ''): array {
    global $VALID_TYPES;
    
    $me = CharacterModel::find($charId);
    
    if (!$me) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 解析参数（支持-check格式，但当前版本暂不实现wizard检查）
    $check = '';
    $arg = $param;
    if (!empty($param)) {
        if (preg_match('/^-(\w+)\s+(.*)/', $param, $matches)) {
            $check = $matches[1];
            $arg = $matches[2];
        }
    }
    
    // 1. 查看当前启用的技能（无参数或-check）
    if (empty($arg) || $check === 'check') {
        return viewEnabledSkills($charId, $me);
    }
    
    // 2. 列出所有可用技能种类
    if ($arg === '?') {
        return listSkillTypes($charId);
    }
    
    // 3. 启用/取消技能映射
    $parts = preg_split('/\s+/', trim($arg), 2);
    if (count($parts) !== 2) {
        return [
            'success' => false,
            'message' => "指令格式：enable [<技能种类> <技能名称>|none]\n例如：enable dodge moshenbu"
        ];
    }
    
    [$ski, $mapTo] = $parts;
    
    // 验证技能种类
    if (!isset($VALID_TYPES[$ski])) {
        return [
            'success' => false,
            'message' => "没有这个技能种类，用 enable ? 可以查看有哪些种类。\n"
        ];
    }
    
    // 取消映射
    if ($mapTo === 'none') {
        SkillManager::mapSkill($charId, $ski, null);
        
        // 清除临时状态（perf_quick等）
        clearSkillTempState($charId);
        
        // 特殊处理：spells/force/magic切换时重置相关属性
        $switchMsg = handleSpecialSkillSwitch($charId, $me, $ski, $mapTo);
        
        $output = HIGRN . 'Ok.' . NOR . "\n你取消了" . HIYEL . $VALID_TYPES[$ski] . '(' . $ski . ')' . NOR . "的技能映射。";
        if ($switchMsg) {
            $output .= "\n" . $switchMsg;
        }
        
        return [
            'success' => true,
            'type' => 'skill_unmapped',
            'output' => $output
        ];
    }
    
    // 映射到自身（不需要enable），但 buddhism/daoism/literate/taiyi 除外
    $selfEnableTypes = ['buddhism', 'daoism', 'literate', 'taiyi'];
    if ($mapTo === $ski && !in_array($ski, $selfEnableTypes)) {
        return [
            'success' => true,
            'message' => "「{$ski}」是所有" . $VALID_TYPES[$ski] . "的基础，不需要 enable。"
        ];
    }
    
    // 检查是否学会了该技能
    $skillLevel = SkillManager::querySkill($charId, $mapTo, true); // 查询原始等级
    if ($skillLevel <= 0) {
        return [
            'success' => false,
            'message' => "你不会这种技能。"
        ];
    }
    
    // 验证技能是否可以用于该用途
    $skillConfig = SkillManager::getSkillConfig($mapTo);
    if ($skillConfig && !empty($skillConfig['valid_enable'])) {
        $validEnable = is_string($skillConfig['valid_enable']) ? json_decode($skillConfig['valid_enable'], true) : $skillConfig['valid_enable'];
        if (!in_array($ski, $validEnable)) {
            return ['success' => false, 'message' => "这个技能不能用作{$VALID_TYPES[$ski]}。"];
        }
    }
    
    // 设置映射
    SkillManager::mapSkill($charId, $ski, $mapTo);
    
    // 清除临时状态（perf_quick等）
    clearSkillTempState($charId);
    
    // 特殊处理：spells/force/magic切换时重置相关属性
    $switchMsg = handleSpecialSkillSwitch($charId, $me, $ski, $mapTo);
    
    $mappedName = toChinese($mapTo);
    $output = HIGRN . 'Ok.' . NOR . "\n你将" . HIYEL . $VALID_TYPES[$ski] . '(' . $ski . ')' . NOR . 
               "映射到" . HICYN . $mappedName . '(' . $mapTo . ')' . NOR . "。";
    if ($switchMsg) {
        $output .= "\n" . $switchMsg;
    }
    
    return [
        'success' => true,
        'type' => 'skill_mapped',
        'output' => $output
    ];
}

/**
 * 查看当前启用的技能
 */
function viewEnabledSkills(int $charId, array $me): array {
    global $VALID_TYPES;
    
    // 从 character_skill_map 表获取技能映射
    $mapRows = Database::queryAll(
        "SELECT skill_type, mapped_skill FROM character_skill_map WHERE char_id = ?",
        [$charId]
    );
    $map = [];
    foreach ($mapRows as $row) {
        $map[$row['skill_type']] = $row['mapped_skill'];
    }
    
    if (empty($map)) {
        return [
            'success' => false,
            'message' => "你现在没有使用任何特殊技能。"
        ];
    }
    
    $output = [];
    $output[] = HIYEL . '以下是你目前使用中的特殊技能。' . NOR;
    $output[] = '';
    
    foreach ($VALID_TYPES as $skill => $desc) {
        // 检查是否有这个基础技能或者有映射
        $baseLevel = SkillManager::querySkill($charId, $skill, true);
        $mapped = $map[$skill] ?? null;
        
        // 如果没有基础技能也没有映射，跳过
        if ($baseLevel <= 0 && !$mapped) {
            continue;
        }
        
        $mappedName = $mapped ? toChinese($mapped) : '无';
        
        // 计算最终等级（包含映射）
        $finalLevel = SkillManager::querySkill($charId, $skill, false);
        
        // 格式化输出
        $skillInfo = sprintf("  %-20s：%-20s  有效等级：%4d",
            $desc . " ({$skill})",
            $mappedName,
            $finalLevel
        );
        
        $output[] = $skillInfo;
    }
    
    return [
        'success' => true,
        'type' => 'skill_list',
        'output' => implode("\n", $output)
    ];
}

/**
 * 列出所有可用的技能种类及该类型下的技能
 */
function listSkillTypes(int $charId): array {
    global $VALID_TYPES;
    
    $output = [];
    $output[] = HIYEL . '以下是可以使用特殊技能的种类：' . NOR;
    $output[] = '';
    
    // 按字母排序
    ksort($VALID_TYPES);
    
    foreach ($VALID_TYPES as $type => $desc) {
        $output[] = sprintf("  %s (%s)", $desc, $type);
        
        // 从数据库获取该类型下的技能
        $skills = SkillManager::getAllSkillsByType($type);
        if (!empty($skills)) {
            foreach ($skills as $skill) {
                $skillName = $skill['skill_id'] ?? '';
                $skillChinese = SkillManager::getSkillChineseName($skillName);
                $skillLevel = SkillManager::getSkillLevel($charId, $skillName);
                
                $output[] = sprintf("    %-20s  等级：%4d", $skillChinese, $skillLevel);
            }
            $output[] = '';
        }
    }
    
    return [
        'success' => true,
        'type' => 'skill_types',
        'output' => implode("\n", $output)
    ];
}

/**
 * 处理特殊技能切换（spells/force）
 * 参考原始项目的逻辑
 */
function handleSpecialSkillSwitch(int $charId, array $me, string $ski, string $mapTo): string {
    if ($ski === 'force') {
        if ($mapTo !== 'none') {
            // 切换内功，重置内力和最大内力
            $sql = "UPDATE characters SET `force` = 0, max_force = 0 WHERE id = ?";
            Database::execute($sql, [$charId]);
        }
        
        // 重新计算max_force
        $newMaxForce = SkillManager::queryMaxForce($charId);
        $sql = "UPDATE characters SET max_force = ? WHERE id = ?";
        Database::execute($sql, [$newMaxForce, $charId]);
        
        if ($mapTo !== 'none') {
            return "你改用另一种内功，内力必须重新修炼。";
        }
        
    } elseif ($ski === 'spells') {
        if ($mapTo !== 'none') {
            // 切换法术，重置法力和最大法力
            $sql = "UPDATE characters SET mana = 0, max_mana = 0 WHERE id = ?";
            Database::execute($sql, [$charId]);
        }
        
        // 重新计算max_mana
        $newMaxMana = SkillManager::queryMaxMana($charId);
        $sql = "UPDATE characters SET max_mana = ? WHERE id = ?";
        Database::execute($sql, [$newMaxMana, $charId]);
        
        if ($mapTo !== 'none') {
            return "你改用另一种法术，法力必须重新修炼。";
        }
        
    } elseif ($ski === 'magic') {
        if ($mapTo !== 'none') {
            // 切换魔法，重置灵力和最大灵力
            $sql = "UPDATE characters SET atman = 0, max_atman = 0 WHERE id = ?";
            Database::execute($sql, [$charId]);
        }
        
        // 重新计算max_atman
        $newMaxAtman = SkillManager::queryMaxAtman($charId);
        $sql = "UPDATE characters SET max_atman = ? WHERE id = ?";
        Database::execute($sql, [$newMaxAtman, $charId]);
        
        if ($mapTo !== 'none') {
            return "你改用另一种魔法，灵力必须重新修炼。";
        }
    }
    
    return '';
}

/**
 * 清除技能切换后的临时状态
 * 参考原始项目: me->delete_temp("perf_quick") 和 me->reset_action()
 */
function clearSkillTempState(int $charId): void {
    // 清除 perf_quick 临时状态（如果存在）
    $sql = "DELETE FROM character_temp_states WHERE char_id = ? AND state_key IN ('perf_quick', 'skill_action', 'perform_queue')";
    Database::execute($sql, [$charId]);
    
    // 清除 session 中的技能施展相关状态
    $performKey = "perform_{$charId}";
    if (isset($_SESSION[$performKey])) {
        unset($_SESSION[$performKey]);
    }
}

/**
 * 转换为中文名称
 * 优先从数据库获取，未找到则使用硬编码映射作为 fallback
 */
function toChinese(string $skillName): string {
    // 优先从数据库获取中文名称
    $chineseName = SkillManager::getSkillChineseName($skillName);
    if ($chineseName !== $skillName) {
        return $chineseName;
    }
    
    static $chineseNames = [
        // 基础技能（对齐原始项目 xyj2000-php config/chinese.php）
        'unarmed' => '扑击格斗之技',
        'sword' => '基本剑术',
        'blade' => '基本刀法',
        'stick' => '基本棍法',
        'staff' => '基本杖法',
        'spear' => '基本枪法',
        'axe' => '基本斧法',
        'whip' => '基础鞭术',
        'bow' => '基本弓箭',
        'dagger' => '短兵刃',
        'fork' => '基本叉法',
        'rake' => '基本钯法',
        'hammer' => '基本锤法',
        'mace' => '基本锏法',
        'archery' => '基本弓箭',
        'throwing' => '暗器使用',
        'dodge' => '基本轻功',
        'parry' => '拆招卸力之法',
        'force' => '内功心法',
        'spells' => '法术',
        'stealing' => '妙手空空之技',
        'literate' => '读书识字',
        'makeup' => '养颜术',
        'fuqin' => '抚琴之技',
        'jindouyun' => '筋斗云',
        // 具体流派
        'moshenbu' => '魔神步法',
        'hunyuan-yiqi' => '混元一气功',
        'taoism' => '天师正道',
    ];
    
    return $chineseNames[$skillName] ?? $skillName;
}

