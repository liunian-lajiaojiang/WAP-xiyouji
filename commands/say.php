<?php
/**
 * 说话命令 (say)
 * 在当前房间向所有人说话
 */
require_once DAEMON_PATH . 'MessageDaemon.php';

function cmd_say(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    if (empty($param)) {
        return ['success' => false, 'message' => '你要说什么？'];
    }
    
    // 构建说话消息
    $message = HTML_HICYN . $char['name'] . HTML_NOR . '说：' . HTML_HIYEL . $param . HTML_NOR;
    
    // 广播给房间内所有玩家（包括自己，用于 chat.php 显示）
    // 使用 'chat' 类型而不是 'room'，这样可以在 chat.php 中正确显示
    // 传入 0 作为 excludeCharId，不排除发送者自己
    $receivers = MessageDaemon::broadcastToRoom($char['current_room'], $message, 0, 'chat');

    log_game('SAY', "{$char['name']} 在 {$char['current_room']} 说: {$param}");
    
    return [
        'success' => true,
        'type' => 'say',
        'output' => $message,
        'broadcast' => true,
        'skip_queue' => true,  // 告诉 action.php 不要再次保存消息
        'room_id' => $char['current_room'],
        'receiver_count' => count($receivers)
    ];
}

