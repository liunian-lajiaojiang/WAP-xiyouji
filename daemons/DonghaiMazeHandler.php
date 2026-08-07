<?php
/**
 * 海底迷宫处理器（大闹天宫 - 龙宫借宝）
 * 
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 迷宫结构：
 *   固定路径（哈密顿路径，单向不可回头）：
 *     haidimigong → maze1 → maze2 → maze3 → maze4 → maze5 → maze6 → mazeend（海藏）
 *     mazeend → (up) → 东海之滨
 * 
 *   随机区域（mazea~mazed）：四个方向随机传送，模拟海底迷宫迷失
 *     原始LPC表达式：donghai/ways[random(sizeof(ways))]
 * 
 *   特殊房间：
 *     mazee：小金鱼（引路使者），可回到 maze1
 *     mazeend（海藏）：金箍棒等神兵所在
 * 
 * 入口: dntg/donghai/haidimigong
 * 出口: mazeend → (up) → changan/eastseashore
 */

require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/MessageDaemon.php';

class DonghaiMazeHandler {

    // 固定路径迷宫房间（哈密顿路径）
    private const FIXED_ROOMS = [
        'dntg/donghai/haidimigong',
        'dntg/donghai/maze1',
        'dntg/donghai/maze2',
        'dntg/donghai/maze3',
        'dntg/donghai/maze4',
        'dntg/donghai/maze5',
        'dntg/donghai/maze6',
        'dntg/donghai/mazeend',  // 海藏
    ];

    // 随机迷宫区域（LPC: donghai/ways[random(sizeof(ways))]）
    private const RANDOM_ROOMS = [
        'dntg/donghai/mazea',
        'dntg/donghai/mazeb',
        'dntg/donghai/mazec',
        'dntg/donghai/mazed',
    ];

    // 引路使者房间
    private const GUIDE_ROOM = 'dntg/donghai/mazee';

    // 所有迷宫房间
    private const ALL_ROOMS = [
        'dntg/donghai/haidimigong',
        'dntg/donghai/maze1',
        'dntg/donghai/maze2',
        'dntg/donghai/maze3',
        'dntg/donghai/maze4',
        'dntg/donghai/maze5',
        'dntg/donghai/maze6',
        'dntg/donghai/mazea',
        'dntg/donghai/mazeb',
        'dntg/donghai/mazec',
        'dntg/donghai/mazed',
        'dntg/donghai/mazee',
        'dntg/donghai/mazeend',
    ];

    /**
     * 检查是否为随机迷宫房间（mazea~mazed）
     */
    public static function isRandomMazeRoom(string $roomId): bool {
        return in_array($roomId, self::RANDOM_ROOMS);
    }

    /**
     * 检查是否为海底迷宫房间（所有类型）
     */
    public static function isMazeRoom(string $roomId): bool {
        return in_array($roomId, self::ALL_ROOMS);
    }

    /**
     * 检查是否为固定路径房间
     */
    private static function isFixedRoom(string $roomId): bool {
        return in_array($roomId, self::FIXED_ROOMS);
    }

    /**
     * 获取玩家的海底迷宫访问状态
     * @return array ['haidimigong' => bool, 'maze1' => bool, ..., 'mazeend' => bool]
     */
    private static function getMazeState(int $charId): array {
        $rows = Database::queryAll(
            "SELECT state_key, state_value FROM character_temp_states 
             WHERE char_id = ? AND state_key LIKE 'donghaimaze_%'",
            [$charId]
        );
        $state = [];
        foreach (self::FIXED_ROOMS as $roomId) {
            $key = self::roomToStateKey($roomId);
            $state[$key] = false;
        }
        foreach ($rows as $row) {
            $key = str_replace('donghaimaze_', '', $row['state_key']);
            if (array_key_exists($key, $state)) {
                $state[$key] = ($row['state_value'] === '1');
            }
        }
        return $state;
    }

    /**
     * 房间ID转状态key
     */
    private static function roomToStateKey(string $roomId): string {
        $parts = explode('/', $roomId);
        return end($parts);
    }

    /**
     * 设置某个房间的访问标记
     */
    private static function setRoomVisited(int $charId, string $roomId): void {
        $key = self::roomToStateKey($roomId);
        Database::execute(
            "INSERT INTO character_temp_states (char_id, state_key, state_value) 
             VALUES (?, ?, '1')
             ON DUPLICATE KEY UPDATE state_value = '1'",
            [$charId, "donghaimaze_{$key}"]
        );
    }

    /**
     * 重置全部迷宫进度
     */
    public static function resetMaze(int $charId): void {
        Database::execute(
            "DELETE FROM character_temp_states WHERE char_id = ? AND state_key LIKE 'donghaimaze_%'",
            [$charId]
        );
    }

    /**
     * 获取已访问的固定房间数
     */
    private static function visitedFixedCount(array $state): int {
        $count = 0;
        foreach ($state as $v) {
            if ($v) $count++;
        }
        return $count;
    }

