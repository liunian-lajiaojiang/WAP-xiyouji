<?php
/**
 * reject 命令 - 拒绝各种请求（切磋邀请等）
 */

function cmd_reject(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    $param = trim($param);
    
    // 如果参数是 fight，处理切磋邀请
    if ($param === 'fight' || $param === '切磋') {
        return rejectFightRequest($charId);
    }
    
    return ['success' => false, 'message' => '你没有需要拒绝的事情。'];
}

/**
 * 拒绝切磋邀请
 */
function rejectFightRequest(int $charId): array {
    // 查找最新的待处理切磋请求
    $request = Database::queryOne(
        'SELECT * FROM fight_requests 
         WHERE to_character_id = ? AND status = "pending"
         ORDER BY created_at DESC LIMIT 1',
        [$charId]
    );
    
    if (!$request) {
        return ['success' => false, 'message' => '你没有待处理的切磋邀请。'];
    }
    
    // 检查是否过期
    if (!empty($request['expires_at']) && strtotime($request['expires_at']) < time()) {
        // 过期了，标记为已过期
        Database::execute(
            'UPDATE fight_requests SET status = "expired", resolved_at = NOW() WHERE id = ?',
            [$request['id']]
        );
        return ['success' => false, 'message' => '切磋邀请已经过期了。'];
    }
    
    $fromCharId = intval($request['from_character_id']);
    $fromChar = CharacterModel::find($fromCharId);
    
    // 更新请求状态为已拒绝
    Database::execute(
        'UPDATE fight_requests SET status = "rejected", resolved_at = NOW() WHERE id = ?',
        [$request['id']]
    );
    
    // 通知发起者（参考探查功能，使用 sendPrivateMessage 发送私信）
    if ($fromChar) {
        require_once DAEMON_PATH . 'MessageDaemon.php';
        $charName = $char['name'] ?? '对方';
        $rejectMsg = "{$charName}拒绝了你的切磋邀请。";
        MessageDaemon::sendPrivateMessage($fromCharId, $rejectMsg, $charId);
    }
    
    return [
        'success' => true,
        'message' => '你拒绝了' . ($fromChar['name'] ?? '对方') . '的切磋邀请。'
    ];
}
