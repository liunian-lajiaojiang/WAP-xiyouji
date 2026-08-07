<?php
/**
 * 内功运用命令 (exert)
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 功能：运用已启用的内功心法，施展特殊效果
 * 
 * 用法：
 *   exert jingxin    # 冷泉神功·静心诀 - 降低杀气，获得闪避加成
 *   exert powerfade  # 摄气诀·化功 - 大幅降低杀气，战斗中有昏迷风险
 *   exert            # 查看可用运用类型
 */

require_once HELPER_PATH . 'SkillManager.php';

// 加载 exert 技能消耗配置
$_exertCfg = null;
if ($_exertCfg === null) {
    $_exertCfg = require __DIR__ . '/../config/exert.php';
}

// 运用类型别名映射
$EXERT_ALIAS_MAP = [
    'jingxin'    => 'jingxin',
    '静心'       => 'jingxin',
    '静心诀'     => 'jingxin',
    'powerfade'  => 'powerfade',
    '化功'       => 'powerfade',
    'recover'    => 'recover',
    '恢复'       => 'recover',
    'refresh'    => 'refresh',
    '提神'       => 'refresh',
    'heal'       => 'heal',
    '疗伤'       => 'heal',
    'powerup'    => 'powerup',
    '蓄力'       => 'powerup',
    'regenerate' => 'regenerate',
    '再生'       => 'regenerate',
    'transfer'   => 'transfer',
    '传送'       => 'transfer',
    '真气传送'   => 'transfer',
    'sheqi'      => 'sheqi',
    '舍气'       => 'sheqi',
    'yuanyue'    => 'yuanyue',
    '月圆'       => 'yuanyue',
    'lifeheal'   => 'lifeheal',
    '生命治疗'   => 'lifeheal',
    '救人'       => 'lifeheal',
];

function cmd_exert(int $charId, string $param = ''): array {
    global $EXERT_ALIAS_MAP;

    $me = CharacterModel::find($charId);
    if (!$me) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    // is_busy() 检查（统一使用 is_player_busy）
    if (is_player_busy($charId)) {
        return ['success' => false, 'message' => '你正在忙碌中，请稍后再试。'];
    }

    // 无参数时显示帮助
    $arg = trim($param);
    if (empty($arg)) {
        return exertHelp();
    }

    // 解析运用类型和目标（格式: <类型> [目标名]）
    $parts = preg_split('/\s+/', $arg, 2);
    $exertKey = strtolower(trim($parts[0]));
    $targetName = isset($parts[1]) ? trim($parts[1]) : '';

    // 别名映射
    $exertType = $EXERT_ALIAS_MAP[$exertKey] ?? null;
    if ($exertType === null) {
        return [
            'success' => false,
            'message' => '没有这种运用方式。输入 exert 查看可用的运用类型。'
        ];
    }

    // 查询角色已启用的内功
    $mappedForce = SkillManager::querySkillMapped($charId, 'force');
    if (empty($mappedForce)) {
        return ['success' => false, 'message' => '你必须先用 enable force 选择你要用的内功心法。'];
    }

    // 根据运用类型分发
    switch ($exertType) {
        case 'jingxin':
            return exertJingxin($charId, $me, $mappedForce);
        case 'powerfade':
            return exertPowerfade($charId, $me, $mappedForce);
        case 'recover':
            return exertRecover($charId, $me, $mappedForce);
        case 'refresh':
            return exertRefresh($charId, $me, $mappedForce);
        case 'heal':
            return exertHeal($charId, $me, $mappedForce);
        case 'powerup':
            return exertPowerup($charId, $me, $mappedForce);
        case 'regenerate':
            return exertRegenerate($charId, $me, $mappedForce);
        case 'transfer':
            return exertTransfer($charId, $me, $mappedForce, $targetName);
        case 'sheqi':
            return exertSheqi($charId, $me, $mappedForce, $targetName);
        case 'yuanyue':
            return exertYuanyue($charId, $me, $mappedForce, $targetName);
        case 'lifeheal':
            return exertLifeheal($charId, $me, $mappedForce, $targetName);
        default:
            return ['success' => false, 'message' => '该运用方式尚未实现。'];
    }
}

/**
 * 显示运用帮助信息
 */
function exertHelp(): array {
    $output = [];
    $output[] = HTML_HIYEL . '【内功运用】' . HTML_NOR;
    $output[] = '你可以运用已启用的内功心法，施展特殊效果。';
    $output[] = '';
    $output[] = '可用运用类型：';
    $output[] = '  recover（恢复）     - 运功恢复气血';
    $output[] = '  refresh（提神）     - 运功恢复精力';
    $output[] = '  regenerate（再生）  - 运功恢复有效气血上限';
    $output[] = '  heal（疗伤）        - 运功疗伤';
    $output[] = '  powerup（蓄力）     - 运功蓄力，临时提升攻击和防御';
    $output[] = '  jingxin（静心诀）   - 冷泉神功，降低杀气，获得闪避加成';
    $output[] = '  powerfade（化功）   - 摄气诀，大幅降低杀气，战斗中有昏迷风险';
    $output[] = '  transfer（真气传送） - 将多余内力传送给同门派玩家';
    $output[] = '  sheqi（舍气）       - 摄气诀，战斗中吸取对方气血';
    $output[] = '  yuanyue（月圆）     - 月宫圆月心法，解月毒+治疗气血（需目标）';
    $output[] = '  lifeheal（生命治疗） - 莲花心法，治疗他人气血（需目标）';
    $output[] = '';
    $output[] = '用法：exert <运用类型>';
    $output[] = '部分运用类型需要指定目标：exert <运用类型> <目标>';
    return [
        'success' => false,
        'message' => implode("\n", $output),
        'skip_queue' => true
    ];
}

/**
 * 静心诀 - 冷泉神功
 * 降低杀气，获得临时闪避加成
 */
