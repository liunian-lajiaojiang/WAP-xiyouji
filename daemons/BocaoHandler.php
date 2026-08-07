<?php
/**
 * 拨开草处理器
 * 
 * 处理饮马峪和天魔庙的拨开草动作，需要装备武器才能拨开草丛
 * 双向对称，拨开一次两边都通
 * 定时恢复：1小时后草丛重新长出来
 */

require_once __DIR__ . '/ActionHandler.php';

class BocaoHandler extends ActionHandler {
    
    // 房间映射关系：当前房间 => [对面房间, 方向]
    private $roomMap = [
        'westway/yinma' => [
            'target_room' => 'qujing/qujingren/tianmo/miao',
            'target_area' => 'qujing',
            'direction' => 'north',
            'target_direction' => 'south',
            'target_area_other' => 'westway'
        ],
        'qujing/qujingren/tianmo/miao' => [
            'target_room' => 'westway/yinma',
            'target_area' => 'westway',
            'direction' => 'south',
            'target_direction' => 'north',
            'target_area_other' => 'qujing'
        ]
    ];
    
    // 草丛恢复时间（秒）
    const RESTORE_TIME = 3600; // 1小时
    
    /**
     * 配置缓存
     */
    private ?array $configCache = null;
    
    /**
     * 获取配置（优先从 room_actions.config JSON 读取）
     */
    private function getBocaoConfig(array $action): array {
        if ($this->configCache === null) {
            $dbConfig = $this->parseConfig($action);
            $this->configCache = [
                'restore_time' => $dbConfig['restore_time'] ?? self::RESTORE_TIME,
            ];
        }
        return $this->configCache;
    }
    
    /**
     * 执行拨开草动作
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $config = $this->getBocaoConfig($action);
            $character = $this->getCharacter($charId);
            
            if (!$character) {
                return [
                    'success' => false,
                    'message' => '角色不存在',
                    'data' => null
                ];
            }
            
            $roomId = $action['room_id'] ?? 'westway/yinma';
            
            // 检查是否是支持的房间
            if (!isset($this->roomMap[$roomId])) {
                return [
                    'success' => false,
                    'message' => '这里没有草丛可以拨开。',
                    'data' => ['type' => 'no_grass']
                ];
            }
            
            $roomConfig = $this->roomMap[$roomId];
            
            // 检查草丛是否已经被拨开了（同时检查是否过期）
            $isOpen = $this->isGrassCleared($roomId);
            
            if ($isOpen) {
                return [
                    'success' => true,
                    'message' => '还拔什么啊！没看见有路了吗？',
                    'data' => ['type' => 'already_open']
                ];
            }
            
            // 检查玩家是否装备了武器
            $hasWeapon = $this->hasWeaponEquipped($charId);
            
            if (!$hasWeapon) {
                return [
                    'success' => false,
                    'message' => '你需要装备武器才能拨开草丛。',
                    'data' => ['type' => 'no_weapon']
                ];
            }
            
            // 拨开草丛（两边都添加出口）
            $this->clearGrass($roomId);
            
            // 广播消息
            $charName = $character['name'];
            $direction = $roomConfig['direction'];
            $directionName = $this->getDirectionName($direction);
            $broadcastMsg = "{$charName}拔出武器，用力一挥，将{$directionName}边的草丛拨开，露出了一条小路！";
            $this->broadcastToRoom($roomId, $broadcastMsg, $charId);
            
            return [
                'success' => true,
                'message' => "你拔出武器，用力一挥，将{$directionName}边的草丛拨开，露出了一条小路！",
                'data' => ['type' => 'bocao_success']
            ];
            
        } catch (\Exception $e) {
            error_log("BocaoHandler error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '拨开草动作执行失败',
                'data' => null
            ];
        }
    }
    
    /**
     * 获取方向的中文名称
     */
    private function getDirectionName(string $direction): string {
        $names = [
            'north' => '北',
            'south' => '南',
            'east' => '东',
            'west' => '西'
        ];
        return $names[$direction] ?? $direction;
    }
    
    /**
     * 检查玩家是否装备了武器
     */
    private function hasWeaponEquipped(int $charId): bool {
        // 查询玩家装备的武器
        $sql = "SELECT ci.id 
                FROM character_inventory ci
                JOIN items i ON ci.item_id = i.item_id
                WHERE ci.char_id = ? 
                  AND ci.equipped = 1 
                  AND i.type = 'weapon'
                LIMIT 1";
        
        $result = Database::queryOne($sql, [$charId]);
        
        return !empty($result);
    }
    
