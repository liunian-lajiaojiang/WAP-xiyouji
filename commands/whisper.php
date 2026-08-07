<?php
/**
 * 耳语命令 (whisper)
 * 在当前房间向指定目标耳语，包括 NPC
 */
require_once DAEMON_PATH . 'MessageDaemon.php';
require_once MODEL_PATH . 'Character.php';

function cmd_whisper(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);

    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    if (empty($param)) {
        return ['success' => false, 'message' => '你要对谁耳语什么？'];
    }

    // 解析参数：第一个词是目标名，其余是消息
    $parts = explode(' ', $param, 2);

    if (count($parts) < 2 || empty(trim($parts[1]))) {
        return ['success' => false, 'message' => '你要对谁耳语什么？'];
    }

    $targetName = trim($parts[0]);
    $message = trim($parts[1]);

    $roomId = $char['current_room'] ?? '';
    $roomArea = $char['current_area'] ?? '';

    if (empty($roomId)) {
        return ['success' => false, 'message' => '你不在任何房间中。'];
    }

    // 1. 先在房间 NPC 中查找目标
    $targetNpc = null;
    $room = null;
    if (!empty($roomArea)) {
        $room = RoomModel::getFullInfo($roomArea, $roomId);
        if (!empty($room['npcs'])) {
            foreach ($room['npcs'] as $npc) {
                if (strcasecmp($npc['name'], $targetName) === 0
                    || stripos($npc['npc_id'], $targetName) !== false
                    || mb_stripos($npc['name'], $targetName) !== false
                ) {
                    $targetNpc = $npc;
                    break;
                }
            }
        }
    }

    // 2. 在线玩家中查找同房间目标
    $targetPlayer = null;
    if (!$targetNpc) {
        $sql = "SELECT id, name FROM characters WHERE name = ? AND online = 1 AND current_room = ?";
        $targetPlayer = Database::queryOne($sql, [$targetName, $roomId]);

        if (!$targetPlayer) {
            // 检查目标是否在线但不在同一房间
            $sqlAny = "SELECT id, name FROM characters WHERE name = ? AND online = 1";
            $anyOnline = Database::queryOne($sqlAny, [$targetName]);

            if ($anyOnline) {
                return ['success' => false, 'message' => '这里没有这个人。'];
            }

            return ['success' => false, 'message' => '这里没有这个人。'];
        }

        // 不能对自己耳语
        if ($targetPlayer['id'] == $charId) {
            return ['success' => false, 'message' => '你不能对自己耳语。'];
        }
    }

    // ---- 发送耳语 ----

    if ($targetNpc) {
        // 对 NPC 耳语
        $npcName = $targetNpc['name'];

        // 发送者看到
        $sendMsg = HTML_HIGRN . '你对' . $npcName . '小声说道：' . $message . HTML_NOR;
        MessageDaemon::queueMessageToSelf($charId, $sendMsg, 'self_event');

        // 旁观者看到（排除发送者）
        $observerMsg = HTML_WHT . $char['name'] . '在' . $npcName . '的耳边小声地说了些话。' . HTML_NOR;
        MessageDaemon::broadcastToRoom($roomId, $observerMsg, $charId, 'room');

        // NPC 回调
        if (method_exists($targetNpc, 'relay_whisper')) {
            $targetNpc->relay_whisper($char, $message);
        }

        log_game('WHISPER', "{$char['name']} 对 NPC {$npcName} 耳语: {$message}");

        return [
            'success' => true,
            'type' => 'whisper',
            'output' => $sendMsg,
            'skip_queue' => true,
        ];
    }

    // 对玩家耳语
    $targetId = intval($targetPlayer['id']);
    $targetPlayerName = $targetPlayer['name'];

    // 发送者看到：你对<目标>小声说道：<消息>（绿色）
    $sendMsg = HTML_HIGRN . '你对' . $targetPlayerName . '小声说道：' . $message . HTML_NOR;
    MessageDaemon::queueMessageToSelf($charId, $sendMsg, 'self_event');

    // 目标看到：<发送者>在你的耳边小声说道：<消息>（绿色）
    $receiveMsg = HTML_HIGRN . $char['name'] . '在你的耳边小声说道：' . $message . HTML_NOR;
    MessageDaemon::sendToPlayer($targetId, $receiveMsg, 'whisper');

    // 旁观者看到（排除发送者和目标）
    $observerMsg = HTML_WHT . $char['name'] . '在' . $targetPlayerName . '的耳边小声地说了些话。' . HTML_NOR;

    $sqlRoom = "SELECT id FROM characters WHERE current_room = ? AND online = 1 AND id != ? AND id != ?";
    $bystanders = Database::queryAll($sqlRoom, [$roomId, $charId, $targetId]);
    foreach ($bystanders as $bystander) {
        MessageDaemon::sendToPlayer(intval($bystander['id']), $observerMsg, 'room');
    }

    log_game('WHISPER', "{$char['name']} 对 {$targetPlayerName} 耳语: {$message}");

    return [
        'success' => true,
        'type' => 'whisper',
        'output' => $sendMsg,
        'skip_queue' => true,
        'target' => $targetPlayerName,
    ];
}