    /**
     * 处理玩家进入海底迷宫房间
     * 在 room.php 加载房间时调用
     * 
     * @return array|null
     */
    public static function handleEnterRoom(int $charId, string $roomId): ?array {
        if (!self::isMazeRoom($roomId)) {
            return null;
        }

        // 只追踪固定路径房间（随机区和小金鱼房不追踪）
        if (!self::isFixedRoom($roomId) && $roomId !== self::GUIDE_ROOM) {
            // 随机迷宫房间不追踪访问进度
            return null;
        }

        // 小金鱼房间特殊处理
        if ($roomId === self::GUIDE_ROOM) {
            $flashMsg = HTML_HIYEL . '一条小金鱼在你面前游来游去，似乎在为你指引方向……' . HTML_NOR . "\n"
                . HTML_HICYN . '（小金鱼是引路使者，跟着它也许能走出迷宫）' . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $flashMsg, 'self_event');
            return [
                'flash' => $flashMsg,
            ];
        }

        $state = self::getMazeState($charId);
        $key = self::roomToStateKey($roomId);

        // 标记当前房间已访问
        self::setRoomVisited($charId, $roomId);
        $state[$key] = true;

        $totalRooms = count(self::FIXED_ROOMS);
        $visited = self::visitedFixedCount($state);

