<?php
/**
 * 宝象国野路三关连环机制处理器
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/MessageDaemon.php';

class BaoxiangHandler {
    
    /**
     * 处理NPC死亡链条
     * 女子 → 夫人 → 公公 → 白骨
     */
    public static function handleNpcDeathChain(int $npcId, array $npc, int $killerId, string $killerName, string $roomId, string $roomArea): void {
        $npcIdVal = $npc['npc_id'] ?? '';
        $npcName = $npc['name'] ?? '';
        
        // 设置击杀标记
        if (strpos($npcIdVal, 'nuzi') !== false || $npcName === '女子') {
            self::setKillMark($killerId, 'baoxiang_killed_nuzi');
            // 生成夫人
            self::spawnNextNpc('baoxiang_furen', '老妇人', $roomId, $roomArea, '唉，可见吾小女？');
        } elseif (strpos($npcIdVal, 'furen') !== false || $npcName === '老妇人') {
            self::setKillMark($killerId, 'baoxiang_killed_furen');
            // 生成公公
            self::spawnNextNpc('baoxiang_gonggong', '老公公', $roomId, $roomArea, '唉唉，可见吾小女老妻？');
        } elseif (strpos($npcIdVal, 'gonggong') !== false || $npcName === '老公公') {
            self::setKillMark($killerId, 'baoxiang_killed_gonggong');
            // 生成白骨
            self::spawnBaigu($roomId, $roomArea);
        }
    }
    
    /**
     * 设置击杀标记
     */
    public static function setKillMark(int $charId, string $markKey): void {
        // 检查是否已存在
        $existing = Database::queryOne(
            "SELECT id FROM character_temp_states WHERE char_id = ? AND state_key = ?",
            [$charId, $markKey]
        );
        
        if ($existing) {
            // 更新
            Database::execute(
                "UPDATE character_temp_states SET state_value = '1', updated_at = NOW() WHERE id = ?",
                [$existing['id']]
            );
        } else {
            // 插入
            Database::execute(
                "INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at) VALUES (?, ?, '1', NOW(), NOW())",
                [$charId, $markKey]
            );
        }
    }
    
    /**
     * 检查是否有击杀标记
     */
    public static function hasKillMark(int $charId, string $markKey): bool {
        $result = Database::queryOne(
            "SELECT id FROM character_temp_states WHERE char_id = ? AND state_key = ? AND state_value = '1'",
            [$charId, $markKey]
        );
        return !empty($result);
    }
    
    /**
     * 生成下一个NPC（通过npc_temp表临时出现）
     */
    private static function spawnNextNpc(string $targetNpcId, string $npcName, string $roomId, string $roomArea, string $greetMsg): void {
        // 查找目标NPC
        $npc = Database::queryOne("SELECT * FROM npcs WHERE npc_id = ?", [$targetNpcId]);
        if (!$npc) {
            log_game('BAOXIANG_ERROR', "找不到NPC: {$targetNpcId}");
            return;
        }
        
        $npcRealId = intval($npc['id']);
        
        // 设置current_location
        $locationJson = json_encode(['area' => $roomArea, 'room' => $roomId]);
        
        // 检查是否已存在
        $existing = Database::queryOne(
            "SELECT npc_id FROM npc_temp WHERE npc_id = ? AND temp_key = 'current_location'",
            [$npcRealId]
        );
        
        if ($existing) {
            Database::execute(
                "UPDATE npc_temp SET temp_value = ?, updated_at = ? WHERE npc_id = ? AND temp_key = 'current_location'",
                [$locationJson, time(), $npcRealId]
            );
        } else {
            Database::execute(
                "INSERT INTO npc_temp (npc_id, temp_key, temp_value, updated_at) VALUES (?, ?, ?, ?)",
                [$npcRealId, 'current_location', $locationJson, time()]
            );
        }
        
        // 恢复NPC的气血等状态
        Database::execute(
            "UPDATE npcs SET kee = max_kee, gin = max_gin, sen = max_sen, `force` = max_force, mana = max_mana WHERE id = ?",
            [$npcRealId]
        );
        
        // 发送打招呼消息
        if (!empty($greetMsg)) {
            $message = HTML_HIYEL . "{$npcName}走了过来，说道：{$greetMsg}" . HTML_NOR . "\n";
            MessageDaemon::broadcastToRoom($roomId, $message);
        }
        
        log_game('BAOXIANG_SPAWN', "生成NPC: {$npcName} ({$targetNpcId}) 在 {$roomId}");
    }
    
    /**
     * 生成白骨（物品）
     */
    private static function spawnBaigu(string $roomId, string $roomArea): void {
        // 查找白骨物品
        $baigu = Database::queryOne("SELECT * FROM items WHERE item_id = 'baigu'");
        if (!$baigu) {
            log_game('BAOXIANG_ERROR', "找不到白骨物品");
            return;
        }
        
        // 添加到房间物品：先解析 rooms.id（room_items.room_id 是 INT 外键）
        $roomRow = Database::queryOne("SELECT id FROM rooms WHERE room_id = ? LIMIT 1", [$roomId]);
        $roomDbId = $roomRow['id'] ?? 0;
        if ($roomDbId <= 0) {
            return;
        }
        
        // 检查room_items表
        $existing = Database::queryOne(
            "SELECT id FROM room_items WHERE room_id = ? AND item_id = 'baigu'",
            [$roomDbId]
        );
        
        if ($existing) {
            // 数量+1
            Database::execute(
                "UPDATE room_items SET quantity = quantity + 1 WHERE id = ?",
                [$existing['id']]
            );
        } else {
            // 插入新记录
            Database::execute(
                "INSERT INTO room_items (room_id, item_id, quantity) VALUES (?, 'baigu', 1)",
                [$roomDbId]
            );
        }
        
        // 发送消息
        $message = HTML_HIYEL . "老公公倒在地上，化作一堆白骨。" . HTML_NOR . "\n";
        MessageDaemon::broadcastToRoom($roomId, $message);
        
        log_game('BAOXIANG_BAIGU', "生成白骨在 {$roomId}");
    }
    
    /**
     * 检查野路8的房间阻挡（valid_leave）
     * 玩家往西南方向走时，检查是否已经击杀了三个NPC
     */
    public static function checkYelu8Block(int $charId, string $direction, string $currentRoomId): array {
        // 只有野路8的西南方向需要检查
        if ($currentRoomId !== 'qujing/baoxiang/yelu8' || $direction !== 'southwest') {
            return ['allowed' => true, 'message' => ''];
        }
        
        // 检查是否已经过了2关以上（简化处理：检查击杀标记数量）
        $killCount = 0;
        if (self::hasKillMark($charId, 'baoxiang_killed_nuzi')) $killCount++;
        if (self::hasKillMark($charId, 'baoxiang_killed_furen')) $killCount++;
        if (self::hasKillMark($charId, 'baoxiang_killed_gonggong')) $killCount++;
        
        // 已经过了2关以上，直接通过
        if ($killCount >= 2) {
            return ['allowed' => true, 'message' => ''];
        }
        
        // 1/20概率直接通过
        if (mt_rand(1, 20) === 1) {
            return ['allowed' => true, 'message' => ''];
        }
        
        // 按顺序检查，没杀的就生成对应的NPC挡住
        $blockNpcId = '';
        $blockNpcName = '';
        $greetMsg = '';
        
        if (!self::hasKillMark($charId, 'baoxiang_killed_nuzi')) {
            // 没杀女子，生成女子挡住
            $blockNpcId = 'nuzi';
            $blockNpcName = '女子';
            $greetMsg = '哟，客从何来？';
        } elseif (!self::hasKillMark($charId, 'baoxiang_killed_furen')) {
            // 没杀夫人，生成夫人挡住
            $blockNpcId = 'baoxiang_furen';
            $blockNpcName = '老妇人';
            $greetMsg = '唉，可见吾小女？';
        } elseif (!self::hasKillMark($charId, 'baoxiang_killed_gonggong')) {
            // 没杀公公，生成公公挡住
            $blockNpcId = 'baoxiang_gonggong';
            $blockNpcName = '老公公';
            $greetMsg = '唉唉，可见吾小女老妻？';
        } else {
            // 三个都杀了，可以通过
            return ['allowed' => true, 'message' => ''];
        }
        
        // 生成阻挡的NPC
        if (!empty($blockNpcId)) {
            self::spawnNextNpc($blockNpcId, $blockNpcName, $currentRoomId, 'qujing', $greetMsg);
        }
        
        // 阻挡提示
        $blockMessage = HTML_HIYEL . "你眼前身影一晃，似乎有谁挡住了你的路。" . HTML_NOR . "\n";
        
        return [
            'allowed' => false,
            'message' => $blockMessage
        ];
    }
}
