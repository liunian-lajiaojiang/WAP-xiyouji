<?php
/**
 * 海底莽林迷宫处理器
 * 
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 迷宫规则：
 * - 10个房间（maze0~maze9），海底莽林随机迷宫
 * - maze0 是入口/出口房间，可回到 under3
 * - maze1~maze9 的所有方向出口随机传送，模拟海底莽林迷失效果
 * - maze6 是特殊房间，有固定出口到 maze4/5/7（暗礁通道）
 * - 海流方向每30秒变化一次（通过 character_temp_states 存储当前海流）
 * 
 * 入口: sea/under3 → (southwest) → sea/maze0
 * 出口: sea/maze0 → (northeast) → sea/under3
 * 特殊: sea/maze6 → (south) → sea/maze5, (west) → sea/maze4, (north) → sea/maze7
 */

require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/MessageDaemon.php';

class SeaMazeHandler {

    // 10个迷宫房间ID
    private const ROOMS = [
        'sea/maze0',
        'sea/maze1',
        'sea/maze2',
        'sea/maze3',
        'sea/maze4',
        'sea/maze5',
        'sea/maze6',
        'sea/maze7',
        'sea/maze8',
        'sea/maze9',
    ];

    // maze6 固定出口（暗礁通道）
    private const MAZE6_EXITS = [
        'south' => 'sea/maze5',
        'west'  => 'sea/maze4',
        'north' => 'sea/maze7',
    ];

    /**
     * 检查房间ID是否为海底莽林迷宫房间
     */
    public static function isMazeRoom(string $roomId): bool {
        return in_array($roomId, self::ROOMS);
    }

    /**
     * 获取房间编号 (0-9)
     */
    private static function getRoomNumber(string $roomId): int {
        if (preg_match('/maze(\d+)$/', $roomId, $m)) {
            return intval($m[1]);
        }
        return -1;
    }

    /**
     * 获取玩家的迷宫访问状态
     * @return array [0 => bool, 1 => bool, ..., 9 => bool]
     */
    private static function getMazeState(int $charId): array {
        $rows = Database::queryAll(
            "SELECT state_key, state_value FROM character_temp_states 
             WHERE char_id = ? AND state_key LIKE 'seamaze_%'",
            [$charId]
        );
        $state = [];
        for ($i = 0; $i <= 9; $i++) {
            $state[$i] = false;
        }
        foreach ($rows as $row) {
            $num = intval(str_replace('seamaze_', '', $row['state_key']));
            if ($num >= 0 && $num <= 9) {
                $state[$num] = ($row['state_value'] === '1');
            }
        }
        return $state;
    }

    /**
     * 设置某个房间的访问标记
     */
    private static function setRoomVisited(int $charId, int $roomNum): void {
        Database::execute(
            "INSERT INTO character_temp_states (char_id, state_key, state_value) 
             VALUES (?, ?, '1')
             ON DUPLICATE KEY UPDATE state_value = '1'",
            [$charId, "seamaze_{$roomNum}"]
        );
    }

    /**
     * 重置全部迷宫进度
     */
    public static function resetMaze(int $charId): void {
        Database::execute(
            "DELETE FROM character_temp_states WHERE char_id = ? AND state_key LIKE 'seamaze_%'",
            [$charId]
        );
    }

    /**
     * 检查是否全部10个房间都已访问
     */
    private static function allVisited(array $state): bool {
        for ($i = 0; $i <= 9; $i++) {
            if (!$state[$i]) return false;
        }
        return true;
    }

    /**
     * 获取已访问房间数
     */
    private static function visitedCount(array $state): int {
        $count = 0;
        for ($i = 0; $i <= 9; $i++) {
            if ($state[$i]) $count++;
        }
        return $count;
    }

    /**
     * 处理玩家进入迷宫房间
     * 在 room.php 加载房间时调用
     * 
     * @return array|null 如果完成迷宫，返回 ['redirect' => url, 'message' => msg]；否则返回 null
     */
    public static function handleEnterRoom(int $charId, string $roomId): ?array {
        if (!self::isMazeRoom($roomId)) {
            return null;
        }

        $roomNum = self::getRoomNumber($roomId);
        if ($roomNum < 0 || $roomNum > 9) {
            return null;
        }

        $state = self::getMazeState($charId);

        if ($state[$roomNum]) {
            // 重复进入已访问的房间 → 暗流涌动警告（不重置进度）
            $flashMsg = HTML_HIBLU . '暗流涌动，你发现自己又回到了曾经经过的海底莽林……' . HTML_NOR . "\n"
                . HTML_HICYN . '（海底莽林：已探索 ' . self::visitedCount($state) . '/10 片莽林）' . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $flashMsg, 'self_event');

            return [
                'flash' => $flashMsg,
            ];
        }

