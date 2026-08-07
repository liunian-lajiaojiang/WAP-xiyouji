<?php
/**
 * 聊天命令 (chat)
 * 在全局聊天频道说话
 */
require_once MODEL_PATH . 'Character.php';
require_once DAEMON_PATH . 'MessageDaemon.php';

function cmd_chat(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    if (empty($param)) {
        return ['success' => false, 'message' => '你要聊什么？'];
    }
    
    $userId = intval($char['user_id']);
    $isBlocked = Database::queryOne('SELECT id FROM user_blocks WHERE user_id = ? AND block_type = ?', [$userId, 'chat']);
    if ($isBlocked) {
        return ['success' => false, 'message' => '你的聊天功能已被封禁'];
    }
    
    // 构建聊天消息
    $message = MAG . '【聊天】' . HICYN . $char['name'] . NOR . MAG . '：' . HIYEL . $param . NOR;
    
    // 广播给所有在线玩家
    $receivers = MessageDaemon::broadcastToAll($message, $charId);

    log_game('CHAT', "{$char['name']} 在聊天频道说: {$param}");
    
    return [
        'success' => true,
        'type' => 'chat',
        'output' => $message,
        'broadcast' => true,
        'channel' => 'global',
        'receiver_count' => count($receivers)
    ];
}

