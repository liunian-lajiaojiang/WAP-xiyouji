<?php
/**
 * 杀敌命令 (kill)
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */
require_once DAEMON_PATH . 'CombatDaemon.php';

function cmd_kill(int $charId, string $param = ''): array {
    $me = CharacterModel::find($charId);
    
    if (!$me) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 检查是否昏迷 (参考原始LPC: living() 函数)
    if (isset($_SESSION["unconscious_{$charId}"])) {
        $unconscious = $_SESSION["unconscious_{$charId}"];
        $elapsed = time() - $unconscious['timestamp'];
        $duration = $unconscious['duration'] ?? 30;
        
        if ($elapsed < $duration) {
            return [
                'success' => false,
                'message' => HTML_HIRED . '你昏迷中，无法攻击！' . HTML_NOR,
                'skip_queue' => true,
            ];
        } else {
            unset($_SESSION["unconscious_{$charId}"]);
        }
    }
    
    $userId = intval($me['user_id']);
    $isBlocked = Database::queryOne('SELECT id FROM user_blocks WHERE user_id = ? AND block_type = ?', [$userId, 'pk']);
    if ($isBlocked) {
        return ['success' => false, 'message' => '你的PK功能已被封禁'];
    }
    
    // is_busy() 检查
    if (is_player_busy($charId)) {
        return ['success' => false, 'message' => '你正忙着呢，没空动手。'];
    }
    
    // 检查是否在禁止战斗的房间
    $room = RoomModel::load($me['current_area'], $me['current_room']);
    if ($room && isset($room['no_fight']) && $room['no_fight']) {
        return ['success' => false, 'message' => '这里不准战斗。'];
    }
    
    if (empty($param)) {
        return ['success' => false, 'message' => '你想杀谁？'];
    }
    
    // 查找目标（在当前房间的NPC或玩家）
    $target = null;
    $targetType = '';
    
    // 1. 先查找NPC（获取完整数据以支持AI）
    $sql = "SELECT id, npc_id, name, attitude FROM npcs WHERE name = ? OR npc_id = ? LIMIT 1";
    $npc = Database::queryOne($sql, [$param, $param]);
    
    if ($npc) {
        $target = $npc;
        $targetType = 'npc';
    } else {
        // 2. 查找在线玩家
        $sql = "SELECT id, name FROM characters WHERE name = ? AND online = 1 AND id != ? LIMIT 1";
        $player = Database::queryOne($sql, [$param, $charId]);
        
        if ($player) {
            $target = $player;
            $targetType = 'player';
        }
    }
    
    if (!$target) {
        return ['success' => false, 'message' => '这里没有这个人。'];
    }
    
    // 不能攻击自己
    if ($targetType === 'player' && $target['id'] == $charId) {
        return ['success' => false, 'message' => '用 suicide 指令会比较快:P。'];
    }
    
    // NPC AI: 根据态度生成反应消息（kill模式NPC无法拒绝，但会有不同反应）
    $npcReactionMsg = '';
    if ($targetType === 'npc' && !empty($npc['attitude'])) {
        require_once __DIR__ . '/../helpers/NpcAiHelper.php';
        $attitude = $npc['attitude'];
        $npcName = $npc['name'];
        switch ($attitude) {
            case NpcAiHelper::ATTITUDE_PEACEFUL:
                $npcReactionMsg = ' ' . HTML_HICYN . $npcName . '惊恐地喊道：你为何如此狠毒！' . HTML_NOR;
                break;
            case NpcAiHelper::ATTITUDE_FRIENDLY:
                $npcReactionMsg = ' ' . HTML_HICYN . $npcName . '叹了口气：既然如此，只好应战了。' . HTML_NOR;
                break;
            case NpcAiHelper::ATTITUDE_AGGRESSIVE:
            case NpcAiHelper::ATTITUDE_KILLER:
                $npcReactionMsg = ' ' . HTML_HIRED . $npcName . '冷笑道：来吧！今日不是你死就是我亡！' . HTML_NOR;
                break;
            case NpcAiHelper::ATTITUDE_HEROISM:
                $npcReactionMsg = ' ' . HTML_HICYN . $npcName . '朗声大笑：好！正想领教！' . HTML_NOR;
                break;
        }
    }
    
    // 调用CombatDaemon开始战斗
    $targetId = $targetType === 'npc' ? $target['id'] : $target['id'];  // 使用主键id
    
    // ★ 对玩家目标的互相击杀检测必须提前到 startKill 之前
    // 因为 startKill 会拒绝已在战斗中的玩家（line 44: "你已经在战斗中！"）
    // 如果对方先发动了 kill 并把我们被动拉入战斗，此时我们已在战斗中
    $alreadyInCombat = false;
    $mutualCombat = null;
    if ($targetType === 'player') {
        $targetPlayerId = $target['id'];
        // 检查双方是否互相击杀
        $mutualCombat = Database::queryOne(
            "SELECT id FROM active_combats WHERE char_id = ? AND target_id = ? AND target_type = 'player'",
            [$targetPlayerId, $charId]
        );
        $alreadyInCombat = CombatDaemon::isInCombat($charId);
    }
    
    // 如果互相击杀且自己已在战斗中（被动拉入），跳过 startKill
    if ($mutualCombat && $alreadyInCombat) {
        // 不调用 startKill，直接进入战斗页
        $targetMsg = "\n【战斗】 " . $me['name'] . ' 接受了你的挑战，要与你一决生死！';
        require_once DAEMON_PATH . 'MessageDaemon.php';
        MessageDaemon::sendToPlayer($targetPlayerId, $targetMsg, 'combat');
        MessageDaemon::queueMessageToSelf($charId, '【战斗】 你接受了 ' . $target['name'] . ' 的挑战，双方进入生死之战！', 'combat');
        
        return [
            'success' => true,
            'type' => 'combat_start',
            'output' => HTML_HIRED . $target['name'] . ' 也正向你杀来！' . HTML_NOR,
            'target_id' => $targetId,
            'target_type' => 'player',
            'target_name' => $target['name'],
            'mutual' => true
        ];
    }
    
    $result = CombatDaemon::startKill($charId, $targetId, $targetType);
    
    if (!$result['success']) {
        return $result;
    }
    
    // 对玩家的特殊处理
    if ($targetType === 'player') {
        $targetPlayerId = $target['id'];
        
        // 对方已经对我方发动了 kill（互相击杀，但自己之前不在战斗中）
        if ($mutualCombat) {
            $targetMsg = "\n【战斗】 " . $me['name'] . ' 接受了你的挑战，要与你一决生死！';
            require_once DAEMON_PATH . 'MessageDaemon.php';
            MessageDaemon::sendToPlayer($targetPlayerId, $targetMsg, 'combat');
            MessageDaemon::queueMessageToSelf($charId, '【战斗】 你接受了 ' . $target['name'] . ' 的挑战，双方进入生死之战！', 'combat');
            
            return [
                'success' => true,
                'type' => 'combat_start',
                'output' => $result['message'] . ' ' . HTML_HIRED . $target['name'] . ' 也正向你杀来！' . HTML_NOR,
                'target_id' => $targetId,
                'target_type' => 'player',
                'target_name' => $target['name'],
                'mutual' => true
            ];
        }
        
        // 对方还没有发动 kill → 也将对方拉入战斗状态（被动应战）
        // 这样对方的 room.php 会自动检测到并跳转 fight.php
        if (!CombatDaemon::isInCombat($targetPlayerId)) {
            CombatDaemon::startKill($targetPlayerId, $charId, 'player', $me['name']);
        }
        
        return [
            'success' => true,
            'type' => 'combat_start',
            'output' => $result['message'] . ' ' . HTML_HIRED . $target['name'] . ' 被迫与你一决生死！' . HTML_NOR,
            'target_id' => $targetId,
            'target_type' => 'player',
            'target_name' => $target['name'],
            'mutual' => true
        ];
    }
    
    return [
        'success' => true,
        'type' => 'combat_start',
        'output' => $result['message'] . $npcReactionMsg,
        'target_id' => $targetId,
        'target_type' => 'npc',
        'target_name' => $target['name']
    ];
}

