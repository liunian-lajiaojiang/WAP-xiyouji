<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 战斗页面 - 显示战斗状态和操作
 * 重写版：增加招式(perform)、运功(exert)操作链接
 */

// 加载游戏配置（定义MODEL_PATH、DAEMON_PATH等常量）
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Character.php';
require_once MODEL_PATH . 'Npc.php';
require_once DAEMON_PATH . 'CombatDaemon.php';
require_once HELPER_PATH . 'SkillManager.php';

// 引入 perform.php 获取 LEGACY_PERFORM_MAP（用于招式消耗数据的 fallback）
require_once CMD_PATH . 'perform.php';

/**
 * 获取招式动作的消耗数据（数据库优先，LEGACY fallback）
 * @param string $skillId 技能ID
 * @param string $actionCode 招式代码
 * @param array $dbAction 数据库中的招式数据
 * @return array 合并后的招式数据
 */
function getActionWithFallback(string $skillId, string $actionCode, array $dbAction): array {
    global $LEGACY_PERFORM_MAP;
    
    $key = $skillId . '/' . $actionCode;
    
    // 如果数据库有 force_cost 或 mana_cost 非0值，直接使用数据库
    $dbForceCost = intval($dbAction['force_cost'] ?? 0);
    $dbManaCost = intval($dbAction['mana_cost'] ?? 0);
    
    $result = $dbAction;
    
    // 如果数据库的消耗值为0，尝试从 LEGACY_PERFORM_MAP 获取
    if ($dbForceCost === 0 && isset($LEGACY_PERFORM_MAP[$key]['force_cost'])) {
        $result['force_cost'] = $LEGACY_PERFORM_MAP[$key]['force_cost'];
    }
    if ($dbManaCost === 0 && isset($LEGACY_PERFORM_MAP[$key]['mana_cost'])) {
        $result['mana_cost'] = $LEGACY_PERFORM_MAP[$key]['mana_cost'];
    }
    
    return $result;
}

// 要求登录
require_login();

$charId = get_char_id();
$char = CharacterModel::getFullInfo($charId);

if (!$char) {
    redirect('character_select.php');
}

// 检查是否在战斗中
if (!CombatDaemon::isInCombat($charId)) {
    // 战斗已结束（如对手投降），先读取跨session消息再跳转
    require_once DAEMON_PATH . 'MessageDaemon.php';
    $pendingMsgs = MessageDaemon::getPendingMessages($charId);
    $combatEndMsg = '';
    foreach ($pendingMsgs as $pm) {
        if (in_array($pm['type'], ['combat', 'system', 'private'])) {
            $combatEndMsg .= ($combatEndMsg ? "\n" : '') . $pm['message'];
        }
    }
    $redirectUrl = room_url($char['current_area'], $char['current_room']);
    if ($combatEndMsg) {
        $redirectUrl .= '&msg=' . urlencode($combatEndMsg);
    }
    redirect($redirectUrl);
}

$combatStatus = CombatDaemon::getCombatStatus($charId);

// 跨session容错：如果B被A拉入战斗，B的session中没有target_name（session是A的）
// 从DB补齐玩家目标的名字
if (empty($combatStatus['target_name']) && ($combatStatus['target_type'] ?? '') === 'player') {
    $targetInfo = Database::queryOne("SELECT name FROM characters WHERE id = ?", [intval($combatStatus['target_id'])]);
    if ($targetInfo) {
        $combatStatus['target_name'] = $targetInfo['name'];
    }
}

// === 服务端驱动NPC攻击 ===
// 每次进入战斗页面时检查并触发待处理的NPC攻击
// 还原LPC heart_beat机制：NPC有自己的心跳，不依赖前端定时器
$npcAttackResult = CombatDaemon::processPendingNpcAttacks($charId);
if ($npcAttackResult['attacks'] > 0 && !empty($npcAttackResult['messages'])) {
    // 将NPC攻击消息存入战斗日志
    $logKey = "combat_log_{$charId}";
    if (!isset($_SESSION[$logKey]) || !is_array($_SESSION[$logKey])) {
        $_SESSION[$logKey] = [];
    }
    $msgLines = array_filter(explode("\n", trim($npcAttackResult['messages'])));
    foreach ($msgLines as $line) {
        if (!empty($line)) {
            $_SESSION[$logKey][] = [
                'time' => date('H:i:s'),
                'content' => $line
            ];
        }
    }
    // 只保留最近50条
    if (count($_SESSION[$logKey]) > 50) {
        $_SESSION[$logKey] = array_slice($_SESSION[$logKey], -50);
    }

    // 如果NPC击杀了玩家，跳转到死亡页面
    if ($npcAttackResult['killed']) {
        redirect(room_url($char['current_area'], $char['current_room']) . '&msg=' . urlencode('你被打败了！'));
    }

    // 重新获取玩家血量（NPC攻击后可能变化）
    $char = CharacterModel::getFullInfo($charId);
}

