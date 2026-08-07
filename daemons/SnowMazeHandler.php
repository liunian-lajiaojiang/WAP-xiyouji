<?php
/**
 * 雪山迷宫处理器
 * 
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 迷宫规则（哈密顿路径谜题）：
 * - 9个房间组成3x3方阵，玩家必须恰好经过每个房间一次
 * - 重复进入已访问的房间 → 重置全部进度
 * - 走完9个房间 → 自动传送到xueshan3或xueshan4
 * - 敲墙/qiang命令触发迷宫逻辑
 * 
 * 入口: snowmaze1 → (north) → snowmaze5
 * 出口: 第9个房间 → xueshan3/xueshan4
 */

require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/MessageDaemon.php';

class SnowMazeHandler {

    // 9个迷宫房间ID
    private const ROOMS = [
        'xueshan/snowmaze1',
        'xueshan/snowmaze2',
        'xueshan/snowmaze3',
        'xueshan/snowmaze4',
        'xueshan/snowmaze5',
        'xueshan/snowmaze6',
        'xueshan/snowmaze7',
        'xueshan/snowmaze8',
        'xueshan/snowmaze9',
    ];

    /**
     * 检查房间ID是否为雪山迷宫房间
     */
    public static function isMazeRoom(string $roomId): bool {
        return in_array($roomId, self::ROOMS);
    }

    /**
     * 获取房间编号 (1-9)
     * 从房间ID中提取迷宫编号
     */
    private static function getRoomNumber(string $roomId): int {
        if (preg_match('/snowmaze(\d)$/', $roomId, $m)) {
            return intval($m[1]);
        }
        return 0;
    }

    /**
     * 获取玩家的迷宫访问状态
     * @return array [1 => bool, 2 => bool, ..., 9 => bool]
     */
    private static function getMazeState(int $charId): array {
        $rows = Database::queryAll(
            "SELECT state_key, state_value FROM character_temp_states 
             WHERE char_id = ? AND state_key LIKE 'snowmaze%'",
            [$charId]
        );
        $state = [];
        for ($i = 1; $i <= 9; $i++) {
            $state[$i] = false;
        }
        foreach ($rows as $row) {
            $num = intval(str_replace('snowmaze', '', $row['state_key']));
            if ($num >= 1 && $num <= 9) {
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
            [$charId, "snowmaze{$roomNum}"]
        );
    }

    /**
     * 重置全部迷宫进度
     */
    public static function resetMaze(int $charId): void {
        Database::execute(
            "DELETE FROM character_temp_states WHERE char_id = ? AND state_key LIKE 'snowmaze%'",
            [$charId]
        );
    }

    /**
     * 检查是否全部9个房间都已访问
     */
    private static function allVisited(array $state): bool {
        for ($i = 1; $i <= 9; $i++) {
            if (!$state[$i]) return false;
        }
        return true;
    }

    /**
     * 处理玩家进入迷宫房间
     * 在 room.php 加载房间时调用
     * 
     * @return array|null 如果需要传送，返回 ['redirect' => url, 'message' => msg]；否则返回 null
     */
    public static function handleEnterRoom(int $charId, string $roomId): ?array {
        if (!self::isMazeRoom($roomId)) {
            return null;
        }

        $roomNum = self::getRoomNumber($roomId);
        if ($roomNum < 1 || $roomNum > 9) {
            return null;
        }

        require_once DAEMON_PATH . 'MessageDaemon.php';

        $state = self::getMazeState($charId);

        if ($state[$roomNum]) {
            // 重复进入已访问的房间 → 重置全部进度
            self::resetMaze($charId);

            // 重新标记当前房间为已访问（从当前房间重新开始）
            self::setRoomVisited($charId, $roomNum);

            $flashMsg = HTML_HIRED . '你发现自己走回了已经经过的雪地，脚下的雪印一闪，迷宫重新恢复了原状……' . HTML_NOR . "\n"
                . HTML_HIYEL . '（雪山迷宫进度已重置）' . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $flashMsg, 'self_event');

            return [
                'flash' => $flashMsg,
            ];
        }

        // 首次访问此房间 → 标记
        self::setRoomVisited($charId, $roomNum);

        // 重新获取状态（刚标记了当前房间）
        $state[$roomNum] = true;

        // 检查是否全部走完
        if (self::allVisited($state)) {
            // 全部走完 → 传送到xueshan3/xueshan4
            self::resetMaze($charId);

            // 随机选择传送目标
            $targetRoom = (rand(0, 1) === 0) ? 'xueshan3' : 'xueshan4';
            CharacterModel::updatePosition($charId, 'xueshan', $targetRoom);

            // 广播离开消息（给当前迷宫房间的其他人）
            $char = CharacterModel::find($charId);
            $charName = $char['name'] ?? '某人';
            MessageDaemon::broadcastToRoom(
                $roomId,
                HTML_HIYEL . $charName . '脚下的雪印忽然闪亮，身影一闪便消失在雪地中。' . HTML_NOR,
                $charId, 'room'
            );

            // 广播到达消息（给目标房间的人）
            MessageDaemon::broadcastToRoom(
                'xueshan/' . $targetRoom,
                HTML_HIYEL . '一道雪光闪过，' . $charName . '从雪地迷宫中走了出来。' . HTML_NOR,
                $charId, 'room'
            );

            $msg = HTML_HIGRN . '恭喜你通过了雪山迷宫！' . HTML_NOR . "\n";
            $msg .= HTML_HIYEL . '你感觉脚下的雪印突然消失，身体被一股力量传送到了新的地方。' . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');

            return [
                'redirect' => 'room.php?area=xueshan&room=' . urlencode($targetRoom),
                'message' => $msg,
            ];
        }

        // 还没走完，提示进度
        $visited = array_sum(array_map(function($v) { return $v ? 1 : 0; }, $state));

        $progressMsg = HTML_HICYN . '【雪山迷宫】你已经走过了 ' . $visited . '/9 个房间。' . HTML_NOR;
        MessageDaemon::queueMessageToSelf($charId, $progressMsg, 'self_event');

        return [
            'flash' => $progressMsg,
        ];
    }

