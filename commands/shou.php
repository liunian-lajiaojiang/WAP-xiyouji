<?php
/**
 * 天魔茧使用命令
 * 处理使用天魔茧收取经人的功能
 */

/**
 * 收取经人
 * 用法：action.php?action=shou&param=tianmojian
 */
function cmd_shou(int $charId, string $param = ''): array {
    if (empty($param)) {
        return ['success' => false, 'message' => '你要收什么？'];
    }
    
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // busy check
    if (function_exists('is_player_busy') && is_player_busy($charId)) {
        return ['success' => false, 'message' => '你现在正忙着呢。'];
    }
    
    // 检查是否是天魔茧
    if ($param !== 'tianmojian' && $param !== '天魔茧') {
        return ['success' => false, 'message' => '这个东西不能用来收人。'];
    }
    
    // 检查玩家是否有天魔茧
    $sql = "SELECT ci.id, ci.item_id, ci.quantity, gi.name 
            FROM character_inventory ci
            JOIN items gi ON ci.item_id = gi.item_id
            WHERE ci.char_id = ? AND ci.item_id = 'tianmojian'";
    $invItem = Database::queryOne($sql, [$charId]);
    
    if (!$invItem || intval($invItem['quantity']) < 1) {
        return ['success' => false, 'message' => '你身上没有天魔茧。'];
    }
    
    // 如果指定了npc_id，检查这个NPC是不是取经人
    $targetNpcId = isset($_GET['npc_id']) ? intval($_GET['npc_id']) : (isset($_POST['npc_id']) ? intval($_POST['npc_id']) : 0);
    if ($targetNpcId > 0) {
        $targetNpc = Database::queryOne(
            "SELECT * FROM npcs WHERE id = ?",
            [$targetNpcId]
        );
        
        if (!$targetNpc) {
            return ['success' => false, 'message' => '目标NPC不存在。'];
        }
        
        // 检查这个NPC是不是取经人
        // 取经人NPC的npc_id可能是'tangseng'（唐僧/玄奘）或'qujing ren'
        $npcStringId = $targetNpc['npc_id'] ?? '';
        $npcName = $targetNpc['name'] ?? '';
        $isQujingren = ($npcStringId === 'tangseng' || $npcStringId === 'qujing ren' || strpos($npcName, '玄奘') !== false);
        
        if (!$isQujingren) {
            return ['success' => false, 'message' => $targetNpc['name'] . '不是取经人，不能用天魔茧收他！'];
        }
    }
    
    $roomId = $char['current_room'] ?? '';
    $area = $char['current_area'] ?? '';
    $charName = $char['name'] ?? '某人';
    
    // 检查取经人是否在房间里
    $qujingren = findQujingrenInRoom($roomId);
    
    if (!$qujingren) {
        return ['success' => false, 'message' => '这里没有取经人。'];
    }
    
    // 检查是否已经被抓
    $obstacled = Database::queryOne("SELECT cated_id, obstacle_fail, husong, last_jie_id FROM obstacled WHERE id = 1");
    if ($obstacled && !empty($obstacled['cated_id'])) {
        return ['success' => false, 'message' => '取经人已经被人抓走了。'];
    }
    
    // 检查护送人是否在场
    $husongId = $obstacled['husong'] ?? 0;
    if ($husongId > 0) {
        $husong = Database::queryOne(
            "SELECT id, name, current_room FROM characters WHERE id = ?",
            [$husongId]
        );
        
        if ($husong && $husong['current_room'] === $roomId) {
            return ['success' => false, 'message' => '护送人在旁边，你不能下手！'];
        }
    }
    
    // 检查战斗经验要求（≥50万）
    $combatExp = intval($char['combat_exp'] ?? 0);
    if ($combatExp < 500000) {
        return ['success' => false, 'message' => '你的道行太浅，驾驭不了天魔茧。'];
    }
    
    // 开始抓取经人
    Database::beginTransaction();
    
    try {
        // 1. 消耗天魔茧
        Database::execute(
            "UPDATE character_inventory SET quantity = quantity - 1 
             WHERE char_id = ? AND item_id = 'tianmojian' AND quantity >= 1",
            [$charId]
        );
        
        // 2. 把取经人传送到蒸笼房
        $zlfRoomId = 'qujing/qujingren/tianmo/zlf';
        moveQujingrenToRoom($qujingren['id'], $zlfRoomId);
        
        // 3. 更新 obstacled 表状态
        $now = time();
        Database::execute(
            "UPDATE obstacled SET 
             cated_id = ?, 
             where_qujingren = ?,
             last_env = ?,
             open_door = 0,
             obstacle_fail = 0,
             updated_at = NOW()
             WHERE id = 1",
            [$charId, $zlfRoomId, $roomId]
        );
        
        // 4. 设置24小时后取经失败（通过变量记录失败时间）
        $failTime = $now + 86400; // 24小时
        Database::execute(
            "INSERT INTO variables (var_key, value, created_at, updated_at) 
             VALUES ('qujing_fail_time', ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE value = ?, updated_at = NOW()",
            [$failTime, $failTime]
        );
        
        // 5. 设置抓取时间（用于开门机制，120秒后开门）
        Database::execute(
            "INSERT INTO variables (var_key, value, created_at, updated_at) 
             VALUES ('tianmo_cated_time', ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE value = ?, updated_at = NOW()",
            [$now, $now]
        );
        
        // 6. 设置门为关闭状态（120秒后自动打开）
        Database::execute(
            "UPDATE obstacled SET open_door = 0 WHERE id = 1"
        );
        
        // 7. 记录借宝者（如果还没有的话）
        $lastJieId = $obstacled['last_jie_id'] ?? 0;
        if (empty($lastJieId)) {
            Database::execute(
                "UPDATE obstacled SET last_jie_id = ? WHERE id = 1",
                [$charId]
            );
        }
        
        // 8. 设置 no_qujing 标记（以后不能参加取经）
        Database::execute(
            "UPDATE characters SET `obstacle/no_qujing` = 1 WHERE id = ?",
            [$charId]
        );
        
        Database::commit();
        
        // 广播消息
        $qjrName = $qujingren['name'] ?? '取经人';
        $broadcastMsg = "{$charName}拿出天魔茧，只见一道黑光闪过，{$qjrName}就不见了！";
        
        require_once DAEMON_PATH . 'MessageDaemon.php';
        MessageDaemon::broadcastToRoom($roomId, $broadcastMsg, $charId);
        
        // 全服广播
        $globalMsg = HTML_HIRED . "【天魔劫难】{$charName}使用天魔茧抓走了取经人！" . HTML_NOR;
        MessageDaemon::broadcastToAll($globalMsg);
        
        return [
            'success' => true,
            'message' => "你拿出天魔茧，口中念念有词...\n只见一道黑光闪过，取经人就被收入了天魔茧中！\n你哈哈大笑：「取经人，乖乖待在里面吧！」",
            'data' => ['type' => 'shou_success']
        ];
        
    } catch (\Exception $e) {
        Database::rollBack();
        error_log("cmd_shou error: " . $e->getMessage());
        return ['success' => false, 'message' => '收取失败，请稍后再试。'];
    }
}

/**
 * 查找房间内的取经人
 */
function findQujingrenInRoom(string $roomId): ?array {
    // 可能的取经人npc_id列表
    $qujingrenNpcIds = ['qujing ren', 'tangseng'];
    
    foreach ($qujingrenNpcIds as $npcId) {
        // 通过 npc_id 查找
        $npc = Database::queryOne(
            "SELECT * FROM npcs WHERE npc_id = ? LIMIT 1",
            [$npcId]
        );
        
        if ($npc) {
            // 检查NPC是否在这个房间
            // 先检查 npc_temp 表的 current_location
            $locationResult = Database::queryOne(
                "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'current_location'",
                [$npc['id']]
            );
            
            if ($locationResult) {
                $locationData = json_decode($locationResult['temp_value'], true);
                if ($locationData && isset($locationData['room']) && $locationData['room'] === $roomId) {
                    return $npc;
                }
            }
            
            // 再检查 npcs 表的 current_room 或 spawn_room
            if (($npc['current_room'] ?? '') === $roomId || ($npc['spawn_room'] ?? '') === $roomId) {
                return $npc;
            }
        }
    }
    
    return null;
}

/**
 * 移动取经人到指定房间
 */
function moveQujingrenToRoom(int $npcId, string $roomId): void {
    // 提取一级区域名（如 qujing）
    $areaParts = explode('/', $roomId);
    $area = $areaParts[0] ?? 'qujing';
    
    // 通过 npc_temp 表设置当前位置
    $locationJson = json_encode([
        'area' => $area,
        'room' => $roomId
    ]);
    
    Database::execute(
        "INSERT INTO npc_temp (npc_id, temp_key, temp_value, updated_at) 
         VALUES (?, 'current_location', ?, ?)
         ON DUPLICATE KEY UPDATE temp_value = ?, updated_at = ?",
        [$npcId, $locationJson, time(), $locationJson, time()]
    );
}
