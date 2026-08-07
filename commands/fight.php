<?php
/**
 * 切磋武艺命令 (fight)
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 与kill的区别：
 * - fight: 友好切磋，点到为止，需要对方同意（玩家）或接受（NPC）
 * - kill: 生死相搏，直接攻击，不需要同意
 */

require_once DAEMON_PATH . 'CombatDaemon.php';

// 加载技能消耗配置
static $_skillCosts = null;
if ($_skillCosts === null) {
    $_skillCosts = require __DIR__ . '/../config/skill_costs.php';
}

function cmd_fight(int $charId, string $param = ''): array {
    // 调试日志
    $debugLog = "\n=== cmd_fight 入口 ===\n";
    $debugLog .= "charId: {$charId}\n";
    $debugLog .= "param: {$param}\n";
    file_put_contents(__DIR__ . '/../debug_fight.log', $debugLog, FILE_APPEND);
    
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
                'message' => HTML_HIRED . '你昏迷中，无法切磋！' . HTML_NOR,
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
        return ['success' => false, 'message' => '你正忙着呢，没空打架。'];
    }
    
    // 检查是否在禁止战斗的房间
    $room = RoomModel::load($me['current_area'], $me['current_room']);
    if ($room && isset($room['no_fight']) && $room['no_fight']) {
        return ['success' => false, 'message' => '这里禁止战斗。'];
    }
    
    // 检查参数
    if (empty($param)) {
        return ['success' => false, 'message' => '你想攻击谁？'];
    }
    
    // 查找目标（优先在当前房间查找）
    $target = findTargetInRoomForFight($charId, $param);
    
    // 调试日志
    $debugLog = "\n=== cmd_fight 查找目标 ===\n";
    $debugLog .= "target found: " . ($target ? 'yes' : 'no') . "\n";
    if ($target) {
        $debugLog .= "target type: {$target['type']}\n";
        $debugLog .= "target name: " . ($target['name'] ?? 'unknown') . "\n";
        $debugLog .= "target id: " . ($target['id'] ?? $target['npc_id'] ?? 'unknown') . "\n";
    }
    file_put_contents(__DIR__ . '/../debug_fight.log', $debugLog, FILE_APPEND);
    
    if (!$target) {
        return ['success' => false, 'message' => '你想攻击谁？'];
    }
    
    // 检查是否是生物
    if ($target['type'] !== 'npc' && $target['type'] !== 'player') {
        return ['success' => false, 'message' => '看清楚一点，那并不是生物。'];
    }
    
    // 检查目标是否已经在与攻击者战斗
    if ($target['type'] === 'npc') {
        // 检查NPC是否已经在战斗中
        $combatState = CombatDaemon::getCombatStatus($charId);
        if ($combatState && isset($combatState['target_id']) && $combatState['target_id'] == $target['npc_id']) {
            return ['success' => false, 'message' => '加油！加油！加油！'];
        }
    } else {
        // 玩家之间的战斗检查
        $targetCombatState = CombatDaemon::getCombatStatus($target['id']);
        if ($targetCombatState && isset($targetCombatState['target_id']) && $targetCombatState['target_id'] == $charId) {
            return ['success' => false, 'message' => '加油！加油！加油！'];
        }
    }
    
    // 检查目标是否存活
    if ($target['type'] === 'npc') {
        // NPC总是"存活"的，除非特别标记
    } else {
        $targetChar = CharacterModel::find($target['id']);
        if ($targetChar && $targetChar['kee'] <= 0) {
            return ['success' => false, 'message' => $target['name'] . '已经无法战斗了。'];
        }
        // 目标玩家正在修炼中，不可攻击
        if ($targetChar && is_player_busy($target['id'])) {
            return ['success' => false, 'message' => $target['name'] . '正在闭关修炼，不宜打扰。'];
        }
    }
    
    // 不能攻击自己
    if ($target['type'] === 'player' && $target['id'] == $charId) {
        return ['success' => false, 'message' => '你不能攻击自己。'];
    }
    
    // 玩家之间的比武需要对方同意
    if ($target['type'] === 'player') {
        return handlePlayerFight($charId, $target['id'], $target['name']);
    }
    
    // NPC比武
    return handleNpcFight($charId, $target);
}

/**
 * 处理玩家之间的比试
 */