function exertJingxin(int $charId, array $me, string $mappedForce): array {
    global $_exertCfg;
    // 前置条件：已启用冷泉神功
    if (strpos($mappedForce, 'lengquan') === false) {
        return ['success' => false, 'message' => '静心诀需要冷泉神功，你目前启用的内功不支持。'];
    }

    // 获取技能等级
    $skillLevel = SkillManager::getSkillLevel($charId, $mappedForce);
    if ($skillLevel < 1) {
        return ['success' => false, 'message' => '你还没有学会冷泉神功。'];
    }

    // 资源检查：内力 >= 配置值
    $cfg = $_exertCfg['jingxin'];
    $currentForce = intval($me['force'] ?? 0);
    if ($currentForce < $cfg['force_min']) {
        return ['success' => false, 'message' => "你的内力不足，至少需要{$cfg['force_min']}点内力才能施展静心诀。"];
    }

    // 状态检查：bellicosity > 0
    $bellicosity = intval($me['bellicosity'] ?? 0);
    if ($bellicosity <= 0) {
        return ['success' => false, 'message' => '你目前没有杀气，不需要运用静心诀。'];
    }

    // 检查是否在 powerup 临时状态中
    if (!empty($_SESSION['powerup_' . $charId])) {
        return ['success' => false, 'message' => '你正在运功状态中，无法施展静心诀。'];
    }

    // 计算效果（从配置读取系数）
    $bellicosityReduce = intval($skillLevel / $cfg['kill_reduce_div']);
    $forceCost = $cfg['force_cost'];
    $dodgeBonus = intval($skillLevel / $cfg['dodge_bonus_div']);
    $expiresAt = time() + $skillLevel;

    // 更新数据库：杀气减少，内力减少
    Database::execute(
        "UPDATE characters SET bellicosity = GREATEST(0, bellicosity - ?), `force` = `force` - ? WHERE id = ?",
        [$bellicosityReduce, $forceCost, $charId]
    );

    // 设置临时闪避加成（session存储）
    $_SESSION['dodge_bonus_' . $charId] = [
        'bonus' => $dodgeBonus,
        'expires_at' => $expiresAt,
    ];

    // 战斗中设置 busy（从配置读取秒数）
    $inCombat = CombatDaemon::isInCombat($charId);
    if ($inCombat) {
        set_player_busy($charId, $cfg['combat_busy']);
    }

    // 随机提升内功技能熟练度
    $messages = [];
    $practiceResult = SkillManager::practiceSkill($charId, $mappedForce);
    if ($practiceResult['success']) {
        $expGained = $practiceResult['exp_gained'] ?? 0;
        if ($expGained > 0) {
            $messages[] = '(内功熟练度+' . $expGained . ')';
        }
    }

    // 构建消息
    $name = $me['name'] ?? '你';
    $selfMsg = '你深深吸了一口气，心中的戾气顿时平复下来，周围一片祥和之气。';
    $roomMsg = $name . '深深吸了一口气，周围一片祥和之气。';

    $output = [];
    $output[] = HTML_HICYN . $selfMsg . HTML_NOR;
    $output[] = '杀气减少了 ' . $bellicosityReduce . ' 点，消耗内力 ' . $forceCost . ' 点。';
    $output[] = '你获得了 ' . $dodgeBonus . ' 点闪避加成，持续 ' . $skillLevel . ' 秒。';
    if (!empty($messages)) {
        $output[] = implode(' ', $messages);
    }

    // 房间广播
    $roomId = $me['current_room'] ?? '';
    if (!empty($roomId)) {
        require_once DAEMON_PATH . 'MessageDaemon.php';
        MessageDaemon::broadcastToRoom($roomId, $roomMsg, $charId, 'room');
    }

    return [
        'success' => true,
        'message' => implode("\n", $output),
        'skip_queue' => true,  // 告诉 action.php 不要再次保存消息
        'bellicosity_reduced' => $bellicosityReduce,
        'force_cost' => $forceCost,
        'dodge_bonus' => $dodgeBonus,
    ];
}

/**
 * 化功 - 摄气诀
 * 大幅降低杀气，战斗中有昏迷风险
 */
function exertPowerfade(int $charId, array $me, string $mappedForce): array {
    global $_exertCfg;
    // 前置条件：已启用摄气诀
    if (strpos($mappedForce, 'tonsillit') === false) {
        return ['success' => false, 'message' => '化功需要摄气诀，你目前启用的内功不支持。'];
    }

    // 获取技能等级
    $skillLevel = SkillManager::getSkillLevel($charId, $mappedForce);
    if ($skillLevel < 1) {
        return ['success' => false, 'message' => '你还没有学会摄气诀。'];
    }

    // 资源检查（从配置读取阈值）
    $cfg = $_exertCfg['powerfade'];
    $currentForce = intval($me['force'] ?? 0);
    $currentSen = intval($me['sen'] ?? 0);
    if ($currentForce < $cfg['force_min']) {
        return ['success' => false, 'message' => "你的内力不足，至少需要{$cfg['force_min']}点内力才能施展化功。"];
    }
    if ($currentSen < $cfg['sen_min']) {
        return ['success' => false, 'message' => "你的精神不足，至少需要{$cfg['sen_min']}点精神才能施展化功。"];
    }

    // 状态检查：bellicosity > 0
    $bellicosity = intval($me['bellicosity'] ?? 0);
    if ($bellicosity <= 0) {
        return ['success' => false, 'message' => '你目前没有杀气，不需要运用化功。'];
    }

    // 检查是否在 powerup 临时状态中
    if (!empty($_SESSION['powerup_' . $charId])) {
        return ['success' => false, 'message' => '你正在运功状态中，无法施展化功。'];
    }

    // 计算效果（从配置读取系数）
    $bellicosityReduce = $cfg['kill_reduce_base'] + intval($skillLevel / $cfg['kill_reduce_div']);
    $forceCost = $cfg['force_cost'];
    $senCost = $cfg['sen_cost'];

    // 更新数据库：杀气减少，内力减少，精神减少
    Database::execute(
        "UPDATE characters SET bellicosity = GREATEST(0, bellicosity - ?), `force` = `force` - ?, sen = sen - ? WHERE id = ?",
        [$bellicosityReduce, $forceCost, $senCost, $charId]
    );

    // 战斗风险判定
    $inCombat = CombatDaemon::isInCombat($charId);
    $fainted = false;
    if ($inCombat) {
        set_player_busy($charId, $cfg['combat_busy']);

        // 昏迷判定：random(skill) < cps 时昏迷（原始项目 random(n) 返回 0 到 n-1）
        $cps = intval($me['cps'] ?? $cfg['default_cps']);
        if (rand(0, $skillLevel - 1) < $cps) {
            $fainted = true;
            // 设置昏迷状态
            $_SESSION['fainted_' . $charId] = [
                'start_time' => time(),
                'duration' => $cfg['stun_seconds'],
            ];
        }
    }

    // 随机提升内功技能熟练度
    $messages = [];
    $practiceResult = SkillManager::practiceSkill($charId, $mappedForce);
    if ($practiceResult['success']) {
        $expGained = $practiceResult['exp_gained'] ?? 0;
        if ($expGained > 0) {
            $messages[] = '(内功熟练度+' . $expGained . ')';
        }
    }

    // 构建消息
    $name = $me['name'] ?? '你';
    $selfMsg = '你盘膝坐下，双目微闭，将全身内力缓缓压入体内的杀气之中。';
    $roomMsg = $name . '盘膝坐下，双目微闭，似乎在运功。';

    $output = [];
    $output[] = HTML_HICYN . $selfMsg . HTML_NOR;
    $output[] = '杀气减少了 ' . $bellicosityReduce . ' 点，消耗内力 ' . $forceCost . ' 点，消耗精神 ' . $senCost . ' 点。';
    if (!empty($messages)) {
        $output[] = implode(' ', $messages);
    }
    if ($fainted) {
        $output[] = HTML_HIRED . '你感到一阵眩晕，眼前一黑，昏了过去！' . HTML_NOR;
    }

    // 房间广播
    $roomId = $me['current_room'] ?? '';
    if (!empty($roomId)) {
        require_once DAEMON_PATH . 'MessageDaemon.php';
        MessageDaemon::broadcastToRoom($roomId, $roomMsg, $charId, 'room');
    }

    return [
        'success' => true,
        'message' => implode("\n", $output),
        'skip_queue' => true,  // 告诉 action.php 不要再次保存消息
        'bellicosity_reduced' => $bellicosityReduce,
        'force_cost' => $forceCost,
        'sen_cost' => $senCost,
        'fainted' => $fainted,
    ];
}

