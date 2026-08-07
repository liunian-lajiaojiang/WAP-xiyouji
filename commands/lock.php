<?php
/**
 * 锁门/解锁命令 (lock/unlock)
 * 用于枫雪宫谈心室的私密交谈机制
 * 参考原始LPC: /d/moon/fengxue/talkroom.c
 */

function cmd_lock(int $charId, string $arg = ''): array {
    $char = CharacterModel::getFullInfo($charId);
    $room = RoomModel::getFullInfo($char['current_area'], $char['current_room']);
    
    if (!$room) {
        return ['success' => false, 'message' => '当前位置无效'];
    }
    
    $roomId = $room['room_id'];
    
    if ($roomId !== 'moon/fengxue/talkroom') {
        return ['success' => false, 'message' => '这里没有可以上锁的门。'];
    }
    
    $isLocked = isTalkroomLocked($roomId);
    
    if ($isLocked) {
        return ['success' => false, 'message' => '门已经锁上了。'];
    }
    
    $numPlayers = countPlayersInRoom($roomId);
    
    if ($numPlayers < 2) {
        return ['success' => false, 'message' => '房里只有你一个人，向谁说悄悄话呢？不必把门锁上吧。'];
    }
    
    $lockTime = time() + 1800;
    
    Database::execute(
        "INSERT INTO variables (var_key, value, created_at, updated_at) 
         VALUES (?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE value = ?, updated_at = NOW()",
        ["talkroom_locked_{$roomId}", 1, 1]
    );
    
    Database::execute(
        "INSERT INTO variables (var_key, value, created_at, updated_at) 
         VALUES (?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE value = ?, updated_at = NOW()",
        ["talkroom_unlock_time_{$roomId}", $lockTime, $lockTime]
    );
    
    $broadcastMsg = HTML_HIYEL . $char['name'] . '将门拴一旋一按锁好，满脸笑容的转过身来。' . HTML_NOR;
    MessageDaemon::broadcastToRoom($roomId, $broadcastMsg, $charId, 'room');
    
    return [
        'success' => true,
        'message' => '你数了数屋里一共' . $numPlayers . '个人。',
        'broadcast_message' => $broadcastMsg,
        'skip_queue' => true
    ];
}

function cmd_unlock(int $charId, string $arg = ''): array {
    $char = CharacterModel::getFullInfo($charId);
    $room = RoomModel::getFullInfo($char['current_area'], $char['current_room']);
    
    if (!$room) {
        return ['success' => false, 'message' => '当前位置无效'];
    }
    
    $roomId = $room['room_id'];
    
    if ($roomId !== 'moon/fengxue/talkroom') {
        return ['success' => false, 'message' => '这里没有可以解锁的门。'];
    }
    
    $isLocked = isTalkroomLocked($roomId);
    
    if (!$isLocked) {
        return ['success' => false, 'message' => '门没有锁。'];
    }
    
    Database::execute("DELETE FROM variables WHERE var_key = ?", ["talkroom_locked_{$roomId}"]);
    Database::execute("DELETE FROM variables WHERE var_key = ?", ["talkroom_unlock_time_{$roomId}"]);
    
    $broadcastMsg = HTML_HIYEL . $char['name'] . '打开了门锁。' . HTML_NOR;
    MessageDaemon::broadcastToRoom($roomId, $broadcastMsg, $charId, 'room');
    
    return [
        'success' => true,
        'message' => '门锁蹦的一声弹开了！',
        'broadcast_message' => $broadcastMsg,
        'skip_queue' => true
    ];
}

function isTalkroomLocked(string $roomId): bool {
    $result = Database::queryOne(
        "SELECT value FROM variables WHERE var_key = ?",
        ["talkroom_locked_{$roomId}"]
    );
    
    if ($result && intval($result['value']) === 1) {
        $unlockTime = Database::queryOne(
            "SELECT value FROM variables WHERE var_key = ?",
            ["talkroom_unlock_time_{$roomId}"]
        );
        
        if ($unlockTime && time() >= intval($unlockTime['value'])) {
            Database::execute("DELETE FROM variables WHERE var_key = ?", ["talkroom_locked_{$roomId}"]);
            Database::execute("DELETE FROM variables WHERE var_key = ?", ["talkroom_unlock_time_{$roomId}"]);
            return false;
        }
        
        return true;
    }
    
    return false;
}

function countPlayersInRoom(string $roomId): int {
    $sql = "SELECT COUNT(*) as count FROM characters WHERE current_room = ? AND online = 1";
    $result = Database::queryOne($sql, [$roomId]);
    return intval($result['count'] ?? 0);
}