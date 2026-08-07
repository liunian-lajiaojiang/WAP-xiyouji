<?php
/**
 * 法术施放命令 (cast)
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 功能：施放已启用的法术体系下的法术
 * 
 * 用法：
 *   cast thunder on <目标>    # 雷咒 - 消耗法力100+精神50，造成气伤和神伤
 *   cast light on <目标>      # 光明咒 - 消耗法力80+精神30，造成神伤
 *   cast dingshen on <目标>   # 定神术 - 消耗法力60+精神40，眩晕目标
 *   cast transfer             # 真气传送 - 消耗法力200，传送内力给自身
 *   cast bighammer on <目标>  # 大力锤 - 消耗法力120+精神60，造成大量气伤
 *   cast                      # 查看可用法术列表
 */
// 加载配置
static $_castConfig = null;
if ($_castConfig === null) {
    $_castConfig = require __DIR__ . '/../config/quest.php';
}
static $_skillCosts = null;
if ($_skillCosts === null) {
    $_skillCosts = require __DIR__ . '/../config/skill_costs.php';
}

require_once HELPER_PATH . 'SkillManager.php';
require_once HELPER_PATH . 'SpellHelper.php';
require_once DAEMON_PATH . 'CombatDaemon.php';
require_once DAEMON_PATH . 'MessageDaemon.php';
require_once MODEL_PATH . 'Room.php';

// 法术名称映射表（英文 => 中文别名统一映射到英文代码）
$CAST_ALIAS_MAP = [
    'thunder'    => 'thunder',
    '雷咒'      => 'thunder',
    '雷'        => 'thunder',
    'light'     => 'light',
    '光明咒'    => 'light',
    '光明'      => 'light',
    'dingshen'  => 'dingshen',
    '定神术'    => 'dingshen',
    '定神'      => 'dingshen',
    'transfer'  => 'transfer',
    '真气传送'  => 'transfer',
    '传送'      => 'transfer',
    'bighammer' => 'bighammer',
    '大力锤'    => 'bighammer',
    '锤'        => 'bighammer',
    'mihun'     => 'mihun',
    '迷魂'      => 'mihun',
];

// 法术消耗与元数据配置
$CAST_MAP = [
    'thunder' => [
        'name'     => '雷咒',
        'mana'     => 100,
        'sen'      => 50,
        'busy'     => 2,
        'desc'     => '消耗法力100+精神50，造成气伤和神伤',
    ],
    'light' => [
        'name'     => '光明咒',
        'mana'     => 80,
        'sen'      => 30,
        'busy'     => 2,
        'desc'     => '消耗法力80+精神30，造成神伤',
    ],
    'dingshen' => [
        'name'     => '定神术',
        'mana'     => 60,
        'sen'      => 40,
        'busy'     => 3,
        'desc'     => '消耗法力60+精神40，眩晕目标',
    ],
    'transfer' => [
        'name'     => '真气传送',
        'mana'     => 200,
        'sen'      => 0,
        'busy'     => 2,
        'desc'     => '消耗法力200，传送内力给自身',
    ],
    'bighammer' => [
        'name'     => '大力锤',
        'mana'     => 120,
        'sen'      => 60,
        'busy'     => 2,
        'desc'     => '消耗法力120+精神60，造成大量气伤',
    ],
    'mihun' => [
        'name'     => '迷魂',
        'mana'     => 200,
        'sen'      => 20,
        'busy'     => 3,
        'desc'     => '月宫秘技，迷惑目标使其定身，成功时不进入战斗',
    ],
];

/**
 * 法术施放命令入口
 * 
 * @param int $charId 角色ID
 * @param string $param 命令参数（格式: <法术名> on <目标> 或 <法术名>）
 * @return array
 */
function cmd_cast(int $charId, string $param = ''): array {
    global $CAST_ALIAS_MAP, $CAST_MAP;

    $me = CharacterModel::find($charId);
    if (!$me) {
        return ['success' => false, 'message' => '角色不存在。'];
    }

    // 检查角色是否忙碌
    if (is_player_busy($charId)) {
        return ['success' => false, 'message' => '你正忙着呢，没有精力施放法术。'];
    }

    // 查询已启用的法术体系
    $mappedSpells = SkillManager::querySkillMapped($charId, 'spells');
    if (empty($mappedSpells)) {
        return ['success' => false, 'message' => '你必须先用 enable 指令选择你要使用的法术体系。'];
    }

    // 无参数时显示帮助
    $arg = trim($param);
    if (empty($arg)) {
        return castHelp();
    }

    // 解析参数：cast <法术名> on <目标>
    $spellName = '';
    $targetName = '';

    $onPos = stripos($arg, ' on ');
    if ($onPos !== false) {
        $spellName = strtolower(trim(substr($arg, 0, $onPos)));
        $targetName = trim(substr($arg, $onPos + 4));
    } else {
        // 没有 "on"，整个参数作为法术名（如 transfer 无需目标）
        $spellName = strtolower(trim($arg));
    }

    // 别名映射
    $castCode = $CAST_ALIAS_MAP[$spellName] ?? null;
    if ($castCode === null) {
        return [
            'success' => false,
            'message' => '没有这种法术。输入 cast 查看可用的法术列表。',
        ];
    }

    // 获取法术配置
    $castConfig = $CAST_MAP[$castCode] ?? null;
    if (!$castConfig) {
        return ['success' => false, 'message' => '该法术尚未实现。'];
    }

    // 检查法术技能等级
    $spellsLevel = SkillManager::getSkillLevel($charId, 'spells');
    if ($spellsLevel < 1) {
        return ['success' => false, 'message' => '你还没有学会任何法术技能。'];
    }

    // 检查已启用的法术体系对应的技能等级
    $mappedLevel = SkillManager::getSkillLevel($charId, $mappedSpells);
    if ($mappedLevel < 1) {
        return ['success' => false, 'message' => '你还没有学会你所启用的法术体系。'];
    }

    // 检查法力(mana)是否足够
    $currentMana = intval($me['mana'] ?? 0);
    $manaCost = $castConfig['mana'];
    if ($currentMana < $manaCost) {
        return [
            'success' => false,
            'message' => '你的法力不足，需要' . $manaCost . '点，当前只有' . $currentMana . '点。',
        ];
    }

    // 检查精神(sen)是否足够
    $currentSen = intval($me['sen'] ?? 0);
    $senCost = $castConfig['sen'];
    if ($senCost > 0 && $currentSen < $senCost) {
        return [
            'success' => false,
            'message' => '你的精神不足，无法集中精力施法。',
        ];
    }

    // 根据法术类型分发到对应的处理函数
    switch ($castCode) {
        case 'thunder':
            return castThunder($charId, $me, $mappedSpells, $targetName);
        case 'light':
            return castLight($charId, $me, $mappedSpells, $targetName);
        case 'dingshen':
            return castDingshen($charId, $me, $mappedSpells, $targetName);
        case 'transfer':
            return castTransfer($charId, $me, $mappedSpells, $targetName);
        case 'bighammer':
            return castBighammer($charId, $me, $mappedSpells, $targetName);
        case 'mihun':
            return castMihun($charId, $me, $mappedSpells, $targetName);
        default:
            return ['success' => false, 'message' => '该法术尚未实现。'];
    }
}

