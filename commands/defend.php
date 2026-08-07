<?php
/**
 * 天魔走廊防守命令
 * 处理走廊防守的设置和撤销
 */

/**
 * 设置防守
 * 用法：action.php?action=defend&param=<player_name>
 * 或 action.php?action=defend&param=none （撤消防守）
 */
function cmd_defend(int $charId, string $param = ''): array {
    if (empty($param)) {
        return ['success' => false, 'message' => '你要让谁防守？'];
    }
    
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // busy check
    if (function_exists('is_player_busy') && is_player_busy($charId)) {
        return ['success' => false, 'message' => '你现在正忙着呢。'];
    }
    
    $roomId = $char['current_room'] ?? '';
    $charName = $char['name'] ?? '某人';
    
    // 检查是否在天魔走廊
    if (strpos($roomId, 'qujing/qujingren/tianmo/zoulang') === false) {
        return ['success' => false, 'message' => '这里不是天魔走廊，不能设置防守。'];
    }
    
    // 检查是否是抓取经人的玩家
    $obstacled = Database::queryOne("SELECT cated_id FROM obstacled WHERE id = 1");
    $catedId = intval($obstacled['cated_id'] ?? 0);
    
    if ($catedId === 0) {
        return ['success' => false, 'message' => '现在没有取经人被抓，不需要防守。'];
    }
    
    if ($charId !== $catedId) {
        return ['success' => false, 'message' => '只有抓取经人的人才能设置防守。'];
    }
    
    // 撤消防守
    if ($param === 'none' || $param === '取消') {
        return removeDefender($roomId, $charId, $charName);
    }
    
    // 设置防守
    return setDefender($roomId, $charId, $charName, $param);
}

/**
 * 设置防守人
 */
function setDefender(string $roomId, int $catorId, string $catorName, string $defenderName): array {
    // 查找防守人玩家
    $defender = Database::queryOne(
        "SELECT id, name, current_room FROM characters WHERE name = ? LIMIT 1",
        [$defenderName]
    );
    
    if (!$defender) {
        return ['success' => false, 'message' => '这个人不存在。'];
    }
    
    // 检查防守人是否在这个房间
    if ($defender['current_room'] !== $roomId) {
        return ['success' => false, 'message' => '这个人不在这里。'];
    }
    
    $defenderId = intval($defender['id']);
    $defenderName = $defender['name'];
    
    // 获取当前防守配置
    $defenders = getDefendersConfig();
    
    // 设置这个走廊的防守人
    $defenders[$roomId] = [
        'defender_id' => $defenderId,
        'defender_name' => $defenderName,
        'set_by' => $catorId,
        'set_time' => time()
    ];
    
    // 保存配置
    saveDefendersConfig($defenders);
    
    // 广播消息
    $broadcastMsg = "{$catorName}对{$defenderName}说：你给我好好守在这里，别让护送的人过去！";
    
    require_once DAEMON_PATH . 'MessageDaemon.php';
    MessageDaemon::broadcastToRoom($roomId, $broadcastMsg, $catorId);
    
    return [
        'success' => true,
        'message' => "你对{$defenderName}说：你给我好好守在这里，别让护送的人过去！",
        'data' => ['type' => 'defend_set_success']
    ];
}

/**
 * 撤消防守
 */
function removeDefender(string $roomId, int $catorId, string $catorName): array {
    // 获取当前防守配置
    $defenders = getDefendersConfig();
    
    if (!isset($defenders[$roomId])) {
        return ['success' => false, 'message' => '这里没有设置防守。'];
    }
    
    $defenderName = $defenders[$roomId]['defender_name'] ?? '某人';
    
    // 移除防守
    unset($defenders[$roomId]);
    
    // 保存配置
    saveDefendersConfig($defenders);
    
    // 广播消息
    $broadcastMsg = "{$catorName}挥了挥手：算了，这里不用守了。";
    
    require_once DAEMON_PATH . 'MessageDaemon.php';
    MessageDaemon::broadcastToRoom($roomId, $broadcastMsg, $catorId);
    
    return [
        'success' => true,
        'message' => "你挥了挥手：算了，这里不用守了。",
        'data' => ['type' => 'defend_remove_success']
    ];
}

/**
 * 获取防守配置
 */
function getDefendersConfig(): array {
    $result = Database::queryOne(
        "SELECT value FROM variables WHERE var_key = 'tianmo_defenders'"
    );
    
    if ($result && !empty($result['value'])) {
        $config = json_decode($result['value'], true);
        if (is_array($config)) {
            return $config;
        }
    }
    
    return [];
}

/**
 * 保存防守配置
 */
function saveDefendersConfig(array $defenders): void {
    $configJson = json_encode($defenders, JSON_UNESCAPED_UNICODE);
    
    Database::execute(
        "INSERT INTO variables (var_key, value, created_at, updated_at) 
         VALUES ('tianmo_defenders', ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE value = ?, updated_at = NOW()",
        [$configJson, $configJson]
    );
}

/**
 * 检查走廊是否有防守人
 * @param string $roomId 走廊房间ID
 * @return array|null 防守人信息，没有则返回null
 */
function checkRoomDefender(string $roomId): ?array {
    $defenders = getDefendersConfig();
    
    if (isset($defenders[$roomId])) {
        $defender = $defenders[$roomId];
        
        // 检查防守人是否还在房间里
        $defenderChar = Database::queryOne(
            "SELECT id, name, current_room FROM characters WHERE id = ?",
            [$defender['defender_id']]
        );
        
        if ($defenderChar && $defenderChar['current_room'] === $roomId) {
            return $defender;
        }
        
        // 防守人不在了，清除防守
        unset($defenders[$roomId]);
        saveDefendersConfig($defenders);
    }
    
    return null;
}
