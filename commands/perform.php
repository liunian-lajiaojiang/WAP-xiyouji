<?php
/**
 * 特殊招式命令 (perform)
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 用法: perform <技能>/<招式> [目标]
 * 例如: perform snowsword/diezhang
 * 
 * 已重构: 从 skill_actions 表动态加载招式数据，
 * 替代原有的硬编码映射。保留 $LEGACY_PERFORM_MAP 作为 fallback。
 */
require_once HELPER_PATH . 'SkillManager.php';
require_once HELPER_PATH . 'CombatMessages.php';
require_once DAEMON_PATH . 'CombatDaemon.php';

// 向后兼容的 fallback 映射（数据库未迁移时使用）
$LEGACY_PERFORM_MAP = [
    // 三板斧
    'sanban-axe/sanban' => [
        'action_code' => 'sanban',
        'action_name' => '三板斧',
        'action_text' => '连环三斧，势大力沉！',
        'min_level' => 30,
        'damage' => 0,
        'dodge_mod' => 0,
        'parry_mod' => 0,
        'damage_type' => 'blunt',
        'force_cost' => 30,
        'mana_cost' => 0,
    ],
    
    // 霸王枪
    'bawang-qiang/qiangjian' => [
        'action_code' => 'qiangjian',
        'action_name' => '枪剑',
        'action_text' => '枪出如龙，剑气纵横！',
        'min_level' => 40,
        'damage' => 0,
        'dodge_mod' => 0,
        'parry_mod' => 0,
        'damage_type' => 'piercing',
        'force_cost' => 40,
        'mana_cost' => 0,
    ],
    
    // 解难指
    'jienan-zhi/storm' => [
        'action_code' => 'storm',
        'action_name' => '风暴',
        'action_text' => '指风如暴，势不可挡！',
        'min_level' => 50,
        'damage' => 0,
        'dodge_mod' => 0,
        'parry_mod' => 0,
        'damage_type' => 'hit',
        'force_cost' => 50,
        'mana_cost' => 0,
    ],
    
    // 轮回杖
    'lunhui-zhang/qifei' => [
        'action_code' => 'qifei',
        'action_name' => '起飞',
        'action_text' => '杖起飞舞，势若轮回！',
        'min_level' => 35,
        'damage' => 0,
        'dodge_mod' => 0,
        'parry_mod' => 0,
        'damage_type' => 'blunt',
        'force_cost' => 35,
        'mana_cost' => 0,
    ],
    
    // 火云枪
    'huoyun-qiang/qifei' => [
        'action_code' => 'qifei',
        'action_name' => '起飞',
        'action_text' => '枪势起飞，直冲云霄！',
        'min_level' => 30,
        'damage' => 0,
        'dodge_mod' => 0,
        'parry_mod' => 0,
        'damage_type' => 'piercing',
        'force_cost' => 30,
        'mana_cost' => 0,
    ],
    'huoyun-qiang/fire' => [
        'action_code' => 'fire',
        'action_name' => '火焰',
        'action_text' => '枪出火焰，灼热逼人！',
        'min_level' => 50,
        'damage' => 0,
        'dodge_mod' => 0,
        'parry_mod' => 0,
        'damage_type' => 'hit',
        'force_cost' => 0,
        'mana_cost' => 50,
    ],
    
    // 千钧棒
    'qianjun-bang/pili' => [
        'action_code' => 'pili',
        'action_name' => '霹雳',
        'action_text' => '棒势霹雳，威震天地！',
        'min_level' => 60,
        'damage' => 0,
        'dodge_mod' => 0,
        'parry_mod' => 0,
        'damage_type' => 'blunt',
        'force_cost' => 60,
        'mana_cost' => 0,
    ],
    'qianjun-bang/qiankun' => [
        'action_code' => 'qiankun',
        'action_name' => '乾坤',
        'action_text' => '棒转乾坤，扭转天地！',
        'min_level' => 80,
        'damage' => 0,
        'dodge_mod' => 0,
        'parry_mod' => 0,
        'damage_type' => 'blunt',
        'force_cost' => 80,
        'mana_cost' => 0,
    ],
    
    // 枯骨刀
    'kugu-blade/pozhan' => [
        'action_code' => 'pozhan',
        'action_name' => '破斩',
        'action_text' => '刀势破斩，锐不可当！',
        'min_level' => 40,
        'damage' => 0,
        'dodge_mod' => 0,
        'parry_mod' => 0,
        'damage_type' => 'slashing',
        'force_cost' => 40,
        'mana_cost' => 0,
    ],
    
    // 摩云手
    'moyun-shou/zhangxinlei' => [
        'action_code' => 'zhangxinlei',
        'action_name' => '掌心雷',
        'action_text' => '掌心雷起，轰然炸裂！',
        'min_level' => 50,
        'damage' => 0,
        'dodge_mod' => 0,
        'parry_mod' => 0,
        'damage_type' => 'hit',
        'force_cost' => 0,
        'mana_cost' => 50,
    ],
    
    // 月牙铲
    'yueya-chan/feicha' => [
        'action_code' => 'feicha',
        'action_name' => '飞铲',
        'action_text' => '铲势飞转，直取要害！',
        'min_level' => 35,
        'damage' => 0,
        'dodge_mod' => 0,
        'parry_mod' => 0,
        'damage_type' => 'piercing',
        'force_cost' => 35,
        'mana_cost' => 0,
    ],
    
    // 百花掌
    'baihua-zhang/flower' => [
        'action_code' => 'flower',
        'action_name' => '百花',
        'action_text' => '掌出百花，漫天花雨！',
        'min_level' => 40,
        'damage' => 0,
        'dodge_mod' => 0,
        'parry_mod' => 0,
        'damage_type' => 'hit',
        'force_cost' => 0,
        'mana_cost' => 40,
    ],
    
    // 雪山剑法
    'snowsword/diezhang' => [
        'action_code' => 'diezhang',
        'action_name' => '叠嶂',
        'action_text' => '剑光如山峦叠嶂，层层推进！',
        'min_level' => 30,
        'damage' => 0,
        'dodge_mod' => 0,
        'parry_mod' => 0,
        'damage_type' => 'slashing',
        'force_cost' => 30,
        'mana_cost' => 0,
    ],
    'snowsword/huifeng' => [
        'action_code' => 'huifeng',
        'action_name' => '回风',
        'action_text' => '剑势回旋，如狂风呼啸！',
        'min_level' => 50,
        'damage' => 0,
        'dodge_mod' => 0,
        'parry_mod' => 0,
        'damage_type' => 'slashing',
        'force_cost' => 50,
        'mana_cost' => 0,
    ],
    'snowsword/wuxue' => [
        'action_code' => 'wuxue',
        'action_name' => '舞雪',
        'action_text' => '剑舞飞雪，漫天剑气！',
        'min_level' => 80,
        'damage' => 0,
        'dodge_mod' => 0,
        'parry_mod' => 0,
        'damage_type' => 'slashing',
        'force_cost' => 80,
        'mana_cost' => 0,
    ],
    
    // 龙形搏斗
    'dragonfight/sheshen' => [
        'action_code' => 'sheshen',
        'action_name' => '蛇身',
        'action_text' => '身如蛇行，矫若游龙！',
        'min_level' => 50,
        'damage' => 0,
        'dodge_mod' => 0,
        'parry_mod' => 0,
        'damage_type' => 'hit',
        'force_cost' => 50,
        'mana_cost' => 0,
    ],
    
    // 地狱火鞭
    'hellfire-whip/three' => [
        'action_code' => 'three',
        'action_name' => '三连',
        'action_text' => '鞭势三连，连环灼烧！',
        'min_level' => 60,
        'damage' => 0,
        'dodge_mod' => 0,
        'parry_mod' => 0,
        'damage_type' => 'hit',
        'force_cost' => 0,
        'mana_cost' => 60,
    ],
];

