<?php
/**
 * 天魔叩拜处理器
 * 
 * 处理天魔庙的叩拜动作，触发蒸笼老人出现
 */

require_once __DIR__ . '/ActionHandler.php';

class TianmoKoubaiHandler extends ActionHandler {
    
    /**
     * 默认配置
     */
    public function getDefaultConfig(): array {
        return [
            'cooldown_seconds' => 60,  // 叩拜冷却时间（秒）
        ];
    }

    /**
     * 获取配置（带缓存）
     */
    private function getKoubaiConfig(array $action): array {
        static $cache = null;
        if ($cache === null) {
            $cache = $this->mergeConfig($this->getDefaultConfig(), $this->parseConfig($action));
        }
        return $cache;
    }

    /**
     * 执行叩拜动作
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            
            if (!$character) {
                return [
                    'success' => false,
                    'message' => '角色不存在',
                    'data' => null
                ];
            }
            
            $cfg = $this->getKoubaiConfig($action);
            $roomId = $action['room_id'] ?? 'qujing/qujingren/tianmo/miao';
            
            // 获取蒸笼老人NPC
            $laorenNpc = $this->getLaorenNpc();
            if (!$laorenNpc) {
                return [
                    'success' => false,
                    'message' => '蒸笼老人不见了...',
                    'data' => null
                ];
            }
            
            $npcId = $laorenNpc['id'];
            
            // 检查老人是否已经在房间里
            $isLaorenHere = $this->isLaorenInRoom($npcId, $roomId);
            
            if ($isLaorenHere) {
                return [
                    'success' => true,
                    'message' => '蒸笼老人已经在这里了。',
                    'data' => ['type' => 'already_here']
                ];
            }
            
            // 检查是否刚叩拜过（防止频繁触发）
            $lastKoubaiTime = $this->getLastKoubaiTime($roomId);
            $cooldown = $cfg['cooldown_seconds'];
            if ($lastKoubaiTime && (time() - $lastKoubaiTime) < $cooldown) {
                return [
                    'success' => true,
                    'message' => '你刚刚已经叩拜过了，先歇一会儿吧。',
                    'data' => ['type' => 'cooldown']
                ];
            }
            
            // 记录叩拜时间
            $this->setLastKoubaiTime($roomId);
            
            // 让老人出现在房间（5秒延迟效果，这里立即出现，但可以加消息提示）
            $this->spawnLaoren($npcId, $roomId);
            
            // 广播叩拜消息
            $charName = $character['name'];
            $broadcastMsg = "{$charName}虔诚地跪在神像前叩拜...";
            $this->broadcastToRoom($roomId, $broadcastMsg, $charId);
            
            // 5秒后老人出现的消息（这里立即出现，但用文字描述延迟效果）
            // 实际项目中可以用AJAX轮询来实现延迟出现
            $appearMsg = "过了一会儿，从后堂走出一位老人，正是蒸笼老人。";
            $this->broadcastToRoom($roomId, $appearMsg, 0);
            
            return [
                'success' => true,
                'message' => "你虔诚地跪在神像前叩拜，心中默默祈祷...\n过了一会儿，从后堂走出一位老人，正是蒸笼老人。",
                'data' => ['type' => 'koubai_success']
            ];
            
        } catch (\Exception $e) {
            error_log("TianmoKoubaiHandler error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '叩拜动作执行失败',
                'data' => null
            ];
        }
    }
    
    /**
     * 获取蒸笼老人NPC
     */
    private function getLaorenNpc(): ?array {
        $npc = Database::queryOne(
            "SELECT * FROM npcs WHERE npc_id = 'zhenglonglaoren' LIMIT 1"
        );
        return $npc ?: null;
    }
    
    /**
     * 检查老人是否在房间里
     */
    private function isLaorenInRoom(int $npcId, string $roomId): bool {
        // 检查current_location
        $locationResult = Database::queryOne(
            "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'current_location'",
            [$npcId]
        );
        
        if (!$locationResult) {
            return false;
        }
        
        $locationData = json_decode($locationResult['temp_value'], true);
        if (!$locationData || !isset($locationData['room'])) {
            return false;
        }
        
        if ($locationData['room'] !== $roomId) {
            return false;
        }
        
        // 检查是否过期
        $expireResult = Database::queryOne(
            "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'expire_time'",
            [$npcId]
        );
        
        if ($expireResult) {
            $expireTime = intval($expireResult['temp_value']);
            if (time() >= $expireTime) {
                // 已过期，清除位置
                $this->removeLaoren($npcId);
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * 让老人出现在房间
     */
    private function spawnLaoren(int $npcId, string $roomId): void {
        // 提取一级区域名（如 qujing）
        $areaParts = explode('/', $roomId);
        $area = $areaParts[0] ?? 'qujing';
        
        // 设置当前位置
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
        
        // 设置过期时间（60秒后消失）
        $expireTime = time() + 60;
        Database::execute(
            "INSERT INTO npc_temp (npc_id, temp_key, temp_value, updated_at) 
             VALUES (?, 'expire_time', ?, ?)
             ON DUPLICATE KEY UPDATE temp_value = ?, updated_at = ?",
            [$npcId, $expireTime, time(), $expireTime, time()]
        );
    }
    
    /**
     * 移除老人
     */
    private function removeLaoren(int $npcId): void {
        Database::execute(
            "DELETE FROM npc_temp WHERE npc_id = ? AND temp_key IN ('current_location', 'expire_time')",
            [$npcId]
        );
    }
    
    /**
     * 获取最后叩拜时间
     */
    private function getLastKoubaiTime(string $roomId): ?int {
        $varKey = "tianmo_koubai_{$roomId}";
        $result = Database::queryOne(
            "SELECT value FROM variables WHERE var_key = ?",
            [$varKey]
        );
        return $result ? intval($result['value']) : null;
    }
    
    /**
     * 设置最后叩拜时间
     */
    private function setLastKoubaiTime(string $roomId): void {
        $varKey = "tianmo_koubai_{$roomId}";
        Database::execute(
            "INSERT INTO variables (var_key, value, created_at, updated_at) 
             VALUES (?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE value = ?, updated_at = NOW()",
            [$varKey, time(), time()]
        );
    }
}
