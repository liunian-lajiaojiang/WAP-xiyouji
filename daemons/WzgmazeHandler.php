<?php
/**
 * 八卦桥迷宫处理器
 * 
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 迷宫规则（哈密顿路径谜题）：
 * - 8个房间组成八卦阵，每个房间有3个出口
 * - 玩家必须恰好经过每个房间一次
 * - 重复进入已访问的房间 → 重置全部进度
 * - 走完8个房间 → 自动传送到北塘边(northpool)
 * - 跳桥(jump bridge) → 传送到荷塘中(inpool)，重置进度
 * 
 * 入口: southpool → (north) → wzgmaze1
 * 出口: 第8个房间 → northpool → (north) → huangguadi(黄瓜地)
 */
class WzgmazeHandler {

    // 8个迷宫房间ID
    private const ROOMS = [
        'qujing/wuzhuang/wzgmaze1',
        'qujing/wuzhuang/wzgmaze2',
        'qujing/wuzhuang/wzgmaze3',
        'qujing/wuzhuang/wzgmaze4',
        'qujing/wuzhuang/wzgmaze5',
        'qujing/wuzhuang/wzgmaze6',
        'qujing/wuzhuang/wzgmaze7',
        'qujing/wuzhuang/wzgmaze8',
    ];

    /**
     * 检查房间ID是否为八卦桥迷宫房间
     */
    public static function isMazeRoom(string $roomId): bool {
        return in_array($roomId, self::ROOMS);
    }

    /**
     * 获取房间编号 (1-8)
     */
    private static function getRoomNumber(string $roomId): int {
        if (preg_match('/wzgmaze(\d)$/', $roomId, $m)) {
            return intval($m[1]);
        }
        return 0;
    }

    /**
     * 获取玩家的迷宫访问状态
     * @return array [1 => bool, 2 => bool, ..., 8 => bool]
     */
    private static function getMazeState(int $charId): array {
        $rows = Database::queryAll(
            "SELECT state_key, state_value FROM character_temp_states 
             WHERE char_id = ? AND state_key LIKE 'wzgmaze%'",
            [$charId]
        );
        $state = [];
        for ($i = 1; $i <= 8; $i++) {
            $state[$i] = false;
        }
        foreach ($rows as $row) {
            $num = intval(str_replace('wzgmaze', '', $row['state_key']));
            if ($num >= 1 && $num <= 8) {
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
            [$charId, "wzgmaze{$roomNum}"]
        );
    }

    /**
     * 重置全部迷宫进度
     */
    public static function resetMaze(int $charId): void {
        Database::execute(
            "DELETE FROM character_temp_states WHERE char_id = ? AND state_key LIKE 'wzgmaze%'",
            [$charId]
        );
    }

    /**
     * 检查是否全部8个房间都已访问
     */
    private static function allVisited(array $state): bool {
        for ($i = 1; $i <= 8; $i++) {
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
        if ($roomNum < 1 || $roomNum > 8) {
            return null;
        }

        require_once DAEMON_PATH . 'MessageDaemon.php';

        $state = self::getMazeState($charId);

        if ($state[$roomNum]) {
            // 重复进入已访问的房间 → 重置全部进度
            self::resetMaze($charId);

            // 重新标记当前房间为已访问（从当前房间重新开始）
            self::setRoomVisited($charId, $roomNum);

            $flashMsg = HTML_HIRED . '你发现自己走回了已经经过的桥面，脚下的卦象一闪，八卦阵重新恢复了原状……' . HTML_NOR . "\n"
                . HTML_HIYEL . '（八卦桥进度已重置）' . HTML_NOR;
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
            // 全部走完 → 传送到 northpool
            self::resetMaze($charId);

            CharacterModel::updatePosition($charId, 'qujing', 'qujing/wuzhuang/northpool');

            // 广播离开消息（给当前迷宫房间的其他人）
            $char = CharacterModel::find($charId);
            $charName = $char['name'] ?? '某人';
            MessageDaemon::broadcastToRoom(
                $roomId,
                HTML_HIYEL . $charName . '脚下的八卦图案忽然金光大盛，一瞬间身影便消失不见了。' . HTML_NOR,
                $charId, 'room'
            );

            // 广播到达消息（给 northpool 的人）
            MessageDaemon::broadcastToRoom(
                'qujing/wuzhuang/northpool',
                HTML_HIYEL . '一道金光闪过，' . HTML_HIYEL . $charName . HTML_HIYEL . '从八卦桥中走了出来。' . HTML_NOR,
                $charId, 'room'
            );

            $msg = HTML_HIGRN . '恭喜你通过了八卦桥！' . HTML_NOR . "\n";
            $msg .= HTML_HIYEL . '脚下的八卦图案依次亮起，金光大盛之间，你被传送到了一片荷塘之畔。' . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');

            return [
                'redirect' => 'room.php?area=qujing&room=' . urlencode('qujing/wuzhuang/northpool'),
                'message' => $msg,
            ];
        }

        // 还没走完，提示进度
        $visited = array_sum(array_map(function($v) { return $v ? 1 : 0; }, $state));

        $progressMsg = HTML_HICYN . '【八卦桥】你已经走过了 ' . $visited . '/8 座桥面。' . HTML_NOR;
        MessageDaemon::queueMessageToSelf($charId, $progressMsg, 'self_event');

        return [
            'flash' => $progressMsg,
        ];
    }

    /**
     * 处理 jump bridge 命令
     * 从任何迷宫房间跳桥 → 传送到 inpool（荷塘中），重置进度
     * 
     * @return array 命令结果
     */
    public static function handleJumpBridge(int $charId, string $currentRoomId): array {
        if (!self::isMazeRoom($currentRoomId)) {
            return ['success' => false, 'message' => '你要跳什么？'];
        }

        $char = CharacterModel::find($charId);
        $charName = $char['name'] ?? '某人';

        // 重置迷宫进度
        self::resetMaze($charId);

        // 广播跳桥消息（给当前房间其他人）
        require_once DAEMON_PATH . 'MessageDaemon.php';
        MessageDaemon::broadcastToRoom(
            $currentRoomId,
            HTML_HIYEL . $charName . '一纵身，往桥下跳了下去...' . HTML_NOR,
            $charId, 'room'
        );

        // 更新位置到 inpool
        CharacterModel::updatePosition($charId, 'qujing', 'qujing/wuzhuang/inpool');

        // 广播落水消息（给 inpool 的人）
        MessageDaemon::broadcastToRoom(
            'qujing/wuzhuang/inpool',
            HTML_HIYEL . '只听得扑通一声，' . $charName . '从桥上面跳进了荷塘中。' . HTML_NOR,
            $charId, 'room'
        );

        $msg = HTML_HIYEL . '你一纵身，往桥下跳了下去...' . HTML_NOR . "\n";
        $msg .= HTML_HIBLU . '只听得扑通一声，你掉进了荷塘之中！' . HTML_NOR . "\n";
        $msg .= HTML_HIYEL . '（八卦桥进度已重置）' . HTML_NOR;
        MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');

        return [
            'success' => true,
            'type' => 'move',
            'message' => $msg,
            'leave_message' => '',  // 已自行广播
            'arrive_message' => '', // 已自行广播
            'new_room' => null,
            'redirect' => 'room.php?area=qujing&room=' . urlencode('qujing/wuzhuang/inpool'),
        ];
    }
}
