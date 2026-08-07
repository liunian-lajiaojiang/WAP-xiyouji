<?php
/**
 * 天魔掀蒸笼处理器
 * 
 * 处理蒸笼房的掀蒸笼动作，救出取经人或获取取经人的肉
 */

require_once __DIR__ . '/ActionHandler.php';

class TianmoXianHandler extends ActionHandler {
    
    /**
     * 执行掀蒸笼动作
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
            
            $roomId = $action['room_id'] ?? 'qujing/qujingren/tianmo/zlf';
            $charName = $character['name'] ?? '你';
            
            // 获取取经系统状态
            $obstacled = Database::queryOne("SELECT * FROM obstacled WHERE id = 1");
            
            if (!$obstacled || empty($obstacled['cated_id'])) {
                return [
                    'success' => false,
                    'message' => '蒸笼里空空如也，什么都没有。',
                    'data' => null
                ];
            }
            
            $catedId = intval($obstacled['cated_id']);
            $husongId = intval($obstacled['husong'] ?? 0);
            $obstacleFail = intval($obstacled['obstacle_fail'] ?? 0);
            
            // 检查取经人是否在蒸笼房
            $whereQjr = $obstacled['where_qujingren'] ?? '';
            if ($whereQjr !== $roomId) {
                return [
                    'success' => false,
                    'message' => '蒸笼里好像没有取经人...',
                    'data' => null
                ];
            }
            
            // 情况1：抓取经人的玩家来掀蒸笼（取经失败后可以拿肉）
            if ($charId === $catedId) {
                return $this->handleCatorXian($charId, $character, $obstacled, $roomId);
            }
            
            // 情况2：护送人来掀蒸笼（救出取经人）
            if ($charId === $husongId) {
                return $this->handleHusongXian($charId, $character, $obstacled, $roomId);
            }
            
            // 情况3：其他人不能乱掀
            return [
                'success' => false,
                'message' => '这蒸笼可不是你能随便掀的。',
                'data' => null
            ];
            
        } catch (\Exception $e) {
            error_log("TianmoXianHandler error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '掀蒸笼动作执行失败',
                'data' => null
            ];
        }
    }
    
    /**
     * 处理抓取经人的玩家掀蒸笼
     */
    private function handleCatorXian(int $charId, array $character, array $obstacled, string $roomId): array {
        $obstacleFail = intval($obstacled['obstacle_fail'] ?? 0);
        
        // 如果还没失败，不能拿肉
        if (!$obstacleFail) {
            return [
                'success' => false,
                'message' => '取经人还没蒸熟呢，再等等吧。',
                'data' => null
            ];
        }
        
        // 检查是否已经拿过肉了
        $hasMeat = Database::queryOne(
            "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = 'qujingren_rou'",
            [$charId]
        );
        
        if ($hasMeat) {
            return [
                'success' => false,
                'message' => '你已经拿过肉了。',
                'data' => null
            ];
        }
        
        Database::beginTransaction();
        
        try {
            // 给玩家6块取经人的肉
            $this->giveQujingrenMeat($charId, 6);
            
            // 清理取经人
            $this->removeQujingren();
            
            // 重置 obstacled 状态
            Database::execute(
                "UPDATE obstacled SET 
                 cated_id = NULL,
                 where_qujingren = NULL,
                 last_env = NULL,
                 open_door = 0,
                 obstacle_fail = 0,
                 haved_qujingren = 0,
                 updated_at = NOW()
                 WHERE id = 1"
            );
            
            // 清理失败时间
            Database::execute(
                "DELETE FROM variables WHERE var_key = 'qujing_fail_time'"
            );
            
            Database::commit();
            
            // 广播消息
            $charName = $character['name'] ?? '某人';
            $broadcastMsg = "{$charName}掀开蒸笼盖，从里面拿出了几块香喷喷的肉。";
            
            $this->broadcastToRoom($roomId, $broadcastMsg, $charId);
            
            // 全服广播
            $globalMsg = HTML_HIYEL . "【天魔劫难】取经失败，{$charName}获得了取经人的肉！" . HTML_NOR;
            require_once __DIR__ . '/MessageDaemon.php';
            MessageDaemon::broadcastToAll($globalMsg);
            
            return [
                'success' => true,
                'message' => "你掀开蒸笼盖，一股浓郁的香味扑面而来...\n你从里面拿出了6块香喷喷的取经人肉！\n吃了这肉，想必能增进不少道行。",
                'data' => ['type' => 'xian_meat_success']
            ];
            
        } catch (\Exception $e) {
            Database::rollBack();
            throw $e;
        }
    }
    