/**
 * 恢复 - 运功恢复气血
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 消耗内力恢复气血，战斗中额外busy 1秒
 */
function exertRecover(int $charId, array $me, string $mappedForce): array {
    global $_exertCfg;
    // 获取技能等级
    $skillLevel = SkillManager::getSkillLevel($charId, $mappedForce);
    if ($skillLevel < 1) {
        return ['success' => false, 'message' => '你还没有学会内功心法。'];
    }

    // 资源检查（从配置读取阈值）
    $cfg = $_exertCfg['recover'];
    $currentForce = intval($me['force'] ?? 0);
    if ($currentForce < $cfg['force_min']) {
        return ['success' => false, 'message' => "你的内力不足，至少需要{$cfg['force_min']}点内力。"];
    }

    // 计算恢复量
    $currentKee = intval($me['kee'] ?? 0);
    $maxKee = intval($me['max_kee'] ?? 0);
    $diff = $maxKee - $currentKee;
    if ($diff <= 0) {
        return ['success' => false, 'message' => '你的气血充沛，不需要恢复。'];
    }

    // 恢复量 = min(差距, 内功等级 * 乘数)
    $recoverAmount = min($diff, $skillLevel * $cfg['recover_mult']);
    // 消耗内力 = 差距 * 乘数 / 内功等级
    $forceCost = max($cfg['force_min'], intval($diff * $cfg['force_cost_mult'] / max(1, $skillLevel)));
    $forceCost = min($forceCost, $currentForce);

    // 实际恢复量受内力消耗限制
    $actualRecover = min($recoverAmount, intval($forceCost * $skillLevel / $cfg['actual_mult']));

    // 更新数据库
    Database::execute(
        "UPDATE characters SET kee = LEAST(max_kee, kee + ?), `force` = `force` - ? WHERE id = ?",
        [$actualRecover, $forceCost, $charId]
    );

    // 战斗中设置 busy
    $inCombat = CombatDaemon::isInCombat($charId);
    if ($inCombat) {
        set_player_busy($charId, $cfg['combat_busy']);
    }

    // 随机提升内功技能熟练度
    $messages = [];
    $practiceResult = SkillManager::practiceSkill($charId, $mappedForce);
    if ($practiceResult['success']) {
        $expGained = $practiceResult['exp_gained'] ?? 0;
        if ($expGained > 0) {
            $messages[] = '(内功熟练度+' . $expGained . ')';
        }
    }

    $output = [];
    $output[] = HTML_HICYN . '你运功调息，气血恢复了 ' . $actualRecover . ' 点。' . HTML_NOR;
    $output[] = '消耗内力 ' . $forceCost . ' 点。';
    if (!empty($messages)) {
        $output[] = implode(' ', $messages);
    }

    // 房间广播
    $name = $me['name'] ?? '你';
    $roomId = $me['current_room'] ?? '';
    if (!empty($roomId)) {
        require_once DAEMON_PATH . 'MessageDaemon.php';
        MessageDaemon::broadcastToRoom($roomId, $name . '运功调息，面色渐渐红润起来。', $charId, 'room');
    }

    return [
        'success' => true,
        'message' => implode("\n", $output),
        'skip_queue' => true,
        'recover_amount' => $actualRecover,
        'force_cost' => $forceCost,
    ];
}

/**
 * 提神 - 运功恢复精力
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 消耗内力恢复精力
 */
function exertRefresh(int $charId, array $me, string $mappedForce): array {
    global $_exertCfg;
    // 获取技能等级
    $skillLevel = SkillManager::getSkillLevel($charId, $mappedForce);
    if ($skillLevel < 1) {
        return ['success' => false, 'message' => '你还没有学会内功心法。'];
    }

    // 资源检查（从配置读取阈值）
    $cfg = $_exertCfg['refresh'];
    $currentForce = intval($me['force'] ?? 0);
    if ($currentForce < $cfg['force_min']) {
        return ['success' => false, 'message' => "你的内力不足，至少需要{$cfg['force_min']}点内力。"];
    }

    // 计算恢复量
    $currentSen = intval($me['sen'] ?? 0);
    $maxSen = intval($me['max_sen'] ?? 0);
    $diff = $maxSen - $currentSen;
    if ($diff <= 0) {
        return ['success' => false, 'message' => '你的精力充沛，不需要恢复。'];
    }

    // 恢复量 = min(差距, 内功等级 * 乘数)
    $recoverAmount = min($diff, $skillLevel * $cfg['recover_mult']);
    // 消耗内力
    $forceCost = max($cfg['force_min'], intval($diff * $cfg['force_cost_mult'] / max(1, $skillLevel)));
    $forceCost = min($forceCost, $currentForce);

    // 实际恢复量受内力消耗限制
    $actualRecover = min($recoverAmount, intval($forceCost * $skillLevel / $cfg['actual_div']));

    // 更新数据库
    Database::execute(
        "UPDATE characters SET sen = LEAST(max_sen, sen + ?), `force` = `force` - ? WHERE id = ?",
        [$actualRecover, $forceCost, $charId]
    );

    // 战斗中设置 busy
    $inCombat = CombatDaemon::isInCombat($charId);
    if ($inCombat) {
        set_player_busy($charId, $cfg['combat_busy']);
    }

    // 随机提升内功技能熟练度
    $messages = [];
    $practiceResult = SkillManager::practiceSkill($charId, $mappedForce);
    if ($practiceResult['success']) {
        $expGained = $practiceResult['exp_gained'] ?? 0;
        if ($expGained > 0) {
            $messages[] = '(内功熟练度+' . $expGained . ')';
        }
    }

    $output = [];
    $output[] = HTML_HICYN . '你闭目运功，精力恢复了 ' . $actualRecover . ' 点。' . HTML_NOR;
    $output[] = '消耗内力 ' . $forceCost . ' 点。';
    if (!empty($messages)) {
        $output[] = implode(' ', $messages);
    }

    // 房间广播
    $name = $me['name'] ?? '你';
    $roomId = $me['current_room'] ?? '';
    if (!empty($roomId)) {
        require_once DAEMON_PATH . 'MessageDaemon.php';
        MessageDaemon::broadcastToRoom($roomId, $name . '闭目运功，精神渐渐恢复。', $charId, 'room');
    }

    return [
        'success' => true,
        'message' => implode("\n", $output),
        'skip_queue' => true,
        'recover_amount' => $actualRecover,
        'force_cost' => $forceCost,
    ];
}