function cmd_perform(int $charId, string $param = ''): array {
    global $LEGACY_PERFORM_MAP;
    
    $me = CharacterModel::find($charId);
    
    if (!$me) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // is_busy() 检查
    if (is_player_busy($charId)) {
        return ['success' => false, 'message' => '你正忙着呢，没空施展技能。'];
    }
    
    // 检查参数
    if (empty($param)) {
        return ['success' => false, 'message' => '你想使用什么特殊招式？格式: perform <技能>/<招式> [目标]'];
    }
    
    // 解析参数: skill/action [target] 或 skill/action on target
    if (strpos($param, '/') === false) {
        // 只有技能名，没有招式，列出可用招式
        $skillName = strtolower($param);
        $allActions = SkillManager::getSkillActions($skillName);
        
        if (empty($allActions)) {
            // 数据库无数据，尝试从 fallback 中找
            $fallbackActions = [];
            foreach ($LEGACY_PERFORM_MAP as $key => $data) {
                if (strpos($key, $skillName . '/') === 0) {
                    $fallbackActions[] = $data;
                }
            }
            if (empty($fallbackActions)) {
                return ['success' => false, 'message' => "未找到技能「{$skillName}」的招式。"];
            }
            $allActions = $fallbackActions;
        }
        
        $skillChineseName = SkillManager::getSkillChineseName($skillName);
        $actionList = [];
        foreach ($allActions as $act) {
            $code = $act['action_code'] ?? '';
            $name = $act['action_name'] ?? $code;
            $minLv = $act['min_level'] ?? 0;
            $actionList[] = "  {$code}（{$name}）- 需要{$minLv}级";
        }
        
        return [
            'success' => false,
            'message' => "「{$skillChineseName}」可用招式:\n" . implode("\n", $actionList) . "\n格式: perform {$skillName}/<招式> [目标]"
        ];
    }
    
    // 分离技能名和剩余部分
    list($skillName, $rest) = explode('/', $param, 2);
    $skillName = strtolower(trim($skillName));
    $rest = trim($rest);
    
    // 从剩余部分解析招式代码和目标名
    $actionCode = '';
    $targetName = '';
    
    // 支持 "on" 分隔符（如: perform snowsword/diezhang on 妖怪）
    $onPos = stripos($rest, ' on ');
    if ($onPos !== false) {
        $actionCode = strtolower(trim(substr($rest, 0, $onPos)));
        $targetName = trim(substr($rest, $onPos + 4));
    } else {
        $spacePos = strpos($rest, ' ');
        if ($spacePos !== false) {
            $actionCode = strtolower(trim(substr($rest, 0, $spacePos)));
            $targetName = trim(substr($rest, $spacePos + 1));
        } else {
            $actionCode = strtolower($rest);
        }
    }
    
    if (empty($actionCode)) {
        return ['success' => false, 'message' => '你想使用什么招式？格式: perform <技能>/<招式> [目标]'];
    }
    
    $performKey = $skillName . '/' . $actionCode;
    
    // 从数据库动态加载招式
    $action = SkillManager::getPerformAction($skillName, $actionCode);
    
    // 数据库无数据时使用 fallback
    if (!$action) {
        if (isset($LEGACY_PERFORM_MAP[$performKey])) {
            $action = $LEGACY_PERFORM_MAP[$performKey];
        } else {
            // 精确匹配失败，尝试获取技能的所有招式列表供用户选择
            $allActions = SkillManager::getSkillActions($skillName);
            if (empty($allActions)) {
                // 也从 fallback 中找
                foreach ($LEGACY_PERFORM_MAP as $key => $data) {
                    if (strpos($key, $skillName . '/') === 0) {
                        $allActions[] = $data;
                    }
                }
            }
            if (empty($allActions)) {
                return ['success' => false, 'message' => "未找到技能「{$skillName}」的招式。"];
            }
            
            $skillChineseName = SkillManager::getSkillChineseName($skillName);
            $actionList = [];
            foreach ($allActions as $act) {
                $code = $act['action_code'] ?? '';
                $name = $act['action_name'] ?? $code;
                $minLv = $act['min_level'] ?? 0;
                $actionList[] = "  {$code}（{$name}）- 需要{$minLv}级";
            }
            
            return [
                'success' => false,
                'message' => "没有招式「{$actionCode}」。\n「{$skillChineseName}」可用招式:\n" . implode("\n", $actionList) . "\n格式: perform {$skillName}/<招式> [目标]"
            ];
        }
    }
    
    // 检查角色是否学会该技能
    $skillLevel = SkillManager::getSkillLevel($charId, $skillName);
    if ($skillLevel < 1) {
        $skillChineseName = SkillManager::getSkillChineseName($skillName);
        return ['success' => false, 'message' => "你还没有学会「{$skillChineseName}」。"];
    }
    
    // 检查 min_level
    $minLevel = $action['min_level'] ?? 0;
    if ($skillLevel < $minLevel) {
        $skillChineseName = SkillManager::getSkillChineseName($skillName);
        $actionName = $action['action_name'] ?? $actionCode;
        return ['success' => false, 'message' => "你的「{$skillChineseName}」等级不够，需要达到{$minLevel}级才能使用招式「{$actionName}」。"];
    }
    
    // ========== 检查武器类型要求（参考原始 perform.c）==========
    // 获取当前装备的武器类型
    $equippedWeapon = CombatDaemon::getEquippedWeapon($charId);
    $currentWeaponType = $equippedWeapon ? ($equippedWeapon['weapon_type'] ?? null) : null;
    
    // 获取技能配置，从 valid_learn 中解析武器类型要求（修复：原代码错误地使用了 learn_config）
    $skillConfig = SkillManager::getSkillConfig($skillName);
    $requiredWeaponType = null;
    
    // 从 valid_learn JSON 中获取 weapon_type（修复字段名）
    if ($skillConfig && !empty($skillConfig['valid_learn'])) {
        $learnConfig = is_string($skillConfig['valid_learn']) 
            ? json_decode($skillConfig['valid_learn'], true) 
            : $skillConfig['valid_learn'];
        $requiredWeaponType = $learnConfig['weapon_type'] ?? null;
    }
    
    // 如果技能需要特定武器类型，检查玩家是否装备了对应武器
    if ($requiredWeaponType && $requiredWeaponType !== 'unarmed') {
        if (!$currentWeaponType) {
            // 玩家空手，但技能需要武器
            $skillChineseName = SkillManager::getSkillChineseName($skillName);
            $weaponTypeNames = [
                'sword' => '剑', 'blade' => '刀', 'spear' => '枪', 'staff' => '杖',
                'stick' => '棒', 'whip' => '鞭', 'axe' => '斧', 'hammer' => '锤',
                'mace' => '锏', 'fork' => '叉', 'rake' => '钯'
            ];
            $weaponTypeName = $weaponTypeNames[$requiredWeaponType] ?? $requiredWeaponType;
            return ['success' => false, 'message' => "「{$skillChineseName}」需要装备{$weaponTypeName}类武器才能施展。"];
        }
        if ($currentWeaponType !== $requiredWeaponType) {
            // 武器类型不匹配
            $weaponName = $equippedWeapon['name'] ?? '当前武器';
            $skillChineseName = SkillManager::getSkillChineseName($skillName);
            $weaponTypeNames = [
                'sword' => '剑', 'blade' => '刀', 'spear' => '枪', 'staff' => '杖',
                'stick' => '棒', 'whip' => '鞭', 'axe' => '斧', 'hammer' => '锤',
                'mace' => '锏', 'fork' => '叉', 'rake' => '钯'
            ];
            $weaponTypeName = $weaponTypeNames[$requiredWeaponType] ?? $requiredWeaponType;
            return ['success' => false, 'message' => "「{$skillChineseName}」需要{$weaponTypeName}类武器，你当前装备的是{$weaponName}，无法施展此招式。"];
        }
    }
    
    // 检查内力/法力消耗
    $forceCost = $action['force_cost'] ?? 0;
    $manaCost = $action['mana_cost'] ?? 0;
    
    if ($forceCost > 0 && ($me['force'] ?? 0) < $forceCost) {
        return ['success' => false, 'message' => "你的内力不足，需要{$forceCost}点内力。"];
    }
    if ($manaCost > 0 && ($me['mana'] ?? 0) < $manaCost) {
        return ['success' => false, 'message' => "你的法力不足，需要{$manaCost}点法力。"];
    }
    
    // 判断是否在战斗中
    if (CombatDaemon::isInCombat($charId)) {
        // 场景A: 已在战斗中，对当前战斗目标使用招式
        $combatState = CombatDaemon::getCombatStatus($charId);
        if (!$combatState || empty($combatState['target_id'])) {
            return ['success' => false, 'message' => '你没有战斗目标。'];
        }
        
        $targetId = $combatState['target_id'];
        $target = NpcModel::find($targetId);
        
        if (!$target) {
            // 可能是玩家对手
            $target = CharacterModel::find($targetId);
        }
        
        if (!$target) {
            return ['success' => false, 'message' => '目标不存在。'];
        }
        
        // 执行perform动作
        return executePerformAction($charId, $me, $target, $skillName, $actionCode, $skillLevel, $action, $forceCost, $manaCost);
    }
    
    // 不在战斗中
    if (!empty($targetName)) {
        // 场景B: 非战斗状态，指定了目标
        $target = findTargetInRoomForPerform($charId, $targetName);
        
        if (!$target) {
            return ['success' => false, 'message' => "这里没有 {$targetName}。"];
        }
        
        // 检查是否是生物
        if ($target['type'] !== 'npc' && $target['type'] !== 'player') {
            return ['success' => false, 'message' => '看清楚一点，那并不是生物。'];
        }
        
        // 不能攻击自己
        if ($target['type'] === 'player' && $target['id'] == $charId) {
            return ['success' => false, 'message' => '你不能攻击自己。'];
        }
        
        // 检查当前房间是否禁止战斗
        $room = RoomModel::load($me['current_area'], $me['current_room']);
        if ($room && isset($room['no_fight']) && $room['no_fight']) {
            return ['success' => false, 'message' => HTML_HIRED . '这里禁止打斗！' . HTML_NOR];
        }
        
        // 发起战斗（kill模式，生死相搏）
        $startResult = CombatDaemon::startKill($charId, $target['id'], $target['type']);
        if (!$startResult['success']) {
            return $startResult;
        }
        
        // 获取目标完整数据
        if ($target['type'] === 'npc') {
            $targetEntity = NpcModel::find($target['id']);
            if ($targetEntity) {
                $targetEntity['type'] = 'npc';
            }
        } else {
            $targetEntity = CharacterModel::find($target['id']);
            if ($targetEntity) {
                $targetEntity['type'] = 'player';
            }
        }
        
        if (!$targetEntity) {
            return ['success' => false, 'message' => '目标不存在。'];
        }
        
        // 执行perform动作
        return executePerformAction($charId, $me, $targetEntity, $skillName, $actionCode, $skillLevel, $action, $forceCost, $manaCost);
    }
    
    // 场景C: 非战斗状态，没有指定目标（对空练习）
    return performPractice($charId, $me, $skillName, $actionCode, $skillLevel, $action, $forceCost, $manaCost);
}

