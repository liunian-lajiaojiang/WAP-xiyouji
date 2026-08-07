<?php
/**
 * 尸体模型
 * 处理尸体相关的数据库操作
 */

class Corpse {
    /**
     * 创建NPC尸体
     * @param int $npcId NPC ID
     * @param string $npcName NPC名称
     * @param string $roomArea 所在区域
     * @param string $roomId 所在房间
     * @param int|null $killerId 击杀者ID
     * @param string|null $killerName 击杀者名称
     * @return int 返回尸体ID
     */
    public static function createNpcCorpse(int $npcId, string $npcName, string $roomArea, string $roomId, ?int $killerId = null, ?string $killerName = null): int {
        $decayTime = date('Y-m-d H:i:s', time() + 600); // 10分钟后腐烂

        $sql = "INSERT INTO corpses 
                (owner_type, owner_id, owner_name, room_area, room_id, killer_id, killer_name, decay_time, decay_phase) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)";
        
        Database::execute($sql, ['npc', $npcId, $npcName, $roomArea, $roomId, $killerId, $killerName, $decayTime]);
        
        return Database::lastInsertId();
    }

    /**
     * 创建玩家尸体
     * @param int $playerId 玩家ID
     * @param string $playerName 玩家名称
     * @param string $roomArea 所在区域
     * @param string $roomId 所在房间
     * @param int|null $killerId 击杀者ID
     * @param string|null $killerName 击杀者名称
     * @return int 返回尸体ID
     */
    public static function createPlayerCorpse(int $playerId, string $playerName, string $roomArea, string $roomId, ?int $killerId = null, ?string $killerName = null): int {
        $decayTime = date('Y-m-d H:i:s', time() + 300); // 5分钟后腐烂

        $sql = "INSERT INTO corpses 
                (owner_type, owner_id, owner_name, room_area, room_id, killer_id, killer_name, decay_time, decay_phase) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)";
        
        Database::execute($sql, ['player', $playerId, $playerName, $roomArea, $roomId, $killerId, $killerName, $decayTime]);
        
        return Database::lastInsertId();
    }

    /**
     * 获取房间内的尸体列表
     * @param string $roomArea 区域
     * @param string $roomId 房间ID
     * @return array
     */
    public static function getCorpsesInRoom(string $roomArea, string $roomId): array {
        $sql = "SELECT * FROM corpses 
                WHERE room_area = ? AND room_id = ? AND decay_time > NOW() AND carried = 0
                ORDER BY created_at DESC";
        
        return Database::queryAll($sql, [$roomArea, $roomId]);
    }

    /**
     * 获取尸体详情
     * @param int $corpseId 尸体ID
     * @return array|null
     */
    public static function find(int $corpseId): ?array {
        $sql = "SELECT * FROM corpses WHERE id = ?";
        return Database::queryOne($sql, [$corpseId]);
    }

    /**
     * 获取尸体中的物品
     * @param int $corpseId 尸体ID
     * @return array
     */
    public static function getItems(int $corpseId): array {
        $sql = "SELECT * FROM corpse_items WHERE corpse_id = ? ORDER BY created_at DESC";
        return Database::queryAll($sql, [$corpseId]);
    }

    /**
     * 添加物品到尸体
     * @param int $corpseId 尸体ID
     * @param array $item 物品数据
     * @return bool
     */
    public static function addItem(int $corpseId, array $item): bool {
        $sql = "INSERT INTO corpse_items 
                (corpse_id, item_id, category, item_name, quantity, item_type, equipment_slot, is_equipped) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        Database::execute($sql, [
            $corpseId,
            $item['item_id'] ?? '',
            $item['category'] ?? '',
            $item['item_name'] ?? '',
            $item['quantity'] ?? 1,
            $item['item_type'] ?? null,
            $item['equipment_slot'] ?? null,
            $item['is_equipped'] ?? 0
        ]);
        
        return true;
    }

    /**
     * 批量添加物品到尸体
     * @param int $corpseId 尸体ID
     * @param array $items 物品数组
     * @return bool
     */
    public static function addItems(int $corpseId, array $items): bool {
        foreach ($items as $item) {
            self::addItem($corpseId, $item);
        }
        return true;
    }

    /**
     * 从尸体移除物品
     * @param int $corpseItemId 尸体物品ID
     * @return bool
     */
    public static function removeItem(int $corpseItemId): bool {
        $sql = "DELETE FROM corpse_items WHERE id = ?";
        Database::execute($sql, [$corpseItemId]);
        return true;
    }

    /**
     * 检查物品是否仍在尸体中（用于防重复拾取）
     * @param int $corpseId 尸体ID
     * @param int $corpseItemId 尸体物品ID
     * @return bool
     */
    public static function itemStillInCorpse(int $corpseId, int $corpseItemId): bool {
        $sql = "SELECT COUNT(*) as count FROM corpse_items WHERE id = ? AND corpse_id = ?";
        $result = Database::queryOne($sql, [$corpseItemId, $corpseId]);
        return ($result['count'] ?? 0) > 0;
    }

    /**
     * 标记尸体为已搜刮
     * @param int $corpseId 尸体ID
     * @return bool
     */
    public static function markLooted(int $corpseId): bool {
        $sql = "UPDATE corpses SET looted = 1 WHERE id = ?";
        Database::execute($sql, [$corpseId]);
        return true;
    }

    /**
     * 清理已腐烂的尸体（散落物品到房间后删除）
     * @return int 删除的尸体数量
     */
    public static function cleanupDecayedCorpses(): int {
        // 先获取所有过期尸体
        $sql = "SELECT * FROM corpses WHERE decay_time <= NOW()";
        $corpses = Database::queryAll($sql, []);
        
        foreach ($corpses as $corpse) {
            if (intval($corpse['carried']) === 1 && !empty($corpse['carried_by'])) {
                // 被背着的尸体：散落到背尸者当前所在房间
                $carrier = Database::queryOne(
                    "SELECT current_area, current_room FROM characters WHERE id = ?",
                    [$corpse['carried_by']]
                );
                if ($carrier) {
                    $scatterCorpse = $corpse;
                    $scatterCorpse['room_area'] = $carrier['current_area'] ?? '';
                    $scatterCorpse['room_id'] = $carrier['current_room'] ?? '';
                    self::scatterItemsToRoom($scatterCorpse);
                }
            } else {
                // 在地上的尸体：散落到所在房间
                self::scatterItemsToRoom($corpse);
            }
        }
        
        // 删除所有过期尸体的corpse_items
        $sql = "DELETE ci FROM corpse_items ci 
                INNER JOIN corpses c ON ci.corpse_id = c.id 
                WHERE c.decay_time <= NOW()";
        Database::execute($sql, []);
        
        // 删除过期尸体
        $sql = "DELETE FROM corpses WHERE decay_time <= NOW()";
        return Database::execute($sql, []);
    }

    /**
     * 获取尸体显示名称（根据腐烂阶段）
     * @param array $corpse 尸体数据
     * @return string
     */
    public static function getCorpseDisplayName(array $corpse): string {
        $phase = intval($corpse['decay_phase'] ?? 0);
        
        switch ($phase) {
            case 1:
                return '腐烂的尸体';
            case 2:
                return '一具枯干的骸骨';
            default:
                return ($corpse['owner_name'] ?? '无名') . '的尸体';
        }
    }

    /**
     * 获取尸体描述文字（根据腐烂阶段）
     * @param array $corpse 尸体数据
     * @return string
     */
    public static function getCorpseDescription(array $corpse): string {
        $phase = intval($corpse['decay_phase'] ?? 0);
        
        switch ($phase) {
            case 1:
                return '这具尸体显然已经躺在这里有一段时间了，正散发着一股腐尸的味道。';
            case 2:
                return '这副骸骨已经躺在这里很久了。';
            default:
                return '然而，他已经死了，只剩下一具尸体静静地躺在这里。';
        }
    }

    /**
     * 化尸：销毁尸体并将物品散落到房间
     * @param int $corpseId 尸体ID
     * @param string $roomArea 区域
     * @param string $roomId 房间ID
     * @return void
     */
    public static function dissolveCorpse(int $corpseId, string $roomArea, string $roomId): void {
        $corpse = self::find($corpseId);
        if (!$corpse) return;
        
        // 确定散落目标房间
        if (intval($corpse['carried']) === 1) {
            // 被携带的尸体：使用传入的房间（玩家当前所在房间）
            $targetArea = $roomArea;
            $targetRoomId = $roomId;
        } else {
            // 在地上的尸体：使用尸体自身的房间
            $targetArea = $corpse['room_area'];
            $targetRoomId = $corpse['room_id'];
        }
        
        // 构造一个带正确房间信息的副本用于散落物品
        $scatterCorpse = $corpse;
        $scatterCorpse['room_area'] = $targetArea;
        $scatterCorpse['room_id'] = $targetRoomId;
        
        // 将尸体物品散落到房间
        self::scatterItemsToRoom($scatterCorpse);
        
        // 删除尸体物品
        Database::execute("DELETE FROM corpse_items WHERE corpse_id = ?", [$corpseId]);
        
        // 删除尸体
        Database::execute("DELETE FROM corpses WHERE id = ?", [$corpseId]);
    }

    /**
     * 将尸体中的物品散落到房间地面
     * @param array $corpse 尸体数据
     * @return void
     */
    private static function scatterItemsToRoom(array $corpse): void {
        $items = self::getItems($corpse['id']);
        if (empty($items)) return;
        
        // 查找房间的数字ID（room_items表使用rooms.id作为room_id）
        $roomArea = $corpse['room_area'] ?? '';
        $roomIdStr = $corpse['room_id'] ?? '';
        if (empty($roomArea) || empty($roomIdStr)) {
            error_log("scatterItemsToRoom: 房间信息为空 - corpse_id={$corpse['id']}, owner={$corpse['owner_name']}");
            return;
        }
        
        $roomRow = Database::queryOne(
            "SELECT id FROM rooms WHERE area = ? AND room_id = ? LIMIT 1",
            [$roomArea, $roomIdStr]
        );
        if (!$roomRow) {
            error_log("scatterItemsToRoom: 房间不存在 - area={$roomArea}, room_id={$roomIdStr}, corpse_id={$corpse['id']}, owner={$corpse['owner_name']}");
            return;
        }
        
        $roomDbId = intval($roomRow['id']);
        
        foreach ($items as $item) {
            // 跳过货币类物品（白银等），不放到地上
            if (($item['item_type'] ?? '') === 'currency') continue;
            
            $quantity = intval($item['quantity'] ?? 1);
            if ($quantity <= 0) $quantity = 1;
            
            Database::execute(
                "INSERT INTO room_items (room_id, item_id, item_name, category, quantity, dropped_at) 
                 VALUES (?, ?, ?, ?, ?, NOW())",
                [
                    $roomDbId,
                    $item['item_id'] ?? '',
                    $item['item_name'] ?? '',
                    $item['category'] ?? '',
                    $quantity
                ]
            );
        }
    }

    /**
     * 推进所有尸体的腐烂阶段
     * 阶段时间比例（基于总寿命）：
     *   Phase 0（新鲜）: 0% - 50%
     *   Phase 1（腐烂）: 50% - 83%
     *   Phase 2（骸骨）: 83% - 100%
     * @return array 变更摘要
     */
    public static function advanceDecayPhases(): array {
        $changes = [];
        
        // 获取所有存活的尸体
        $sql = "SELECT * FROM corpses WHERE decay_time > NOW()";
        $corpses = Database::queryAll($sql, []);
        
        foreach ($corpses as $corpse) {
            $currentPhase = intval($corpse['decay_phase'] ?? 0);
            $createdAt = strtotime($corpse['created_at']);
            $decayAt = strtotime($corpse['decay_time']);
            $totalLife = $decayAt - $createdAt;
            if ($totalLife <= 0) continue;
            
            $elapsed = time() - $createdAt;
            $ratio = $elapsed / $totalLife;
            
            // 根据时间比例计算应处阶段
            $targetPhase = 0;
            if ($ratio >= 0.83) {
                $targetPhase = 2;
            } elseif ($ratio >= 0.50) {
                $targetPhase = 1;
            }
            
            if ($targetPhase > $currentPhase) {
                Database::execute(
                    "UPDATE corpses SET decay_phase = ? WHERE id = ?",
                    [$targetPhase, $corpse['id']]
                );
                $changes[] = [
                    'corpse_id' => $corpse['id'],
                    'owner_name' => $corpse['owner_name'],
                    'old_phase' => $currentPhase,
                    'new_phase' => $targetPhase
                ];
            }
        }
        
        return $changes;
    }

    /**
     * 获取玩家携带的尸体
     * @param int $charId 玩家ID
     * @return array
     */
    public static function getCarriedCorpses(int $charId): array {
        $sql = "SELECT * FROM corpses 
                WHERE carried = 1 AND carried_by = ? AND decay_time > NOW()
                ORDER BY created_at DESC";
        return Database::queryAll($sql, [$charId]);
    }
    
    /**
     * 背起尸体
     * @param int $corpseId 尸体ID
     * @param int $charId 玩家ID
     * @return bool
     */
    public static function carryCorpse(int $corpseId, int $charId): bool {
        // 检查玩家是否已背着其他尸体
        $existing = self::getCarriedCorpses($charId);
        if (!empty($existing)) {
            return false; // 已经背着尸体，不能再背
        }
        
        $sql = "UPDATE corpses SET carried = 1, carried_by = ?, room_area = '', room_id = '' WHERE id = ?";
        Database::execute($sql, [$charId, $corpseId]);
        return true;
    }
    
    /**
     * 放下尸体
     * @param int $corpseId 尸体ID
     * @param string $roomArea 所在区域
     * @param string $roomId 所在房间
     * @return bool
     */
    public static function dropCorpse(int $corpseId, string $roomArea, string $roomId): bool {
        $sql = "UPDATE corpses SET carried = 0, carried_by = NULL, room_area = ?, room_id = ? WHERE id = ?";
        Database::execute($sql, [$roomArea, $roomId, $corpseId]);
        return true;
    }
    
    /**
     * 埋葬尸体
     * @param int $corpseId 尸体ID
     * @return bool
     */
    public static function buryCorpse(int $corpseId): bool {
        // 先删除尸体中的物品
        $sql = "DELETE FROM corpse_items WHERE corpse_id = ?";
        Database::execute($sql, [$corpseId]);
        
        // 再删除尸体
        $sql = "DELETE FROM corpses WHERE id = ?";
        Database::execute($sql, [$corpseId]);
        
        return true;
    }
    
    /**
     * 检查玩家是否携带某具尸体
     * @param int $corpseId 尸体ID
     * @param int $charId 玩家ID
     * @return bool
     */
    public static function isCarriedBy(int $corpseId, int $charId): bool {
        $sql = "SELECT COUNT(*) as count FROM corpses WHERE id = ? AND carried = 1 AND carried_by = ?";
        $result = Database::queryOne($sql, [$corpseId, $charId]);
        return ($result['count'] ?? 0) > 0;
    }

    /**
     * 从NPC掉落物品
     * @param int $corpseId 尸体ID
     * @param array $npc NPC数据
     * @return void
     */
    public static function dropNpcItems(int $corpseId, array $npc): void {
        $npcId = $npc['id'] ?? 0;
        $daoxing = $npc['daoxing'] ?? 0;
        
        // === 第一步：掉落NPC身上的装备 ===
        // 从 npc_equipment 表中获取该NPC的所有装备
        if ($npcId > 0) {
            $sql = "SELECT ne.*, i.name as item_name, i.type as item_type
                    FROM npc_equipment ne
                    LEFT JOIN items i ON ne.item_id = i.item_id AND ne.category = i.category
                    WHERE ne.npc_id = ?";
            $equipment = Database::queryAll($sql, [$npcId]);
            
            // 去重：使用 item_id + category 作为唯一标识
            $droppedItems = [];
            
            // 把所有装备掉落到尸体上
            foreach ($equipment as $equip) {
                // 跳过已掉落的相同物品（防重复）
                $itemKey = ($equip['item_id'] ?? '') . ':' . ($equip['category'] ?? '');
                if (isset($droppedItems[$itemKey])) {
                    continue;
                }
                $droppedItems[$itemKey] = true;
                
                // 如果items表中没有该物品定义，使用item_id作为名称
                $itemName = !empty($equip['item_name']) ? $equip['item_name'] : $equip['item_id'];
                
                self::addItem($corpseId, [
                    'item_id' => $equip['item_id'] ?? '',
                    'category' => $equip['category'] ?? '',
                    'item_name' => $itemName,
                    'quantity' => 1,
                    'item_type' => $equip['item_type'] ?? null,
                    'equipment_slot' => $equip['equip_slot'] ?? null,
                    'is_equipped' => $equip['worn'] ?? 0
                ]);
            }
        }
        
        // === 第二步：货币掉落（基于道行计算） ===
        $silverDrop = mt_rand(0, 10);
        self::addItem($corpseId, [
            'item_id' => 'silver',
            'item_name' => '白银',
            'quantity' => $silverDrop,
            'item_type' => 'currency'
        ]);
        
        // === 第三步：drop_items JSON配置掉落 ===
        // 支持NPC表中的drop_items字段，格式为JSON
        // 兼容两种格式：
        // 格式1（直接数组）：[{"item_id":"elixir","name":"补气药","chance":10,"min":1,"max":3}, ...]
        // 格式2（guaranteed/random）：{"guaranteed":[...], "random":[...]}
        $dropConfig = json_decode($npc['drop_items'] ?? '[]', true);
        if (!empty($dropConfig)) {
            // 判断格式：直接数组 vs guaranteed/random对象
            // 直接数组：json_decode后为数字索引数组（0,1,2...）
            // guaranteed/random对象：json_decode后为关联数组，键名含"guaranteed"或"random"
            $isDirectArray = isset($dropConfig[0]);

            if ($isDirectArray) {
                // 格式1：直接数组，每项用chance字段判断概率
                foreach ($dropConfig as $drop) {
                    $chance = $drop['chance'] ?? 0;
                    if ($chance > 0 && mt_rand(1, 100) <= $chance) {
                        $qty = isset($drop['min']) && isset($drop['max'])
                            ? mt_rand((int)$drop['min'], (int)$drop['max'])
                            : ($drop['quantity'] ?? 1);
                        self::addItem($corpseId, [
                            'item_id' => $drop['item_id'],
                            'item_name' => $drop['name'] ?? $drop['item_id'],
                            'quantity' => $qty,
                            'item_type' => $drop['type'] ?? 'item'
                        ]);
                    }
                }
            } else {
                // 格式2：guaranteed/random对象（向后兼容）
                // 处理必定掉落
                if (!empty($dropConfig['guaranteed'])) {
                    foreach ($dropConfig['guaranteed'] as $drop) {
                        $qty = $drop['quantity'] ?? 1;
                        self::addItem($corpseId, [
                            'item_id' => $drop['item_id'],
                            'item_name' => $drop['name'] ?? $drop['item_id'],
                            'quantity' => $qty,
                            'item_type' => $drop['type'] ?? 'item'
                        ]);
                    }
                }

                // 处理概率掉落
                if (!empty($dropConfig['random'])) {
                    foreach ($dropConfig['random'] as $drop) {
                        $rate = $drop['rate'] ?? 0;
                        if ($rate > 0 && mt_rand(1, 100) <= $rate) {
                            // 计算掉落数量：有min/max则随机，否则取quantity
                            $qty = isset($drop['min']) && isset($drop['max'])
                                ? mt_rand((int)$drop['min'], (int)$drop['max'])
                                : ($drop['quantity'] ?? 1);
                            self::addItem($corpseId, [
                                'item_id' => $drop['item_id'],
                                'item_name' => $drop['name'] ?? $drop['item_id'],
                                'quantity' => $qty,
                                'item_type' => $drop['type'] ?? 'item'
                            ]);
                        }
                    }
                }
            }
        }
    }
}