/**
 * 疗伤 - 运功疗伤
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 消耗内力和精神恢复受伤状态
 */
function exertHeal(int $charId, array $me, string $mappedForce): array {
    global $_exertCfg;
    // 获取技能等级
    $skillLevel = SkillManager::getSkillLevel($charId, $mappedForce);
    if ($skillLevel < 1) {
        return ['success' => false, 'message' => '你还没有学会内功心法。'];
    }

    // 资源检查（从配置读取阈值）
    $cfg = $_exertCfg['heal'];
    $currentForce = intval($me['force'] ?? 0);
    $currentSen = intval($me['sen'] ?? 0);
    if ($currentForce < $cfg['force_min']) {
        return ['success' => false, 'message' => "你的内力不足，至少需要{$cfg['force_min']}点内力才能疗伤。"];
    }
    if ($currentSen < $cfg['sen_min']) {
        return ['success' => false, 'message' => "你的精神不足，至少需要{$cfg['sen_min']}点精神才能疗伤。"];
    }

    // 计算疗伤量
    $currentKee = intval($me['kee'] ?? 0);
    $maxKee = intval($me['max_kee'] ?? 0);
    $diff = $maxKee - $currentKee;
    if ($diff <= 0) {
        return ['success' => false, 'message' => '你没有受伤，不需要疗伤。'];
    }

    // 疗伤量 = 内功等级 * 乘数 + 精神 * 乘数
    $healAmount = min($diff, intval($skillLevel * $cfg['heal_skill_mult'] + $currentSen * $cfg['heal_sen_mult']));
    $forceCost = $cfg['force_cost'];
    $senCost = $cfg['sen_cost'];

    // 更新数据库
    Database::execute(
        "UPDATE characters SET kee = LEAST(max_kee, kee + ?), `force` = `force` - ?, sen = sen - ? WHERE id = ?",
        [$healAmount, $forceCost, $senCost, $charId]
    );

    // 战斗中设置 busy
    $inCombat = CombatDaemon::isInCombat($charId);
    if ($inCombat) {
        set_player_busy($charId, $cfg['combat_busy']);
    }

    // 随机提升内功技能熟练度
    $messages = [];
    $practiceResult = SkillManager::practiceSkill($charId, $mappedForce);
    if ($practiceResult['success']) {
        $expGained = $practiceResult['exp_gained'] ?? 0;
        if ($expGained > 0) {
            $messages[] = '(内功熟练度+' . $expGained . ')';
        }
    }

    $output = [];
    $output[] = HTML_HICYN . '你盘膝坐下，运功疗伤，伤口渐渐愈合，恢复了 ' . $healAmount . ' 点气血。' . HTML_NOR;
    $output[] = '消耗内力 ' . $forceCost . ' 点，消耗精神 ' . $senCost . ' 点。';
    if (!empty($messages)) {
        $output[] = implode(' ', $messages);
    }

    // 房间广播
    $name = $me['name'] ?? '你';
    $roomId = $me['current_room'] ?? '';
    if (!empty($roomId)) {
        require_once DAEMON_PATH . 'MessageDaemon.php';
        MessageDaemon::broadcastToRoom($roomId, $name . '盘膝坐下，运功疗伤。', $charId, 'room');
    }

    return [
        'success' => true,
        'message' => implode("\n", $output),
        'skip_queue' => true,
        'heal_amount' => $healAmount,
        'force_cost' => $forceCost,
        'sen_cost' => $senCost,
    ];
}

/**
 * 蓄力 - 运功蓄力，临时提升攻击和防御
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 消耗内力获得临时攻击/防御加成
 */
function exertPowerup(int $charId, array $me, string $mappedForce): array {
    global $_exertCfg;
    // 获取技能等级
    $skillLevel = SkillManager::getSkillLevel($charId, $mappedForce);
    if ($skillLevel < 1) {
        return ['success' => false, 'message' => '你还没有学会内功心法。'];
    }

    // 资源检查（从配置读取阈值）
    $cfg = $_exertCfg['powerup'];
    $currentForce = intval($me['force'] ?? 0);
    if ($currentForce < $cfg['force_min']) {
        return ['success' => false, 'message' => "你的内力不足，至少需要{$cfg['force_min']}点内力才能蓄力。"];
    }

    // 检查是否已在蓄力状态
    if (!empty($_SESSION['powerup_' . $charId])) {
        $expiresAt = $_SESSION['powerup_' . $charId]['expires_at'] ?? 0;
        if (time() < $expiresAt) {
            return ['success' => false, 'message' => '你已经在蓄力状态中了。'];
        }
    }

    // 计算加成效果（从配置读取系数）
    $attackBonus = intval($skillLevel / $cfg['attack_div']) + $cfg['attack_base'];
    $defenseBonus = intval($skillLevel / $cfg['defense_div']) + $cfg['defense_base'];
    $forceCost = $cfg['force_cost'];
    $duration = $skillLevel * $cfg['duration_mult'];

    // 更新数据库：内力减少
    Database::execute(
        "UPDATE characters SET `force` = `force` - ? WHERE id = ?",
        [$forceCost, $charId]
    );

    // 设置临时蓄力状态
    $_SESSION['powerup_' . $charId] = [
        'attack_bonus'  => $attackBonus,
        'defense_bonus' => $defenseBonus,
        'expires_at'    => time() + $duration,
    ];

    // 同步到 char_apply（让 AttributeHelper 能读取）
    if (!isset($_SESSION["char_apply_{$charId}"])) {
        $_SESSION["char_apply_{$charId}"] = [];
    }
    $_SESSION["char_apply_{$charId}"]['attack'] = ($_SESSION["char_apply_{$charId}"]['attack'] ?? 0) + $attackBonus;
    $_SESSION["char_apply_{$charId}"]['defense'] = ($_SESSION["char_apply_{$charId}"]['defense'] ?? 0) + $defenseBonus;

    // 添加到 StatusEffectHelper
    require_once HELPER_PATH . 'StatusEffectHelper.php';
    StatusEffectHelper::addBuff($charId, 'powerup', $attackBonus, intval($duration / $cfg['effect_dur_div']), 'exert_powerup');

    // 战斗中设置 busy
    $inCombat = CombatDaemon::isInCombat($charId);
    if ($inCombat) {
        set_player_busy($charId, $cfg['combat_busy']);
    }

    // 随机提升内功技能熟练度
    $messages = [];
    $practiceResult = SkillManager::practiceSkill($charId, $mappedForce);
    if ($practiceResult['success']) {
        $expGained = $practiceResult['exp_gained'] ?? 0;
        if ($expGained > 0) {
            $messages[] = '(内功熟练度+' . $expGained . ')';
        }
    }

    $output = [];
    $output[] = HTML_HICYN . '你深吸一口气，将内力灌注全身，感到力量大增！' . HTML_NOR;
    $output[] = '攻击 +' . $attackBonus . '，防御 +' . $defenseBonus . '，持续 ' . $duration . ' 秒。';
    $output[] = '消耗内力 ' . $forceCost . ' 点。';
    if (!empty($messages)) {
        $output[] = implode(' ', $messages);
    }

    // 房间广播
    $name = $me['name'] ?? '你';
    $roomId = $me['current_room'] ?? '';
    if (!empty($roomId)) {
        require_once DAEMON_PATH . 'MessageDaemon.php';
        MessageDaemon::broadcastToRoom($roomId, $name . '深吸一口气，气势陡然攀升！', $charId, 'room');
    }

    return [
        'success' => true,
        'message' => implode("\n", $output),
        'skip_queue' => true,
        'attack_bonus'  => $attackBonus,
        'defense_bonus' => $defenseBonus,
        'duration'      => $duration,
        'force_cost'    => $forceCost,
    ];
}