/**
 * 雷咒 - 对目标造成气伤和神伤
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */
function castThunder(int $charId, array $me, string $mappedSpells, string $target): array {
    global $CAST_MAP;
    $config = $CAST_MAP['thunder'];
    $manaCost = $config['mana'];
    $senCost = $config['sen'];

    // 需要目标
    if (empty($target)) {
        // 如果已在战斗中，使用当前战斗目标
        if (CombatDaemon::isInCombat($charId)) {
            $combatState = CombatDaemon::getCombatStatus($charId);
            if ($combatState && !empty($combatState['target_id'])) {
                $targetId = $combatState['target_id'];
                $targetType = $combatState['target_type'] ?? 'npc';
            } else {
                return ['success' => false, 'message' => '你想用雷咒劈谁？'];
            }
        } else {
            return ['success' => false, 'message' => '你想用雷咒劈谁？用法：cast thunder on <目标>'];
        }
    }

    // 查找目标
    if (!isset($targetId)) {
        $targetData = findCastTarget($charId, $me, $target);
        if (!$targetData) {
            return ['success' => false, 'message' => '这里没有 ' . $target . '。'];
        }
        $targetId = $targetData['id'];
        $targetType = $targetData['type'];
    }

    // 获取目标数据
    $targetEntity = getTargetEntity($targetId, $targetType);
    if (!$targetEntity) {
        return ['success' => false, 'message' => '目标不存在。'];
    }

    $targetName = $targetEntity['name'] ?? '目标';
    $myName = $me['name'] ?? '你';

    // 如果不在战斗中，先发起战斗
    if (!CombatDaemon::isInCombat($charId)) {
        $startResult = CombatDaemon::startKill($charId, $targetId, $targetType);
        if (!$startResult['success']) {
            return $startResult;
        }
    }

    // 扣除法力和精神
    Database::execute(
        "UPDATE characters SET mana = GREATEST(0, mana - ?), sen = GREATEST(0, sen - ?) WHERE id = ?",
        [$manaCost, $senCost, $charId]
    );

    // 获取spells技能等级（含映射）
    $spellsLevel = SkillManager::querySkill($charId, 'spells');

    // 施法成功率判定：基于max_mana
    $maxMana = SpellHelper::queryMaxMana($me);
    if (mt_rand(0, max(1, $maxMana - 1)) < 50) {
        // 施法失败
        set_player_busy($charId, $config['busy']);
        $output = [];
        $output[] = HTML_HICYN . '你口中念念有词，突然天雷滚滚...但雷电没有落下来！' . HTML_NOR;
        $output[] = '法力消耗了 ' . $manaCost . ' 点，精神消耗了 ' . $senCost . ' 点。';
        return [
            'success' => true,
            'message' => implode("\n", $output),
        ];
    }

    // 伤害计算：法术伤害 = spells等级 * 1.5 + random(1,10)
    $baseDamage = intval($spellsLevel * 1.5) + mt_rand(1, 10);

    // 分配气伤和神伤（各一半）
    $qiDamage = intval($baseDamage * 0.6);
    $shenDamage = $baseDamage - $qiDamage;

    // 应用法宝防御
    $defendResult = FabaoHelper::applyFabaoDefense($targetId, $qiDamage, $shenDamage);

    // 应用伤害到目标
    applyDamageToTarget($targetId, $targetType, $qiDamage, $shenDamage);

    // 设置busy
    set_player_busy($charId, $config['busy']);

    // 提升技能熟练度
    $messages = $defendResult['messages'] ?? [];
    $practiceResult = SkillManager::practiceSkill($charId, $mappedSpells);
    if ($practiceResult['success']) {
        $expGained = $practiceResult['exp_gained'] ?? 0;
        if ($expGained > 0) {
            $messages[] = '(法术熟练度+' . $expGained . ')';
        }
    }

    // 构建输出消息
    $output = [];
    $output[] = HTML_HICYN . $myName . '念动咒语，指天画地，一道天雷轰然劈向' . $targetName . '！' . HTML_NOR;
    if ($qiDamage > 0) {
        $output[] = HTML_HIRED . '造成气血伤害 ' . $qiDamage . ' 点！' . HTML_NOR;
    }
    if ($shenDamage > 0) {
        $output[] = HTML_HIRED . '造成精神伤害 ' . $shenDamage . ' 点！' . HTML_NOR;
    }
    $output[] = '法力消耗了 ' . $manaCost . ' 点，精神消耗了 ' . $senCost . ' 点。';
    if (!empty($messages)) {
        $output[] = implode(' ', $messages);
    }

    // 房间广播
    $roomId = $me['current_room'] ?? '';
    if (!empty($roomId)) {
        $roomMsg = HTML_HICYN . $myName . '念动咒语，一道天雷轰然劈向' . $targetName . '！' . HTML_NOR;
        MessageDaemon::broadcastToRoom($roomId, $roomMsg, $charId, 'room');
    }

    // 检查目标是否被击败
    $defeatMsg = checkTargetDefeated($targetId, $targetType, $targetName, $charId, $me);
    if ($defeatMsg) {
        $output[] = $defeatMsg;
    }

    return [
        'success' => true,
        'message' => implode("\n", $output),
        'skip_queue' => true,
        'damage_qi' => $qiDamage,
        'damage_shen' => $shenDamage,
    ];
}