        // 特殊提示
        if ($roomId === 'dntg/donghai/haidimigong') {
            // 入口提示
            $flashMsg = HTML_HIBLU . '你进入了海底迷宫，四周海水昏暗，海草遮蔽了光线……' . HTML_NOR . "\n"
                . HTML_HICYN . '（海底迷宫：向着北方前行，据说海藏中藏有神兵利器……）' . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $flashMsg, 'self_event');
            return [
                'flash' => $flashMsg,
            ];
        }

        if ($roomId === 'dntg/donghai/mazeend') {
            // 到达海藏！
            $flashMsg = HTML_HIGRN . '你来到了海藏！空荡荡的海藏中直立着一根铁柱子，金光闪闪照得人睁不开眼。' . HTML_NOR . "\n"
                . HTML_HIYEL . '这里陈列着大砍刀、方天画戟、如意金箍棒、九股托天叉、梅花亮银锤、神铁等神兵利器……' . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $flashMsg, 'self_event');
            return [
                'flash' => $flashMsg,
            ];
        }

        // 进度提示
        $progressMsg = HTML_HICYN . '【海底迷宫】你已经探索了 ' . $visited . '/' . $totalRooms . ' 个区域。' . HTML_NOR;
        MessageDaemon::queueMessageToSelf($charId, $progressMsg, 'self_event');

        return [
            'flash' => $progressMsg,
        ];
    }

    /**
     * 处理随机迷宫区域的移动（mazea~mazed）
     * 由 commands/go.php 调用
     * 
     * 还原原始LPC: donghai/ways[random(sizeof(ways))]
     * 四个方向随机传送到 mazea~mazed 或 mazee 中的某个房间
     * 
     * @return array|null
     */
    public static function handleMove(int $charId, array $char, array $room, string $direction): ?array {
        $roomId = $room['room_id'];
        
        if (!self::isRandomMazeRoom($roomId)) {
            return null;
        }

        $charName = $char['name'];

        // 消耗少量气血
        $maxKee = $char['max_kee'] ?? 100;
        $costKee = max(3, intval($maxKee * 0.02));
        
        if (($char['kee'] ?? 0) < $costKee) {
            return [
                'success' => false,
                'message' => HTML_HIRED . '你的气血不足，在海底迷宫中寸步难行。' . HTML_NOR,
            ];
        }

        Database::execute('UPDATE characters SET kee = kee - ? WHERE id = ?', [$costKee, $charId]);

        // 随机目标：mazea~mazed + mazee（小金鱼房有概率出现引导）
        $possibleTargets = array_merge(self::RANDOM_ROOMS, [self::GUIDE_ROOM]);
        // 排除当前房间
        $available = array_filter($possibleTargets, function($r) use ($roomId) {
            return $r !== $roomId;
        });
        $available = array_values($available);
        
        // 80% 概率随机，20% 概率引导到 mazee
        if (mt_rand(1, 100) <= 20 && in_array(self::GUIDE_ROOM, $available)) {
            $targetRoomId = self::GUIDE_ROOM;
        } else {
            $targetRoomId = $available[array_rand($available)];
        }

        // 离开消息
        $leaveMessages = [
            HTML_HIBLU . $charName . '的身影消失在了海底迷宫的暗流之中。' . HTML_NOR,
            HTML_HIBLU . '一阵海流卷过，' . $charName . '已不见了踪影。' . HTML_NOR,
        ];
        $leaveMsg = $leaveMessages[array_rand($leaveMessages)];

        MessageDaemon::broadcastToRoom(
            $roomId,
            $leaveMsg,
            $charId, 'room'
        );

        return self::executeMove($charId, $char, $room, $direction, $targetRoomId, $leaveMsg);
    }

    /**
     * 执行实际移动
     */
    private static function executeMove(int $charId, array $char, array $room, string $direction, string $targetRoomId, string $leaveMsg = ''): array {
        $charName = $char['name'];
        $parts = explode('/', $targetRoomId);
        $targetArea = $parts[0];

        // 更新位置
        CharacterModel::updatePosition($charId, $targetArea, $targetRoomId);

        // 获取目标房间信息
        $newRoom = Database::queryOne('SELECT * FROM rooms WHERE room_id = ?', [$targetRoomId]);
        $roomName = $newRoom ? ($newRoom['room_name'] ?? '海底迷宫') : '海底迷宫';
        $roomDesc = $newRoom ? ($newRoom['room_desc'] ?? '') : '';

        // 到达消息
        if ($targetRoomId === self::GUIDE_ROOM) {
            $arriveMsg = HTML_HIYEL . $charName . '随着海流漂了过来，一条小金鱼在其身旁游动。' . HTML_NOR;
        } else {
            $arriveMessages = [
                HTML_HIBLU . $charName . '顺着海流漂了过来。' . HTML_NOR,
                HTML_HIBLU . '海水一阵翻涌，' . $charName . '出现在了这里。' . HTML_NOR,
            ];
            $arriveMsg = $arriveMessages[array_rand($arriveMessages)];
        }

        MessageDaemon::broadcastToRoom(
            $targetRoomId,
            $arriveMsg,
            $charId, 'room'
        );

        // 个人消息
        $personalMsg = HTML_HIBLU . '你在海底迷宫中摸索前行……' . HTML_NOR . "\n";
        $personalMsg .= HTML_HICYN . $roomName . HTML_NOR . "\n";
        $personalMsg .= ($roomDesc ? $roomDesc . "\n" : '');

        if ($targetRoomId === self::GUIDE_ROOM) {
            $personalMsg .= HTML_HIYEL . '一条小金鱼在你面前游动，似乎在为你指引方向。' . HTML_NOR . "\n";
        }

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
     * 获取海底迷宫房间的虚拟出口列表
     * 用于 room.php 动态生成出口显示
     * 只覆盖随机区房间（mazea~mazed），固定路径房间和 mazee 由 room_exits 正常处理
     * 
     * @param string $roomId 当前房间ID
     * @return array|null 虚拟出口数组，或 null 表示不需要覆盖
     */
    public static function getVirtualExits(string $roomId): ?array {
        // 只处理随机迷宫区域（mazea~mazed）
        if (!self::isRandomMazeRoom($roomId)) {
            return null;
        }

        $exits = [];
        $directions = ['north', 'south', 'east', 'west'];
        $targetNames = [
            '昏暗的海底',
            '海草丛生的水道',
            '珊瑚礁通道',
            '幽深的海沟',
            '海底岩洞',
            '暗流涌动处',
            '海藻密布区',
        ];

        foreach ($directions as $dir) {
            $exits[] = [
                'direction'    => $dir,
                'target_area'  => 'dntg',
                'target_room'  => 'donghai/mazea', // 占位，实际由 Handler 随机决定
                'door_name'    => null,
                'door_closed'  => 0,
                'target_name'  => $targetNames[array_rand($targetNames)],
                'is_virtual'   => true,
            ];
        }

        return $exits;
    }

    /**
     * 小金鱼引路：带玩家回到 maze1
     * 可通过 room_actions 表注册 "ask xiaojinyu about 出路" 或 "follow xiaojinyu"
     * 也可在 NPC 对话系统中触发
     * 
     * @return array
     */
    public static function handleFollowGoldfish(int $charId, array $char): array {
        $charName = $char['name'];
        $currentRoomId = $char['current_room'];

        // 小金鱼只在 mazee
        if ($currentRoomId !== self::GUIDE_ROOM) {
            return ['success' => false, 'message' => '这里没有小金鱼。'];
        }

        // 传送到 maze1
        $targetRoomId = 'dntg/donghai/maze1';
        $targetArea = 'dntg';
        CharacterModel::updatePosition($charId, $targetArea, $targetRoomId);

        // 广播消息
        MessageDaemon::broadcastToRoom(
            $currentRoomId,
            HTML_HIYEL . $charName . '跟着小金鱼游走了……' . HTML_NOR,
            $charId, 'room'
        );

        MessageDaemon::broadcastToRoom(
            $targetRoomId,
            HTML_HIYEL . '一条小金鱼带着' . $charName . '游了过来。' . HTML_NOR,
            $charId, 'room'
        );

        $msg = HTML_HIYEL . '你跟着小金鱼在海底迷宫中穿梭……' . HTML_NOR . "\n";
        $msg .= HTML_HIGRN . '眼前忽然一亮，你回到了海底迷宫的主路上！' . HTML_NOR;

        MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');

        return [
            'success' => true,
            'message' => $msg,
            'redirect' => 'room.php?area=dntg&room=' . urlencode('dntg/donghai/maze1'),
            'skip_queue' => true,
        ];
    }
}