        // 首次访问此房间 → 标记
        self::setRoomVisited($charId, $roomNum);
        $state[$roomNum] = true;

        // 检查是否全部走完
        if (self::allVisited($state)) {
            // 全部走完 → 标记永久完成，传送回 maze0
            // 原始设计：海底莽林只是通往龙宫的通道，不直接给奖励
            self::resetMaze($charId);

            $char = CharacterModel::find($charId);
            $charName = $char['name'] ?? '某人';

            // 广播离开消息
            MessageDaemon::broadcastToRoom(
                $roomId,
                HTML_HIGRN . '海底忽然光芒大盛！' . $charName . '的周身被一道金光笼罩，海底莽林的迷雾瞬间消散……' . HTML_NOR,
                $charId, 'room'
            );

            // 传送回 maze0（入口处）
            CharacterModel::updatePosition($charId, 'sea', 'maze0');

            $msg = HTML_HIGRN . '恭喜你完全探索了海底莽林！' . HTML_NOR . "\n";
            $msg .= HTML_HIYEL . '海底的迷雾在你眼前散去，你发现自己回到了莽林的入口。' . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');

            return [
                'redirect' => 'room.php?area=sea&room=' . urlencode('sea/maze0'),
                'message' => $msg,
            ];
        }

        // 还没走完，提示进度
        $visited = self::visitedCount($state);
        $progressMsg = HTML_HICYN . '【海底莽林】你已经探索了 ' . $visited . '/10 片莽林。' . HTML_NOR;
        if ($roomNum === 6) {
            $progressMsg .= "\n" . HTML_HIYEL . '你注意到这里的礁石似乎形成了一个通道……（有固定出口）' . HTML_NOR;
        }
        MessageDaemon::queueMessageToSelf($charId, $progressMsg, 'self_event');

