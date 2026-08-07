<?php
/**
 * 关闭服务器命令 (shutdown)
 *
 * 用法: shutdown [minutes] [reason]
 * 功能: 设置服务器维护，将普通玩家踢出游戏，战斗中玩家直接逃跑成功
 *       shutdown cancel  -- 取消维护
 *       shutdown status  -- 查看当前维护状态
 * 权限: arch (等级5) 及以上
 */

require_once __DIR__ . '/../helpers/WizardHelper.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../daemons/CombatDaemon.php';

/**
 * 执行 shutdown 命令
 * @param int $charId 操作者角色ID
 * @param string $param 参数: "[minutes] [reason]" 或 "cancel" 或 "status"
 * @return array
 */
function cmd_shutdown(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    $userId = intval($char['user_id']);
    if (!WizardHelper::canUseCommand($userId, 'shutdown')) {
        return ['success' => false, 'message' => '你的巫师等级不够，无法使用 shutdown 命令。需要大巫师(arch)以上权限。'];
    }

    $parts = preg_split('/\s+/', trim($param));
    $sub = strtolower($parts[0] ?? 'status');

    // 查看状态
    if ($sub === 'status' || $sub === '') {
        $row = Database::queryOne("SELECT * FROM variables WHERE var_key = 'shutdown_status'");
        if (!$row || $row['value'] !== 'active') {
            return ['success' => true, 'message' => '当前服务器正常运行，无维护计划。'];
        }
        $minutes = Database::queryOne("SELECT value FROM variables WHERE var_key = 'shutdown_minutes'");
        $reason  = Database::queryOne("SELECT value FROM variables WHERE var_key = 'shutdown_reason'");
        $setAt   = Database::queryOne("SELECT value FROM variables WHERE var_key = 'shutdown_set_at'");
        $msg  = "【服务器维护状态】\n";
        $msg .= "  状态: 维护中\n";
        $msg .= "  倒计时: " . ($minutes['value'] ?? '?') . " 分钟\n";
        $msg .= "  原因: " . ($reason['value'] ?? '未说明') . "\n";
        $msg .= "  设置时间: " . ($setAt['value'] ?? '?') . "\n";
        return ['success' => true, 'message' => $msg];
    }

    // 取消维护
    if ($sub === 'cancel') {
        Database::execute("UPDATE variables SET value = 'cancelled' WHERE var_key = 'shutdown_status'");
        log_game('SHUTDOWN', "巫师 {$char['name']} 取消了服务器维护");
        return ['success' => true, 'message' => '已取消服务器维护计划。'];
    }

    // 设置维护
    $minutes = intval($parts[0]);
    if ($minutes < 1) $minutes = 5;
    if ($minutes > 1440) $minutes = 1440;
    $reason = implode(' ', array_slice($parts, 1));
    if ($reason === '') $reason = '例行维护';

    $setTime = date('Y-m-d H:i:s');

    // UPSERT 方式写入 variables 表
    $keys = [
        'shutdown_status'  => 'active',
        'shutdown_minutes' => (string)$minutes,
        'shutdown_reason'  => $reason,
        'shutdown_set_at'  => $setTime,
        'shutdown_set_by'  => (string)$userId,
    ];
    foreach ($keys as $k => $v) {
        $existing = Database::queryOne("SELECT var_key FROM variables WHERE var_key = ?", [$k]);
        if ($existing) {
            Database::execute("UPDATE variables SET value = ? WHERE var_key = ?", [$v, $k]);
        } else {
            Database::execute("INSERT INTO variables (var_key, value) VALUES (?, ?)", [$k, $v]);
        }
    }

    // 获取所有在线玩家（排除管理员级别的巫师）
    $onlinePlayers = Database::queryAll(
        "SELECT c.id, c.name, c.user_id, c.current_room, u.wizard_level 
         FROM characters c 
         LEFT JOIN users u ON c.user_id = u.id 
         WHERE c.online = 1"
    );

    $kickedCount = 0;
    $fleeCount = 0;

    foreach ($onlinePlayers as $player) {
        $playerWizardLevel = intval($player['wizard_level'] ?? 0);
        
        // 管理员和大巫师不踢（等级5及以上）
        if ($playerWizardLevel >= WizardHelper::LEVEL_ARCH) {
            continue;
        }

        $playerId = intval($player['id']);
        $playerName = $player['name'];
        $playerRoom = $player['current_room'];

        // 检查玩家是否在战斗中
        if (CombatDaemon::isInCombat($playerId)) {
            // 结束战斗，标记为逃跑成功
            CombatDaemon::endCombat($playerId);
            
            // 广播逃跑消息给房间内其他玩家
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            $fleeMsg = HTML_HIYEL . "{$playerName}因服务器维护紧急逃离了战斗！" . HTML_NOR;
            if (!empty($playerRoom)) {
                MessageDaemon::broadcastToRoom($playerRoom, $fleeMsg, $playerId, 'combat');
            }
            
            $fleeCount++;
        }

        // 将玩家踢下线（设置在线状态为0）
        CharacterModel::updateOnlineStatus($playerId, false);
        $kickedCount++;
    }

    // 向巫师发送广播消息
    $broadcastMsg = "[系统公告] 服务器将在 {$minutes} 分钟后进行维护。原因: {$reason}。";
    if ($kickedCount > 0) {
        $broadcastMsg .= " 已将 {$kickedCount} 名普通玩家踢下线";
        if ($fleeCount > 0) {
            $broadcastMsg .= "，其中 {$fleeCount} 名玩家在战斗中被强制逃跑";
        }
    }

    // 向所有在线角色发送系统消息（包括巫师）
    $broadcastSql = "INSERT INTO message_queue (char_id, message, type, from_char_id)
                      SELECT id, ?, 'system', 0 FROM characters WHERE online = 1";
    Database::execute($broadcastSql, [$broadcastMsg]);

    log_game('SHUTDOWN', "巫师 {$char['name']} 设置服务器维护: {$minutes}分钟后, 原因: {$reason}, 踢出玩家: {$kickedCount}, 战斗中逃跑: {$fleeCount}");

    $resultMsg = "已设置服务器维护: {$minutes} 分钟后执行。原因: {$reason}\n";
    if ($kickedCount > 0) {
        $resultMsg .= "已将 {$kickedCount} 名普通玩家踢下线";
        if ($fleeCount > 0) {
            $resultMsg .= "（{$fleeCount} 名玩家在战斗中被强制逃跑）";
        }
        $resultMsg .= "\n";
    }
    $resultMsg .= "全服广播已发送。";

    return ['success' => true, 'message' => $resultMsg];
}