// 获取多目标列表
$multiTargets = $combatStatus['multi_targets'] ?? [];

// === 互相击杀检测 ===
$isMutualCombat = false;
if (($combatStatus['target_type'] ?? '') === 'player') {
    $targetPlayerId = intval($combatStatus['target_id']);
    $mutualCheck = Database::queryOne(
        "SELECT id FROM active_combats WHERE char_id = ? AND target_id = ? AND target_type = 'player' AND is_friendly = 0 LIMIT 1",
        [$targetPlayerId, $charId]
    );
    $isMutualCombat = ($mutualCheck !== null);
}

// === 读取消息队列中的跨session通知（如互相击杀通知） ===
require_once DAEMON_PATH . 'MessageDaemon.php';
$combatLog = $_SESSION["combat_log_{$charId}"] ?? [];
$pendingMessages = MessageDaemon::getPendingMessages($charId);
$surrenderMsg = '';
foreach ($pendingMessages as $pm) {
    if (in_array($pm['type'], ['combat', 'system', 'private'])) {
        $combatLog[] = ['time' => date('H:i:s'), 'content' => $pm['message']];
        // 检测投降消息
        if (!$surrenderMsg && (strpos($pm['message'], '投降') !== false || strpos($pm['message'], '认输') !== false)) {
            $surrenderMsg = $pm['message'];
        }
    }
}
// 只保留最近8条
if (count($combatLog) > 8) {
    $combatLog = array_slice($combatLog, -8);
}
$_SESSION["combat_log_{$charId}"] = $combatLog;



// 获取对方气血（如果是NPC或妖怪，从active_combats表读取）
$targetHp = null;
$targetMaxHp = null;
$isFriendly = ($combatStatus['friendly'] ?? false);

// 玩家血量（击杀和切磋都使用真实血量）
$playerKee = $char['kee'];
$playerMaxKee = $char['max_kee'];

// 切磋模式：NPC 使用 session 中扣除后的血量
if ($isFriendly && $combatStatus['target_type'] === 'npc') {
    // NPC 使用真实血量（从 session 中获取已扣除的血量）
    $npc = Database::queryOne("SELECT max_kee FROM npcs WHERE id = ? LIMIT 1", [$combatStatus['target_id']]);
    $targetMaxHp = max(100, intval($npc['max_kee'] ?? 100));
    
    // 优先从 session 获取 NPC 当前血量（已扣除伤害）
    $npcHpKey = "npc_hp_friendly_{$combatStatus['target_id']}";
    $targetHp = $_SESSION[$npcHpKey] ?? $targetMaxHp;
} elseif ($combatStatus['target_type'] === 'npc' || $combatStatus['target_type'] === 'yaoguai') {
    // 击杀模式：使用真实血量
    if (isset($combatStatus['target_current_hp'])) {
        $targetHp = $combatStatus['target_current_hp'];
        $targetMaxHp = $combatStatus['target_max_hp'] ?? 0;
    } else {
        $targetHp = CombatDaemon::getTargetCurrentHp(intval($combatStatus['target_id']), $combatStatus['target_type']);
        if ($combatStatus['target_type'] === 'yaoguai') {
            $yaoguai = Database::queryOne("SELECT max_kee FROM mieyao_yaoguai WHERE id = ?", [$combatStatus['target_id']]);
            if ($yaoguai) {
                $targetMaxHp = $yaoguai['max_kee'] ?? 100;
            }
        } else {
            $npc = NpcModel::find(intval($combatStatus['target_id']));
            if ($npc) {
                $targetMaxHp = max(100, intval($npc['max_kee'] ?? 100));
            }
        }
    }
} elseif ($combatStatus['target_type'] === 'player') {
    // 玩家对战：从characters表获取对手的真实血量
    $targetChar = CharacterModel::find(intval($combatStatus['target_id']));
    if ($targetChar) {
        $targetHp = intval($targetChar['kee'] ?? 0);
        $targetMaxHp = intval($targetChar['max_kee'] ?? 0);
    }
}

