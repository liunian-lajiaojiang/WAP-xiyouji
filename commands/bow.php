<?php
/**
 * 鞠躬命令 (bow)
 * 向房间内所有人或指定玩家鞠躬行礼
 */
require_once DAEMON_PATH . 'MessageDaemon.php';

function cmd_bow(int $charId, string $param = ''): array {
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
            // 向特定玩家鞠躬
            $message = HIY . $char['name'] . '恭恭敬敬地向' . $target['name'] . '鞠了一躬。' . NOR;
            
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
    
    // 没有目标或找不到目标，向所有人鞠躬
    $message = HIY . $char['name'] . '恭恭敬敬地向大家鞠了一躬。' . NOR;
    
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

