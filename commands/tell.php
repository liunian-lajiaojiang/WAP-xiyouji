<?php
/**
 * 私聊命令 (tell)
 * 向指定玩家发送私人消息
 */
require_once DAEMON_PATH . 'MessageDaemon.php';

function cmd_tell(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    if (empty($param)) {
        return ['success' => false, 'message' => '用法：tell <玩家名> <消息内容>'];
    }
    
    // 解析参数：第一个词是玩家名，其余是消息
    $parts = explode(' ', $param, 2);
    
    if (count($parts) < 2) {
        return ['success' => false, 'message' => '用法：tell <玩家名> <消息内容>'];
    }
    
    $targetName = $parts[0];
    $message = $parts[1];
    
    // 查找目标玩家
    $sql = "SELECT id, name FROM characters WHERE name = ? AND online = 1";
    $target = Database::queryOne($sql, [$targetName]);
    
    if (!$target) {
        // 检查是否是不在线
        $sqlOffline = "SELECT id, name FROM characters WHERE name = ?";
        $offlineTarget = Database::queryOne($sqlOffline, [$targetName]);
        
        if ($offlineTarget) {
            return ['success' => false, 'message' => "{$targetName} 目前不在线"];
        } else {
            return ['success' => false, 'message' => "找不到玩家 {$targetName}"];
        }
    }
    
    // 不能私聊自己
    if ($target['id'] == $charId) {
        return ['success' => false, 'message' => '你不能私聊自己'];
    }
    
    // 构建私聊消息（发送给接收者）
    $receiveMsg = HTML_HICYN . $char['name'] . '对你说道：' . HTML_HIYEL . $message . HTML_NOR;
    
    // 构建私聊消息（发送给发送者）
    $sendMsg = HTML_HICYN . '你对' . $target['name'] . '说道：' . HTML_HIYEL . $message . HTML_NOR;
    
    // 发送消息
    MessageDaemon::sendPrivateMessage($target['id'], $receiveMsg, $charId);

    // 记录最后私聊的对象（用于reply命令）
    $_SESSION["last_tell_{$charId}"] = $target['id'];
    $_SESSION["last_tell_{$target['id']}"] = $charId;
    
    log_game('TELL', "{$char['name']} 对 {$target['name']} 私聊: {$message}");
    
    return [
        'success' => true,
        'type' => 'tell',
        'output' => $sendMsg,
        'target' => $target['name']
    ];
}

// 别名：t
if (!function_exists('cmd_t')) {
    function cmd_t(int $charId, string $param = ''): array {
        return cmd_tell($charId, $param);
    }
}