// 获取待显示的消息（URL参数优先级更高）
$message = $_GET['msg'] ?? '';
if (empty($message)) {
    $flashMessage = $_SESSION['flash_message'] ?? null;
    if ($flashMessage && time() - ($flashMessage['timestamp'] ?? 0) < 10) {
        $message = $flashMessage['content'];
        unset($_SESSION['flash_message']);
    }
}

// 获取并更新战斗日志
$combatLog = $_SESSION["combat_log_{$charId}"] ?? [];
if (!empty($message)) {
    $combatLog[] = ['time' => date('H:i:s'), 'content' => $message];
    if (count($combatLog) > 8) {
        $combatLog = array_slice($combatLog, -8);
    }
    $_SESSION["combat_log_{$charId}"] = $combatLog;
}

// ========== 获取上一回合的伤害数据（用于飘血显示） ==========
$damageData = $_SESSION['combat_damage_' . $charId] ?? null;
$playerDamage = 0;
$targetDamage = 0;
if ($damageData && time() - ($damageData['timestamp'] ?? 0) < 5) {
    $playerDamage = intval($damageData['player_damage'] ?? 0);
    $targetDamage = intval($damageData['damage'] ?? 0);
    // 读取后清除，避免重复显示
    unset($_SESSION['combat_damage_' . $charId]);
}

// ========== 新增：获取玩家已学技能和可用招式 ==========
$allSkills = SkillManager::getAllSkills($charId);

// 获取当前启用的技能映射（只有启用的技能才显示招式）
$skillMapRows = Database::queryAll(
    "SELECT skill_type, mapped_skill FROM character_skill_map WHERE char_id = ?",
    [$charId]
);
$enabledSkillIds = [];
foreach ($skillMapRows as $row) {
    if (!empty($row['mapped_skill'])) {
        $enabledSkillIds[] = $row['mapped_skill'];
    }
}

// 检查忙碌状态
$isBusy = is_player_busy($charId);

// 构建可用招式列表（perform）- 只显示已启用的技能
$availableActions = [];
foreach ($allSkills as $skill) {
    $skillId = $skill['skill_id'] ?? '';
    if (empty($skillId)) continue;
    
    // 只显示已启用的技能（skill_id 在启用列表中）
    if (!in_array($skillId, $enabledSkillIds)) {
        continue;
    }

    // 获取该技能的招式
    $actions = SkillManager::getSkillActions($skillId);
    if (empty($actions)) continue;

    $skillLevel = intval($skill['level'] ?? 0);
    $usableActions = [];

    foreach ($actions as $action) {
        $minLevel = intval($action['min_level'] ?? 0);
        if ($skillLevel >= $minLevel) {
            // 使用合并函数获取招式数据（数据库优先，LEGACY fallback）
            $actionCode = $action['action_code'] ?? '';
            $mergedAction = getActionWithFallback($skillId, $actionCode, $action);
            
            $mergedAction['can_use'] = true;
            $mergedAction['reason'] = '';
            // 检查忙碌状态
            if ($isBusy) {
                $mergedAction['can_use'] = false;
                $mergedAction['reason'] = '正忙着';
            }
            // 检查内力是否充足
            $forceCost = intval($mergedAction['force_cost'] ?? 0);
            $manaCost = intval($mergedAction['mana_cost'] ?? 0);
            if ($mergedAction['can_use'] && $forceCost > 0 && $forceCost > intval($char['force'] ?? 0)) {
                $mergedAction['can_use'] = false;
                $mergedAction['reason'] = '内力不足';
            }
            if ($mergedAction['can_use'] && $manaCost > 0 && $manaCost > intval($char['mana'] ?? 0)) {
                $mergedAction['can_use'] = false;
                $mergedAction['reason'] = '法力不足';
            }
            // 检查武器类型要求
            if ($mergedAction['can_use']) {
                $skillConfig = SkillManager::getSkillConfig($skillId);
                $reqWeaponType = null;
                if ($skillConfig && !empty($skillConfig['valid_learn'])) {
                    $lc = is_string($skillConfig['valid_learn'])
                        ? json_decode($skillConfig['valid_learn'], true)
                        : $skillConfig['valid_learn'];
                    $reqWeaponType = $lc['weapon_type'] ?? null;
                }
                if ($reqWeaponType && $reqWeaponType !== 'unarmed') {
                    $equipped = CombatDaemon::getEquippedWeapon($charId);
                    $curType = $equipped ? ($equipped['weapon_type'] ?? null) : null;
                    $weaponTypeNames = [
                        'sword' => '剑', 'blade' => '刀', 'spear' => '枪', 'staff' => '杖',
                        'stick' => '棒', 'whip' => '鞭', 'axe' => '斧', 'hammer' => '锤',
                        'mace' => '锏', 'fork' => '叉', 'rake' => '钯'
                    ];
                    $skillCnName = SkillManager::getSkillChineseName($skillId);
                    $reqName = $weaponTypeNames[$reqWeaponType] ?? $reqWeaponType;
                    if (!$curType) {
                        $mergedAction['can_use'] = false;
                        $mergedAction['reason'] = "「{$skillCnName}」需要装备{$reqName}类武器才能施展。";
                    } elseif ($curType !== $reqWeaponType) {
                        $curWeaponName = $equipped['name'] ?? '当前武器';
                        $mergedAction['can_use'] = false;
                        $mergedAction['reason'] = "「{$skillCnName}」需要{$reqName}类武器，你当前装备的是{$curWeaponName}，无法施展此招式。";
                    }
                }
            }
            $usableActions[] = $mergedAction;
        }
    }

    if (!empty($usableActions)) {
        $skillName = $skill['name'] ?? SkillManager::getSkillChineseName($skillId);
        $availableActions[] = [
            'skill_id' => $skillId,
            'skill_name' => $skillName,
            'skill_level' => $skillLevel,
            'actions' => $usableActions
        ];
    }
}

