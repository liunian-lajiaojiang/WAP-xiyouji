<?php
/**
 * 开门/关门命令 (open/close)
 * 用于打开或关闭房间出口上的门
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */

/**
 * 开门命令: open <方向>
 * 例如: open west, open east
 */
function cmd_open(int $charId, string $direction = ''): array {
    // 方向别名
    $aliases = [
        'n' => 'north', 's' => 'south', 'e' => 'east', 'w' => 'west',
        'u' => 'up', 'd' => 'down', 'ne' => 'northeast', 'nw' => 'northwest',
        'se' => 'southeast', 'sw' => 'southwest',
    ];
    $direction = strtolower(trim($direction));
    $direction = $aliases[$direction] ?? $direction;

    $char = CharacterModel::getFullInfo($charId);
    $room = RoomModel::getFullInfo($char['current_area'], $char['current_room']);
    if (!$room) {
        return ['success' => false, 'message' => '当前位置无效'];
    }
    
    // 特殊处理：房间动作系统（如棺材等非门类的 open 交互）
    $roomActionResult = handleRoomActionOpen($charId, $direction, $room['room_id']);
    if ($roomActionResult !== null) {
        return $roomActionResult;
    }
    
    if (empty($direction)) {
        return ['success' => false, 'message' => '你要打开什么？'];
    }
    
    // 特殊处理：西瓜地北门（需要黄铜钥匙，触发人参果事件）
    if ($room['room_id'] === 'qujing/wuzhuang/xiguadi' && $direction === 'north') {
        return handleXiguadiNorthDoor($charId, $char, $room);
    }
    
    // 特殊处理：枫雪宫走廊开门控制（谈心室锁门时无法从外面打开）
    if ($room['room_id'] === 'moon/fengxue/zoulang' && $direction === 'enter') {
        return handleFengxueZoulangOpen($charId, $room);
    }
    
    // 查找该方向的出口
    $targetExit = null;
    foreach ($room['exits'] as $exit) {
        if (strtolower($exit['direction']) === $direction) {
            $targetExit = $exit;
            break;
        }
    }

    if (!$targetExit) {
        return ['success' => false, 'message' => '那个方向没有出口。'];
    }

    // 检查是否有门
    if (empty($targetExit['door_name'])) {
        return ['success' => false, 'message' => '那个方向没有门。'];
    }

    $doorName = $targetExit['door_name'];

    // 检查门是否已经开着
    if (empty($targetExit['door_closed'])) {
        return ['success' => false, 'message' => $doorName . '已经是开着的。'];
    }

    // 打开门 - 同时更新当前房间和对面的房间
    Database::execute(
        "UPDATE room_exits SET door_closed = 0 WHERE room_id = ? AND direction = ?",
        [$room['room_id'], $direction]
    );

    // 找到对面的房间，更新对面的门状态
    $targetRoomId = $targetExit['target_area'] . '/' . $targetExit['target_room'];
    $oppositeDir = getOppositeDirection($direction);
    if ($oppositeDir) {
        Database::execute(
            "UPDATE room_exits SET door_closed = 0 WHERE room_id = ? AND direction = ?",
            [$targetRoomId, $oppositeDir]
        );
    }

    // 广播开门消息给房间其他人
    $leaveMsg = HTML_HIYEL . $char['name'] . '打开了' . $doorName . '。' . HTML_NOR;
    MessageDaemon::broadcastToRoom($room['room_id'], $leaveMsg, $charId, 'room');

    return [
        'success' => true,
        'message' => HTML_HIYEL . '你打开了' . $doorName . '。' . HTML_NOR,
        'broadcast_message' => $leaveMsg,
        'skip_queue' => true
    ];
}

/**
 * 关门命令: close <方向>
 */