/**
 * 执行perform动作
 * 
 * @param int $charId 角色ID
 * @param array $me 角色数据
 * @param array $target 目标数据
 * @param string $skillName 技能名
 * @param string $actionCode 招式代码
 * @param int $skillLevel 技能等级
 * @param array $action 招式数据（从数据库或fallback获取）
 * @param int $forceCost 内力消耗
 * @param int $manaCost 法力消耗
 */
function executePerformAction(int $charId, array $me, array $target, string $skillName, string $actionCode, int $skillLevel, array $action, int $forceCost, int $manaCost): array {
    // 获取目标名称（提前定义，供调试日志使用）
    $targetName = $target['name'] ?? '对方';
    
    // 从招式数据获取战斗数值
    $baseDamage = intval($action['damage'] ?? 0);
    $dodgeMod = intval($action['dodge_mod'] ?? 0);
    $parryMod = intval($action['parry_mod'] ?? 0);
    $damageType = $action['damage_type'] ?? 'hit';
    $actionText = $action['action_text'] ?? '';
    $actionName = $action['action_name'] ?? $actionCode;
    
    // 获取玩家装备的武器
    $equippedWeapon = CombatDaemon::getEquippedWeapon($charId);
    $weaponName = $equippedWeapon ? ($equippedWeapon['name'] ?? '武器') : '空手';
    
    // 如果数据库 damage 为0（未迁移或fallback），使用等级作为基础伤害
    if ($baseDamage === 0) {
        $baseDamage = intval($skillLevel * 1.5);
    }
    
    // 技能等级带来的额外伤害加成
    $levelBonus = intval($skillLevel * 0.2);
    
    // 闪避/招架修正影响实际伤害
    // dodge_mod > 0 表示招式更难被闪避（伤害增加）
    // parry_mod > 0 表示招式更难被招架（伤害增加）
    $dodgeBonus = intval($dodgeMod * 0.5);
    $parryBonus = intval($parryMod * 0.5);
    
    $totalDamage = $baseDamage + $levelBonus + $dodgeBonus + $parryBonus;
    
    // 确保最低伤害
    $totalDamage = max(1, $totalDamage);
    
    // 将招式加成数据存储到 session，供下次攻击时应用（不直接扣除血量）
    $performKey = "perform_active_{$charId}";
    $_SESSION[$performKey] = [
        'skill_id' => $skillName,
        'action_code' => $actionCode,
        'action_name' => $actionName,
        'damage' => $totalDamage,
        'damage_type' => $damageType,
        'dodge_mod' => $dodgeMod,
        'parry_mod' => $parryMod,
        'weapon_name' => $weaponName,
        'timestamp' => time()
    ];
    // 调试日志
    error_log("[PERFORM_DEBUG] Stored perform加成: totalDamage={$totalDamage}, action={$actionName}, charId={$charId}, targetName={$targetName}");
    
    // 扣除内力/法力消耗
    if ($forceCost > 0) {
        Database::execute(
            "UPDATE characters SET `force` = GREATEST(0, `force` - ?) WHERE id = ?",
            [$forceCost, $charId]
        );
    }
    if ($manaCost > 0) {
        Database::execute(
            "UPDATE characters SET mana = GREATEST(0, mana - ?) WHERE id = ?",
            [$manaCost, $charId]
        );
    }
    
    // 构建消息 - 替换MUD模板变量
    $meName = $me['name'] ?? '你';
    $skillChineseName = SkillManager::getSkillChineseName($skillName);
    
    // 招式动作描述（使用replaceCombatText替换MUD模板变量）
    $specialMsg = $actionText;
    if (!empty($specialMsg)) {
        // 替换 MUD 模板变量：$N=攻击者, $n=目标, $w=武器, $l=部位, $p=目标所属格
        $replacements = [
            '$N' => $meName,
            '$n' => $targetName,
            '$p' => $targetName,  // 目标所属格
            '$w' => $weaponName,
            '$l' => '要害',  // 默认部位
        ];
        $specialMsg = strtr($specialMsg, $replacements);
    } else {
        $specialMsg = "{$meName}使出「{$skillChineseName}」的「{$actionName}」！";
    }
    
    // 使用CombatMessages生成伤害消息，并替换变量
    $damageMsgTemplate = CombatMessages::getDamageMessage($totalDamage, $damageType);
    // 对伤害消息也进行变量替换（$n, $p, $l, $w）
    $damageMsg = strtr($damageMsgTemplate, [
        '$n' => $targetName,
        '$p' => $targetName,
        '$l' => '要害',
        '$w' => $weaponName,
    ]);
    
    $messages = [];
    $messages[] = HTML_HIYEL . $specialMsg . HTML_NOR;
    
    // 在战斗中执行 perform 后立即触发攻击（应用伤害）
    $attackResult = null;
    $damageToTarget = 0;
    $targetHpPercent = 100;
    $playerDamage = 0;
    $playerHp = intval($me['kee']);
    
    if (CombatDaemon::isInCombat($charId)) {
        $attackResult = CombatDaemon::doAttack($charId);
        if ($attackResult['success']) {
            if (!empty($attackResult['message'])) {
                $messages[] = $attackResult['message'];
            }
            // 获取伤害数据用于前端血条更新
            $damageToTarget = intval($attackResult['damage'] ?? 0);
            $targetHpPercent = intval($attackResult['target_hp_percent'] ?? 100);
            $playerDamage = intval($attackResult['player_damage'] ?? 0);
            $playerHp = intval($attackResult['player_hp'] ?? $playerHp);
            
            // 切磋模式：doAttack() 已在内部正确更新了 session NPC 血量（line 629）
            // 无需在此重新计算，避免 intval 截断导致血量误差
        } else {
            $messages[] = HTML_HIRED . $attackResult['message'] . HTML_NOR;
        }
    } else {
        // 非战斗状态，只显示伤害消息（不实际扣血）
        $messages[] = HTML_HIRED . $damageMsg . HTML_NOR;
    }
    
    // 内力/法力消耗提示
    $costParts = [];
    if ($forceCost > 0) {
        $costParts[] = "内力 {$forceCost} 点";
    }
    if ($manaCost > 0) {
        $costParts[] = "法力 {$manaCost} 点";
    }
    if (!empty($costParts)) {
        $messages[] = HTML_HICYN . "消耗" . implode("，", $costParts) . "。" . HTML_NOR;
    }
    
    // 招式加成已存储，下次攻击时将应用额外伤害
    
    // 构建返回值，包含伤害数据用于前端血条更新
    $msgStr = implode("\n", $messages);
    // 处理换行：先把字面意义的 \n 换成真正的换行，再转成 HTML <br>
    $msgStr = str_replace('\\n', "\n", $msgStr);
    $msgStr = nl2br($msgStr);
    
    $result = [
        'success' => true,
        'message' => $msgStr,
        'perform_activated' => true,
        'damage' => $damageToTarget,  // 对目标造成的伤害
        'player_damage' => $playerDamage,  // 玩家受到的伤害
        'player_hp' => $playerHp,  // 玩家当前血量
        'target_hp_percent' => $targetHpPercent,  // 目标血量百分比
    ];
    
    // 如果有击杀标记，也传递
    if (!empty($attackResult['killed'])) {
        $result['killed'] = true;
        $result['friendly'] = !empty($attackResult['friendly']);
    }
    
    return $result;
}

