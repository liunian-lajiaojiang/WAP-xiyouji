<?php
/**
 * NPC重生模型
 * 处理NPC死亡后的重生逻辑
 */

class NpcRespawn {
    /**
     * 记录NPC死亡并安排重生
     * @param int $npcId NPC ID
     * @param string $npcName NPC名称
     * @param string $originalArea 原始区域
     * @param string $originalRoom 原始房间
     * @param int|null $respawnMinutes 重生时间（分钟），默认根据道行计算
     * @return int 返回记录ID
     */
    public static function recordDeath(int $npcId, string $npcName, string $originalArea, string $originalRoom, ?int $respawnMinutes = null): int {
        // 如果没有指定重生时间，根据NPC道行计算
        if ($respawnMinutes === null) {
            $sql = "SELECT daoxing FROM npcs WHERE id = ?";
            $npc = Database::queryOne($sql, [$npcId]);
            
            $daoxing = $npc['daoxing'] ?? 0;
            if ($daoxing > 500) {
                $respawnMinutes = 30; // 30分钟
            } elseif ($daoxing > 200) {
                $respawnMinutes = 15; // 15分钟
            } elseif ($daoxing > 100) {
                $respawnMinutes = 10; // 10分钟
            } else {
                $respawnMinutes = 5; // 5分钟
            }
        }
        
        $respawnTime = date('Y-m-d H:i:s', time() + $respawnMinutes * 60);
        
        $sql = "INSERT INTO npc_respawn 
                (npc_id, npc_name, original_area, original_room, respawn_time) 
                VALUES (?, ?, ?, ?, ?)";
        
        Database::execute($sql, [$npcId, $npcName, $originalArea, $originalRoom, $respawnTime]);
        
        return Database::lastInsertId();
    }
    
    /**
     * 获取待重生的NPC列表
     * @return array
     */
    public static function getPendingRespawns(): array {
        $sql = "SELECT * FROM npc_respawn 
                WHERE respawned = 0 AND respawn_time <= NOW() 
                ORDER BY respawn_time ASC";
        
        return Database::queryAll($sql);
    }
    
    /**
     * 标记NPC已重生
     * @param int $respawnId 重生记录ID
     * @return bool
     */
    public static function markRespawned(int $respawnId): bool {
        $sql = "UPDATE npc_respawn SET respawned = 1 WHERE id = ?";
        Database::execute($sql, [$respawnId]);
        return true;
    }
    
    /**
     * 检查NPC是否在重生冷却中
     * @param int $npcId NPC ID
     * @return bool
     */
    public static function isInRespawnCooldown(int $npcId): bool {
        $sql = "SELECT 1 FROM npc_respawn 
                WHERE npc_id = ? AND respawned = 0 
                LIMIT 1";
        
        $result = Database::queryOne($sql, [$npcId]);
        return !empty($result);
    }
    
    /**
     * 处理NPC重生
     * @param array $respawnRecord 重生记录
     * @return bool
     */
    public static function doRespawn(array $respawnRecord): bool {
        $npcId = $respawnRecord['npc_id'];
        $originalArea = $respawnRecord['original_area'];
        $originalRoom = $respawnRecord['original_room'];
        
        // 更新NPC的位置和状态（使用 try-catch 避免表结构问题）
        try {
            $sql = "UPDATE npcs SET 
                    current_area = ?, 
                    current_room = ? 
                    WHERE id = ?";
            
            Database::execute($sql, [$originalArea, $originalRoom, $npcId]);
        } catch (Exception $e) {
            // 如果表字段不存在，记录错误但继续执行
            error_log("NPC respawn update failed: " . $e->getMessage());
        }
        
        // 标记为已重生
        self::markRespawned($respawnRecord['id']);
        
        // 清除session中的死亡标记，让NPC重新显示
        $deathKey = "npc_dead_" . $npcId;
        if (isset($_SESSION[$deathKey])) {
            unset($_SESSION[$deathKey]);
        }
        
        // 记录日志
        log_game('NPC_RESPAWN', "NPC {$respawnRecord['npc_name']} (ID: {$npcId}) 已在 {$originalArea}/{$originalRoom} 重生");
        
        return true;
    }
    
    /**
     * 处理所有待重生的NPC
     * @return int 重生的NPC数量
     */
    public static function processPendingRespawns(): int {
        $pending = self::getPendingRespawns();
        $count = 0;
        
        foreach ($pending as $record) {
            if (self::doRespawn($record)) {
                $count++;
            }
        }
        
        // 清理超过24小时的已重生记录，避免表数据越来越大
        self::cleanupOldRespawnRecords();
        
        return $count;
    }
    
    /**
     * 清理旧的重生记录
     * 删除超过24小时的已重生记录
     * @return int 删除的记录数量
     */
    public static function cleanupOldRespawnRecords(): int {
        $sql = "DELETE FROM npc_respawn 
                WHERE respawned = 1 AND respawn_time < DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        
        return Database::execute($sql);
    }
}

