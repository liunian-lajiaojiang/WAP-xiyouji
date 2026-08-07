<?php
/**
 * 房间消息广播助手
 * 处理飞行、对战、探查、对话等特殊动作的房间消息广播
 */

class RoomMessageHelper {
    
    /**
     * 广播飞行消息
     * @param int $charId 角色ID
     * @param string $fromRoom 出发房间
     * @param string $toRoom 目标房间
     * @param array $charData 角色数据
     */
    public static function broadcastFlyMessage(int $charId, string $fromRoom, string $toRoom, array $charData): void {
        require_once __DIR__ . '/daemons/MessageDaemon.php';
        
        $race = $charData['race'] ?? '';
        $gender = $charData['gender'] ?? '';
        $level = $charData['level'] ?? 0;
        $name = $charData['name'];
        
        // 根据种族和等级生成不同的起飞消息
        if ($race === '妖' || $race === '魔') {
            $takeoffMsg = HIM . "只见{$name}化作一股黑风，呼啸而去。" . NOR;
        } elseif ($race === '仙' || $race === '神') {
            $takeoffMsg = HICYN . "只见{$name}脚踏祥云，腾空而起。" . NOR;
        } elseif ($race === '佛' || $race === '僧') {
            $takeoffMsg = HIY . "只见{$name}足下生莲，冉冉升起。" . NOR;
        } elseif ($race === '鬼' || $race === '魂') {
            $takeoffMsg = HIM . "只见{$name}化作一缕青烟，飘然而去。" . NOR;
        } elseif ($level >= 50) {
            $takeoffMsg = HIR . "只见{$name}长虹贯日，破空而去。" . NOR;
        } elseif ($gender === '女') {
            $takeoffMsg = HICYN . "只见{$name}衣袂飘飘，御风而行。" . NOR;
        } else {
            $takeoffMsg = HIY . "只见{$name}纵身一跃，腾空而去。" . NOR;
        }
        
        // 广播给出发房间的其他玩家
        MessageDaemon::broadcastToRoom($fromRoom, $takeoffMsg, $charId);
        
        log_game('FLY', "{$name} 从 {$fromRoom} 飞往 {$toRoom}");
    }
    
    /**
     * 广播战斗开始消息（fight）
     * @param int $charId 角色ID
     * @param string $targetName 目标名称
     * @param string $roomId 房间ID
     */
    public static function broadcastFightStart(int $charId, string $targetName, string $roomId): void {
        require_once __DIR__ . '/../helpers/RankHelper.php';
        require_once __DIR__ . '/daemons/MessageDaemon.php';
        
        $char = \CharacterModel::find($charId);
        if (!$char) return;
        
        // 根据性别和年龄生成自称
        $selfTitle = RankHelper::querySelf($char);
        
        // 获取对方的尊敬称呼
        $targetRespect = '';
        $targetNpc = \NpcModel::findByNpcId($targetName);
        if ($targetNpc) {
            $targetRespect = RankHelper::queryRespect($targetNpc);
        }
        
        // 生成切磋消息
        $message = HIY . "{$char['name']}对着{$targetName}说道：“{$selfTitle}{$char['name']}，领教{$targetRespect}的高招！”" . NOR;
        MessageDaemon::broadcastToRoom($roomId, $message, $charId);
        
        log_game('FIGHT', "{$char['name']} 与 {$targetName} 开始切磋");
    }
    
    /**
     * 广播击杀开始消息（kill）
     * @param int $charId 角色ID
     * @param string $targetName 目标名称
     * @param string $roomId 房间ID
     */
    public static function broadcastKillStart(int $charId, string $targetName, string $roomId): void {
        require_once __DIR__ . '/daemons/MessageDaemon.php';
        
        $char = \CharacterModel::find($charId);
        if (!$char) return;
        
        $message = HIR . "{$char['name']}对着{$targetName}喝道：“今日不是你死就是我活！”" . NOR;
        MessageDaemon::broadcastToRoom($roomId, $message, $charId);
        
        log_game('KILL', "{$char['name']} 试图杀死 {$targetName}");
    }
    
    /**
     * 广播探查消息
     * @param int $charId 角色ID
     * @param string $targetName 被探查者名称
     * @param string $roomId 房间ID
     */
    public static function broadcastExamineMessage(int $charId, string $targetName, string $roomId): void {
        require_once __DIR__ . '/daemons/MessageDaemon.php';
        
        $message = HIY . "{$targetName}忽然莫名其妙地哆嗦了一下。" . NOR;
        MessageDaemon::broadcastToRoom($roomId, $message, $charId);
        
        log_game('EXAMINE', "角色 {$charId} 探查了 {$targetName}");
    }
    
    /**
     * 广播对话消息（ask）
     * @param int $charId 角色ID
     * @param string $npcName NPC名称
     * @param string $topic 话题
     * @param string $roomId 房间ID
     */
    public static function broadcastAskMessage(int $charId, string $npcName, string $topic, string $roomId): void {
        require_once __DIR__ . '/daemons/MessageDaemon.php';
        
        $char = \CharacterModel::find($charId);
        if (!$char) return;
        
        $message = HIY . "{$char['name']}向{$npcName}打听有关『{$topic}』的消息。" . NOR;
        MessageDaemon::broadcastToRoom($roomId, $message, $charId);
        
        log_game('ASK', "{$char['name']} 向 {$npcName} 询问关于 {$topic}");
    }
    