// 构建可用运功列表（exert）
$exertOptions = [];
$mappedForce = SkillManager::querySkillMapped($charId, 'force');
if (!empty($mappedForce)) {
    // 静心诀 - 冷泉神功
    if (strpos($mappedForce, 'lengquan') !== false) {
        $canUse = intval($char['force'] ?? 0) >= 200;
        $reason = '';
        if ($isBusy) {
            $canUse = false;
            $reason = '正忙着';
        } elseif (intval($char['force'] ?? 0) < 200) {
            $reason = '内力不足';
        }
        $exertOptions[] = [
            'code' => 'jingxin',
            'name' => '静心诀',
            'cost' => '内力200',
            'can_use' => $canUse,
            'reason' => $reason
        ];
    }
    // 化功 - 摄气诀
    if (strpos($mappedForce, 'tonsillit') !== false) {
        $canUse = intval($char['force'] ?? 0) >= 100 && intval($char['sen'] ?? 0) >= 100;
        $reason = '';
        if ($isBusy) {
            $canUse = false;
            $reason = '正忙着';
        } elseif (intval($char['force'] ?? 0) < 100) {
            $reason = '内力不足';
        } elseif (intval($char['sen'] ?? 0) < 100) {
            $reason = '精神不足';
        }
        $exertOptions[] = [
            'code' => 'powerfade',
            'name' => '化功',
            'cost' => '内力100+精神100',
            'can_use' => $canUse,
            'reason' => $reason
        ];
    }
}

// 获取连击数
$comboCount = $combatStatus['combo_count'] ?? 0;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title>战斗 - <?= h($combatStatus['target_name'] ?? '未知') ?>_西游记mud</title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
    <link rel="stylesheet" href="../assets/css/fight.css">
