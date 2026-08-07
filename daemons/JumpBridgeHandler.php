<?php
/**
 * 泾水桥跳桥处理器 (JumpBridgeHandler)
 * 
 * 实现泾水桥(changan/bridge)跳桥功能：
 * 1. 持有避水咒 → 传送至阴阳界(death/huang)
 * 2. 无避水咒 → 掉落至泾水(changan/inwater)
 * 
 * 参考原始LPC逻辑：help/BOOK.TXT 记载的 jump bridge 入地府
 */

require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/MessageDaemon.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/../models/Room.php';

class JumpBridgeHandler extends ActionHandler {

    /** 跳桥出发房间 */
    private const BRIDGE_ROOM = 'changan/bridge';

    /** 无避水咒 → 掉落泾水 */
    private const FALL_ROOM_AREA = 'changan';
    private const FALL_ROOM_ID = 'changan/inwater';

    /** 有避水咒 → 传送阴阳界 */
    private const PORTAL_ROOM_AREA = 'death';
    private const PORTAL_ROOM_ID = 'death/huang';

    /** 避水咒 item_id 列表（兼容两个版本） */
    private const BISHUI_ITEMS = ['zhou', 'bishuizhou'];

    /**
     * 执行跳桥动作
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            require_once __DIR__ . '/../includes/db.php';
            
            $character = $this->getCharacter($charId);
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在'];
            }

            $currentRoom = $character['current_room'];
            if ($currentRoom !== self::BRIDGE_ROOM) {
                return ['success' => false, 'message' => '这里没有桥，不能跳桥。'];
            }

            $charName = $character['name'];

            // 检查是否持有避水咒
            $hasBishui = $this->hasBishuiZhou($charId);

            if ($hasBishui) {
                // 有避水咒 → 传送至阴阳界
                return $this->handlePortalToDeath($charId, $charName);
            } else {
                // 无避水咒 → 掉落泾水
                return $this->handleFallToWater($charId, $charName);
            }

        } catch (\Exception $e) {
            error_log("JumpBridgeHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '跳桥功能执行失败'];
        }
    }

    /**
     * 有避水咒 → 传送至阴阳界(death/huang)
     */
    private function handlePortalToDeath(int $charId, string $charName): array {
        $fromRoom = self::BRIDGE_ROOM;
        $targetArea = self::PORTAL_ROOM_AREA;
        $targetRoom = self::PORTAL_ROOM_ID;

        // 获取目标房间信息
        $newRoom = RoomModel::getFullInfo($targetArea, $targetRoom);
        $roomName = $newRoom['name'] ?? '阴阳界';

        // 个人消息
        $selfMsg = '你叹了口气，眼一闭，往桥下跳去. . .<br>只听呼呼阴风四起，你转眼来到了阴阳界';

        // 广播离开消息（泾水桥房间内其他人看到）
        $leaveMsg = HTML_HIYEL . "{$charName}叹了口气，眼一闭，往桥下跳了下去。" . HTML_NOR;

        // 广播到达消息（阴阳界房间内其他人看到）
        $arriveMsg = HTML_HIYEL . "只听呼呼阴风四起，{$charName}的身影突然出现在阴阳界。" . HTML_NOR;

        // 广播离开消息到泾水桥
        MessageDaemon::broadcastToRoom($fromRoom, $leaveMsg, $charId);

        // 更新角色位置
        CharacterModel::updatePosition($charId, $targetArea, $targetRoom);

        // 广播到达消息到阴阳界
        MessageDaemon::broadcastToRoom($targetRoom, $arriveMsg, $charId);

        // 构建个人消息（不含房间描述）
        $personalMsg = HTML_HIBLU . $selfMsg . HTML_NOR;

        return [
            'success' => true,
            'type' => 'move',
            'message' => $personalMsg,
            'redirect' => room_url($targetArea, $targetRoom),
        ];
    }

    /**
     * 无避水咒 → 掉落泾水(changan/inwater)
     */
    private function handleFallToWater(int $charId, string $charName): array {
        $fromRoom = self::BRIDGE_ROOM;
        $targetArea = self::FALL_ROOM_AREA;
        $targetRoom = self::FALL_ROOM_ID;

        // 获取目标房间信息
        $newRoom = RoomModel::getFullInfo($targetArea, $targetRoom);
        $roomName = $newRoom['name'] ?? '泾水';

        // 个人消息（按用户要求的格式）
        $selfMsg = '你叹了口气，眼一闭，往桥下跳去. . .<br>你来到了 ' . $roomName . ' 。<br>只听⌈噗通⌋一声你从桥上掉到水中。';

        // 广播离开消息（泾水桥房间内其他人看到）
        $leaveMsg = HTML_HIYEL . "{$charName}叹了口气，眼一闭，往桥下跳了下去。" . HTML_NOR;

        // 广播到达消息（泾水房间内其他人看到）
        $arriveMsg = HTML_HIYEL . "只听⌈噗通⌋一声，{$charName}从桥上掉到了水中。" . HTML_NOR;

        // 广播离开消息到泾水桥
        MessageDaemon::broadcastToRoom($fromRoom, $leaveMsg, $charId);

        // 更新角色位置
        CharacterModel::updatePosition($charId, $targetArea, $targetRoom);

        // 广播到达消息到泾水
        MessageDaemon::broadcastToRoom($targetRoom, $arriveMsg, $charId);

        // 构建个人消息（不含房间描述）
        $personalMsg = HTML_HIBLU . $selfMsg . HTML_NOR;

        return [
            'success' => true,
            'type' => 'move',
            'message' => $personalMsg,
            'redirect' => room_url($targetArea, $targetRoom),
        ];
    }

    /**
     * 检查角色背包中是否有避水咒（兼容 zhou 和 bishuizhou 两种 item_id）
     */
    private function hasBishuiZhou(int $charId): bool {
        $placeholders = implode(',', array_fill(0, count(self::BISHUI_ITEMS), '?'));
        $item = Database::queryOne(
            "SELECT id FROM character_inventory WHERE char_id = ? AND item_id IN ({$placeholders}) AND quantity > 0",
            array_merge([$charId], self::BISHUI_ITEMS)
        );
        return !empty($item);
    }
}