function handlePlayerFight(int $attackerId, int $targetId, string $targetName): array {
    // 调试日志
    $debugLog = "\n=== handlePlayerFight 调试 ===\n";
    $debugLog .= "attackerId: {$attackerId}\n";
    $debugLog .= "targetId: {$targetId}\n";
    $debugLog .= "targetName: {$targetName}\n";
    file_put_contents(__DIR__ . '/../debug_fight.log', $debugLog, FILE_APPEND);
    
    // 检查对方是否在战斗中
    require_once DAEMON_PATH . 'CombatDaemon.php';
    if (CombatDaemon::isInCombat($targetId)) {
        return [
            'success' => false,
            'message' => "{$targetName} 正在战斗中，无法发起切磋。"
        ];
    }
    
    // 检查是否已有待处理的请求（向同一人）
    $existing = Database::queryOne(
        'SELECT id FROM fight_requests 
         WHERE from_character_id = ? AND to_character_id = ? AND status = "pending"',
        [$attackerId, $targetId]
    );
    
    if ($existing) {
        return [
            'success' => false,
            'message' => "你已经向 {$targetName} 发出了切磋邀请，等待对方回应。",
            'type' => 'fight_invite',
            'pending' => true
        ];
    }
    
    // 检查自己向对方发起的请求是否已被接受
    $acceptedRequest = Database::queryOne(
        'SELECT id FROM fight_requests 
         WHERE from_character_id = ? AND to_character_id = ? AND status = "accepted"',
        [$attackerId, $targetId]
    );
    
    if ($acceptedRequest) {
        // 请求已被接受，开始战斗
        $result = CombatDaemon::startFight($attackerId, $targetId, 'player');
        
        if ($result['success']) {
            // 标记请求为已完成
            Database::execute(
                'UPDATE fight_requests SET status = "completed", resolved_at = NOW() WHERE id = ?',
                [$acceptedRequest['id']]
            );
            
            return [
                'success' => true,
                'type' => 'combat_start',
                'output' => $result['message'],
                'friendly' => true
            ];
        }
        
        return $result;
    }
    
    // 检查对方是否也向自己发起了切磋请求
    $reverseRequest = Database::queryOne(
        'SELECT id FROM fight_requests 
         WHERE from_character_id = ? AND to_character_id = ? AND status = "pending"',
        [$targetId, $attackerId]
    );
    
    if ($reverseRequest) {
        // 对方已同意（也发起了请求），开始比试
        // 更新对方的请求状态为已接受
        Database::execute(
            'UPDATE fight_requests SET status = "accepted", resolved_at = NOW() WHERE id = ?',
            [$reverseRequest['id']]
        );
        
        // 开始友好的战斗（不获得经验，不会死亡）
        $result = CombatDaemon::startFight($attackerId, $targetId, 'player');
        
        // 通知对方战斗开始了
        require_once DAEMON_PATH . 'MessageDaemon.php';
        $attacker = CharacterModel::find($attackerId);
        $attackerName = $attacker['name'] ?? '对方';
        $jumpUrl = "action.php?action=fight&target=" . $attackerId;
        $startMsg = HTML_HIGRN . '【切磋】' . HTML_NOR . ' ' . $attackerName . ' 接受了你的切磋邀请，战斗即将开始！';
        $startMsg .= '<span data-auto-jump="' . htmlspecialchars($jumpUrl) . '" style="display:none"></span>';
        MessageDaemon::sendToPlayer($targetId, $startMsg, 'system');
        
        return [
            'success' => true,
            'type' => 'combat_start',
            'output' => $result['message'],
            'friendly' => true
        ];
    }
    
    // 取消自己之前的其他待处理请求
    Database::execute(
        'UPDATE fight_requests SET status = "cancelled", resolved_at = NOW() 
         WHERE from_character_id = ? AND status = "pending"',
        [$attackerId]
    );
    
    // 创建新的切磋请求
    $expiresAt = date('Y-m-d H:i:s', time() + $_skillCosts['spar_invite']['expire_seconds']);
    Database::execute(
        'INSERT INTO fight_requests 
            (from_character_id, to_character_id, status, created_at, expires_at)
         VALUES (?, ?, "pending", NOW(), ?)',
        [$attackerId, $targetId, $expiresAt]
    );
    
    // 向目标玩家发送邀请通知（使用 sendPrivateMessage 发送私信，和探查功能完全一样的方式）
    require_once DAEMON_PATH . 'MessageDaemon.php';
    $attacker = CharacterModel::find($attackerId);
    $attackerName = $attacker['name'] ?? '有人';
    
    // 生成接受和拒绝的链接
    $acceptUrl = "action.php?action=accept+" . urlencode("fight");
    $rejectUrl = "action.php?action=reject+" . urlencode("fight");
    
    $inviteMsg = "{$attackerName}向你发起了切磋邀请！";
    $inviteMsg .= " <a href=\"{$acceptUrl}\" style=\"color:#00cc00;font-weight:bold;\">[接受切磋]</a> ";
    $inviteMsg .= "<a href=\"{$rejectUrl}\" style=\"color:#999;\">[拒绝切磋]</a> ";
    $inviteMsg .= "（30秒后自动失效）";
    
    // 调试日志：发送消息前
    $debugLog = "\n=== 发送切磋邀请消息 ===\n";
    $debugLog .= "targetId: {$targetId}\n";
    $debugLog .= "message: {$inviteMsg}\n";
    $debugLog .= "attackerId: {$attackerId}\n";
    file_put_contents(__DIR__ . '/../debug_fight.log', $debugLog, FILE_APPEND);
    
    $sendResult = MessageDaemon::sendPrivateMessage($targetId, $inviteMsg, $attackerId);
    
    // 调试日志：发送消息后
    $debugLog = "sendResult: " . ($sendResult ? 'success' : 'failed') . "\n";
    file_put_contents(__DIR__ . '/../debug_fight.log', $debugLog, FILE_APPEND);
    
    $message = HTML_HIGRN . "你向 {$targetName} 发出比试邀请。\n";
    $message .= "由于对方是由玩家控制的人物，你必须等对方同意才能进行比试。\n" . HTML_NOR;
    $message .= "（邀请30秒后自动失效）";
    
    return [
        'success' => true,
        'type' => 'fight_invite',
        'output' => $message,
        'pending' => true
    ];
}