/**
 * 再生 - 运功恢复有效气血上限
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 消耗内力恢复 eff_kee（有效气血上限），用于恢复受伤后的气血上限
 */
function exertRegenerate(int $charId, array $me, string $mappedForce): array {
    global $_exertCfg;
    // 获取技能等级
    $skillLevel = SkillManager::getSkillLevel($charId, $mappedForce);
    if ($skillLevel < 1) {
        return ['success' => false, 'message' => '你还没有学会内功心法。'];
    }

    // 资源检查（从配置读取阈值）
    $cfg = $_exertCfg['regenerate'];
    $currentForce = intval($me['force'] ?? 0);
    if ($currentForce < $cfg['force_min']) {
        return ['success' => false, 'message' => "你的内力不足，至少需要{$cfg['force_min']}点内力。"];
    }

    // 计算恢复量
    $currentEffKee = intval($me['eff_kee'] ?? 0);
    $maxKee = intval($me['max_kee'] ?? 0);
    $diff = $maxKee - $currentEffKee;
    if ($diff <= 0) {
        return ['success' => false, 'message' => '你的气血上限完好，不需要再生。'];
    }

    // 再生量 = min(差距, 内功等级 * 乘数)
    $regenerateAmount = min($diff, $skillLevel * $cfg['regen_mult']);
    // 消耗内力 = 差距 * 乘数 / 内功等级
    $forceCost = max($cfg['force_min'], intval($diff * $cfg['force_cost_mult'] / max(1, $skillLevel)));
    $forceCost = min($forceCost, $currentForce);

    // 实际再生量受内力消耗限制
    $actualRegenerate = min($regenerateAmount, intval($forceCost * $skillLevel / $cfg['actual_div']));

    // 更新数据库：恢复 eff_kee 和 kee（如果 kee 低于 eff_kee）
    $newEffKee = min($maxKee, $currentEffKee + $actualRegenerate);
    $newKee = min($newEffKee, intval($me['kee'] ?? 0));
    
    Database::execute(
        "UPDATE characters SET eff_kee = ?, kee = LEAST(?, kee), `force` = `force` - ? WHERE id = ?",
        [$newEffKee, $newEffKee, $forceCost, $charId]
    );

    // 战斗中设置 busy
    $inCombat = CombatDaemon::isInCombat($charId);
    if ($inCombat) {
        set_player_busy($charId, $cfg['combat_busy']);
    }

    // 随机提升内功技能熟练度
    $messages = [];
    $practiceResult = SkillManager::practiceSkill($charId, $mappedForce);
    if ($practiceResult['success']) {
        $expGained = $practiceResult['exp_gained'] ?? 0;
        if ($expGained > 0) {
            $messages[] = '(内功熟练度+' . $expGained . ')';
        }
    }

    $output = [];
    $output[] = HTML_HICYN . '你运功调息，气血上限恢复了 ' . $actualRegenerate . ' 点。' . HTML_NOR;
    $output[] = '消耗内力 ' . $forceCost . ' 点。';
    if (!empty($messages)) {
        $output[] = implode(' ', $messages);
    }

    // 房间广播
    $name = $me['name'] ?? '你';
    $roomId = $me['current_room'] ?? '';
    if (!empty($roomId)) {
        require_once DAEMON_PATH . 'MessageDaemon.php';
        MessageDaemon::broadcastToRoom($roomId, $name . '运功调息，面色渐渐红润起来。', $charId, 'room');
    }

    return [
        'success' => true,
        'message' => implode("\n", $output),
        'skip_queue' => true,
        'regenerate_amount' => $actualRegenerate,
        'force_cost' => $forceCost,
    ];
}

/**
 * 真气传送 - 将多余内力传送给同门派玩家
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 要求：双方启用相同内功，施法者内力超过上限
 */
