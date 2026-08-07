<?php
/**
 * 回复命令 (reply)
 * 回复最近的私聊消息
 */
require_once DAEMON_PATH . 'MessageDaemon.php';

function cmd_reply(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    if (empty($param)) {
        return ['success' => false, 'message' => '你要回复什么？'];
    }
    
    // 获取最后私聊的对象（从会话中）
    $lastTellKey = "last_tell_{$charId}";
    
    if (!isset($_SESSION[$lastTellKey])) {
        return ['success' => false, 'message' => '你还没有和任何人私聊过'];
    }
    
    $targetId = $_SESSION[$lastTellKey];
    
    // 检查目标玩家是否仍然在线
    $sql = "SELECT id, name FROM characters WHERE id = ? AND online = 1";
    $target = Database::queryOne($sql, [$targetId]);
    
    if (!$target) {
        return ['success' => false, 'message' => '对方已不在线'];
    }
    
    // 构建消息
    $receiveMsg = HTML_HICYN . '有人对你说道：' . HTML_HIYEL . $param . HTML_NOR;
    $sendMsg = HTML_HICYN . '你对' . $target['name'] . '说道：' . HTML_HIYEL . $param . HTML_NOR;
    
    // 发送消息
    MessageDaemon::sendPrivateMessage($target['id'], $receiveMsg, $charId);
    
    log_game('REPLY', "{$char['name']} 回复 {$target['name']}: {$param}");
    
    return [
        'success' => true,
        'type' => 'reply',
        'output' => $sendMsg,
        'target' => $target['name']
    ];
}

// 别名：r
if (!function_exists('cmd_r')) {
    function cmd_r(int $charId, string $param = ''): array {
        return cmd_reply($charId, $param);
    }
}