/**
 * 对空施展招式（练习模式）
 * 
 * @param int $charId 角色ID
 * @param array $me 角色数据
 * @param string $skillName 技能名
 * @param string $actionCode 招式代码
 * @param int $skillLevel 技能等级
 * @param array $action 招式数据
 * @param int $forceCost 内力消耗
 * @param int $manaCost 法力消耗
 */
function performPractice(int $charId, array $me, string $skillName, string $actionCode, int $skillLevel, array $action, int $forceCost, int $manaCost): array {
    // 扣除内力/法力消耗
    if ($forceCost > 0) {
        if (($me['force'] ?? 0) < $forceCost) {
            return ['success' => false, 'message' => "你的内力不足，需要{$forceCost}点内力。"];
        }
        Database::execute(
            "UPDATE characters SET `force` = GREATEST(0, `force` - ?) WHERE id = ?",
            [$forceCost, $charId]
        );
    }
    if ($manaCost > 0) {
        if (($me['mana'] ?? 0) < $manaCost) {
            return ['success' => false, 'message' => "你的法力不足，需要{$manaCost}点法力。"];
        }
        Database::execute(
            "UPDATE characters SET mana = GREATEST(0, mana - ?) WHERE id = ?",
            [$manaCost, $charId]
        );
    }
    
    $meName = $me['name'] ?? '你';
    $skillChineseName = SkillManager::getSkillChineseName($skillName);
    $actionText = $action['action_text'] ?? '';
    $actionName = $action['action_name'] ?? $actionCode;
    
    // 格式化施展消息（对空练习，目标用"虚空"）
    $specialMsg = $actionText;
    if (empty($specialMsg)) {
        $specialMsg = "使出「{$skillChineseName}」的「{$actionName}」！";
    } else {
        // 替换占位符
        $specialMsg = strtr($specialMsg, [
            '$N' => $meName,
            '$n' => '虚空',
            '$p' => '虚空',
            '$w' => '',
            '$l' => '',
        ]);
        // 去除ANSI颜色码和MUD标记
        $specialMsg = preg_replace('/\x1B\[[0-9;]*[mK]/', '', $specialMsg);
        $specialMsg = preg_replace('/\b(HTML_HIRED|HTML_NOR|HTML_HICYN|HTML_HIGRN|HIYEL|HTML_HIMAG|HIW|HIBLU|HIC|HIPUR|HTML_HIWHT)\b/', '', $specialMsg);
        $specialMsg = trim($specialMsg);
    }
    
    $messages = [];
    $messages[] = HTML_HICYN . "{$meName}" . HTML_NOR . HIYEL . "{$specialMsg}" . HTML_NOR;
    
    // 内力/法力消耗提示
    $costParts = [];
    if ($forceCost > 0) {
        $costParts[] = "内力 {$forceCost} 点";
    }
    if ($manaCost > 0) {
        $costParts[] = "法力 {$manaCost} 点";
    }
    if (!empty($costParts)) {
        $messages[] = HTML_HICYN . "消耗" . implode("，", $costParts) . "。" . HTML_NOR;
    }
    
    // 提升技能熟练度（参考 practice.php 逻辑）
    $practiceResult = SkillManager::practiceSkill($charId, $skillName);
    if ($practiceResult['success']) {
        $messages[] = "你的「{$skillChineseName}」熟练度略有提升。";
        $expGained = $practiceResult['exp_gained'] ?? 0;
        if ($expGained > 0) {
            $messages[] = "(获得 {$expGained} 点技能经验)";
        }
    } else {
        // 即使practiceSkill失败，也显示练习消息
        $messages[] = "你反复演练这招，但似乎还没有什么进展。";
    }
    
    $msgStr = implode("\n", $messages);
    // 处理换行：先把字面意义的 \n 换成真正的换行，再转成 HTML <br>
    $msgStr = str_replace('\\n', "\n", $msgStr);
    $msgStr = nl2br($msgStr);
    
    return [
        'success' => true,
        'message' => $msgStr,
        'practice' => true
    ];
}

/**
 * 在当前房间查找目标（供perform命令使用）
 * 
 * @param int $charId 角色ID
 * @param string $targetName 目标名称
 * @return array|null 目标数据，找不到返回null
 */
function findTargetInRoomForPerform(int $charId, string $targetName): ?array {
    $me = CharacterModel::find($charId);
    if (!$me) {
        return null;
    }
    
    $room = RoomModel::getFullInfo($me['current_area'], $me['current_room']);
    if (!$room) {
        return null;
    }
    
    // 查找NPC
    if (!empty($room['npcs'])) {
        foreach ($room['npcs'] as $npc) {
            if (stripos($npc['name'], $targetName) !== false || 
                (isset($npc['npc_id']) && stripos($npc['npc_id'], $targetName) !== false)) {
                return [
                    'type' => 'npc',
                    'id' => $npc['id'],
                    'npc_id' => $npc['npc_id'] ?? '',
                    'name' => $npc['name'],
                    'data' => $npc
                ];
            }
        }
    }
    
    // TODO: 查找其他玩家（需要在线玩家列表）
    
    return null;
}