function exertTransfer(int $charId, array $me, string $mappedForce, string $targetName): array {
    global $_exertCfg;
    // 获取技能等级
    $skillLevel = SkillManager::getSkillLevel($charId, $mappedForce);
    if ($skillLevel < 1) {
        return ['success' => false, 'message' => '你还没有学会内功心法。'];
    }

    // 检查目标
    if (empty($targetName)) {
        return ['success' => false, 'message' => '你要输送内力给谁？'];
    }

    // 查找目标角色
    $target = CharacterModel::findByName($targetName);
    if (!$target) {
        return ['success' => false, 'message' => '这里没有这个人。'];
    }

    $targetId = intval($target['id']);
    
    // 不能对自己使用
    if ($targetId == $charId) {
        return ['success' => false, 'message' => '你不能输送内力给自己。'];
    }

    // 检查目标是否忙碌
    if (is_player_busy($targetId)) {
        return ['success' => false, 'message' => '对方正在忙得很。'];
    }

    // 检查目标是否在战斗中
    if (CombatDaemon::isInCombat($targetId)) {
        return ['success' => false, 'message' => '对方正在忙得很。'];
    }

    // 检查双方是否启用相同内功
    $targetMappedForce = SkillManager::querySkillMapped($targetId, 'force');
    if ($targetMappedForce !== $mappedForce) {
        $targetNameStr = $target['name'] ?? '对方';
        return ['success' => false, 'message' => $targetNameStr . '所使的内功和你不同。'];
    }

    // 检查施法者内力是否超过上限
    $currentForce = intval($me['force'] ?? 0);
    $maxForce = intval($me['max_force'] ?? 0);
    if ($currentForce <= $maxForce) {
        return ['success' => false, 'message' => '你的内力不够。'];
    }

    // 计算可传送的内力（从配置读取除数）
    $cfg = $_exertCfg['transfer'];
    $excessForce = $currentForce - $maxForce;
    if ($excessForce / $cfg['excess_div'] < 1) {
        return ['success' => false, 'message' => '你的内力不够。'];
    }

    // 消耗内力（传送一半的多余内力）
    $forceCost = intval($excessForce / $cfg['excess_div']);
    
    // 更新施法者内力
    Database::execute(
        "UPDATE characters SET `force` = `force` - ? WHERE id = ?",
        [$forceCost, $charId]
    );

    // 传送成功率判定（从配置读取除数）
    $transferChance = $forceCost / $cfg['chance_div'];
    if (rand(0, intval($transferChance) - 1) >= $skillLevel) {
        // 传送失败
        set_player_busy($charId, $cfg['fail_busy_base'] + rand(0, $cfg['fail_busy_rand']));
        return [
            'success' => true,
            'message' => '你双手抵在对方背后，将一股内力缓缓输送过去。\n你失败了。',
            'skip_queue' => true,
        ];
    }

    // 传送成功
    $targetForce = intval($target['force'] ?? 0);
    $targetMaxForce = intval($target['max_force'] ?? 0);
    
    // 目标获得的内力，不超过上限
    $transferGain = intval($forceCost / $cfg['target_receive_div']);
    $newTargetForce = min($targetMaxForce, $targetForce + $transferGain);
    
    Database::execute(
        "UPDATE characters SET `force` = ? WHERE id = ?",
        [$newTargetForce, $targetId]
    );

    // 设置双方 busy
    set_player_busy($charId, $cfg['success_busy_base'] + rand(0, $cfg['success_busy_rand']));
    set_player_busy($targetId, $cfg['success_busy_base'] + rand(0, $cfg['success_busy_rand']));

    // 构建消息
    $name = $me['name'] ?? '你';
    $targetNameStr = $target['name'] ?? '对方';
    
    $output = [];
    $output[] = '你双手抵在' . $targetNameStr . '背后，将一股内力缓缓输送过去。';
    $output[] = 'Ok.';
    $output[] = '消耗内力 ' . $forceCost . ' 点，' . $targetNameStr . ' 获得内力 ' . $transferGain . ' 点。';

    // 房间广播
    $roomId = $me['current_room'] ?? '';
    if (!empty($roomId)) {
        require_once DAEMON_PATH . 'MessageDaemon.php';
        MessageDaemon::broadcastToRoom($roomId, $name . '双手抵在' . $targetNameStr . '背后，似乎在输送内力。', $charId, 'room');
    }

    // 发送消息给目标
    require_once DAEMON_PATH . 'MessageDaemon.php';
    MessageDaemon::sendToPlayer($targetId, HTML_HIYEL . '你感到一股热气从' . $name . '体内传送了过来。' . HTML_NOR);

    return [
        'success' => true,
        'message' => implode("\n", $output),
        'skip_queue' => true,
        'force_cost' => $forceCost,
        'transfer_gain' => $transferGain,
    ];
}

/**
 * 舍气 - 摄气诀，战斗中吸取对方气血
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 要求：摄气诀等级 >= 30，幽冥地府门派，战斗中使用
 */
function exertSheqi(int $charId, array $me, string $mappedForce, string $targetName): array {
    global $_exertCfg;
    // 前置条件：已启用摄气诀
    if (strpos($mappedForce, 'tonsillit') === false) {
        return ['success' => false, 'message' => '舍气需要摄气诀，你目前启用的内功不支持。'];
    }

    // 获取技能等级
    $cfg = $_exertCfg['sheqi'];
    $skillLevel = SkillManager::getSkillLevel($charId, $mappedForce);
    if ($skillLevel < $cfg['skill_min']) {
        return ['success' => false, 'message' => '你的摄气诀等级太低，不能吸取对方气血。'];
    }

    // 检查门派：必须是幽冥地府
    $family = $me['family'] ?? '';
    if ($family !== '幽冥地府') {
        return ['success' => false, 'message' => '你并非幽冥地府之人，不能吸取对方气血。'];
    }

    // 检查目标
    if (empty($targetName)) {
        return ['success' => false, 'message' => '你要吸取谁的气血？'];
    }

    // 查找目标角色
    $target = CharacterModel::findByName($targetName);
    if (!$target) {
        return ['success' => false, 'message' => '这里没有这个人。'];
    }

    $targetId = intval($target['id']);
    
    // 不能对自己使用
    if ($targetId == $charId) {
        return ['success' => false, 'message' => '你不能吸取自己的气血。'];
    }

    // 必须在战斗中
    if (!CombatDaemon::isInCombat($charId) || !CombatDaemon::isInCombat($targetId)) {
        return ['success' => false, 'message' => '只能在战斗中吸取对方气血。'];
    }

    // 检查气血是否过高
    $currentKee = intval($me['kee'] ?? 0);
    $maxKee = intval($me['max_kee'] ?? 0);
    if ($currentKee > $maxKee * $cfg['kee_threshold_mult']) {
        return ['success' => false, 'message' => '你的气血太盈，快要爆炸了。'];
    }

    // 计算吸取量
    $targetKee = intval($target['kee'] ?? 0);
    $qiLost = intval($targetKee / $cfg['absorb_div']);
    if ($qiLost < $cfg['absorb_min']) {
        return ['success' => false, 'message' => '对方就要死了，没什么好吸的了。'];
    }

    // 成功判定：基于道行和法力
    $myCombatExp = intval($me['combat_exp'] ?? 0);
    $targetCombatExp = intval($target['combat_exp'] ?? 0);
    $myMaxMana = intval($me['max_mana'] ?? 0);
    $targetMaxMana = intval($target['max_mana'] ?? 0);

    // 计算成功率（从配置读取除数）
    $ap = intval(pow($skillLevel, 3) / $cfg['ap_exp_div']) + $myCombatExp;
    $dp = $targetCombatExp;
    
    // 第一次判定：道行比较
    $success1 = (rand(0, $ap - 1) + $ap / 2) >= (rand(0, $dp - 1) + $dp / 2);
    
    // 第二次判定：法力比较
    $success2 = rand(0, $myMaxMana * $cfg['mana_check_mult'] - 1) >= $targetMaxMana;

    $success = $success1 && $success2;

    // 构建消息
    $name = $me['name'] ?? '你';
    $targetNameStr = $target['name'] ?? '对方';

    $output = [];
    $output[] = HTML_HIRED . $name . '阴阴一笑，露出森森獠牙向' . $targetNameStr . '的脖颈状咬了过去！' . HTML_NOR;

    if ($success) {
        // 成功吸取
        $actualQiLost = $qiLost - rand(0, intval($qiLost / $cfg['actual_div']) - 1);
        
        // 计算吸取量（受目标 max_kee 和 combat_exp 影响）
        $qiGain = $actualQiLost;
        $targetMaxKee = intval($target['max_kee'] ?? 0);
        if ($targetMaxKee < $maxKee) {
            $qiGain = intval($qiGain * $targetMaxKee / (1 + $maxKee));
        }
        if ($targetCombatExp < $myCombatExp) {
            $qiGain = intval($qiGain * $targetCombatExp / (1 + $myCombatExp));
        }

        // 更新数据库
        Database::execute(
            "UPDATE characters SET kee = kee - ? WHERE id = ?",
            [$actualQiLost, $targetId]
        );
        
        if ($qiGain > 0) {
            Database::execute(
                "UPDATE characters SET kee = LEAST(max_kee * ?, kee + ?) WHERE id = ?",
                [$cfg['kee_cap_mult'], $qiGain, $charId]
            );
        }

        $output[] = HTML_HIRED . '只见' . $targetNameStr . '头皮一麻，只觉全身气血源源不断地顺喉流出！' . HTML_NOR;
        $output[] = '你吸取了 ' . $qiGain . ' 点气血。';

        // 提升摄气诀熟练度
        if ($qiGain > 0 && $targetCombatExp > $myCombatExp && $skillLevel <= $cfg['skill_cap'] && rand(0, $cfg['skill_rand_max']) == 0) {
            $practiceResult = SkillManager::practiceSkill($charId, $mappedForce);
            if ($practiceResult['success']) {
                $expGained = $practiceResult['exp_gained'] ?? 0;
                if ($expGained > 0) {
                    $output[] = '(摄气诀熟练度+' . $expGained . ')';
                }
            }
        }
    } else {
        // 失败
        $output[] = HTML_HIRED . '只见' . $targetNameStr . '一扭头躲了过去。' . HTML_NOR;
    }

    // 设置 busy（从配置读取）
    set_player_busy($charId, $cfg['combat_busy']);

    // 房间广播
    $roomId = $me['current_room'] ?? '';
    if (!empty($roomId)) {
        require_once DAEMON_PATH . 'MessageDaemon.php';
        MessageDaemon::broadcastToRoom($roomId, implode("\n", $output), $charId, 'room');
    }

    // 目标会尝试杀死施法者
    // 在战斗系统中处理

    return [
        'success' => true,
        'message' => implode("\n", $output),
        'skip_queue' => true,
        'qi_gain' => $success ? $qiGain : 0,
        'success' => $success,
    ];
}

