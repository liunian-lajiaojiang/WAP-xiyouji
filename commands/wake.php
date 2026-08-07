<?php
/**
 * 唤醒命令 (wake)
 * 可以唤醒其他处于睡眠/昏迷/发呆状态的玩家
 */

function cmd_wake(int $charId, string $param = ''): array {
    require_once __DIR__ . '/../daemons/MessageDaemon.php';
    require_once __DIR__ . '/sleep.php';
    
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    if (empty($param)) {
        return ['success' => false, 'message' => '你要唤醒谁？用法：wake <玩家名>'];
    }
    
    $targetName = trim($param);
    $roomId = $char['current_room'];
    
    $target = Database::queryOne(
        "SELECT id, name, sleep_state, sleep_end_time, unconscious_state, unconscious_end_time, daze_state, daze_end_time 
         FROM characters 
         WHERE current_room = ? AND online = 1 AND name LIKE ?",
        [$roomId, '%' . $targetName . '%']
    );
    
    if (!$target) {
        return ['success' => false, 'message' => '这里没有这个人。'];
    }
    
    if ($target['id'] == $charId) {
        return ['success' => false, 'message' => '你不能自己唤醒自己。'];
    }
    
    $wasUnconscious = false;
    $wasDazed = false;
    $wasSleeping = false;
    
    if (!empty($target['unconscious_state']) && $target['unconscious_state'] == 1) {
        Database::execute(
            'UPDATE characters SET unconscious_state = 0, unconscious_end_time = 0 WHERE id = ?',
            [$target['id']]
        );
        $wasUnconscious = true;
    } elseif (!empty($target['daze_state']) && $target['daze_state'] == 1) {
        Database::execute(
            'UPDATE characters SET daze_state = 0, daze_end_time = 0 WHERE id = ?',
            [$target['id']]
        );
        $wasDazed = true;
    } elseif (!empty($target['sleep_state']) && $target['sleep_state'] == 1) {
        // 唤醒睡眠中的玩家
        wakeup_player($target['id']);
        $wasSleeping = true;
    } else {
        return ['success' => false, 'message' => $target['name'] . '没有处于睡眠、昏迷或发呆状态。'];
    }
    
    if ($wasUnconscious) {
        $selfMsg = '<span style="color: #FFD700;">' . $char['name'] . '将你从昏迷中唤醒了。</span>';
        $roomMsg = "<span style='color: #FFD700;'>{$char['name']}轻轻拍打{$target['name']}，将{$target['name']}从昏迷中唤醒了。</span>";
        $actorMsg = "<span style='color: #FFD700;'>你轻轻拍打{$target['name']}，将{$target['name']}从昏迷中唤醒了。</span>";
    } elseif ($wasDazed) {
        $selfMsg = '<span style="color: #FFD700;">' . $char['name'] . '将你从发呆中叫醒了。</span>';
        $roomMsg = "<span style='color: #FFD700;'>{$char['name']}轻轻拍了拍{$target['name']}，{$target['name']}从发呆中回过神来。</span>";
        $actorMsg = "<span style='color: #FFD700;'>你轻轻拍了拍{$target['name']}，{$target['name']}从发呆中回过神来。</span>";
    } else {
        // 睡眠唤醒
        $selfMsg = '<span style="color: #FFD700;">' . $char['name'] . '将你从睡梦中叫醒了。</span>';
        $roomMsg = "<span style='color: #FFD700;'>{$char['name']}轻轻推了推{$target['name']}，{$target['name']}从睡梦中醒来。</span>";
        $actorMsg = "<span style='color: #FFD700;'>你轻轻推了推{$target['name']}，{$target['name']}从睡梦中醒来。</span>";
    }
    
    MessageDaemon::sendRoomMessage($charId, $roomMsg);
    MessageDaemon::queueMessageToSelf($target['id'], $selfMsg, 'room_event');
    
    return [
        'success' => true,
        'message' => $actorMsg,
        'skip_queue' => true
    ];
}

function cmd_hunxing(int $charId, string $param = '') {
    return cmd_wake($charId, $param);
}

function cmd_jiaoxing(int $charId, string $param = '') {
    return cmd_wake($charId, $param);
}
?>