    /**
     * 检查草丛是否已经被拨开了
     * 同时检查是否过期，如果过期则自动恢复
     */
    private function isGrassCleared(string $roomId): bool {
        $roomConfig = $this->roomMap[$roomId];
        
        // 检查当前房间的出口是否存在
        $sql = "SELECT id FROM room_exits WHERE room_id = ? AND direction = ? LIMIT 1";
        $result = Database::queryOne($sql, [$roomId, $roomConfig['direction']]);
        
        if (empty($result)) {
            return false;
        }
        
        // 检查是否过期
        $restoreTime = $this->getRestoreTime($roomId);
        if ($restoreTime && time() >= $restoreTime) {
            // 过期了，恢复草丛
            $this->restoreGrass($roomId);
            return false;
        }
        
        return true;
    }
    
    /**
     * 获取草丛恢复时间
     */
    private function getRestoreTime(string $roomId): ?int {
        $varKey = "bocao_restore_{$roomId}";
        $result = Database::queryOne(
            "SELECT value FROM variables WHERE var_key = ?",
            [$varKey]
        );
        return $result ? intval($result['value']) : null;
    }
    
    /**
     * 获取剩余时间
     */
    private function getRemainingTime(string $roomId): int {
        $restoreTime = $this->getRestoreTime($roomId);
        if (!$restoreTime) {
            return 0;
        }
        $remaining = $restoreTime - time();
        return max(0, $remaining);
    }
    
    /**
     * 设置草丛恢复时间
     */
    private function setRestoreTime(string $roomId): void {
        $varKey = "bocao_restore_{$roomId}";
        $restoreTime = time() + ($this->configCache['restore_time'] ?? self::RESTORE_TIME);
        
        Database::execute(
            "INSERT INTO variables (var_key, value, created_at, updated_at) 
             VALUES (?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE value = ?, updated_at = NOW()",
            [$varKey, $restoreTime, $restoreTime]
        );
    }
    
    /**
     * 拨开草丛（同时添加两边的出口）
     */
    private function clearGrass(string $roomId): void {
        $roomConfig = $this->roomMap[$roomId];
        
        // 获取最大的 id
        $maxIdResult = Database::queryOne("SELECT MAX(id) as max_id FROM room_exits");
        $maxId = intval($maxIdResult['max_id']);
        
        // 添加当前房间的出口
        $sql = "INSERT INTO room_exits (id, room_id, direction, target_area, target_room, door_name, door_closed) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        Database::execute($sql, [
            $maxId + 1,
            $roomId,
            $roomConfig['direction'],
            $roomConfig['target_area'],
            $roomConfig['target_room'],
            '',
            0
        ]);
        
        // 添加对面房间的出口
        Database::execute($sql, [
            $maxId + 2,
            $roomConfig['target_room'],
            $roomConfig['target_direction'],
            $roomConfig['target_area_other'],
            $roomId,
            '',
            0
        ]);
        
        // 设置恢复时间
        $this->setRestoreTime($roomId);
        // 对面房间也设置恢复时间（保持一致）
        $this->setRestoreTime($roomConfig['target_room']);
    }
    
    /**
     * 恢复草丛（删除两边的出口）
     */
    private function restoreGrass(string $roomId): void {
        $roomConfig = $this->roomMap[$roomId];
        
        // 删除当前房间的出口
        $sql = "DELETE FROM room_exits WHERE room_id = ? AND direction = ?";
        Database::execute($sql, [$roomId, $roomConfig['direction']]);
        
        // 删除对面房间的出口
        Database::execute($sql, [$roomConfig['target_room'], $roomConfig['target_direction']]);
        
        // 删除恢复时间记录
        $this->deleteRestoreTime($roomId);
        $this->deleteRestoreTime($roomConfig['target_room']);
    }
    
    /**
     * 删除恢复时间记录
     */
    private function deleteRestoreTime(string $roomId): void {
        $varKey = "bocao_restore_{$roomId}";
        Database::execute(
            "DELETE FROM variables WHERE var_key = ?",
            [$varKey]
        );
    }
}