/**
 * 光明咒 - 对目标造成神伤
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */
function castLight(int $charId, array $me, string $mappedSpells, string $target): array {
    global $CAST_MAP;
    $config = $CAST_MAP['light'];
    $manaCost = $config['mana'];
    $senCost = $config['sen'];

    // 需要目标
    if (empty($target)) {
        if (CombatDaemon::isInCombat($charId)) {
            $combatState = CombatDaemon::getCombatStatus($charId);
            if ($combatState && !empty($combatState['target_id'])) {
                $targetId = $combatState['target_id'];
                $targetType = $combatState['target_type'] ?? 'npc';
            } else {
                return ['success' => false, 'message' => '你想用光明咒对付谁？'];
            }
        } else {
            return ['success' => false, 'message' => '你想用光明咒对付谁？用法：cast light on <目标>'];
        }
    }

    // 查找目标
    if (!isset($targetId)) {
        $targetData = findCastTarget($charId, $me, $target);
        if (!$targetData) {
            return ['success' => false, 'message' => '这里没有 ' . $target . '。'];
        }
        $targetId = $targetData['id'];
        $targetType = $targetData['type'];
    }

    $targetEntity = getTargetEntity($targetId, $targetType);
    if (!$targetEntity) {
        return ['success' => false, 'message' => '目标不存在。'];
    }

    $targetName = $targetEntity['name'] ?? '目标';
    $myName = $me['name'] ?? '你';

    // 如果不在战斗中，先发起战斗
    if (!CombatDaemon::isInCombat($charId)) {
        $startResult = CombatDaemon::startKill($charId, $targetId, $targetType);
        if (!$startResult['success']) {
            return $startResult;
        }
    }

    // 扣除法力和精神
    Database::execute(
        "UPDATE characters SET mana = GREATEST(0, mana - ?), sen = GREATEST(0, sen - ?) WHERE id = ?",
        [$manaCost, $senCost, $charId]
    );

    $spellsLevel = SkillManager::querySkill($charId, 'spells');

    // 施法成功率判定
    $maxMana = SpellHelper::queryMaxMana($me);
    if (mt_rand(0, max(1, $maxMana - 1)) < 50) {
        set_player_busy($charId, $config['busy']);
        $output = [];
        $output[] = HTML_HICYN . '你双手合十，口诵光明咒...但光芒一闪即逝。' . HTML_NOR;
        $output[] = '法力消耗了 ' . $manaCost . ' 点，精神消耗了 ' . $senCost . ' 点。';
        return [
            'success' => true,
            'message' => implode("\n", $output),
        ];
    }

    // 伤害计算：光明咒主要造成神伤
    $baseDamage = intval($spellsLevel * 1.5) + mt_rand(1, 10);
    $shenDamage = intval($baseDamage * 0.8);
    $qiDamage = $baseDamage - $shenDamage;

    // 应用法宝防御
    $defendResult = FabaoHelper::applyFabaoDefense($targetId, $qiDamage, $shenDamage);

    // 应用伤害
    applyDamageToTarget($targetId, $targetType, $qiDamage, $shenDamage);

    set_player_busy($charId, $config['busy']);

    // 提升技能熟练度
    $messages = $defendResult['messages'] ?? [];
    $practiceResult = SkillManager::practiceSkill($charId, $mappedSpells);
    if ($practiceResult['success']) {
        $expGained = $practiceResult['exp_gained'] ?? 0;
        if ($expGained > 0) {
            $messages[] = '(法术熟练度+' . $expGained . ')';
        }
    }

    // 构建输出消息
    $output = [];
    $output[] = HTML_HICYN . $myName . '双手合十，口中念念有词，一道耀眼的光芒射向' . $targetName . '！' . HTML_NOR;
    if ($shenDamage > 0) {
        $output[] = HTML_HIRED . '造成精神伤害 ' . $shenDamage . ' 点！' . HTML_NOR;
    }
    if ($qiDamage > 0) {
        $output[] = HTML_HIRED . '造成气血伤害 ' . $qiDamage . ' 点！' . HTML_NOR;
    }
    $output[] = '法力消耗了 ' . $manaCost . ' 点，精神消耗了 ' . $senCost . ' 点。';
    if (!empty($messages)) {
        $output[] = implode(' ', $messages);
    }

    // 房间广播
    $roomId = $me['current_room'] ?? '';
    if (!empty($roomId)) {
        $roomMsg = HTML_HICYN . $myName . '施展光明咒，一道耀眼的光芒射向' . $targetName . '！' . HTML_NOR;
        MessageDaemon::broadcastToRoom($roomId, $roomMsg, $charId, 'room');
    }

    $defeatMsg = checkTargetDefeated($targetId, $targetType, $targetName, $charId, $me);
    if ($defeatMsg) {
        $output[] = $defeatMsg;
    }

    return [
        'success' => true,
        'message' => implode("\n", $output),
        'skip_queue' => true,
        'damage_qi' => $qiDamage,
        'damage_shen' => $shenDamage,
    ];
}

