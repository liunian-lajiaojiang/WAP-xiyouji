<?php

function cmd_daze(int $charId, string $param = ''): array {
    require_once __DIR__ . '/../daemons/MessageDaemon.php';
    
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    if (CombatDaemon::isInCombat($charId)) {
        return ['success' => false, 'message' => '战斗中不能发呆！'];
    }

    if (!empty($char['sleep_state']) && $char['sleep_state'] == 1) {
        return ['success' => false, 'message' => '你已经在睡觉了。'];
    }

    if (!empty($char['unconscious_state']) && $char['unconscious_state'] == 1) {
        return ['success' => false, 'message' => '你已经昏迷了。'];
    }

    $duration = 30;
    if (!empty($param) && is_numeric($param)) {
        $duration = min(max((int)$param, 10), 300);
    }

    $endTime = time() + $duration;

    Database::execute(
        'UPDATE characters SET daze_state = 1, daze_end_time = ? WHERE id = ?',
        [$endTime, $charId]
    );

    $dazeMessages = [
        '你两眼发直，陷入了沉思...',
        '你呆呆地站在原地，不知道在想些什么。',
        '你目光呆滞，思绪飘向了远方...',
        '你发起呆来，周围的一切似乎都与你无关。',
        '你望着远方，陷入了深深的发呆中。',
        '你心不在焉地站着，思绪不知飘到了哪里。',
        '你眼神空洞，整个人都放空了。',
        '你愣愣地站着，似乎在思考人生的意义。'
    ];
    $selfMsg = $dazeMessages[array_rand($dazeMessages)];
    
    $roomMsg = "<span style='color: #AAAAAA;'>{$char['name']}两眼发直，发起呆来。</span>";

    MessageDaemon::sendRoomMessage($charId, $roomMsg);

    return [
        'success' => true,
        'message' => $selfMsg,
        'skip_queue' => true
    ];
}

function cmd_fadai($charId, $param = '') {
    return cmd_daze($charId, $param);
}

function snap_out_of_daze(int $charId): void {
    require_once __DIR__ . '/../daemons/MessageDaemon.php';
    
    $char = CharacterModel::find($charId);
    if (!$char) return;

    Database::execute(
        'UPDATE characters SET daze_state = 0, daze_end_time = 0 WHERE id = ?',
        [$charId]
    );

    $snapMessages = [
        '你回过神来，发现自己刚才发呆了。',
        '你突然惊醒，原来刚才在发呆。',
        '你晃了晃脑袋，从发呆中清醒过来。',
        '你眨了眨眼，终于回过神来了。',
        '你从沉思中惊醒，发现时间已经过去了。',
        '你打了个哈欠，从发呆中缓过神来。'
    ];
    $selfMsg = '<span style="color: #FFD700;">' . $snapMessages[array_rand($snapMessages)] . '</span>';
    $roomMsg = "<span style='color: #FFD700;'>{$char['name']}晃了晃脑袋，从发呆中清醒过来。</span>";

    MessageDaemon::sendRoomMessage($charId, $roomMsg);
    MessageDaemon::queueMessageToSelf($charId, $selfMsg, 'room_event');
}
?>