function cmd_close(int $charId, string $direction = ''): array {
    if (empty($direction)) {
        return ['success' => false, 'message' => '你要关上什么？'];
    }

    $aliases = [
        'n' => 'north', 's' => 'south', 'e' => 'east', 'w' => 'west',
        'u' => 'up', 'd' => 'down', 'ne' => 'northeast', 'nw' => 'northwest',
        'se' => 'southeast', 'sw' => 'southwest',
    ];
    $direction = strtolower(trim($direction));
    $direction = $aliases[$direction] ?? $direction;

    $char = CharacterModel::getFullInfo($charId);
    $room = RoomModel::getFullInfo($char['current_area'], $char['current_room']);
    if (!$room) {
        return ['success' => false, 'message' => '当前位置无效'];
    }

    $targetExit = null;
    foreach ($room['exits'] as $exit) {
        if (strtolower($exit['direction']) === $direction) {
            $targetExit = $exit;
            break;
        }
    }

    if (!$targetExit) {
        return ['success' => false, 'message' => '那个方向没有出口。'];
    }

    if (empty($targetExit['door_name'])) {
        return ['success' => false, 'message' => '那个方向没有门。'];
    }

    $doorName = $targetExit['door_name'];

    if (!empty($targetExit['door_closed'])) {
        return ['success' => false, 'message' => $doorName . '已经是关着的。'];
    }

    // 关门
    Database::execute(
        "UPDATE room_exits SET door_closed = 1 WHERE room_id = ? AND direction = ?",
        [$room['room_id'], $direction]
    );

    $targetRoomId = $targetExit['target_area'] . '/' . $targetExit['target_room'];
    $oppositeDir = getOppositeDirection($direction);
    if ($oppositeDir) {
        Database::execute(
            "UPDATE room_exits SET door_closed = 1 WHERE room_id = ? AND direction = ?",
            [$targetRoomId, $oppositeDir]
        );
    }

    $leaveMsg = HTML_HIYEL . $char['name'] . '关上了' . $doorName . '。' . HTML_NOR;
    MessageDaemon::broadcastToRoom($room['room_id'], $leaveMsg, $charId, 'room');

    return [
        'success' => true,
        'message' => HTML_HIYEL . '你关上了' . $doorName . '。' . HTML_NOR,
        'broadcast_message' => $leaveMsg,
        'skip_queue' => true
    ];
}

/**
 * 获取反方向
 */
function getOppositeDirection(string $dir): ?string {
    $map = [
        'north' => 'south', 'south' => 'north',
        'east' => 'west', 'west' => 'east',
        'up' => 'down', 'down' => 'up',
        'northeast' => 'southwest', 'southwest' => 'northeast',
        'northwest' => 'southeast', 'southeast' => 'northwest',
        'in' => 'out', 'out' => 'in',
        'enter' => 'out',
    ];
    return $map[$dir] ?? null;
}

/**
 * 处理西瓜地北门（黄铜钥匙开门 → 传送人参果园 → 触发人参果事件）
 * 
 * 还原原始LPC逻辑：
 * - 检查玩家背包中是否有黄铜钥匙
 * - 消耗钥匙（destruct）
 * - 传送到人参果园
 * - 触发镇元大仙人参果事件
 * 
 * 原始LPC: /d/qujing/wuzhuang/xiguadi.c::do_open()
 */