/**
 * 定神术 - 眩晕目标，使其无法行动
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */
function castDingshen(int $charId, array $me, string $mappedSpells, string $target): array {
    global $CAST_MAP;
    $config = $CAST_MAP['dingshen'];
    $manaCost = $config['mana'];
    $senCost = $config['sen'];

    // 需要目标
    if (empty($target)) {
        if (CombatDaemon::isInCombat($charId)) {
            $combatState = CombatDaemon::getCombatStatus($charId);
            if ($combatState && !empty($combatState['target_id'])) {
                $targetId = $combatState['target_id'];
                $targetType = $combatState['target_type'] ?? 'npc';
            } else {
                return ['success' => false, 'message' => '你想把谁定住？'];
            }
        } else {
            return ['success' => false, 'message' => '你想把谁定住？用法：cast dingshen on <目标>'];
        }
    }

    // 查找目标
    if (!isset($targetId)) {
        $targetData = findCastTarget($charId, $me, $target);
        if (!$targetData) {
            return ['success' => false, 'message' => '这里没有 ' . $target . '。'];
        }
        $targetId = $targetData['id'];
        $targetType = $targetData['type'];
    }

    $targetEntity = getTargetEntity($targetId, $targetType);
    if (!$targetEntity) {
        return ['success' => false, 'message' => '目标不存在。'];
    }

    $targetName = $targetEntity['name'] ?? '目标';
    $myName = $me['name'] ?? '你';

    // 如果不在战斗中，先发起战斗
    if (!CombatDaemon::isInCombat($charId)) {
        $startResult = CombatDaemon::startKill($charId, $targetId, $targetType);
        if (!$startResult['success']) {
            return $startResult;
        }
    }

    // 扣除法力和精神
    Database::execute(
        "UPDATE characters SET mana = GREATEST(0, mana - ?), sen = GREATEST(0, sen - ?) WHERE id = ?",
        [$manaCost, $senCost, $charId]
    );

    $spellsLevel = SkillManager::querySkill($charId, 'spells');

    // 成功率判定：基于 spells 等级和修为（参考原始 AP vs DP 机制）
    // AP = spells^3/10 + combat_exp
    // DP = target combat_exp
    $ap = intval(pow($spellsLevel, 3) / 10) + intval($me['combat_exp'] ?? 0);
    $dp = intval($targetEntity['combat_exp'] ?? 0);

    // 额外判定：max_mana 对比
    $myMaxMana = SpellHelper::queryMaxMana($me);
    $targetMaxMana = intval($targetEntity['max_mana'] ?? 0);

    $success = true;
    if ($dp > 0 && mt_rand(0, $ap + $dp - 1) < $dp) {
        $success = false;
    }
    if ($targetMaxMana > 0 && mt_rand(0, $myMaxMana + $targetMaxMana - 1) < $targetMaxMana) {
        $success = false;
    }

    set_player_busy($charId, $config['busy']);

    $output = [];
    $output[] = HTML_HICYN . $myName . '口中念动咒语，向' . $targetName . '一指，一道灵光飞出！' . HTML_NOR;

    if ($success) {
        // 成功：眩晕目标
        $stunDuration = 10 + mt_rand(0, intval(max(0, $spellsLevel - 100)) / 2);
        if ($stunDuration > 40) {
            $stunDuration = 40;
        }

        if ($targetType === 'player') {
            // 玩家目标：设置 busy 状态
            set_player_busy($targetId, $stunDuration);
        } else {
            // NPC目标：在 session 中记录眩晕状态
            $_SESSION['npc_stun_' . $targetId] = [
                'start_time' => time(),
                'duration'   => $stunDuration,
                'source'     => $charId,
            ];
        }

        $output[] = HTML_HIRED . $targetName . '被灵光击中，顿时动弹不得！' . HTML_NOR;
        $output[] = '定身持续 ' . $stunDuration . ' 秒。';
    } else {
        $output[] = HTML_HICYN . '但' . $targetName . '灵光一闪，挣脱了定身之力！' . HTML_NOR;
    }

    $output[] = '法力消耗了 ' . $manaCost . ' 点，精神消耗了 ' . $senCost . ' 点。';

    // 提升技能熟练度
    $practiceResult = SkillManager::practiceSkill($charId, $mappedSpells);
    if ($practiceResult['success']) {
        $expGained = $practiceResult['exp_gained'] ?? 0;
        if ($expGained > 0) {
            $output[] = '(法术熟练度+' . $expGained . ')';
        }
    }

    // 房间广播
    $roomId = $me['current_room'] ?? '';
    if (!empty($roomId)) {
        $roomMsg = HTML_HICYN . $myName . '施展定神术，一道灵光飞向' . $targetName . '！' . HTML_NOR;
        MessageDaemon::broadcastToRoom($roomId, $roomMsg, $charId, 'room');
    }

    return [
        'success' => true,
        'message' => implode("\n", $output),
        'skip_queue' => true,
        'stun_success' => $success,
    ];
}