    /**
     * 广播跟随消息
     * @param int $charId 角色ID
     * @param string $targetName 跟随目标名称
     * @param string $roomId 房间ID
     */
    public static function broadcastFollowMessage(int $charId, string $targetName, string $roomId): void {
        require_once __DIR__ . '/daemons/MessageDaemon.php';
        
        $char = \CharacterModel::find($charId);
        if (!$char) return;
        
        $message = HIY . "{$char['name']}决定开始跟随{$targetName}一起行动。" . NOR;
        MessageDaemon::broadcastToRoom($roomId, $message, $charId);
        
        log_game('FOLLOW', "{$char['name']} 开始跟随 {$targetName}");
    }
    
    /**
     * 广播变化术消息
     * @param int $charId 角色ID
     * @param string $targetName 变化目标名称
     * @param string $roomId 房间ID
     */
    public static function broadcastTransformMessage(int $charId, string $targetName, string $roomId): void {
        require_once __DIR__ . '/daemons/MessageDaemon.php';
        
        $char = \CharacterModel::find($charId);
        if (!$char) return;
        
        $message = HIY . "只见{$char['name']}浑身上下真元活动，口中念念有词，摇身一变，变得和{$targetName}一模一样！" . NOR;
        MessageDaemon::broadcastToRoom($roomId, $message, $charId);
        
        log_game('TRANSFORM', "{$char['name']} 变成了 {$targetName}");
    }
    
    /**
     * 广播恢复原形消息
     * @param int $charId 角色ID
     * @param string $originalName 原始名称
     * @param string $roomId 房间ID
     */
    public static function broadcastUntransformMessage(int $charId, string $originalName, string $roomId): void {
        require_once __DIR__ . '/daemons/MessageDaemon.php';
        
        $message = HIR . "只见{$originalName}神色一白，一阵烟雾之后，已经恢复了原形。" . NOR;
        MessageDaemon::broadcastToRoom($roomId, $message, $charId);
        
        log_game('UNTRANSFORM', "角色 {$charId} 恢复了原形");
    }
    
    /**
     * 广播捡取物品消息
     * @param int $charId 角色ID
     * @param string $itemName 物品名称
     * @param string $roomId 房间ID
     */
    public static function broadcastGetMessage(int $charId, string $itemName, string $roomId): void {
        require_once __DIR__ . '/daemons/MessageDaemon.php';
        
        $char = \CharacterModel::find($charId);
        if (!$char) return;
        
        $message = HIY . "{$char['name']}从地上捡起一个{$itemName}。" . NOR;
        MessageDaemon::broadcastToRoom($roomId, $message, $charId);
        
        log_game('GET', "{$char['name']} 捡起了 {$itemName}");
    }
    
    /**
     * 广播丢弃物品消息
     * @param int $charId 角色ID
     * @param string $itemName 物品名称
     * @param string $roomId 房间ID
     */
    public static function broadcastDropMessage(int $charId, string $itemName, string $roomId): void {
        require_once __DIR__ . '/daemons/MessageDaemon.php';
        
        $char = \CharacterModel::find($charId);
        if (!$char) return;
        
        $message = HIY . "{$char['name']}丢下了一个{$itemName}。" . NOR;
        MessageDaemon::broadcastToRoom($roomId, $message, $charId);
        
        log_game('DROP', "{$char['name']} 丢弃了 {$itemName}");
    }
    
    /**
     * 广播穿戴装备消息
     * @param int $charId 角色ID
     * @param string $itemName 装备名称
     * @param string $roomId 房间ID
     */
    public static function broadcastWearMessage(int $charId, string $itemName, string $roomId): void {
        require_once __DIR__ . '/daemons/MessageDaemon.php';
        
        $char = \CharacterModel::find($charId);
        if (!$char) return;
        
        $message = HIY . "{$char['name']}穿上了{$itemName}。" . NOR;
        MessageDaemon::broadcastToRoom($roomId, $message, $charId);
        
        log_game('WEAR', "{$char['name']} 穿上了 {$itemName}");
    }
    
    /**
     * 广播卸下装备消息
     * @param int $charId 角色ID
     * @param string $itemName 装备名称
     * @param string $roomId 房间ID
     */
    public static function broadcastRemoveMessage(int $charId, string $itemName, string $roomId): void {
        require_once __DIR__ . '/daemons/MessageDaemon.php';
        
        $char = \CharacterModel::find($charId);
        if (!$char) return;
        
        $message = HIY . "{$char['name']}卸下了{$itemName}。" . NOR;
        MessageDaemon::broadcastToRoom($roomId, $message, $charId);
        
        log_game('REMOVE', "{$char['name']} 卸下了 {$itemName}");
    }
    
    /**
     * 广播拿起武器消息
     * @param int $charId 角色ID
     * @param string $itemName 武器名称
     * @param string $roomId 房间ID
     */
    public static function broadcastWieldMessage(int $charId, string $itemName, string $roomId): void {
        require_once __DIR__ . '/daemons/MessageDaemon.php';
        
        $char = \CharacterModel::find($charId);
        if (!$char) return;
        
        $message = HIY . "{$char['name']}拿起了{$itemName}。" . NOR;
        MessageDaemon::broadcastToRoom($roomId, $message, $charId);
        
        log_game('WIELD', "{$char['name']} 拿起了 {$itemName}");
    }
    
    /**
     * 广播放下武器消息
     * @param int $charId 角色ID
     * @param string $itemName 武器名称
     * @param string $roomId 房间ID
     */
    public static function broadcastUnwieldMessage(int $charId, string $itemName, string $roomId): void {
        require_once __DIR__ . '/daemons/MessageDaemon.php';
        
        $char = \CharacterModel::find($charId);
        if (!$char) return;
        
        $message = HIY . "{$char['name']}放下了{$itemName}。" . NOR;
        MessageDaemon::broadcastToRoom($roomId, $message, $charId);
        
        log_game('UNWIELD', "{$char['name']} 放下了 {$itemName}");
    }
}