/**
 * 处理与NPC的比试
 */
function handleNpcFight(int $charId, array $target): array {
    $me = CharacterModel::find($charId);
    
    // 使用主键id
    $npcId = $target['id'];
    
    // 检查NPC是否可以对话
    $canSpeak = true; // 简化：假设所有NPC都可以对话
    
    if ($canSpeak) {
        // 检查NPC是否处于拒绝切磋状态（被击败后30秒内）
        $rejectKey = "npc_reject_fight_{$npcId}";
        if (isset($_SESSION[$rejectKey])) {
            $expireTime = $_SESSION[$rejectKey];
            if (time() < $expireTime) {
                // NPC还在拒绝时间内
                return [
                    'success' => false,
                    'message' => '看起来' . $target['name'] . '并不想和你较量。'
                ];
            } else {
                // 过期了，清除状态
                unset($_SESSION[$rejectKey]);
            }
        }
        
        // NPC AI: 检查NPC是否接受切磋
        require_once __DIR__ . '/../helpers/NpcAiHelper.php';
        $npcData = $target['data'] ?? [];
        if (!empty($npcData)) {
            $acceptResult = NpcAiHelper::acceptFight($npcData);
            if (!$acceptResult['accept']) {
                return [
                    'success' => false,
                    'message' => $acceptResult['message']
                ];
            }
        }
        
        // 开始友好的战斗
        $targetId = intval($npcId);
        $result = CombatDaemon::startFight($charId, $targetId, 'npc');
        
        return [
            'success' => true,
            'type' => 'combat_start',
            'output' => $result['message'],
            'friendly' => true
        ];
    } else {
        // 不能说话的NPC，直接进入战斗
        $targetId = intval($npcId);
        $result = CombatDaemon::startFight($charId, $targetId, 'npc');
        
        return [
            'success' => true,
            'type' => 'combat_start',
            'output' => $result['message'],
            'friendly' => true
        ];
    }
}

/**
 * 在房间中查找目标
 */
if (!function_exists('findTargetInRoomForFight')) {
function findTargetInRoomForFight(int $charId, string $param): ?array {
    $me = CharacterModel::find($charId);
    $room = RoomModel::getFullInfo($me['current_area'], $me['current_room']);
    
    if (!$room) {
        return null;
    }
    
    // 优先查找玩家（在同一房间的在线玩家）
    $sql = "SELECT id, name FROM characters 
            WHERE current_room = ? AND online = 1 AND id != ?";
    $players = Database::queryAll($sql, [$me['current_room'], $charId]);
    
    if (!empty($players)) {
        foreach ($players as $player) {
            if (stripos($player['name'], $param) !== false || 
                strval($player['id']) === $param) {
                return [
                    'type' => 'player',
                    'id' => $player['id'],
                    'name' => $player['name'],
                    'data' => $player
                ];
            }
        }
    }
    
    // 查找NPC
    if (!empty($room['npcs'])) {
        foreach ($room['npcs'] as $npc) {
            if (stripos($npc['name'], $param) !== false || 
                (isset($npc['npc_id']) && stripos($npc['npc_id'], $param) !== false)) {
                return [
                    'type' => 'npc',
                    'id' => $npc['id'],  // 使用主键id
                    'npc_id' => $npc['npc_id'],
                    'name' => $npc['name'],
                    'data' => $npc
                ];
            }
        }
    }
    
    return null;
}
}