/**
 * 真气传送 - 将法力转化为内力
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 注：原始设计为自身使用，将法力转化为内力
 */
function castTransfer(int $charId, array $me, string $mappedSpells, string $target): array {
    global $CAST_MAP;
    $config = $CAST_MAP['transfer'];
    $manaCost = $config['mana'];

    // 获取内功技能等级
    $forceLevel = SkillManager::getSkillLevel($charId, 'force');
    if ($forceLevel < 20) {
        return ['success' => false, 'message' => '你的内功修为太低，无法施展真气传送。'];
    }

    // 获取spells技能等级
    $spellsLevel = SkillManager::getSkillLevel($charId, 'spells');
    if ($spellsLevel < 20) {
        return ['success' => false, 'message' => '你的法术修为太低，无法施展真气传送。'];
    }

    // 检查内力是否已满
    $myMaxForce = intval($me['max_force'] ?? 0);
    $myForce = intval($me['force'] ?? 0);
    $diff = $myMaxForce - $myForce;
    if ($diff < 1) {
        return ['success' => false, 'message' => '你的内力已经很充盈了。'];
    }

    $myMana = intval($me['mana'] ?? 0);
    if ($myMana < 50) {
        return ['success' => false, 'message' => '你的法力太少了。'];
    }

    // 计算法力消耗（不超过diff和当前法力）
    $actualManaCost = min($diff, $myMana);

    // 计算内力增益
    // 原始公式：neiligain = manacost * max_mana / (1 + max_force)
    // 然后再乘以efficiency系数
    $maxMana = SpellHelper::queryMaxMana($me);
    $neiliGain = intval($actualManaCost * $maxMana / (1 + $myMaxForce));
    if ($neiliGain > $actualManaCost) {
        $neiliGain = $actualManaCost;
    }

    // 效率计算：基于 force 和 spells 等级
    // 原始公式：eff = min(forcelev, spellslev) / 3，再做非线性映射到0~80%
    $eff = min($forceLevel, $spellsLevel);
    if ($eff > 300) {
        $eff = 300;
    }
    $eff = intval($eff / 3); // max 100
    $temp = 100 - $eff;
    $temp = intval($temp * $temp / 100);
    $eff = intval((100 - $temp) * 80 / 100);
    $neiliGain = intval($neiliGain * $eff / 100);

    // 确保至少增加1点内力
    $neiliGain = max(1, $neiliGain);

    // 扣除法力，增加内力
    $newForce = min($myMaxForce, $myForce + $neiliGain);
    $actualGain = $newForce - $myForce;

    Database::execute(
        "UPDATE characters SET mana = GREATEST(0, mana - ?), `force` = ? WHERE id = ?",
        [$actualManaCost, $newForce, $charId]
    );

    // 设置busy
    if (CombatDaemon::isInCombat($charId)) {
        set_player_busy($charId, 1);
    } else {
        // 非战斗时短暂busy
        $kar = intval($me['kar'] ?? 10);
        set_player_busy($charId, max(1, intval(30 / max(1, $kar))));
    }

    // 提升技能熟练度
    $messages = [];
    $practiceResult = SkillManager::practiceSkill($charId, $mappedSpells);
    if ($practiceResult['success']) {
        $expGained = $practiceResult['exp_gained'] ?? 0;
        if ($expGained > 0) {
            $messages[] = '(法术熟练度+' . $expGained . ')';
        }
    }

    $myName = $me['name'] ?? '你';
    $output = [];
    $output[] = HTML_HICYN . $myName . '闭目凝神，将体内法力缓缓转化为真气...' . HTML_NOR;
    $output[] = HTML_HIGRN . '消耗 ' . $actualManaCost . ' 点法力，获得 ' . $actualGain . ' 点内力！' . HTML_NOR;
    $output[] = '（当前内力：' . $newForce . '/' . $myMaxForce . '，效率：' . $eff . '%）';
    if (!empty($messages)) {
        $output[] = implode(' ', $messages);
    }

    return [
        'success' => true,
        'message' => implode("\n", $output),
        'skip_queue' => true,
        'mana_cost' => $actualManaCost,
        'force_gain' => $actualGain,
    ];
}

