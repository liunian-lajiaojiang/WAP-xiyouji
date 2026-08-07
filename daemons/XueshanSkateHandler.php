<?php

require_once __DIR__ . '/ActionHandler.php';

/**
 * 雪山滑冰动作处理器
 */
class XueshanSkateHandler extends ActionHandler {
    /**
     * 处理滑冰动作
     * @param int $charId 玩家ID
     * @param string $param 参数（可选，如方向）
     * @param array $char 玩家数据
     * @return array 处理结果
     */
    public static function execute(int $charId, string $param, array $char): array {
        // 检查当前房间是否为雪山迷宫房间
        $currentRoom = $char['current_room'];
        if (!preg_match('/xueshan\/snowmaze\d$/', $currentRoom)) {
            return [
                'success' => false,
                'message' => '这里没有雪地可以滑冰。'
            ];
        }

        // 滑冰逻辑：移动到指定位置（例如，从当前房间移动到下一个房间）
        $nextRoom = self::getNextRoom($currentRoom);
        if ($nextRoom) {
            // 更新玩家位置
            CharacterModel::updatePosition($charId, 'xueshan', $nextRoom);
            
            // 返回成功消息
            return [
                'success' => true,
                'message' => '你滑过雪坡，来到了新的地方。'
            ];
        } else {
            return [
                'success' => false,
                'message' => '雪坡太滑，你滑到了安全的地方。'
            ];
        }
    }

    /**
     * 获取下一个房间
     * @param string $currentRoom 当前房间ID
     * @return string|null 下一个房间ID或null
     */
    private static function getNextRoom(string $currentRoom): ?string {
        // 简单的房间切换逻辑，根据当前房间ID决定下一个房间
        $roomNumber = substr($currentRoom, -1);
        $nextRoomNumber = $roomNumber + 1;
        
        // 如果是最后一个房间，回到第一个
        if ($nextRoomNumber > 9) {
            $nextRoomNumber = 1;
        }
        
        return 'xueshan/snowmaze' . $nextRoomNumber;
    }
}