        return [
            'flash' => $progressMsg,
        ];
    }

    /**
     * 处理迷宫中的移动（go命令）
     * 由 commands/go.php 调用
     * 
     * @return array|null 如果处理了移动，返回移动结果；否则返回 null（让 go.php 正常处理）
     */
    public static function handleMove(int $charId, array $char, array $room, string $direction): ?array {
        $roomId = $room['room_id'];
        
        if (!self::isMazeRoom($roomId)) {
            return null;
        }

        // maze0 特殊处理：south 进入 maze1，northeast 回到 under3（由 room_exits 正常处理）
        if ($roomId === 'sea/maze0') {
            return null; // 让 go.php 正常处理出口
        }

        // maze6 特殊处理：保留固定出口到 maze4/5/7
        if ($roomId === 'sea/maze6' && isset(self::MAZE6_EXITS[$direction])) {
            $targetRoomId = self::MAZE6_EXITS[$direction];
            return self::executeMove($charId, $char, $room, $direction, $targetRoomId, true);
        }

        // maze1~maze9（除 maze6 固定出口外）：随机传送
        $charName = $char['name'];

        // 消耗少量气血（海底莽林行走消耗）
        $maxKee = $char['max_kee'] ?? 100;
        $costKee = max(5, intval($maxKee * 0.03));
        
        if (($char['kee'] ?? 0) < $costKee) {
            return [
                'success' => false,
                'message' => HTML_HIRED . '你的气血不足，无法在海底莽林中继续前行。' . HTML_NOR,
            ];
        }

        Database::execute('UPDATE characters SET kee = kee - ? WHERE id = ?', [$costKee, $charId]);

        // ★ 优先传送玩家到未访问过的房间（避免卡进度）
        // 先查询当前迷宫访问状态
        $mazeState = self::getMazeState($charId);
        $unvisitedRooms = [];
        $visitedRooms = [];
        foreach (self::ROOMS as $r) {
            if ($r === $roomId) continue;
            $num = self::getRoomNumber($r);
            if ($num >= 0 && !($mazeState[$num] ?? false)) {
                $unvisitedRooms[] = $r;
            } else {
                $visitedRooms[] = $r;
            }
        }
        
        // 如果有未访问的房间，优先从其中随机选择
        if (!empty($unvisitedRooms)) {
            $targetRoomId = $unvisitedRooms[array_rand($unvisitedRooms)];
        } else {
            // 全部访问过了，从已访问房间中随机（玩家可能已经完成迷宫，不会到这里）
            $targetRoomId = $visitedRooms[array_rand($visitedRooms)];
        }

        // 广播暗流消息
        $leaveMessages = [
            HTML_HIBLU . $charName . '被一股暗流卷入了海底莽林深处……' . HTML_NOR,
            HTML_HIBLU . '一阵湍急的海流袭来，' . $charName . '的身影消失在浑浊的海水中。' . HTML_NOR,
            HTML_HIBLU . '海草晃动之间，' . $charName . '已不见踪影。' . HTML_NOR,
        ];
        $leaveMsg = $leaveMessages[array_rand($leaveMessages)];

        MessageDaemon::broadcastToRoom(
            $roomId,
            $leaveMsg,
            $charId, 'room'
        );

        return self::executeMove($charId, $char, $room, $direction, $targetRoomId, false, $leaveMsg);
    }

    /**
     * 执行实际移动
     */
    private static function executeMove(int $charId, array $char, array $room, string $direction, string $targetRoomId, bool $isFixed, string $leaveMsg = ''): array {
        $charName = $char['name'];
        $parts = explode('/', $targetRoomId);
        $targetArea = $parts[0];

        // 更新位置
        CharacterModel::updatePosition($charId, $targetArea, $targetRoomId);

        // 获取目标房间信息
        $newRoom = Database::queryOne('SELECT * FROM rooms WHERE room_id = ?', [$targetRoomId]);
        $roomName = $newRoom ? ($newRoom['room_name'] ?? '海底莽林') : '海底莽林';
        $roomDesc = $newRoom ? ($newRoom['room_desc'] ?? '') : '';

        // 到达消息
        $arriveMessages = [
            HTML_HIBLU . $charName . '被海流冲了过来，踉踉跄跄地稳住身形。' . HTML_NOR,
            HTML_HIBLU . '海草一阵晃动，' . $charName . '从暗流中钻了出来。' . HTML_NOR,
            HTML_HIBLU . $charName . '顺着海流漂了过来。' . HTML_NOR,
        ];
        $arriveMsg = $arriveMessages[array_rand($arriveMessages)];

        MessageDaemon::broadcastToRoom(
            $targetRoomId,
            $arriveMsg,
            $charId, 'room'
        );

        // 个人消息
        if ($isFixed) {
            $personalMsg = HTML_HIYEL . '你顺着礁石间的通道游了过去……' . HTML_NOR . "\n";
        } else {
            $personalMsg = HTML_HIBLU . '暗流将你卷入了海底莽林的另一处……' . HTML_NOR . "\n";
        }
        $personalMsg .= HTML_HICYN . $roomName . HTML_NOR . "\n";
        $personalMsg .= ($roomDesc ? $roomDesc . "\n" : '');
        $personalMsg .= HTML_HICYN . '（消耗了少许气力）' . HTML_NOR;

        return [
            'success' => true,
            'type' => 'move',
            'message' => $personalMsg,
            'leave_message' => '',    // 已在上面自行广播
            'arrive_message' => '',   // 已在上面自行广播
            'new_room' => $newRoom,
            'old_room' => $room,
            'redirect' => 'room.php?area=' . urlencode($targetArea) . '&room=' . urlencode($targetRoomId),
            'skip_queue' => true,
        ];
    }

    /**
     * 检查是否有 maze0 方向的出口（从 under3 来）
     * 用于 go.php 中处理迷宫入口方向
     */
    public static function getMaze0Exit(string $roomId, string $direction): ?string {
        if ($roomId === 'sea/under3' && $direction === 'southwest') {
            return 'sea/maze0';
        }
        return null;
    }

    /**
     * 获取迷宫房间的虚拟出口列表
     * 用于 room.php 动态生成出口显示，解决数据库中出口指向不存在房间导致的"未知"问题
     * 
     * @param string $roomId 当前房间ID
     * @return array|null 虚拟出口数组，或 null 表示不需要覆盖（让 room_exits 正常处理）
     */
    public static function getVirtualExits(string $roomId): ?array {
        if (!self::isMazeRoom($roomId)) {
            return null;
        }

        // maze0：使用 room_exits 真实出口（northeast→under3, south→maze1）
        if ($roomId === 'sea/maze0') {
            return null;
        }

        // maze6：暗礁通道固定出口，使用 room_exits 真实出口
        if ($roomId === 'sea/maze6') {
            return null;
        }

        // maze1~maze5, maze7~maze9：所有方向都是随机传送，但需要显示为可走方向
        // 显示四个基本方向，让玩家知道可以尝试
        $exits = [];
        $directions = ['north', 'south', 'east', 'west'];
        $targetNames = [
            '海底莽林深处',
            '浑浊的海域',
            '海草丛中',
            '暗流尽头',
            '未知的海底',
            '深海迷途',
            '莽林深处',
        ];

        foreach ($directions as $dir) {
            $exits[] = [
                'direction'    => $dir,
                'target_area'  => 'sea',
                'target_room'  => 'maze' . mt_rand(0, 9), // 占位，实际由 Handler 随机决定
                'door_name'    => null,
                'door_closed'  => 0,
                'target_name'  => $targetNames[array_rand($targetNames)],
                'is_virtual'   => true, // 标记为虚拟出口
            ];
        }

        return $exits;
    }
}