/**
 * 大力锤 - 佛法神力，对目标造成大量气伤
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */
function castBighammer(int $charId, array $me, string $mappedSpells, string $target): array {
    global $CAST_MAP;
    $config = $CAST_MAP['bighammer'];
    $manaCost = $config['mana'];
    $senCost = $config['sen'];

    // 需要目标
    if (empty($target)) {
        if (CombatDaemon::isInCombat($charId)) {
            $combatState = CombatDaemon::getCombatStatus($charId);
            if ($combatState && !empty($combatState['target_id'])) {
                $targetId = $combatState['target_id'];
                $targetType = $combatState['target_type'] ?? 'npc';
            } else {
                return ['success' => false, 'message' => '你想用大力锤砸谁？'];
            }
        } else {
            return ['success' => false, 'message' => '你想用大力锤砸谁？用法：cast bighammer on <目标>'];
        }
    }

    // 查找目标
    if (!isset($targetId)) {
        $targetData = findCastTarget($charId, $me, $target);
        if (!$targetData) {
            return ['success' => false, 'message' => '这里没有 ' . $target . '。'];
        }
        $targetId = $targetData['id'];
        $targetType = $targetData['type'];
    }

    $targetEntity = getTargetEntity($targetId, $targetType);
    if (!$targetEntity) {
        return ['success' => false, 'message' => '目标不存在。'];
    }

    $targetName = $targetEntity['name'] ?? '目标';
    $myName = $me['name'] ?? '你';

    // 如果不在战斗中，先发起战斗
    if (!CombatDaemon::isInCombat($charId)) {
        $startResult = CombatDaemon::startKill($charId, $targetId, $targetType);
        if (!$startResult['success']) {
            return $startResult;
        }
    }

    // 扣除法力和精神
    Database::execute(
        "UPDATE characters SET mana = GREATEST(0, mana - ?), sen = GREATEST(0, sen - ?) WHERE id = ?",
        [$manaCost, $senCost, $charId]
    );

    $spellsLevel = SkillManager::querySkill($charId, 'spells');

    // 施法成功率判定
    $maxMana = SpellHelper::queryMaxMana($me);
    if (mt_rand(0, max(1, $maxMana - 1)) < 50) {
        set_player_busy($charId, $config['busy']);
        $output = [];
        $output[] = HTML_HICYN . '你念动佛法，天边隐约出现一柄巨大的锤子...但转瞬即逝。' . HTML_NOR;
        $output[] = '法力消耗了 ' . $manaCost . ' 点，精神消耗了 ' . $senCost . ' 点。';
        return [
            'success' => true,
            'message' => implode("\n", $output),
        ];
    }

    // 伤害计算：大力锤主要造成气伤，伤害系数较高（success_adj=150, damage_adj=140）
    $baseDamage = intval($spellsLevel * 1.5) + mt_rand(1, 10);
    // 大力锤加成：原始 damage_adj=140，对比 thunder 的 120
    $damageBonus = intval($baseDamage * 0.17); // 140/120 ≈ 1.17
    $baseDamage += $damageBonus;

    $qiDamage = intval($baseDamage * 0.8);
    $shenDamage = $baseDamage - $qiDamage;

    // 应用法宝防御
    $defendResult = FabaoHelper::applyFabaoDefense($targetId, $qiDamage, $shenDamage);

    // 应用伤害
    applyDamageToTarget($targetId, $targetType, $qiDamage, $shenDamage);

    set_player_busy($charId, $config['busy']);

    // 提升技能熟练度
    $messages = $defendResult['messages'] ?? [];
    $practiceResult = SkillManager::practiceSkill($charId, $mappedSpells);
    if ($practiceResult['success']) {
        $expGained = $practiceResult['exp_gained'] ?? 0;
        if ($expGained > 0) {
            $messages[] = '(法术熟练度+' . $expGained . ')';
        }
    }

    // 构建输出消息
    $output = [];
    $output[] = HTML_HICYN . $myName . '念动佛法，天边出现一柄巨大无比的降魔锤，带着雷霆万钧之势砸向' . $targetName . '！' . HTML_NOR;
    if ($qiDamage > 0) {
        $output[] = HTML_HIRED . '造成气血伤害 ' . $qiDamage . ' 点！' . HTML_NOR;
    }
    if ($shenDamage > 0) {
        $output[] = HTML_HIRED . '造成精神伤害 ' . $shenDamage . ' 点！' . HTML_NOR;
    }
    $output[] = '法力消耗了 ' . $manaCost . ' 点，精神消耗了 ' . $senCost . ' 点。';
    if (!empty($messages)) {
        $output[] = implode(' ', $messages);
    }

    // 房间广播
    $roomId = $me['current_room'] ?? '';
    if (!empty($roomId)) {
        $roomMsg = HTML_HICYN . $myName . '施展大力锤，一柄巨大的降魔锤砸向' . $targetName . '！' . HTML_NOR;
        MessageDaemon::broadcastToRoom($roomId, $roomMsg, $charId, 'room');
    }

    $defeatMsg = checkTargetDefeated($targetId, $targetType, $targetName, $charId, $me);
    if ($defeatMsg) {
        $output[] = $defeatMsg;
    }

    return [
        'success' => true,
        'message' => implode("\n", $output),
        'skip_queue' => true,
        'damage_qi' => $qiDamage,
        'damage_shen' => $shenDamage,
    ];
}

/**
 * 显示可用法术帮助列表
 */
function castHelp(): array {
    global $CAST_MAP;

    $output = [];
    $output[] = HTML_HIYEL . '【法术施放】' . HTML_NOR;
    $output[] = '施放法术需要先用 enable 指令选择法术体系。';
    $output[] = '';
    $output[] = '可用法术：';
    foreach ($CAST_MAP as $code => $config) {
        $output[] = '  ' . $code . '（' . $config['name'] . '）  - ' . $config['desc'];
    }
    $output[] = '';
    $output[] = '用法：cast <法术名> [on <目标>]';
    $output[] = '注意：使用法术前请先用 enable 指令选择你想要使用的法术体系。';

    return [
        'success' => false,
        'message' => implode("\n", $output),
    ];
}

// =========================================================================
// 辅助函数
// =========================================================================

/**
 * 在当前房间查找法术目标（NPC或玩家）
 * 
 * @param int $charId 施法者ID
 * @param array $me 施法者数据
 * @param string $targetName 目标名称
 * @return array|null ['id' => int, 'type' => string] 未找到返回null
 */
function findCastTarget(int $charId, array $me, string $targetName): ?array {
    $roomId = $me['current_room'] ?? '';
    $roomArea = $me['current_area'] ?? '';

    if (empty($roomId) || empty($targetName)) {
        return null;
    }

    // 使用 RoomModel::getFullInfo 获取房间所有NPC（静态+动态），与 fight.php 保持一致
    $room = RoomModel::getFullInfo($roomArea, $roomId);

    // 先在房间NPC中查找（支持静态spawn_room和动态npc_temp位置）
    if ($room && !empty($room['npcs'])) {
        foreach ($room['npcs'] as $npc) {
            if (stripos($npc['name'], $targetName) !== false || 
                (isset($npc['npc_id']) && stripos($npc['npc_id'], $targetName) !== false)) {
                return ['id' => intval($npc['id']), 'type' => 'npc'];
            }
        }
    }

    // 再查找同房间玩家
    $player = Database::queryOne(
        "SELECT id FROM characters WHERE current_area = ? AND current_room = ? AND name LIKE ? AND id != ? LIMIT 1",
        [$roomArea, $roomId, '%' . $targetName . '%', $charId]
    );
    if ($player) {
        return ['id' => intval($player['id']), 'type' => 'player'];
    }

    return null;
}

