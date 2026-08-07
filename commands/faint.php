<?php
// 加载技能消耗配置
static $_skillCosts = null;
if ($_skillCosts === null) {
    $_skillCosts = require __DIR__ . '/../config/skill_costs.php';
}
static $_questCfg = null;
if ($_questCfg === null) {
    $_questCfg = require __DIR__ . '/../config/quest.php';
}

function cmd_faint(int $charId, string $param = ''): array {
    require_once __DIR__ . '/../daemons/MessageDaemon.php';

    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    if (CombatDaemon::isInCombat($charId)) {
        return ['success' => false, 'message' => '战斗中不能昏迷！'];
    }

    if (!empty($char['sleep_state']) && $char['sleep_state'] == 1) {
        return ['success' => false, 'message' => '你已经在睡觉了。'];
    }

    if (!empty($char['unconscious_state']) && $char['unconscious_state'] == 1) {
        return ['success' => false, 'message' => '你已经昏迷了。'];
    }

    // 昏迷时长：从配置读取基础值，体质越高醒得越快（参考原始 damage.c unconcious）
    $con = intval($char['con'] ?? 10);
    $baseSec = $_questCfg['faint']['base_seconds'];
    $maxSec = $_questCfg['faint']['max_seconds'];
    $duration = random_int($baseSec, max($baseSec, $maxSec - $con));
    $endTime = time() + $duration;

    // 昏迷时设置气血为0（参考原始 damage.c）
    Database::execute(
        'UPDATE characters SET unconscious_state = 1, unconscious_end_time = ?, kee = 0, gin = 0, sen = 0 WHERE id = ?',
        [$endTime, $charId]
    );

    // 同步设置 Session 昏迷标记（go/exercise/practice/meditate 等命令依赖此检查）
    $_SESSION["unconscious_{$charId}"] = [
        'timestamp' => time(),
        'duration' => $duration,
    ];

    $selfMsg = '你感到一阵眩晕，眼前一黑，昏了过去...';
    $roomMsg = "<span style='color: #FF4444;'>{$char['name']}突然眼前一黑，昏了过去！</span>";

    MessageDaemon::sendRoomMessage($charId, $roomMsg);

    return [
        'success' => true,
        'message' => $selfMsg,
        'skip_queue' => true
    ];
}

function cmd_uncon($charId, $param = '') {
    return cmd_faint($charId, $param);
}

function wakeup_unconscious_player(int $charId): void {
    require_once __DIR__ . '/../daemons/MessageDaemon.php';
    
    $char = CharacterModel::find($charId);
    if (!$char) return;

    Database::execute(
        'UPDATE characters SET unconscious_state = 0, unconscious_end_time = 0 WHERE id = ?',
        [$charId]
    );

    // 清除 Session 昏迷标记
    unset($_SESSION["unconscious_{$charId}"]);

    $selfMsg = '<span style="color: #FFD700;">你缓缓睁开眼睛，清醒了过来。</span>';
    $roomMsg = "<span style='color: #FFD700;'>{$char['name']}缓缓睁开眼睛，清醒了过来。</span>";

    MessageDaemon::sendRoomMessage($charId, $roomMsg);
    MessageDaemon::queueMessageToSelf($charId, $selfMsg, 'room_event');
}
?>