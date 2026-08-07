<?php
/**
 * 潜水处理器 (DiveHandler)
 * 
 * 实现东海之滨潜水进入龙宫海底的功能：
 * 1. 龙宫门派弟子可自由潜水进入
 * 2. 非龙宫弟子需持有避水咒(zhou)才能进入
 * 3. 无避水咒则被憋得半死，爬上岸
 * 
 * 参考原始LPC逻辑：xyj2000-php/d/changan/Eastseashore.php
 */

require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/MessageDaemon.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/../models/Room.php';

class DiveHandler extends ActionHandler {

    /** 龙宫门派名称列表（允许自由潜水） */
    private const DRAGON_FAMILIES = ['龙宫', '东海龙宫'];

    /** 潜水出发房间 */
    private const DIVE_FROM_ROOM = 'changan/eastseashore';

    /** 潜水到达房间（海底 under1） */
    private const DIVE_TO_ROOM = 'sea/under1';

    /**
     * 执行潜水动作
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            require_once __DIR__ . '/../includes/db.php';
            
            $character = $this->getCharacter($charId);
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在'];
            }

            $currentRoom = $character['current_room'];
            if ($currentRoom !== self::DIVE_FROM_ROOM) {
                return ['success' => false, 'message' => '这里不是海边，不能潜水。'];
            }

            $charName = $character['name'];
            $familyName = $character['family'] ?? '';

            // 检查是否是龙宫门派弟子
            $isDragonFamily = in_array($familyName, self::DRAGON_FAMILIES);

            // 检查是否持有避水咒
            $hasBishui = $this->hasItem($charId, 'zhou');

            if (!$isDragonFamily && !$hasBishui) {
                // 非龙宫弟子且无避水咒 → 被憋得半死
                return $this->handleDiveFail($charId, $charName);
            }

            // 可以潜水 → 进入海底
            return $this->handleDiveSuccess($charId, $character, $charName, $isDragonFamily);

        } catch (\Exception $e) {
            error_log("DiveHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '潜水功能执行失败'];
        }
    }

    /**
     * 处理潜水成功
     */
    private function handleDiveSuccess(int $charId, array $char, string $charName, bool $isDragonFamily): array {
        $fromRoom = self::DIVE_FROM_ROOM;
        $targetArea = 'sea';
        $targetRoom = self::DIVE_TO_ROOM;

        // 获取目标房间信息
        $newRoom = RoomModel::getFullInfo($targetArea, $targetRoom);
        $roomName = $newRoom['name'] ?? '海底';

        // 生成消息
        if ($isDragonFamily) {
            $selfMsg = HTML_HICYN . '你纵身一跃，潜入东海之中。龙宫血脉在你体内流转，海水自动为你让开一条通路……' . HTML_NOR;
            $leaveMsg = HTML_HIYEL . "{$charName}纵身一跃，身影消失在波涛之中。" . HTML_NOR;
            $arriveMsg = HTML_HIYEL . "只见海水一阵翻涌，{$charName}的身影从上方游了下来。" . HTML_NOR;
            $underMsg = HTML_HIBLU . '你凭借龙宫血脉之力，在海水中自由穿行。' . HTML_NOR;
        } else {
            $selfMsg = HTML_HICYN . '你将避水咒贴在身上，纵身一跃潜入东海。咒文发出淡淡的光芒，海水自动避让……' . HTML_NOR;
            $leaveMsg = HTML_HIYEL . "{$charName}将一张符纸贴在身上，纵身跃入海中，海水竟不能近其身。" . HTML_NOR;
            $arriveMsg = HTML_HIYEL . "只见海水一阵翻涌，{$charName}周身环绕着淡淡光晕从上方游了下来。" . HTML_NOR;
            $underMsg = HTML_HIBLU . '避水咒的光芒笼罩着你，海水在你周身一尺之外便自动退却。' . HTML_NOR;
        }

        // 广播离开消息到东海之滨
        MessageDaemon::broadcastToRoom($fromRoom, $leaveMsg, $charId);

        // 更新角色位置到海底
        CharacterModel::updatePosition($charId, $targetArea, $targetRoom);

        // 广播到达消息到海底房间
        MessageDaemon::broadcastToRoom($targetRoom, $arriveMsg, $charId);

        // 构建个人消息（潜水不显示房间描述，只显示水下状态）
        $personalMsg = $underMsg;

        return [
            'success' => true,
            'type' => 'move',
            'message' => $personalMsg,
            'redirect' => room_url($targetArea, $targetRoom),
        ];
    }

    /**
     * 处理潜水失败（无避水咒）
     */
    private function handleDiveFail(int $charId, string $charName): array {
        $fromRoom = self::DIVE_FROM_ROOM;

        // 广播失败消息
        $failBroadcast = HTML_HIYEL . "{$charName}一个猛子扎到水里，却见浪花四溅，{$charName}被憋得半死，连滚带爬的又爬了上来。" . HTML_NOR;
        MessageDaemon::broadcastToRoom($fromRoom, $failBroadcast, $charId);

        // 扣除少量气血作为惩罚
        Database::execute(
            'UPDATE characters SET kee = GREATEST(1, kee - 30) WHERE id = ?',
            [$charId]
        );

        return [
            'success' => false,
            'message' => HTML_HIRED . '你一个猛子扎到水里，只觉得海水从四面八方涌来，直往口鼻中灌……你被憋得半死，只好又爬上岸来。' . HTML_NOR,
        ];
    }

    /**
     * 检查角色背包中是否有指定物品
     */
    private function hasItem(int $charId, string $itemId): bool {
        $item = Database::queryOne(
            "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = ? AND quantity > 0",
            [$charId, $itemId]
        );
        return !empty($item);
    }
}
