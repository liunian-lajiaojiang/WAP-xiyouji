<?php
/**
 * 感谢命令 (thank)
 * 向房间内所有人或指定玩家表示感谢
 */
require_once DAEMON_PATH . 'MessageDaemon.php';

function cmd_thank(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 如果有参数，尝试查找目标玩家
    if (!empty($param)) {
        // 查找目标玩家（在当前房间）
        $sql = "SELECT id, name FROM characters 
                WHERE current_room = ? AND online = 1 AND name LIKE ? AND id != ?
                LIMIT 1";
        $target = Database::queryOne($sql, [$char['current_room'], '%' . $param . '%', $charId]);
        
        if ($target) {
            // 感谢特定玩家
            $message = HTML_HIYEL . $char['name'] . '对着' . $target['name'] . '拱手道谢："多谢！"' . HTML_NOR;
            
            // 广播到房间
            MessageDaemon::broadcastToRoom($char['current_room'], $message, $charId);
            
            return [
                'success' => true,
                'type' => 'emote',
                'output' => $message,
                'broadcast' => true
            ];
        }
    }
    
    // 没有目标或找不到目标，向所有人表示感谢
    $message = HTML_HIYEL . $char['name'] . '向大家拱手道谢："多谢各位！"' . HTML_NOR;
    
    // 广播到房间
    MessageDaemon::broadcastToRoom($char['current_room'], $message, $charId);
    
    return [
        'success' => true,
        'type' => 'emote',
        'output' => $message,
        'broadcast' => true
    ];
}

