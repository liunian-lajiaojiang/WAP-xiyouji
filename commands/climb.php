<?php
/**
 * 爬树命令 (climb)
 * 支持多个房间的爬树功能：
 * - lingtai/uphill2: 爬松树到 uptree
 * - moon/ontop2: 爬桂树到 tree1
 */

function cmd_climb(int $charId, string $target = ''): array {
    if (empty($target)) {
        return ['success' => false, 'message' => '你要爬什么？'];
    }

    $target = strtolower(trim($target));
    $char = CharacterModel::getFullInfo($charId);
    $room = RoomModel::getFullInfo($char['current_area'], $char['current_room']);

    // 检查是否在枯松坪房间（lingtai/uphill2）
    if ($room['room_id'] === 'lingtai/uphill2') {
        return cmd_climb_lingtai($charId, $char, $room, $target);
    }
    
    // 检查是否在月宫玉女峰顶（moon/ontop2）
    if ($room['room_id'] === 'moon/ontop2') {
        return cmd_climb_moon($charId, $char, $room, $target);
    }
    
    // 检查 room_actions 表中是否有该房间的 climb 动作（如 dntg/hgs/center 爬旗杆）
    $roomActions = Database::queryAll(
        "SELECT action_cmd, handler_class FROM room_actions WHERE room_id = ? AND enabled = 1",
        [$char['current_room']]
    );
    foreach ($roomActions as $roomAction) {
        $actionCmd = $roomAction['action_cmd'] ?? '';
        if (strpos($actionCmd, 'climb') === 0 && !empty($roomAction['handler_class'])) {
            require_once DAEMON_PATH . 'ActionRouter.php';
            $result = ActionRouter::handleCustomAction($charId, 'climb', $target);
            if ($result['success']) {
                return $result;
            }
        }
    }
    
    return ['success' => false, 'message' => '这里没有什么可以爬的。'];
}

/**
 * 灵台方寸山爬松树
 */
function cmd_climb_lingtai(int $charId, array $char, array $room, string $target): array {
    // 检查是否是爬松树
    if ($target !== 'pine' && strpos($target, '松') === false && strpos($target, '树') === false) {
        return ['success' => false, 'message' => '你要爬什么？'];
    }
    
    // 广播爬树消息
    $broadcastMsg = HTML_HIYEL . $char['name'] . '抓住松树枝，小心翼翼地爬了上去。' . HTML_NOR;
    MessageDaemon::broadcastToRoom($room['room_id'], $broadcastMsg, $charId, 'room');
    
    // 扣除一点体力
    $newKee = max(0, $char['kee'] - 20);
    Database::execute(
        "UPDATE characters SET kee = ? WHERE id = ?",
        [$newKee, $charId]
    );
    
    // 移动到 uptree（原始项目逻辑：仅移动，不掉落物品）
    CharacterModel::updatePosition($charId, 'lingtai', 'lingtai/uptree');
    
    // 通知新房间的玩家
    $newRoomMsg = HTML_HIYEL . '树枝一阵晃动，' . $char['name'] . '爬了上来。' . HTML_NOR;
    MessageDaemon::broadcastToRoom('lingtai/uptree', $newRoomMsg, $charId, 'room');
    
    // 保存移动消息到自己的队列
    MessageDaemon::queueMessageToSelf($charId, $broadcastMsg, 'room');
    
    return [
        'success' => true,
        'type' => 'move',
        'message' => HTML_HIYEL . '你抓住松树枝，小心翼翼地爬了上去。' . HTML_NOR,
        'redirect' => room_url('lingtai', 'lingtai/uptree'),
        'new_area' => 'lingtai',
        'new_room' => 'lingtai/uptree'
    ];
}

/**
 * 月宫爬桂树
 * 调用 MoonActionsHandler 处理
 */
function cmd_climb_moon(int $charId, array $char, array $room, string $target): array {
    // 检查是否是爬桂树
    if ($target !== 'tree' && strpos($target, '桂') === false && strpos($target, '树') === false) {
        return ['success' => false, 'message' => '你要爬什么？'];
    }
    
    // 调用 MoonActionsHandler 处理爬树
    require_once DAEMON_PATH . 'MoonActionsHandler.php';
    
    $action = [
        'action_cmd' => 'climb tree',
        'action_name' => '爬上',
    ];
    
    $handler = new MoonActionsHandler();
    $result = $handler->execute($charId, $action);
    
    if (!$result['success']) {
        return $result;
    }
    
    // 处理返回结果
    $response = [
        'success' => true,
        'message' => HTML_HIYEL . $result['message'] . HTML_NOR,
    ];
    
    if (!empty($result['redirect'])) {
        $response['redirect'] = $result['redirect'];
        $response['type'] = 'move';
    }
    
    return $response;
}