/**
 * 月圆 - 月宫圆月心法
 * 还原原始项目 exert yuanyue <目标>：解除目标中毒状态 + 治疗气血
 * 要求：moonforce 80级、内力超出上限 600、双方非战斗
 */
function exertYuanyue(int $charId, array $me, string $mappedForce, string $targetName): array {
    global $_exertCfg;

    // 前置条件：已启用月宫圆月心法
    if (strpos($mappedForce, 'moonforce') === false) {
        return ['success' => false, 'message' => '月圆需要月宫圆月心法，你目前启用的内功不支持。'];
    }

    // 获取技能等级
    $skillLevel = SkillManager::getSkillLevel($charId, $mappedForce);
    $cfg = $_exertCfg['yuanyue'];
    if ($skillLevel < $cfg['skill_min']) {
        return ['success' => false, 'message' => '你的圆月心法等级不够（需要' . $cfg['skill_min'] . '级以上）。'];
    }

    // 检查目标
    if (empty($targetName)) {
        return ['success' => false, 'message' => '你要为谁施展月圆？'];
    }

    $target = CharacterModel::findByName($targetName);
    if (!$target) {
        return ['success' => false, 'message' => '这里没有这个人。'];
    }
    $targetId = intval($target['id']);
    if ($targetId == $charId) {
        return ['success' => false, 'message' => '你不能对自己施展月圆。'];
    }

    // 双方必须非战斗
    if (CombatDaemon::isInCombat($charId)) {
        return ['success' => false, 'message' => '你正在战斗中，无法施展月圆。'];
    }
    if (CombatDaemon::isInCombat($targetId)) {
        return ['success' => false, 'message' => '对方正在战斗中，无法接受月圆。'];
    }

    // 检查目标是否在同一房间
    if (($target['current_room'] ?? '') !== ($me['current_room'] ?? '')) {
        return ['success' => false, 'message' => '这里没有这个人。'];
    }

    // 内力须超出上限
    $currentForce = intval($me['force'] ?? 0);
    $maxForce = intval($me['max_force'] ?? 0);
    if ($currentForce - $maxForce < $cfg['force_min']) {
        return ['success' => false, 'message' => '你的内力不够，需要超出上限' . $cfg['force_min'] . '点。'];
    }

    // 检查目标是否有中毒状态
    require_once HELPER_PATH . 'StatusEffectHelper.php';
    $poisonTypes = ['poison', 'snake_poison', 'ice_poison'];
    $poisonsRemoved = [];
    foreach ($poisonTypes as $pt) {
        if (StatusEffectHelper::hasBuff($targetId, $pt)) {
            StatusEffectHelper::removeBuff($targetId, $pt);
            $poisonsRemoved[] = $pt;
        }
    }

    // 计算治疗量
    $targetKee = intval($target['kee'] ?? 0);
    $targetMaxKee = intval($target['max_kee'] ?? 0);
    $healAmount = min($targetMaxKee - $targetKee, $skillLevel * $cfg['heal_mult']);
    if ($healAmount < 0) $healAmount = 0;

    // 更新数据库
    Database::execute(
        "UPDATE characters SET `force` = `force` - ?, kee = LEAST(max_kee, kee + ?) WHERE id = ?",
        [$cfg['force_cost'], $healAmount, $targetId]
    );
    // 扣除施法者内力
    Database::execute(
        "UPDATE characters SET `force` = `force` - ? WHERE id = ?",
        [$cfg['force_cost'], $charId]
    );

    // 设置 busy
    set_player_busy($charId, $cfg['busy_base'] + rand(0, $cfg['busy_rand']));

    // 提升技能熟练度
    $messages = [];
    $practiceResult = SkillManager::practiceSkill($charId, $mappedForce);
    if ($practiceResult['success']) {
        $expGained = $practiceResult['exp_gained'] ?? 0;
        if ($expGained > 0) $messages[] = '(圆月心法熟练度+' . $expGained . ')';
    }

    // 构建消息
    $name = $me['name'] ?? '你';
    $targetNameStr = $target['name'] ?? '对方';

    $output = [];
    $output[] = HTML_HICYN . '你运起圆月心法，一股柔和的内力缓缓注入' . $targetNameStr . '体内。' . HTML_NOR;
    if (!empty($poisonsRemoved)) {
        $output[] = HTML_HIGRN . $targetNameStr . '身上的毒素被化解了。' . HTML_NOR;
    }
    if ($healAmount > 0) {
        $output[] = $targetNameStr . '的气血恢复了 ' . $healAmount . ' 点。';
    }
    $output[] = '消耗内力 ' . $cfg['force_cost'] . ' 点。';
    if (!empty($messages)) $output[] = implode(' ', $messages);

    // 房间广播
    $roomId = $me['current_room'] ?? '';
    if (!empty($roomId)) {
        require_once DAEMON_PATH . 'MessageDaemon.php';
        MessageDaemon::broadcastToRoom($roomId, $name . '运起圆月心法，为' . $targetNameStr . '疗伤解毒。', $charId, 'room');
    }
    // 通知目标
    require_once DAEMON_PATH . 'MessageDaemon.php';
    MessageDaemon::sendToPlayer($targetId, HTML_HIYEL . $name . '为你施展了月圆，你感到体内毒素化解，气血恢复。' . HTML_NOR);

    return [
        'success' => true,
        'message' => implode("\n", $output),
        'skip_queue' => true,
        'heal_amount' => $healAmount,
        'poisons_removed' => $poisonsRemoved,
        'force_cost' => $cfg['force_cost'],
    ];
}