</head>
<body>
<div class="combat-container">
    <!-- ===== 1. 战斗信息区 ===== -->
    <div class="combat-header">
        ⚔️ 战斗中 ⚔️
    </div>
    
    <div class="combat-info">
        <div>
            <span style="color: #ff4444;">对手：</span>
            <span style="color: #00ffff;"><?= h($combatStatus['target_name'] ?? '未知') ?></span>
            <span style="color: #888;">（<?= ($combatStatus['friendly'] ?? false) ? '切磋' : '击杀' ?>）</span>
            <?php if ($isMutualCombat): ?>
            <span style="color: #ff4444; font-weight: bold;"> ⚠ 对方已应战！生死之战！</span>
            <?php endif; ?>
            <?php if (!empty($multiTargets)): ?>
            <span style="color: #666; font-size: 11px;">
                - <a href="action.php?action=switch_target&param=0&from=fight" style="color: #ffaa00; font-size: 11px;">优先目标</a>
                <?php foreach ($multiTargets as $i => $mt): ?>
                    、<a href="action.php?action=switch_target&param=<?= $i + 1 ?>&from=fight" style="color: #88ccff; font-size: 11px;"><?= h($mt['name']) ?></a>
                <?php endforeach; ?>
            </span>
            <?php endif; ?>
        </div>
        <div>
            <span style="color: #ffff00;">回合：</span>
            <span><?= $combatStatus['round'] ?? 0 ?></span>
            <?php if ($comboCount > 0): ?>
            <span style="color: #ff8800;"> 连击：<?= $comboCount ?></span>
            <?php endif; ?>
        </div>

        <?php if ($targetHp !== null && $targetMaxHp !== null): ?>
        <div class="hp-container" id="target-hp-container">
            <div style="display: flex; justify-content: space-between;">
                <span style="color: #ff4444;">对手气血：</span>
            </div>
            <?php
            $targetHpPercent = $targetMaxHp > 0 ? intval(($targetHp / $targetMaxHp) * 100) : 0;
            $targetHpClass = $targetHpPercent > 50 ? 'hp-high' : ($targetHpPercent > 25 ? 'hp-medium' : 'hp-low');
            ?>
            <div class="hp-bar">
                <div class="hp-fill <?= $targetHpClass ?>" style="width: <?= $targetHpPercent ?>%;">
                    <?= $targetHp ?>/<?= $targetMaxHp ?> (<?= $targetHpPercent ?>%)
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="info-row hp-container" id="player-hp-container">
            <div style="display: flex; justify-content: space-between;">
                <span style="color: #ffff00;">你的气血：</span>
            </div>
            <?php
            // 使用真实血量（切磋模式也使用真实血量）
            $displayKee = $playerKee ?? $char['kee'];
            $displayMaxKee = $playerMaxKee ?? $char['max_kee'];
            $hpPercent = $displayMaxKee > 0 ? intval(($displayKee / $displayMaxKee) * 100) : 0;
            $hpClass = $hpPercent > 50 ? 'hp-high' : ($hpPercent > 25 ? 'hp-medium' : 'hp-low');
            ?>
            <div class="hp-bar">
                <div class="hp-fill <?= $hpClass ?>" style="width: <?= $hpPercent ?>%;" data-maxHp="<?= $displayMaxKee ?>">
                    <?= $displayKee ?>/<?= $displayMaxKee ?> (<?= $hpPercent ?>%)
                </div>
            </div>
        </div>
        <div>
            <span style="color: #00ccff;">内力：</span>
            <span><?= $char['force'] ?? 0 ?>/<?= $char['max_force'] ?? 0 ?></span>
            &ensp;
            <span style="color: #cc66ff;">法力：</span>
            <span><?= $char['mana'] ?? 0 ?>/<?= $char['max_mana'] ?? 0 ?></span>
        </div>
    </div>

    <br>

    <!-- ===== 2. 战斗日志区 ===== -->
    <div class="combat-log" id="combat-log">
        <?php if (!empty($combatLog)): ?>
        <div style="color: #ffff00; font-weight: bold; margin-bottom: 10px;">📜 战斗记录：</div>
        <?php foreach ($combatLog as $log): ?>
        <div class="log-entry">
            <span class="log-time">[<?= h($log['time']) ?>]</span>
            <span><?= ansi_to_html($log['content']) ?></span>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div style="color: #888;">点击"攻击"按钮开始战斗...</div>
        <?php endif; ?>
    </div>

    <br>

    <!-- ===== 3. 操作区 ===== -->
    <div style="color: #ffff00; font-weight: bold;">【基本操作】</div>
    <?php if ($isBusy): ?>
    <div style="color: #888; margin: 5px 0;">
        正忙着，无法操作...
    </div>
    <?php else: ?>
    <div style="margin: 5px 0; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
        <button id="btn-auto-attack" onclick="toggleAutoAttack()" style="padding: 6px 14px; background: #1a5c1a; color: #fff; border: 1px solid #2a8c2a; border-radius: 4px; cursor: pointer; font-size: 14px;">切为手动</button>
        <span id="auto-attack-countdown" style="color: #888; font-size: 12px;">(5)</span>
        <button id="btn-manual-attack" onclick="manualAttack()" style="padding: 6px 14px; background: #444; color: #888; border: 1px solid #666; border-radius: 4px; cursor: not-allowed; font-size: 14px;" disabled>攻击</button>
        <a href="action.php?action=ji&param=<?= urlencode($combatStatus['target_name'] ?? '') ?>&from=fight">祭法宝</a>
        <a href="action.php?action=flee&from=fight">逃跑</a>
        <a href="action.php?action=surrender&from=fight">投降</a>
    </div>
    <?php endif; ?>

    <?php if (!empty($exertOptions)): ?>
    <br>
    <div style="color: #ffff00; font-weight: bold;">【运功】</div>
    <div style="margin: 5px 0;">
        <?php foreach ($exertOptions as $ex): ?>
            <?php if ($ex['can_use']): ?>
                <a href="action.php?action=exert&param=<?= urlencode($ex['code']) ?>&from=fight"><?= h($ex['name']) ?>(<?= h($ex['cost']) ?>)</a>&ensp;
            <?php else: ?>
                <span style="color:#666"><?= h($ex['name']) ?>(<?= h($ex['cost']) ?>，<?= h($ex['reason']) ?>)</span>&ensp;
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($availableActions)): ?>
    <br>
    <div style="color: #ffff00; font-weight: bold;">【招式】</div>
    <?php foreach ($availableActions as $skillGroup): ?>
    <div style="margin: 3px 0;">
        <span style="color:#ccc">▸ <?= h($skillGroup['skill_name']) ?>(<?= $skillGroup['skill_level'] ?>级)：</span>
        <?php foreach ($skillGroup['actions'] as $act): ?>
            <?php
            $actionCode = $act['action_code'] ?? '';
            $actionName = $act['action_name'] ?? $actionCode;
            $performParam = $skillGroup['skill_id'] . '/' . $actionCode;
            $forceCost = intval($act['force_cost'] ?? 0);
            $manaCost = intval($act['mana_cost'] ?? 0);
            $minLevel = intval($act['min_level'] ?? 0);
            // 构建提示：显示消耗和等级要求
            $tipParts = [];
            if ($forceCost > 0) $tipParts[] = '内力' . $forceCost;
            if ($manaCost > 0) $tipParts[] = '法力' . $manaCost;
            if ($minLevel > 0) $tipParts[] = '需' . $minLevel . '级';
            if (!$act['can_use'] && !empty($act['reason'])) $tipParts[] = $act['reason'];
            $tip = implode(' ', $tipParts);
            ?>
            <?php if ($act['can_use']): ?>
                <a href="javascript:void(0)" onclick="doPerform('<?= urlencode($performParam) ?>', this)"<?php if ($tip): ?> title="<?= h($tip) ?>"<?php endif; ?>><?= h($actionName) ?></a>&ensp;
            <?php else: ?>
                <a href="javascript:void(0)" onclick="doPerform('<?= urlencode($performParam) ?>', this)" data-disabled="true" data-reason="<?= h($act['reason'] ?? '不可用') ?>" style="color:#888"<?php if ($tip): ?> title="<?= h($tip) ?>"<?php endif; ?>><?= h($actionName) ?></a>&ensp;
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <br>
    <div style="margin-top: 10px; padding: 5px 0; border-top: 1px dashed #444;">
        <a href="room.php?area=<?= urlencode($char['current_area']) ?>&room=<?= urlencode($char['current_room']) ?>">返回房间</a>
    </div>
</div>

<script>
var fightData = {
    playerDamage: <?= $playerDamage ?>,
    targetDamage: <?= $targetDamage ?>,
    combatActive: <?= (CombatDaemon::isInCombat($charId) ? 'true' : 'false') ?>,
    playerHp: <?= intval($playerKee ?? $char['kee'] ?? 0) ?>,
    playerMaxHp: <?= intval($playerMaxKee ?? $char['max_kee'] ?? 0) ?>,
    targetHp: <?= intval($targetHp ?? 0) ?>,
    targetMaxHp: <?= intval($targetMaxHp ?? 0) ?>
};
</script>
<script src="../assets/js/fight.js"></script>
</body>
</html>