/**
 * 获取目标实体数据
 * 
 * @param int $targetId 目标ID
 * @param string $targetType 目标类型（npc/player）
 * @return array|null
 */
function getTargetEntity(int $targetId, string $targetType): ?array {
    if ($targetType === 'npc') {
        return NpcModel::find($targetId);
    } else {
        return CharacterModel::find($targetId);
    }
}

/**
 * 对目标应用伤害
 * 
 * @param int $targetId 目标ID
 * @param string $targetType 目标类型
 * @param int $qiDamage 气血伤害
 * @param int $shenDamage 精神伤害
 */
function applyDamageToTarget(int $targetId, string $targetType, int $qiDamage, int $shenDamage): void {
    if ($targetType === 'npc') {
        // NPC目标：更新 kee 和 gin
        $totalDamage = $qiDamage + $shenDamage;
        Database::execute(
            "UPDATE npcs SET kee = GREATEST(0, kee - ?) WHERE id = ?",
            [$totalDamage, $targetId]
        );
    } else {
        // 玩家目标：分别更新 kee（气血）和 gin（精神）
        Database::execute(
            "UPDATE characters SET kee = GREATEST(0, kee - ?), gin = GREATEST(0, gin - ?) WHERE id = ?",
            [$qiDamage, $shenDamage, $targetId]
        );
    }
}

/**
 * 检查目标是否被击败
 * 
 * @param int $targetId 目标ID
 * @param string $targetType 目标类型
 * @param string $targetName 目标名称
 * @param int $charId 施法者ID
 * @param array $me 施法者数据
 * @return string|null 击败消息，未击败返回null
 */
function checkTargetDefeated(int $targetId, string $targetType, string $targetName, int $charId, array $me): ?string {
    if ($targetType === 'npc') {
        $npc = Database::queryOne("SELECT kee FROM npcs WHERE id = ? LIMIT 1", [$targetId]);
        if ($npc && intval($npc['kee'] ?? 0) <= 0) {
            // NPC被击败：结束战斗
            CombatDaemon::endCombat($charId);

            // 给予经验和潜能奖励
            $spellsLevel = SkillManager::querySkill($charId, 'spells');
            $expReward = intval($spellsLevel * $_castConfig['spell_reward']['exp_multiplier']);
            $potentialReward = intval($spellsLevel * $_castConfig['spell_reward']['potential_multiplier']);

            Database::execute(
                "UPDATE characters SET combat_exp = combat_exp + ?, potential = potential + ? WHERE id = ?",
                [$expReward, $potentialReward, $charId]
            );

            return HTML_HIGRN . $targetName . '被你的法术击败了！' . HTML_NOR
                . "\n你获得了 " . $expReward . " 点经验和 " . $potentialReward . " 点潜能。";
        }
    } else {
        $player = Database::queryOne("SELECT kee FROM characters WHERE id = ? LIMIT 1", [$targetId]);
        if ($player && intval($player['kee'] ?? 0) <= 0) {
            return HTML_HIGRN . $targetName . '被你的法术击败了！' . HTML_NOR;
        }
    }

    return null;
}

/**
 * 迷魂 - 月宫秘技，迷惑目标使其定身
 * 还原原始项目 cast mihun on <目标>：
 * - 成功时设置 no_move（daze_state），不进入战斗循环
 * - 失败时目标会 kill_ob 攻击施法者
 * 要求：spells 100、moonshentong 60、法力 200、精神 20
 */
