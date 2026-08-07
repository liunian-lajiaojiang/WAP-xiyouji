<?php
/**
 * 亲吻命令 (kiss)
 * 亲吻指定玩家（表情动作）
 */
require_once DAEMON_PATH . 'MessageDaemon.php';

function cmd_kiss(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    if (empty($param)) {
        return ['success' => false, 'message' => '你要亲吻谁？'];
    }
    
    // 查找目标玩家（在当前房间）
    $sql = "SELECT id, name FROM characters 
            WHERE current_room = ? AND online = 1 AND name LIKE ? AND id != ?
            LIMIT 1";
    $target = Database::queryOne($sql, [$char['current_room'], '%' . $param . '%', $charId]);
    
    if (!$target) {
        return ['success' => false, 'message' => '这里没有这个人。'];
    }
    
    // 构建亲吻消息
    $message = HIY . $char['name'] . '轻轻地亲了' . $target['name'] . '一下。' . NOR;
    
    // 广播到房间
    MessageDaemon::broadcastToRoom($char['current_room'], $message, $charId);
    
    return [
        'success' => true,
        'type' => 'emote',
        'output' => $message,
        'broadcast' => true
    ];
}