    /**
     * 处理 jump 命令
     * 从任何迷宫房间跳墙 → 传送到荷塘中(inpool)，重置进度
     * 
     * @return array 周命令结果
     */
    public static function handleJumpBridge(int $charId, string $currentRoomId): array {
        if (!self::isMazeRoom($currentRoomId)) {
            return ['success' => false, 'message' => '你要跳什么？'];
        }

        $char = CharacterModel::find($charId);
        $charName = $char['name'] ?? '某人';

        // 重置迷宫进度
        self::resetMaze($charId);

        // 更新位置到 inpool
        CharacterModel::updatePosition($charId, 'xueshan', 'xueshan/inpool');

        // 广播跳桥消息（给当前房间其他人）
        MessageDaemon::broadcastToRoom(
            $currentRoomId,
            HTML_HIYEL . $charName . '一纵身，往雪地里跳了下去...' . HTML_NOR,
            $charId, 'room'
        );

        $msg = HTML_HIYEL . '你一纵身，往雪地里跳了下去...' . HTML_NOR . "\n";
        $msg .= HTML_HIBLU . '只听得扑通一声，你掉进了冰湖之中！' . HTML_NOR . "\n";
        $msg .= HTML_HIYEL . '（雪山迷宫进度已重置）' . HTML_NOR;
        MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');

        return [
            'success' => true,
            'type' => 'move',
            'message' => $msg,
            'leave_message' => '',  // 已自行广播
            'arrive_message' => '', // 已自行广播
            'new_room' => null,
            'redirect' => 'room.php?area=xueshan&room=' . urlencode('xueshan/inpool'),
        ];
    }

    /**
     * 处理敲墙命令 (qiang)
     * 在雪山迷宫中敲墙会随机打开一个通道
     * 
     * @return array 敲墙命令结果
     */
    public static function handleKnockWall(int $charId, string $currentRoomId): array {
        if (!self::isMazeRoom($currentRoomId)) {
            return ['success' => false, 'message' => '这里没有墙可以敲。'];
        }

        $char = CharacterModel::find($charId);
        $charName = $char['name'] ?? '某人';

        // 消耗一些气血
        $maxKee = $char['max_kee'] ?? 100;
        $costKee = max(10, intval($maxKee * 0.1));
        
        if (($char['kee'] ?? 0) < $costKee) {
            return ['success' => false, 'message' => '你的气血不足，无法用力敲墙。'];
        }

        CharacterModel::updateKee($charId, -$costKee);

        // 广播敲墙消息
        MessageDaemon::broadcastToRoom(
            $currentRoomId,
            HTML_HIYEL . $charName . '用力敲了敲墙壁，发出咚咚的声响。' . HTML_NOR,
            $charId, 'room'
        );

        // 随机决定是否成功打开通道 (30%概率)
        if (rand(1, 10) <= 3) {
            // 成功打开通道 - 随机传送到一个未访问的房间
            $state = self::getMazeState($charId);
            $unvisitedRooms = [];
            
            for ($i = 1; $i <= 9; $i++) {
                if (!$state[$i]) {
                    $unvisitedRooms[] = $i;
                }
            }
            
            if (!empty($unvisitedRooms)) {
                $targetRoomNum = $unvisitedRooms[array_rand($unvisitedRooms)];
                $targetRoomId = 'xueshan/snowmaze' . $targetRoomNum;
                
                // 更新位置
                CharacterModel::updatePosition($charId, 'xueshan', 'snowmaze' . $targetRoomNum);
                
                // 标记目标房间为已访问
                self::setRoomVisited($charId, $targetRoomNum);
                
                // 广播传送消息
                MessageDaemon::broadcastToRoom(
                    $currentRoomId,
                    HTML_HIGRN . $charName . '敲墙时突然发现一道暗门，身影一闪便消失不见了。' . HTML_NOR,
                    $charId, 'room'
                );
                
                $msg = HTML_HIGRN . '你敲墙时突然发现一道暗门！' . HTML_NOR . "\n";
                $msg .= HTML_HIYEL . '你穿过暗门，来到了一个新的地方。' . HTML_NOR;
                MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
                
                return [
                    'success' => true,
                    'message' => $msg,
                    'redirect' => 'room.php?area=xueshan&room=' . urlencode('snowmaze' . $targetRoomNum),
                ];
            }
        }

        // 未成功打开通道
        $msg = HTML_HIYEL . '你用力敲了敲墙壁，但似乎没有什么特别的事情发生。' . HTML_NOR;
        MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
        
        return [
            'success' => true,
            'message' => $msg,
        ];
    }
}