    /**
     * 处理护送人掀蒸笼（救出取经人）
     */
    private function handleHusongXian(int $charId, array $character, array $obstacled, string $roomId): array {
        $obstacleFail = intval($obstacled['obstacle_fail'] ?? 0);
        
        // 如果已经失败了，就救不活了
        if ($obstacleFail) {
            return [
                'success' => false,
                'message' => '太晚了，取经人已经...',
                'data' => null
            ];
        }
        
        $lastEnv = $obstacled['last_env'] ?? '';
        
        Database::beginTransaction();
        
        try {
            // 把取经人传送回原来的地方
            $qujingren = $this->findQujingren();
            if ($qujingren && $lastEnv) {
                $this->moveQujingrenToRoom($qujingren['id'], $lastEnv);
            }
            
            // 重置 obstacled 状态
            Database::execute(
                "UPDATE obstacled SET 
                 cated_id = NULL,
                 where_qujingren = NULL,
                 last_env = NULL,
                 open_door = 0,
                 obstacle_fail = 0,
                 updated_at = NOW()
                 WHERE id = 1"
            );
            
            // 清理失败时间
            Database::execute(
                "DELETE FROM variables WHERE var_key = 'qujing_fail_time'"
            );
            
            // 清理天魔茧借用记录
            Database::execute(
                "UPDATE obstacled SET last_jie_id = NULL WHERE id = 1"
            );
            
            Database::commit();
            
            // 广播消息
            $charName = $character['name'] ?? '某人';
            $broadcastMsg = "{$charName}掀开蒸笼盖，救出了取经人！";
            
            $this->broadcastToRoom($roomId, $broadcastMsg, $charId);
            
            // 全服广播
            $globalMsg = HTML_HIGRN . "【天魔劫难】{$charName}成功救出了取经人！" . HTML_NOR;
            require_once __DIR__ . '/MessageDaemon.php';
            MessageDaemon::broadcastToAll($globalMsg);
            
            return [
                'success' => true,
                'message' => "你掀开蒸笼盖，只见取经人正好好地坐在里面念经呢...\n你赶紧把他救了出来。\n取经人对你连连道谢：「多谢施主救命之恩！」",
                'data' => [
                    'type' => 'xian_rescue_success',
                    'last_env' => $lastEnv
                ]
            ];
            
        } catch (\Exception $e) {
            Database::rollBack();
            throw $e;
        }
    }
    
    /**
     * 查找取经人NPC
     */
    private function findQujingren(): ?array {
        // 可能的取经人npc_id列表
        $qujingrenNpcIds = ['qujing ren', 'tangseng'];
        
        foreach ($qujingrenNpcIds as $npcId) {
            $npc = Database::queryOne(
                "SELECT * FROM npcs WHERE npc_id = ? LIMIT 1",
                [$npcId]
            );
            if ($npc) {
                return $npc;
            }
        }
        
        return null;
    }
    
    /**
     * 移动取经人到指定房间
     */
    private function moveQujingrenToRoom(int $npcId, string $roomId): void {
        $locationJson = json_encode([
            'area' => 'qujing',
            'room' => $roomId
        ]);
        
        Database::execute(
            "INSERT INTO npc_temp (npc_id, temp_key, temp_value, updated_at) 
             VALUES (?, 'current_location', ?, ?)
             ON DUPLICATE KEY UPDATE temp_value = ?, updated_at = ?",
            [$npcId, $locationJson, time(), $locationJson, time()]
        );
    }
    
    /**
     * 给玩家取经人的肉
     */
    private function giveQujingrenMeat(int $charId, int $quantity): void {
        // 检查物品是否存在
        $item = Database::queryOne(
            "SELECT item_id FROM items WHERE item_id = 'qujingren_rou' LIMIT 1"
        );
        
        if (!$item) {
            // 如果物品不存在，先创建（或者用一个已有的物品替代）
            // 这里简化处理，直接插入记录
            Database::execute(
                "INSERT IGNORE INTO items (item_id, name, description, type, category, value, weight) 
                 VALUES ('qujingren_rou', '取经人的肉', '蒸熟的取经人肉，吃了可以增进道行。', 'misc', 'qujing', 1000, 100)"
            );
        }
        
        // 给玩家物品
        Database::execute(
            "INSERT INTO character_inventory (char_id, item_id, quantity) 
             VALUES (?, 'qujingren_rou', ?)
             ON DUPLICATE KEY UPDATE quantity = quantity + ?",
            [$charId, $quantity, $quantity]
        );
    }
    
    /**
     * 移除取经人（取经失败后）
     */
    private function removeQujingren(): void {
        // 删除取经人NPC的位置记录
        // 可能的取经人npc_id列表
        $qujingrenNpcIds = ['qujing ren', 'tangseng'];
        
        foreach ($qujingrenNpcIds as $npcId) {
            Database::execute(
                "DELETE FROM npc_temp WHERE npc_id IN (SELECT id FROM npcs WHERE npc_id = ?) 
                 AND temp_key = 'current_location'",
                [$npcId]
            );
        }
    }
}