function castMihun(int $charId, array $me, string $mappedSpells, string $targetName): array {
    global $CAST_MAP;

    // 前置条件：已启用月宫神通（moonshentong）
    if (strpos($mappedSpells, 'moonshentong') === false && strpos($mappedSpells, 'moon') === false) {
        return ['success' => false, 'message' => '迷魂需要月宫神通，你目前启用的法术体系不支持。'];
    }

    // 获取技能等级
    $skillLevel = SkillManager::getSkillLevel($charId, $mappedSpells);
    if ($skillLevel < 60) {
        return ['success' => false, 'message' => '你的月宫神通等级不够（需要60级以上）。'];
    }

    // spells 等级检查
    $spellsLevel = SkillManager::getSkillLevel($charId, 'spells');
    if ($spellsLevel < 100) {
        return ['success' => false, 'message' => '你的有效法术等级不够（需要100以上）。'];
    }

    // 检查目标
    if (empty($targetName)) {
        return ['success' => false, 'message' => '你想迷魂谁？'];
    }

    // 使用 findCastTarget 支持NPC和玩家目标
    $targetData = findCastTarget($charId, $me, $targetName);
    if (!$targetData) {
        return ['success' => false, 'message' => '这里没有这个人。'];
    }
    $targetId = intval($targetData['id']);
    $targetType = $targetData['type'];  // 'npc' 或 'player'
    if ($targetType === 'player' && $targetId == $charId) {
        return ['success' => false, 'message' => '你不能对自己施展迷魂。'];
    }
    $target = getTargetEntity($targetId, $targetType);
    if (!$target) {
        return ['success' => false, 'message' => '这里没有这个人。'];
    }

    // 检查目标是否已经定身
    $alreadyDazed = false;
    if ($targetType === 'player') {
        // 目标正在修炼中，不可迷魂
        if (is_player_busy($targetId)) {
            return ['success' => false, 'message' => '对方正在闭关修炼，你的迷魂术对修炼者无效。'];
        }
        $alreadyDazed = !empty($target['daze_state']) && $target['daze_state'] == 1;
    } else {
        // NPC: 检查 npc_temp 表中的定身状态
        $npcDaze = Database::queryOne(
            "SELECT temp_value, updated_at FROM npc_temp WHERE npc_id = ? AND temp_key = 'daze_state'",
            [$targetId]
        );
        $alreadyDazed = $npcDaze && $npcDaze['temp_value'] == '1' && intval($npcDaze['updated_at'] ?? 0) > time();
    }
    if ($alreadyDazed) {
        return ['success' => false, 'message' => $target['name'] . '已经不能动弹了。'];
    }

    $cfg = $CAST_MAP['mihun'];
    $currentMana = intval($me['mana'] ?? 0);
    $currentSen = intval($me['sen'] ?? 0);

    // 消耗法力和精神
    Database::execute(
        "UPDATE characters SET mana = mana - ?, sen = sen - ? WHERE id = ?",
        [$cfg['mana'], $cfg['sen'], $charId]
    );

    // 成功率判定：基于法术等级差和法力对抗
    $myMaxMana = intval($me['max_mana'] ?? 1);
    $targetMaxMana = intval($target['max_mana'] ?? 1);

    // 成功率 = 基础 + 技能等级加成 - 目标抗性
    $baseRate = 0.3;
    $skillBonus = min(0.4, $skillLevel / 200);
    $manaDiff = ($myMaxMana - $targetMaxMana) / max(1, $myMaxMana);
    $manaBonus = max(-0.3, min(0.3, $manaDiff * 0.3));
    $successRate = $baseRate + $skillBonus + $manaBonus;

    $success = (mt_rand() / mt_getrandmax()) <= $successRate;

    $name = $me['name'] ?? '你';
    $targetNameStr = $target['name'] ?? '对方';

    if ($success) {
        // 成功：定身效果，不进入战斗
        // 持续时间 4~60秒，基于技能等级
        $duration = min(60, max(4, intval($skillLevel / 3)));
        $endTime = time() + $duration;

        if ($targetType === 'player') {
            Database::execute(
                'UPDATE characters SET daze_state = 1, daze_end_time = ? WHERE id = ?',
                [$endTime, $targetId]
            );
        } else {
            // NPC: 存入 npc_temp 表（updated_at 记录定身结束时间）
            Database::execute(
                "INSERT INTO npc_temp (npc_id, temp_key, temp_value, updated_at) VALUES (?, 'daze_state', '1', ?) ON DUPLICATE KEY UPDATE temp_value = '1', updated_at = ?",
                [$targetId, $endTime, $endTime]
            );
        }

        // 设置施法者 busy
        set_player_busy($charId, $cfg['busy']);

        $output = [];
        $output[] = HTML_HIMAG . '你掐诀念咒，一道迷离的光芒向' . $targetNameStr . '罩去！' . HTML_NOR;
        $output[] = HTML_HIMAG . $targetNameStr . '眼神渐渐迷离，呆呆地站在原地不能动弹。' . HTML_NOR;
        $output[] = '消耗法力 ' . $cfg['mana'] . ' 点，精神 ' . $cfg['sen'] . ' 点。';
        $output[] = $targetNameStr . '被定身 ' . $duration . ' 秒。';

        // 房间广播
        $roomId = $me['current_room'] ?? '';
        if (!empty($roomId)) {
            MessageDaemon::broadcastToRoom($roomId, $name . '掐诀念咒，' . $targetNameStr . '眼神渐渐迷离，呆立当场。', $charId, 'room');
        }
        // 通知目标（仅玩家）
        if ($targetType === 'player') {
            MessageDaemon::sendToPlayer($targetId, HTML_HIMAG . $name . '对你施展了迷魂之术，你感到一阵迷糊，无法动弹！' . HTML_NOR);
        }

        return [
            'success'   => true,
            'message'   => implode("\n", $output),
            'skip_queue'=> true,
            'duration'  => $duration,
            'mana_cost' => $cfg['mana'],
            'sen_cost'  => $cfg['sen'],
        ];
    } else {
        // 失败：目标会攻击施法者
        set_player_busy($charId, $cfg['busy']);

        $output = [];
        $output[] = HTML_HIRED . '你掐诀念咒，一道迷离的光芒向' . $targetNameStr . '罩去！' . HTML_NOR;
        $output[] = HTML_HIRED . '但' . $targetNameStr . '灵巧地避开了你的迷魂之术！' . HTML_NOR;
        $output[] = '消耗法力 ' . $cfg['mana'] . ' 点，精神 ' . $cfg['sen'] . ' 点。';

        // 房间广播
        $roomId = $me['current_room'] ?? '';
        if (!empty($roomId)) {
            MessageDaemon::broadcastToRoom($roomId, $name . '试图对' . $targetNameStr . '施展迷魂，但被避开了。', $charId, 'room');
        }
        // 通知目标（仅玩家）
        if ($targetType === 'player') {
            MessageDaemon::sendToPlayer($targetId, HTML_HIRED . $name . '试图对你施展迷魂之术，被你灵巧地避开了！' . HTML_NOR);
        }

        return [
            'success'   => true,
            'message'   => implode("\n", $output),
            'skip_queue'=> true,
            'mana_cost' => $cfg['mana'],
            'sen_cost'  => $cfg['sen'],
        ];
    }
}
