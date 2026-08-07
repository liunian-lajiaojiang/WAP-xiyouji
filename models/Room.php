<?php
/**
 * 房间模型
 */
require_once __DIR__ . '/../helpers/CacheHelper.php';

class RoomModel {
    
    // 缓存开关（可通过配置文件控制）
    private static $cacheEnabled = true;
    
    /**
     * 根据区域和房间ID加载房间
     * 支持缓存：使用 CacheHelper 缓存房间数据
     * @param string $area 区域
     * @param string $roomId 房间ID
     * @param bool $useCache 是否使用缓存，默认true
     * @return array|null
     */
    public static function load(string $area, string $roomId, bool $useCache = true): ?array {
        $area = trim(str_replace('\\', '/', $area));
        $area = preg_replace('#^/d/#', '', $area);
        $roomId = trim(str_replace('\\', '/', $roomId));
        $roomId = preg_replace('#^/d/#', '', $roomId);
        $roomId = trim($roomId, '/');

        // 保存原始 roomId（用于 fallback 查询）
        $originalRoomId = $roomId;

        if (strpos($roomId, '/') !== false) {
            $parts = explode('/', $roomId);
            if (empty($area)) {
                $area = array_shift($parts);
            } elseif ($parts[0] === $area) {
                array_shift($parts);
            }
            $roomId = implode('/', $parts);
        }

        $cacheKey = "{$area}:{$roomId}";
        
        // 尝试从缓存获取
        if ($useCache && self::$cacheEnabled) {
            $cached = CacheHelper::get(CacheHelper::NAMESPACE_ROOM, $cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        // 从数据库查询（先尝试去前缀格式）
        $sql = "SELECT * FROM rooms WHERE area = ? AND room_id = ?";
        $room = Database::queryOne($sql, [$area, $roomId]);
        
        // Fallback：如果去前缀格式查不到，尝试原始 roomId 格式
        // 兼容数据库中 room_id 同时存储 "kezhan" 和 "city/kezhan" 两种格式
        if ($room === null && $originalRoomId !== $roomId && !empty($area) && strpos($originalRoomId, '/') !== false) {
            $room = Database::queryOne($sql, [$area, $originalRoomId]);
        }
        
        // 存入缓存（房间数据相对稳定，使用较长的缓存时间）
        if ($room !== null && $useCache && self::$cacheEnabled) {
            CacheHelper::set(CacheHelper::NAMESPACE_ROOM, $cacheKey, $room, CacheHelper::LONG_TTL);
        }
        
        return $room;
    }
    
    /**
     * 根据ID加载房间
     * @param int $id 房间ID
     * @param bool $useCache 是否使用缓存
     * @return array|null
     */
    public static function findById(int $id, bool $useCache = true): ?array {
        // ID 查询通常不适合用 area:roomId 格式缓存
        // 但可以用 id 作为键
        if ($useCache && self::$cacheEnabled) {
            $cached = CacheHelper::get(CacheHelper::NAMESPACE_ROOM, "id:{$id}");
            if ($cached !== null) {
                return $cached;
            }
        }
        
        $sql = "SELECT * FROM rooms WHERE id = ?";
        $room = Database::queryOne($sql, [$id]);
        
        if ($room !== null && $useCache && self::$cacheEnabled) {
            CacheHelper::set(CacheHelper::NAMESPACE_ROOM, "id:{$id}", $room, CacheHelper::LONG_TTL);
        }
        
        return $room;
    }
    
    /**
     * 清除房间缓存
     * 在房间数据被修改时调用
     * @param string $area 区域
     * @param string $roomId 房间ID
     */
    public static function clearCache(string $area, string $roomId): void {
        CacheHelper::delete(CacheHelper::NAMESPACE_ROOM, "{$area}:{$roomId}");
    }
    
    /**
     * 清除所有房间缓存
     */
    public static function clearAllCache(): void {
        CacheHelper::clear(CacheHelper::NAMESPACE_ROOM);
    }
    
    /**
     * 归一化 target_area，将旧MudOS路径格式转换为简洁区域名
     * 例如: //xyj//area//d//changan/ → changan
     *       / →(从源房间推断)
     *       .. →(从源房间推断父区域)
     *       ../sky →(从源房间推断兄弟区域)
     */
    private static function normalizeTargetArea(string $targetArea, string $sourceRoomId): string {
        // 模式1: //xyj//area//d//X/ → //xyj//area//d//X/Y/ → 提取 X 或 X/Y
        if (preg_match('#//xyj//area//d//(.+?)/?$#', $targetArea, $m)) {
            return rtrim($m[1], '/');
        }
        // 模式2: / 表示源房间的顶级区域
        if ($targetArea === '/') {
            $parts = explode('/', $sourceRoomId);
            return $parts[0];
        }
        // 模式3: .. 表示源房间的父区域
        if ($targetArea === '..') {
            $parts = explode('/', $sourceRoomId);
            // 如果源房间有多级路径如 moon/quest/boat，父区域是 moon
            // 如果源房间只有两级如 33tian/33tian，父区域还是顶级
            return $parts[0];
        }
        // 模式4: ../X 表示兄弟区域
        if (preg_match('#^\.\./(.+)$#', $targetArea, $m)) {
            // ../sky → 33tian → sky
            return $m[1];
        }
        return $targetArea;
    }
    
    /**
     * 解析动态出口模式
     * 支持语法: {random:MIN-MAX} 表示随机选择 MIN 到 MAX 之间的整数
     * 例如: zhulin{random:0-5} → zhulin0, zhulin1, ..., zhulin5
     *      zhulin{random:6-14} → zhulin6, zhulin7, ..., zhulin14
     */
    private static function resolveDynamicRoomId(string $targetRoom): string {
        // 匹配 {random:MIN-MAX} 模式
        if (preg_match('/\{random:(\d+)-(\d+)\}/', $targetRoom, $matches)) {
            $min = intval($matches[1]);
            $max = intval($matches[2]);
            if ($min <= $max) {
                $randomVal = rand($min, $max);
                return preg_replace('/\{random:\d+-\d+\}/', strval($randomVal), $targetRoom);
            }
        }
        return $targetRoom;
    }
    
    /**
     * 获取房间的出口（包含目标房间名称）
     */
    public static function getExits(int $roomId): array {
        // 首先获取房间的 area 和 room_id
        $room = self::findById($roomId);
        if (!$room) {
            return [];
        }
            
        // 从 room_exits 表查询，带 JOIN 获取目标房间名称
        // 注意：target_room 可能是单独的房间ID，也可能是完整路径
        // 需要兼容两种情况
        $sql = "SELECT e.direction, e.target_area, e.target_room, e.door_name, e.door_closed, r.name as target_name
                FROM room_exits e
                LEFT JOIN rooms r ON (
                    -- 情况1: target_room 是完整路径（如 changan/eside2）
                    e.target_room = r.room_id
                    OR
                    -- 情况2: target_room 是单独ID，需要拼接（如 eside2 -> changan/eside2）
                    CONCAT(e.target_area, '/', e.target_room) = r.room_id
                )
                WHERE e.room_id = ?
                GROUP BY e.id";
        $exits = Database::queryAll($sql, [$room['room_id']]);  // 使用 room_id 字符串
        
        // 过滤无效出口：target_room = '***' 或类似占位符表示不可走
        $exits = array_values(array_filter($exits, function($e) {
            $t = trim($e['target_room'] ?? '');
            return !empty($t) && $t !== '***' && $t !== '---' && $t !== '???';
        }));
        
        // 后处理：归一化 target_area 并尝试解析未匹配的目标房间名称
        $sourceRoomId = $room['room_id'];
        foreach ($exits as &$exit) {
            $originalArea = $exit['target_area'];
            $normalizedArea = self::normalizeTargetArea($originalArea, $sourceRoomId);
            
            // 如果 target_area 被归一化了，更新它（影响URL生成等）
            if ($normalizedArea !== $originalArea) {
                $exit['target_area'] = $normalizedArea;
            }
            
            // 解析动态出口模式（如 zhulin{random:0-5}）
            $originalTargetRoom = $exit['target_room'];
            $resolvedTargetRoom = self::resolveDynamicRoomId($originalTargetRoom);
            if ($resolvedTargetRoom !== $originalTargetRoom) {
                $exit['target_room'] = $resolvedTargetRoom;
                $exit['is_dynamic'] = true;  // 标记为动态出口
            }
            
            // 如果原来的 JOIN 没有匹配到目标名称，或者出口是动态类型，重新查找目标房间名称
            if (empty($exit['target_name']) || !empty($exit['is_dynamic'])) {
                $targetRoomId = $normalizedArea . '/' . $resolvedTargetRoom;
                $targetRoom = Database::queryOne(
                    "SELECT name, room_id FROM rooms WHERE room_id = ?",
                    [$targetRoomId]
                );
                if ($targetRoom) {
                    $exit['target_name'] = $targetRoom['name'];
                } else {
                    // 尝试 resolvedTargetRoom 本身就是完整路径
                    $targetRoom = Database::queryOne(
                        "SELECT name FROM rooms WHERE room_id = ?",
                        [$resolvedTargetRoom]
                    );
                    if ($targetRoom) {
                        $exit['target_name'] = $targetRoom['name'];
                    }
                }
            }
        }
        unset($exit);
        
        return $exits;
    }
    
    /**
     * 获取房间内的NPC
     */
    public static function getNpcsInRoom(string $area, string $roomId): array {
        // roomId 可能是完整路径(如 changan/eside2) 或只是房间ID (如 eside2)
        // 数据库中 spawn_room 存储的是完整路径
        $fullRoomId = (strpos($roomId, '/') !== false) ? $roomId : $area . '/' . $roomId;

        // 1. 获取静态NPC（通过spawn_room字段）
        $sql = "SELECT * FROM npcs WHERE spawn_room = ?";
        $npcs = Database::queryAll($sql, [$fullRoomId]);
        
        // 1.5 过滤掉有动态位置记录但不在当前房间的静态NPC
        // 如果NPC在npc_temp表中有current_location记录，说明他的位置是动态的，以动态位置为准
        if (!empty($npcs)) {
            $npcIds = array_column($npcs, 'id');
            $placeholders = implode(',', array_fill(0, count($npcIds), '?'));
            
            // 获取这些NPC的动态位置
            $dynamicLocations = Database::queryAll(
                "SELECT npc_id, temp_value FROM npc_temp 
                 WHERE temp_key = 'current_location' AND npc_id IN ($placeholders)",
                $npcIds
            );
            
            if (!empty($dynamicLocations)) {
                $dynamicMap = [];
                foreach ($dynamicLocations as $dl) {
                    $locationData = json_decode($dl['temp_value'], true);
                    if ($locationData && isset($locationData['room'])) {
                        $dynamicMap[$dl['npc_id']] = $locationData['room'];
                    }
                }
                
                // 过滤静态NPC：如果有动态位置且不在当前房间，就移除
                $filteredNpcs = [];
                foreach ($npcs as $npc) {
                    $npcId = $npc['id'];
                    if (isset($dynamicMap[$npcId])) {
                        // 有动态位置，检查是否在当前房间
                        if ($dynamicMap[$npcId] === $fullRoomId) {
                            $filteredNpcs[] = $npc;
                        }
                        // 不在当前房间，跳过（不加入静态列表）
                    } else {
                        // 没有动态位置，保留
                        $filteredNpcs[] = $npc;
                    }
                }
                $npcs = $filteredNpcs;
            }
        }

        // 2. 获取跟随玩家的动态NPC（通过npc_temp表的current_location字段）
        $charId = $_SESSION['char_id'] ?? 0;
        if ($charId > 0) {
            $locationJson = json_encode(['area' => $area, 'room' => $fullRoomId]);
            $followerNpcs = Database::queryAll(
                "SELECT n.* FROM npcs n 
                 INNER JOIN npc_temp nt ON n.id = nt.npc_id 
                 WHERE nt.temp_key = 'current_location' AND nt.temp_value = ?",
                [$locationJson]
            );
            
            // 检查NPC是否过期（有expire_time且已过期的NPC需要清除）
            $validNpcs = [];
            foreach ($followerNpcs as $follower) {
                $expireResult = Database::queryOne(
                    "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'expire_time'",
                    [$follower['id']]
                );
                
                if ($expireResult) {
                    $expireTime = intval($expireResult['temp_value']);
                    if (time() >= $expireTime) {
                        // 已过期，清除位置和过期时间
                        Database::execute(
                            "DELETE FROM npc_temp WHERE npc_id = ? AND temp_key IN ('current_location', 'expire_time')",
                            [$follower['id']]
                        );
                        continue;
                    }
                }
                
                $validNpcs[] = $follower;
            }
            
            // 合并跟随的NPC（避免重复）
            $npcMap = [];
            foreach ($npcs as $npc) {
                $npcMap[$npc['id']] = $npc;
            }
            foreach ($validNpcs as $follower) {
                if (!isset($npcMap[$follower['id']])) {
                    $npcs[] = $follower;
                }
            }
        }

        // 排除已死亡的NPC（session标记 + 全局DB重生冷却检查）
        // 注意: session是每个玩家独立的，必须同时检查 npc_respawn 表
        // 确保其他玩家杀死的NPC对所有人都不可见
        require_once __DIR__ . '/NpcRespawn.php';
        require_once __DIR__ . '/Npc.php';
        $result = [];
        foreach ($npcs as $npc) {
            $deathKey = "npc_dead_" . $npc['id'];
            $isDeadBySession = isset($_SESSION[$deathKey]) && $_SESSION[$deathKey] >= time();
            $isDeadByDb = NpcRespawn::isInRespawnCooldown($npc['id']);
            
            if (!$isDeadBySession && !$isDeadByDb) {
                // 对空属性进行种族随机初始化
                $result[] = NpcModel::initializeAttributes($npc);
            }
        }

        // 通天河区域：NPC动态随机化
        if (!empty($result)) {
            $charId = $_SESSION['char_id'] ?? 0;
            if ($charId > 0 && strpos($fullRoomId, 'qujing/tongtian/') === 0) {
                require_once __DIR__ . '/../daemons/TongtianHandler.php';

                // 河底鱼妖：随机名称、属性、武器、防具
                if ($fullRoomId === 'qujing/tongtian/hedi') {
                    TongtianHandler::randomizeFishDemons($charId, $result);
                }

                // 民居：居民和小童随机名称、属性
                if (strpos($fullRoomId, 'qujing/tongtian/minju') === 0) {
                    TongtianHandler::randomizeVillageNpcs($charId, $result, $fullRoomId);
                }
            }
        }

        return $result;
    }
    
    /**
     * 刷新房间默认物品（对应LPC的reset()机制）
     * 检查 room_default_items 表，如果房间缺少默认物品且已过刷新间隔，则补充
     * @param int $roomDbId 房间数据库ID
     * @return int 刷新的物品数量
     */
    public static function respawnDefaultItems(int $roomDbId): int {
        // 获取该房间的默认物品配置
        $defaultItems = Database::queryAll(
            "SELECT * FROM room_default_items WHERE room_id = ?",
            [$roomDbId]
        );
        
        if (empty($defaultItems)) {
            return 0;
        }
        
        // 获取房间当前物品
        $currentItems = [];
        $rows = Database::queryAll(
            "SELECT id, item_id, quantity FROM room_items WHERE room_id = ?",
            [$roomDbId]
        );
        foreach ($rows as $row) {
            $currentItems[$row['item_id']] = $row;
        }
        
        $respawnCount = 0;
        $now = time();
        
        foreach ($defaultItems as $defItem) {
            $itemId = $defItem['item_id'];
            $targetQty = intval($defItem['quantity']);
            $respawnMinutes = intval($defItem['respawn_minutes']);
            
            // 当前有多少此物品
            $currentQty = 0;
            $currentItem = null;
            if (isset($currentItems[$itemId])) {
                $currentQty = intval($currentItems[$itemId]['quantity']);
                $currentItem = $currentItems[$itemId];
            }
            
            // 如果数量已足够，不需要刷新
            if ($currentQty >= $targetQty) {
                continue;
            }
            
            // 检查是否已过刷新间隔
            $lastRespawn = $defItem['last_respawn'];
            if (!empty($lastRespawn)) {
                $lastRespawnTime = strtotime($lastRespawn);
                $elapsedMinutes = ($now - $lastRespawnTime) / 60;
                if ($elapsedMinutes < $respawnMinutes) {
                    continue; // 还没到刷新时间
                }
            }
            
            // 补充物品到默认数量
            $needQty = $targetQty - $currentQty;
            
            if ($currentItem) {
                // 更新现有记录的数量
                $newQty = $currentQty + $needQty;
                Database::execute(
                    "UPDATE room_items SET quantity = ?, dropped_at = NOW() WHERE id = ?",
                    [$newQty, $currentItem['id']]
                );
            } else {
                // 新增记录 - 优先从items表获取物品名
                $itemInfo = Database::queryOne("SELECT name, category FROM items WHERE item_id = ? LIMIT 1", [$itemId]);
                $itemName = $itemInfo['name'] ?? $itemId;
                $itemCategory = $itemInfo['category'] ?? '';
                Database::execute(
                    "INSERT INTO room_items (room_id, item_id, category, item_name, quantity, dropped_at) VALUES (?, ?, ?, ?, ?, NOW())",
                    [$roomDbId, $itemId, $itemCategory, $itemName, $needQty]
                );
            }
            
            // 更新last_respawn时间
            Database::execute(
                "UPDATE room_default_items SET last_respawn = NOW() WHERE id = ?",
                [$defItem['id']]
            );
            
            $respawnCount++;
        }
        
        return $respawnCount;
    }
    
    /**
     * 获取房间内的物品
     */
    public static function getItemsInRoom(string $area, string $roomId): array {
        $room = self::load($area, $roomId);
        if (!$room) {
            return [];
        }
        
        // 先刷新默认物品（对应LPC的reset()）
        self::respawnDefaultItems($room['id']);
        
        // JOIN items 获取物品详细信息
        // 如果 room_items.category 为空，尝试匹配 items 中任意 category 的第一条记录
        $sql = "SELECT ri.id, ri.item_id, ri.category, ri.quantity, ri.dropped_at, ri.enchantments,
                       COALESCE(gi.name, (SELECT name FROM items WHERE item_id = ri.item_id LIMIT 1), ri.item_id) as item_name, 
                       COALESCE(gi.type, (SELECT type FROM items WHERE item_id = ri.item_id LIMIT 1), 'misc') as item_type, 
                       COALESCE(gi.description, (SELECT description FROM items WHERE item_id = ri.item_id LIMIT 1)) as description,
                       COALESCE(gi.value, (SELECT value FROM items WHERE item_id = ri.item_id LIMIT 1), 0) as value, 
                       COALESCE(gi.weight, (SELECT weight FROM items WHERE item_id = ri.item_id LIMIT 1), 0) as weight, 
                       COALESCE(gi.max_liquid, (SELECT max_liquid FROM items WHERE item_id = ri.item_id LIMIT 1), 0) as max_liquid,
                       ri.liquid_remaining, ri.liquid_type, ri.liquid_name
                FROM room_items ri
                LEFT JOIN items gi ON ri.item_id = gi.item_id AND (ri.category = gi.category OR gi.category = '')
                WHERE ri.room_id = ?
                ORDER BY ri.dropped_at DESC";
        $items = Database::queryAll($sql, [$room['id']]);
        
        foreach ($items as &$item) {
            if ($item['item_id'] === 'photo' && !empty($item['category'])) {
                $item['item_name'] = $item['category'] . '照片';
            }
        }
        
        return $items;
    }
    
    /**
     * 获取房间的特殊动作
     * @param string $area 区域
     * @param string $roomId 房间ID
     * @param string|null $type 动作类型过滤 (special/movement/commerce/npc_task)，null表示全部
     * @return array 动作列表
     */
    public static function getActions(string $area, string $roomId, ?string $type = null): array {
        // 使用 room_id 查询（room_id 已包含 area 前缀，如 lingtai/uphill3）
        if ($type) {
            $sql = "SELECT action_name, action_cmd, action_type, description FROM room_actions 
                    WHERE room_id = ? AND enabled = 1 AND action_type = ?
                    ORDER BY sort_order ASC";
            return Database::queryAll($sql, [$roomId, $type]);
        } else {
            $sql = "SELECT action_name, action_cmd, action_type, description FROM room_actions 
                    WHERE room_id = ? AND enabled = 1
                    ORDER BY sort_order ASC";
            return Database::queryAll($sql, [$roomId]);
        }
    }
    
    /**
     * 检查是否是室外
     */
    public static function isOutdoors(string $area, string $roomId): bool {
        $room = self::load($area, $roomId);
        // outdoors: 0=室内, 1=室外, 2=野外（都算室外）
        return $room && $room['outdoors'] > 0;
    }
    
    /**
     * 获取房间完整信息
     */
    public static function getFullInfo(string $area, string $roomId): ?array {
        $room = self::load($area, $roomId);
        if (!$room) {
            return null;
        }
        
        $room['exits'] = self::getExits($room['id']);
        $room['npcs'] = self::getNpcsInRoom($area, $roomId);
        $room['items'] = self::getItemsInRoom($area, $roomId);
        $room['fixed_objects'] = self::getFixedObjects($area, $roomId);  // 添加固定物品
        $room['actions'] = self::getActions($area, $roomId);  // 添加特殊动作
        $room['item_descs'] = self::getItemDescs($roomId);  // 添加房间物品描述（如牌子、告示等）
        
        return $room;
    }
    
    /**
     * 根据房间别名查找房间
     * 别名格式: area_roomId (如 dntg_hgs_entrance)
     */
    public static function findByAlias(string $alias): ?array {
        // 将别名转换为 area/room_id 格式
        // 例如: dntg_hgs_entrance -> area='dntg', room_id='dntg/hgs/entrance'
        $parts = explode('_', $alias, 2);
        if (count($parts) < 2) {
            return null;
        }
        
        $area = $parts[0];
        $roomIdPath = str_replace('_', '/', $parts[1]);
        $fullRoomId = $area . '/' . $roomIdPath;
        
        $sql = "SELECT * FROM rooms WHERE area = ? AND room_id = ?";
        return Database::queryOne($sql, [$area, $fullRoomId]);
    }
    
    /**
     * 获取区域内的所有房间
     */
    public static function getRoomsByArea(string $area): array {
        $sql = "SELECT * FROM rooms WHERE area = ? ORDER BY room_id";
        return Database::queryAll($sql, [$area]);
    }
    
    /**
     * 添加物品到房间（玩家丢弃）
     * @param string $area 区域
     * @param string $roomId 房间ID
     * @param string $itemId 物品ID
     * @param int $quantity 数量
     * @param string $category 分类
     * @param string $enchantments 附魔数据
     * @param int $liquidRemaining 液体剩余量
     * @param string $liquidType 液体类型
     * @param string $liquidName 液体名称
     * @return bool 是否成功
     */
    public static function addItemToRoom(string $area, string $roomId, string $itemId, int $quantity = 1, string $category = '', string $enchantments = '', int $liquidRemaining = 0, string $liquidType = '', string $liquidName = ''): bool {
        $room = self::load($area, $roomId);
        if (!$room) {
            return false;
        }
        
        // 获取物品信息，检查是否可堆叠，并获取物品名称
        if ($category !== '') {
            $itemInfo = Database::queryOne("SELECT stackable, name FROM items WHERE item_id = ? AND category = ?", [$itemId, $category]);
        } else {
            $itemInfo = Database::queryOne("SELECT stackable, name FROM items WHERE item_id = ? LIMIT 1", [$itemId]);
        }
        $isStackable = $itemInfo && intval($itemInfo['stackable']) > 0;
        $itemName = $itemInfo['name'] ?? $itemId;
        
        if ($isStackable) {
            // 可堆叠物品：检查是否已存在，合并数量（兼容 category 为 NULL 的情况）
            $sql = "SELECT id, quantity FROM room_items WHERE room_id = ? AND item_id = ? AND COALESCE(category, '') = ?";
            $existing = Database::queryOne($sql, [$room['id'], $itemId, $category]);
            
            if ($existing) {
                // 增加数量
                $newQuantity = $existing['quantity'] + $quantity;
                $sql = "UPDATE room_items SET quantity = ?, dropped_at = NOW() WHERE id = ?";
                return Database::execute($sql, [$newQuantity, $existing['id']]) > 0;
            }
        }
        
        // 不可堆叠物品或新物品：新增记录
        $sql = "INSERT INTO room_items (room_id, item_id, item_name, category, quantity, dropped_at, enchantments, liquid_remaining, liquid_type, liquid_name) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)";
        return Database::execute($sql, [$room['id'], $itemId, $itemName, $category, $quantity, $enchantments, $liquidRemaining, $liquidType, $liquidName]) > 0;
    }
    
    /**
     * 清理过期房间物品（超过一定时间的自动消失）
     * @param int $expireMinutes 过期时间（分钟），默认30分钟
     * @return int 清理的物品数量
     */
    public static function cleanExpiredItems(int $expireMinutes = 30): int {
        $sql = "DELETE FROM room_items WHERE dropped_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)";
        return Database::execute($sql, [$expireMinutes]);
    }
    
    /**
     * 从房间移除物品（玩家拾取）
     * @param string $area 区域
     * @param string $roomId 房间ID
     * @param string $itemId 物品ID
     * @param int $quantity 数量（默认全部）
     * @return bool 是否成功
     */
    public static function removeItemFromRoom(string $area, string $roomId, string $itemId, int $quantity = 0, string $category = ''): bool {
        $room = self::load($area, $roomId);
        if (!$room) {
            return false;
        }
        
        // 查询房间内的物品（兼容 category 为 NULL 的情况）
        $sql = "SELECT id, quantity FROM room_items WHERE room_id = ? AND item_id = ? AND COALESCE(category, '') = ?";
        $existing = Database::queryOne($sql, [$room['id'], $itemId, $category]);
        
        if (!$existing) {
            return false;
        }
        
        // 如果 quantity 为 0 或大于等于现有数量，则删除整条记录
        if ($quantity <= 0 || $quantity >= $existing['quantity']) {
            $sql = "DELETE FROM room_items WHERE id = ?";
            return Database::execute($sql, [$existing['id']]) > 0;
        } else {
            // 减少数量
            $newQuantity = $existing['quantity'] - $quantity;
            $sql = "UPDATE room_items SET quantity = ? WHERE id = ?";
            return Database::execute($sql, [$newQuantity, $existing['id']]) > 0;
        }
    }
    
    /**
     * 获取房间中的固定物品
     * @param string $area 区域
     * @param string $roomId 房间ID
     * @return array 固定物品列表
     */
    public static function getFixedObjects(string $area, string $roomId): array {
        // 从 fixed_objects 表查询
        // roomId 已经是完整路径（如 dntg/hgs/zhaiyuan）
        $sql = "SELECT * FROM fixed_objects WHERE room_id = ? ORDER BY sort_order ASC";
        return Database::queryAll($sql, [$roomId]);
    }
    
    /**
     * 获取房间物品描述（对应 LPC 的 item_desc）
     * 如牌子、告示等，可以通过 look 命令查看
     * @param string $roomId 房间ID（完整路径）
     * @return array 物品描述列表
     */
    public static function getItemDescs(string $roomId): array {
        $sql = "SELECT * FROM room_item_descs WHERE room_id = ? AND enabled = 1 ORDER BY id ASC";
        return Database::queryAll($sql, [$roomId]);
    }
}