/**
 * 生命治疗 - 莲花心法
 * 还原原始项目 exert lifeheal <目标>：治疗目标气血(kee)
 * 要求：lotusforce、内力超出上限 150、双方非战斗、目标 eff_kee >= max_kee/5
 */
function exertLifeheal(int $charId, array $me, string $mappedForce, string $targetName): array {
    global $_exertCfg;

    // 前置条件：已启用莲花心法
    if (strpos($mappedForce, 'lotusforce') === false) {
        return ['success' => false, 'message' => '生命治疗需要莲花心法，你目前启用的内功不支持。'];
    }

    // 获取技能等级
    $skillLevel = SkillManager::getSkillLevel($charId, $mappedForce);
    if ($skillLevel < 1) {
        return ['success' => false, 'message' => '你还没有学会莲花心法。'];
    }

    // 检查目标
    if (empty($targetName)) {
        return ['success' => false, 'message' => '你要为谁治疗？'];
    }

    $target = CharacterModel::findByName($targetName);
    if (!$target) {
        return ['success' => false, 'message' => '这里没有这个人。'];
    }
    $targetId = intval($target['id']);
    if ($targetId == $charId) {
        return ['success' => false, 'message' => '你不能为自己治疗，请用 exert heal。'];
    }

    // 双方必须非战斗
    if (CombatDaemon::isInCombat($charId)) {
        return ['success' => false, 'message' => '你正在战斗中，无法施展生命治疗。'];
    }
    if (CombatDaemon::isInCombat($targetId)) {
        return ['success' => false, 'message' => '对方正在战斗中，无法接受治疗。'];
    }

    // 检查目标是否在同一房间
    if (($target['current_room'] ?? '') !== ($me['current_room'] ?? '')) {
        return ['success' => false, 'message' => '这里没有这个人。'];
    }

    $cfg = $_exertCfg['lifeheal'];

    // 目标 eff_kee 须 >= max_kee/5（伤势不能过重）
    $targetEffKee = intval($target['eff_kee'] ?? 0);
    $targetMaxKee = intval($target['max_kee'] ?? 0);
    if ($targetMaxKee > 0 && $targetEffKee < intval($targetMaxKee / $cfg['target_kee_ratio'])) {
        return ['success' => false, 'message' => '对方伤势太重，生命治疗无法起效。'];
    }

    // 内力须超出上限
    $currentForce = intval($me['force'] ?? 0);
    $maxForce = intval($me['max_force'] ?? 0);
    if ($currentForce - $maxForce < $cfg['force_min']) {
        return ['success' => false, 'message' => '你的内力不够，需要超出上限' . $cfg['force_min'] . '点。'];
    }

    // 计算治疗量
    $targetKee = intval($target['kee'] ?? 0);
    $healAmount = min($targetMaxKee - $targetKee, $skillLevel * $cfg['heal_mult']);
    if ($healAmount <= 0) {
        return ['success' => false, 'message' => '对方气血充沛，无需治疗。'];
    }

    // 更新数据库
    Database::execute(
        "UPDATE characters SET kee = LEAST(max_kee, kee + ?) WHERE id = ?",
        [$healAmount, $targetId]
    );
    Database::execute(
        "UPDATE characters SET `force` = `force` - ? WHERE id = ?",
        [$cfg['force_cost'], $charId]
    );

    // 设置 busy
    set_player_busy($charId, $cfg['busy_base'] + rand(0, $cfg['busy_rand']));

    // 提升技能熟练度
    $messages = [];
    $practiceResult = SkillManager::practiceSkill($charId, $mappedForce);
    if ($practiceResult['success']) {
        $expGained = $practiceResult['exp_gained'] ?? 0;
        if ($expGained > 0) $messages[] = '(莲花心法熟练度+' . $expGained . ')';
    }

    // 构建消息
    $name = $me['name'] ?? '你';
    $targetNameStr = $target['name'] ?? '对方';

    $output = [];
    $output[] = HTML_HICYN . '你运起莲花心法，一道温暖的内力流入' . $targetNameStr . '体内。' . HTML_NOR;
    $output[] = $targetNameStr . '的气血恢复了 ' . $healAmount . ' 点。';
    $output[] = '消耗内力 ' . $cfg['force_cost'] . ' 点。';
    if (!empty($messages)) $output[] = implode(' ', $messages);

    // 房间广播
    $roomId = $me['current_room'] ?? '';
    if (!empty($roomId)) {
        require_once DAEMON_PATH . 'MessageDaemon.php';
        MessageDaemon::broadcastToRoom($roomId, $name . '运起莲花心法，为' . $targetNameStr . '疗伤。', $charId, 'room');
    }
    // 通知目标
    require_once DAEMON_PATH . 'MessageDaemon.php';
    MessageDaemon::sendToPlayer($targetId, HTML_HIYEL . $name . '为你施展了生命治疗，你的气血恢复了' . $healAmount . '点。' . HTML_NOR);

    return [
        'success' => true,
        'message' => implode("\n", $output),
        'skip_queue' => true,
        'heal_amount' => $healAmount,
        'force_cost' => $cfg['force_cost'],
    ];
}