function handleXiguadiNorthDoor(int $charId, array $char, array $room): array {
    require_once MODEL_PATH . 'Item.php';
    require_once DAEMON_PATH . 'MessageDaemon.php';
    require_once DAEMON_PATH . 'RenshenEventHandler.php';
    
    // 检查事件冷却
    $cooldownRemaining = RenshenEventHandler::getCooldownRemaining();
    if ($cooldownRemaining > 0) {
        $minutes = ceil($cooldownRemaining / 60);
        return [
            'success' => false,
            'message' => HTML_HICYN . '杏木门上的黄铜锁散发着淡淡的光芒，似乎还需要等待一段时间才能再次打开。（约' . $minutes . '分钟后）' . HTML_NOR
        ];
    }
    
    // 检查背包中是否有黄铜钥匙
    $inventory = ItemModel::getCharacterItems($charId);
    $hasKey = false;
    foreach ($inventory as $item) {
        if ($item['item_id'] === 'huangtong-key') {
            $hasKey = true;
            break;
        }
    }
    
    if (!$hasKey) {
        return [
            'success' => false,
            'message' => HTML_HIYEL . '你摆弄了一下杏木门上的黄铜锁，但是没有钥匙打不开。' . HTML_NOR
        ];
    }
    
    // 消耗黄铜钥匙
    Database::execute(
        'UPDATE character_inventory SET quantity = quantity - 1 WHERE char_id = ? AND item_id = ?',
        [$charId, 'huangtong-key']
    );
    Database::execute(
        'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND quantity <= 0',
        [$charId, 'huangtong-key']
    );
    
    // 更新钥匙状态文件
    require_once __DIR__ . '/mo.php';
    if (function_exists('getWuzhuangKeyState') && function_exists('saveWuzhuangKeyState')) {
        $keyState = getWuzhuangKeyState();
        $keyState['key_holder_char_id'] = null;
        $keyState['on_floor'] = false;
        saveWuzhuangKeyState($keyState);
    }
    
    // 广播开门消息
    $openMsg = HTML_HIYEL . $char['name'] . '将黄铜钥匙插入杏木门的锁中，轻轻一转——' . HTML_NOR . "\n"
        . HTML_HIBLU . '只听「咔嚓」一声，木门缓缓打开，一道耀眼的白光从门内射出！' . HTML_NOR;
    MessageDaemon::broadcastToRoom($room['room_id'], $openMsg, $charId, 'room');
    
    // 传送玩家到人参果园
    CharacterModel::updatePosition($charId, 'qujing', 'qujing/wuzhuang/renshenguo-yuan');
    
    // 广播玩家传送消息
    $leaveMsg = HTML_HIWHT . $char['name'] . '的身影在白光中渐渐消失……' . HTML_NOR;
    MessageDaemon::broadcastToRoom($room['room_id'], $leaveMsg, $charId, 'room');
    
    // 触发人参果事件
    RenshenEventHandler::startEvent($charId);
    
    // 获取目标房间信息
    $targetRoom = RoomModel::load('qujing', 'qujing/wuzhuang/renshenguo-yuan');
    
    // 广播到达消息
    $arriveMsg = HTML_HIBLU . '一道白光闪过，' . HTML_HIYEL . $char['name'] . HTML_HIBLU . '出现在了果园之中。' . HTML_NOR;
    MessageDaemon::broadcastToRoom('qujing/wuzhuang/renshenguo-yuan', $arriveMsg, $charId, 'room');
    
    // 构建返回消息
    $msg = HTML_HIBLU . '你只觉得眼前白光一闪，便来到了一片果林之中。' . HTML_NOR . "\n";
    $msg .= HTML_HICYN . ($targetRoom['name'] ?? '人参果园') . HTML_NOR . "\n";
    $msg .= ($targetRoom['description'] ?? '') . "\n";
    $msg .= HTML_HICYN . '镇元大仙笑吟吟地站在那里，说道：「既然找到了老道，就请尝尝人参果吧！」' . HTML_NOR;
    
    return [
        'success' => true,
        'type' => 'move',
        'message' => $msg,
        // leave/arrive 消息已在上方通过 broadcastToRoom 广播，不再由 action.php 重复广播
        'leave_message' => '',
        'arrive_message' => '',
        'new_room' => $targetRoom,
        'old_room' => $room,
    ];
}

/**
 * 检查房间动作系统中是否有 open 相关的特殊交互（如开棺材）
 * 如果有则委托给 InteractHandler，否则返回 null 让 cmd_open 继续正常流程
 */
function handleRoomActionOpen(int $charId, string $arg, string $roomId): ?array {
    // 查询当前房间是否有 action_cmd LIKE 'open%' 且 handler_class = 'InteractHandler' 的动作
    $action = Database::queryOne(
        "SELECT * FROM room_actions WHERE room_id = ? AND action_cmd LIKE ? AND handler_class = ? AND enabled = 1",
        [$roomId, 'open%', 'InteractHandler']
    );
    
    if (!$action) {
        return null;
    }
    
    // 解析 config
    $config = json_decode($action['config'], true);
    if (!$config) {
        return null;
    }
    
    $validParams = $config['valid_params'] ?? [];
    
    // 空参数：返回提示，让前端 room.php 渲染可用选项
    if (empty($arg)) {
        return ['success' => false, 'message' => '你要打开什么？'];
    }
    
    // 检查参数是否在 valid_params 中
    if (!in_array($arg, $validParams)) {
        return null; // 参数不匹配，继续正常 open 流程（可能是个方向）
    }
    
    // 委托给 InteractHandler
    require_once DAEMON_PATH . 'InteractHandler.php';
    $handler = new InteractHandler();
    return $handler->execute($charId, $action, ['arg' => $arg]);
}

/**
 * 处理枫雪宫走廊开门控制
 * 参考原始LPC: /d/moon/fengxue/zoulang.c::do_open()
 * 当谈心室已锁门时，无法从外面打开
 */
function handleFengxueZoulangOpen(int $charId, array $room): ?array {
    require_once __DIR__ . '/lock.php';
    
    $isLocked = isTalkroomLocked('moon/fengxue/talkroom');
    
    if ($isLocked) {
        return ['success' => false, 'message' => '房里有人，门正锁着呢！'];
    }
    
    return null;
}
