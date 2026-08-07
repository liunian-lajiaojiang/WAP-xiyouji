<?php
/**
 * 表情动作命令 (emote)
 * 执行一个表情动作，显示给房间内的人
 */
require_once DAEMON_PATH . 'MessageDaemon.php';

function cmd_emote(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    if (empty($param)) {
        return ['success' => false, 'message' => '你要做什么动作？'];
    }
    
    // 构建表情消息（使用 HTML 颜色）
    $message = HTML_HIGRN . h($char['name']) . HTML_NOR . ' ' . HTML_HIYEL . h($param) . HTML_NOR;
    
    // 广播给房间内其他玩家
    $receivers = MessageDaemon::broadcastToRoom($char['current_room'], $message, $charId);
    
    log_game('EMOTE', "{$char['name']} 做动作: {$param}");
    
    return [
        'success' => true,
        'type' => 'emote',
        'output' => $message,
        'broadcast' => true,
        'room_id' => $char['current_room'],
        'receiver_count' => count($receivers)
    ];
}

// 常用表情别名
if (!function_exists('cmd_smile')) {
    function cmd_smile(int $charId, string $param = ''): array {
        return cmd_emote($charId, '微笑着向大家点头');
    }
}

if (!function_exists('cmd_laugh')) {
    function cmd_laugh(int $charId, string $param = ''): array {
        return cmd_emote($charId, '哈哈大笑起来');
    }
}

if (!function_exists('cmd_cry')) {
    function cmd_cry(int $charId, string $param = ''): array {
        return cmd_emote($charId, '伤心地哭了起来');
    }
}

if (!function_exists('cmd_jump')) {
    function cmd_jump(int $charId, string $param = ''): array {
        return cmd_emote($charId, '高兴地跳了起来');
    }
}

if (!function_exists('cmd_dance')) {
    function cmd_dance(int $charId, string $param = ''): array {
        return cmd_emote($charId, '翩翩起舞');
    }
}

