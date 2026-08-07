<?php
/**
 * 请了/问候命令 (greet)
 * 向房间内所有人或指定玩家打招呼
 */
require_once DAEMON_PATH . 'MessageDaemon.php';

function cmd_greet(int $charId, string $param = ''): array {
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
            // 对特定玩家打招呼
            $message = HIY . $char['name'] . '对着' . $target['name'] . '作了个揖，说道："请了！"' . NOR;
            
            // 广播到房间
            MessageDaemon::broadcastToRoom($char['current_room'], $message, $charId);
            
            return [
                'success' => true,
                'type' => 'emote',
                'output' => $message,
                'broadcast' => true,
                'skip_queue' => true
            ];
        }
    }
    
    // 没有目标或找不到目标，向所有人打招呼
    $message = HIY . $char['name'] . '向大家作了个揖，说道："请了！"' . NOR;
    
    // 广播到房间
    MessageDaemon::broadcastToRoom($char['current_room'], $message, $charId);
    
    return [
        'success' => true,
        'type' => 'emote',
        'output' => $message,
        'broadcast' => true,
        'skip_queue' => true
    ];
}

