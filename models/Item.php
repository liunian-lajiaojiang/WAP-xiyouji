<?php
/**
 * 物品模型
 * 使用 item_id + category 复合键标识物品
 * 支持缓存：物品定义数据相对稳定，适合缓存
 */
require_once __DIR__ . '/../helpers/CacheHelper.php';

class ItemModel {
    
    // 缓存开关
    private static $cacheEnabled = true;
    
    /**
     * 根据ID查找物品
     * @param int $id 物品ID
     * @param bool $useCache 是否使用缓存
     */
    public static function find(int $id, bool $useCache = true): ?array {
        // 尝试从缓存获取
        if ($useCache && self::$cacheEnabled) {
            $cached = CacheHelper::get(CacheHelper::NAMESPACE_ITEM, "id:{$id}");
            if ($cached !== null) {
                return $cached;
            }
        }
        
        $sql = "SELECT * FROM items WHERE id = ?";
        $item = Database::queryOne($sql, [$id]);
        
        // 存入缓存
        if ($item !== null && $useCache && self::$cacheEnabled) {
            CacheHelper::set(CacheHelper::NAMESPACE_ITEM, "id:{$id}", $item, CacheHelper::LONG_TTL);
        }
        
        return $item ?: null;
    }
    
    /**
     * 根据物品标识查找（复合键：item_id + category）
     * @param string $itemId 物品标识
     * @param string $category 区域分类（可选，提供时精确匹配）
     * @param bool $useCache 是否使用缓存
     */
    public static function findByItemId(string $itemId, string $category = '', bool $useCache = true): ?array {
        // 构建缓存键
        $cacheKey = $category !== '' ? "{$itemId}:{$category}" : $itemId;
        
        // 尝试从缓存获取
        if ($useCache && self::$cacheEnabled) {
            $cached = CacheHelper::get(CacheHelper::NAMESPACE_ITEM, $cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        // 从数据库查询
        if ($category !== '') {
            $sql = "SELECT * FROM items WHERE item_id = ? AND category = ?";
            $item = Database::queryOne($sql, [$itemId, $category]);
            
            if (!$item) {
                $sql = "SELECT * FROM items WHERE item_id = ? ORDER BY CASE WHEN category = '' THEN 0 ELSE 1 END LIMIT 1";
                $item = Database::queryOne($sql, [$itemId]);
            }
        } else {
            $sql = "SELECT * FROM items WHERE item_id = ? ORDER BY CASE WHEN category = '' THEN 0 ELSE 1 END LIMIT 1";
            $item = Database::queryOne($sql, [$itemId]);
        }
        
        // 照片特殊处理
        if ($item && $itemId === 'photo' && $category !== '') {
            $item['name'] = $category . '照片';
            $item['description'] = "一张在{$category}拍摄的照片。";
        }
        
        // 存入缓存
        if ($item !== null && $useCache && self::$cacheEnabled) {
            CacheHelper::set(CacheHelper::NAMESPACE_ITEM, $cacheKey, $item, CacheHelper::LONG_TTL);
        }
        
        return $item ?: null;
    }
    
    /**
     * 清除物品缓存
     * 在物品数据被修改时调用
     * @param string $itemId 物品ID
     * @param string $category 分类
     */
    public static function clearCache(string $itemId, string $category = ''): void {
        $cacheKey = $category !== '' ? "{$itemId}:{$category}" : $itemId;
        CacheHelper::delete(CacheHelper::NAMESPACE_ITEM, $cacheKey);
    }
    
    /**
     * 清除所有物品缓存
     */
    public static function clearAllCache(): void {
        CacheHelper::clear(CacheHelper::NAMESPACE_ITEM);
    }
    
    /**
     * 添加物品到角色背包
     * 液体容器（max_liquid > 0）永远不堆叠，每个容器独立一行
     * @param string $category 物品区域分类
     * @param int $liquidRemaining 液体剩余量
     * @param string $liquidType 液体类型
     * @param string $liquidName 液体名称
     */
    public static function addToInventory(int $charId, string $itemId, int $quantity = 1, string $category = '', string $enchantments = '', int $liquidRemaining = 0, string $liquidType = '', string $liquidName = ''): bool {
        // 获取物品信息，检查是否可堆叠且不是液体容器
        // 以 items 表定义的 category 为准，防止调用方传入错误的 category 导致 LEFT JOIN 匹配失败
        if ($category !== '') {
            $itemInfo = Database::queryOne("SELECT stackable, max_liquid, category FROM items WHERE item_id = ? AND category = ?", [$itemId, $category]);
            // 如果精确匹配失败，回退到模糊匹配
            if (!$itemInfo) {
                $itemInfo = Database::queryOne("SELECT stackable, max_liquid, category FROM items WHERE item_id = ? LIMIT 1", [$itemId]);
            }
        } else {
            $itemInfo = Database::queryOne("SELECT stackable, max_liquid, category FROM items WHERE item_id = ? LIMIT 1", [$itemId]);
        }
        // 用 items 表中正确的 category 覆盖传入值
        $category = $itemInfo['category'] ?? $category;
        $isLiquidContainer = $itemInfo && intval($itemInfo['max_liquid'] ?? 0) > 0;
        $isStackable = !$isLiquidContainer && $itemInfo && intval($itemInfo['stackable']) > 0;
        
        if ($isLiquidContainer) {
            // 液体容器：每个独立一行，quantity 始终为 1
            // 如果液体状态未初始化（liquidRemaining=0），则自动初始化为满的
            $maxLiquid = intval($itemInfo['max_liquid'] ?? 0);
            if ($liquidRemaining <= 0) {
                require_once __DIR__ . '/../helpers/LiquidContainerHelper.php';
                $defaults = LiquidContainerHelper::getDefaultLiquid($itemId);
                $liquidRemaining = $maxLiquid;
                $liquidType = $defaults['type'] ?? 'water';
                $liquidName = $defaults['name'] ?? '清水';
            }
            for ($i = 0; $i < max(1, $quantity); $i++) {
                $sql = "INSERT INTO character_inventory (char_id, item_id, category, quantity, enchantments, liquid_remaining, liquid_type, liquid_name) VALUES (?, ?, ?, 1, ?, ?, ?, ?)";
                Database::execute($sql, [$charId, $itemId, $category, $enchantments, $liquidRemaining, $liquidType, $liquidName]);
            }
            return true;
        }
        
        if ($isStackable) {
            // 可堆叠物品：检查是否已存在，合并数量
            $sql = "SELECT * FROM character_inventory WHERE char_id = ? AND item_id = ? AND category = ?";
            $existing = Database::queryOne($sql, [$charId, $itemId, $category]);
            
            if ($existing) {
                // 更新数量
                $newQuantity = $existing['quantity'] + $quantity;
                $sql = "UPDATE character_inventory SET quantity = ? WHERE id = ?";
                return Database::execute($sql, [$newQuantity, $existing['id']]) > 0;
            }
        }
        
        // 不可堆叠物品：新增记录
        $sql = "INSERT INTO character_inventory (char_id, item_id, category, quantity, enchantments) VALUES (?, ?, ?, ?, ?)";
        return Database::execute($sql, [$charId, $itemId, $category, $quantity, $enchantments]) > 0;
    }
    
    /**
     * 从角色背包移除物品
     * @param string $category 物品区域分类
     */
    public static function removeFromInventory(int $charId, string $itemId, int $quantity = 1, string $category = ''): bool {
        if ($category !== '') {
            $sql = "SELECT * FROM character_inventory WHERE char_id = ? AND item_id = ? AND category = ?";
            $existing = Database::queryOne($sql, [$charId, $itemId, $category]);
        } else {
            $sql = "SELECT * FROM character_inventory WHERE char_id = ? AND item_id = ?";
            $existing = Database::queryOne($sql, [$charId, $itemId]);
        }
        
        if (!$existing) {
            return false;
        }
        
        if ($existing['quantity'] <= $quantity) {
            // 删除记录
            $sql = "DELETE FROM character_inventory WHERE id = ?";
            return Database::execute($sql, [$existing['id']]) > 0;
        } else {
            // 减少数量
            $newQuantity = $existing['quantity'] - $quantity;
            $sql = "UPDATE character_inventory SET quantity = ? WHERE id = ?";
            return Database::execute($sql, [$newQuantity, $existing['id']]) > 0;
        }
    }
    
    /**
     * 装备物品
     * @param string $category 物品区域分类
     */
    public static function equipItem(int $charId, string $itemId, string $slot, string $category = ''): bool {
        // 先卸下该位置的装备
        $sql = "UPDATE character_inventory SET equipped = 0, equip_slot = '' 
                WHERE char_id = ? AND equip_slot = ?";
        Database::execute($sql, [$charId, $slot]);
        
        // 装备新物品
        if ($category !== '') {
            $sql = "UPDATE character_inventory SET equipped = 1, equip_slot = ? 
                    WHERE char_id = ? AND item_id = ? AND category = ?";
            return Database::execute($sql, [$slot, $charId, $itemId, $category]) > 0;
        } else {
            $sql = "UPDATE character_inventory SET equipped = 1, equip_slot = ? 
                    WHERE char_id = ? AND item_id = ?";
            return Database::execute($sql, [$slot, $charId, $itemId]) > 0;
        }
    }
    
    /**
     * 卸下物品
     * @param string $category 物品区域分类
     */
    public static function unequipItem(int $charId, string $itemId, string $category = ''): bool {
        if ($category !== '') {
            $sql = "UPDATE character_inventory SET equipped = 0, equip_slot = '' 
                    WHERE char_id = ? AND item_id = ? AND category = ?";
            return Database::execute($sql, [$charId, $itemId, $category]) > 0;
        } else {
            $sql = "UPDATE character_inventory SET equipped = 0, equip_slot = '' 
                    WHERE char_id = ? AND item_id = ?";
            return Database::execute($sql, [$charId, $itemId]) > 0;
        }
    }
    
    /**
     * 按类型获取物品
     */
    public static function getByType(string $type): array {
        $sql = "SELECT * FROM items WHERE type = ? ORDER BY level";
        return Database::queryAll($sql, [$type]);
    }
    
    /**
     * 获取角色背包中的所有物品
     */
    public static function getCharacterItems(int $charId): array {
        $sql = "SELECT ci.*, 
                       COALESCE(i.id, 0) as gi_id,
                       COALESCE(i.name, ci.item_id) as name,
                       i.description,
                       COALESCE(i.type, 'misc') as item_type, COALESCE(i.type, 'misc') as type,
                       COALESCE(i.level, 0) as level,
                       COALESCE(i.category, '') as gi_category,
                       COALESCE(i.unit, '个') as unit,
                       COALESCE(i.weight, 0) as weight,
                       COALESCE(i.value, 0) as value,
                       COALESCE(i.armor_value, 0) as armor_value,
                       COALESCE(i.weapon_damage, 0) as weapon_damage,
                       i.material,
                       COALESCE(i.quality, 'normal') as quality,
                       i.effects,
                       COALESCE(i.no_drop, 0) as no_drop,
                       COALESCE(i.no_sell, 0) as no_sell,
                       COALESCE(i.no_store, 0) as no_store,
                       COALESCE(i.bind_on_pickup, 0) as bind_on_pickup,
                       COALESCE(i.stackable, 0) as stackable,
                       COALESCE(i.max_stack, 1) as max_stack,
                       COALESCE(i.food_value, 0) as food_value,
                       COALESCE(i.water_value, 0) as water_value,
                       COALESCE(i.sen_heal, 0) as sen_heal,
                       COALESCE(i.kee_heal, 0) as kee_heal,
                       COALESCE(i.mana_heal, 0) as mana_heal,
                       COALESCE(i.gin_heal, 0) as gin_heal,
                       COALESCE(i.force_heal, 0) as force_heal,
                       COALESCE(i.max_liquid, 0) as max_liquid,
                       COALESCE(i.is_container, 0) as is_container,
                       COALESCE(i.max_items, 10) as max_items,
                       COALESCE(i.max_encumbrance, 0) as max_encumbrance,
                       ci.liquid_remaining, ci.liquid_type, ci.liquid_name,
                       ci.enchantments
                FROM character_inventory ci
                LEFT JOIN items i ON i.item_id = ci.item_id AND i.category = ci.category
                WHERE ci.char_id = ? AND ci.quantity > 0
                ORDER BY ci.equipped DESC, COALESCE(i.type, 'misc'), COALESCE(i.name, ci.item_id)";
        $items = Database::queryAll($sql, [$charId]);
        
        $fabaoSeries = [];
        $fabaoRows = Database::queryAll(
            "SELECT series_no FROM character_fabao WHERE owner_id = ?",
            [$charId]
        );
        foreach ($fabaoRows as $fb) {
            $fabaoSeries[$fb['series_no']] = true;
        }
        
        foreach ($items as &$item) {
            if ($item['item_id'] === 'photo' && !empty($item['category'])) {
                $item['name'] = $item['category'] . '照片';
            }
            if (!empty($item['series_no']) && isset($fabaoSeries[$item['series_no']])) {
                $item['no_give'] = 1;
                $item['no_sell'] = 1;
                $item['no_store'] = 1;
                $item['no_drop'] = 1;
                $item['is_player_fabao'] = 1;
            }
        }
        
        return $items;
    }

    /**
     * 根据ID查找角色背包中的物品记录
     */
    public static function findInInventory(int $inventoryId): ?array {
        $sql = "SELECT ci.*, 
                       COALESCE(gi.name, ci.item_id) as name,
                       gi.description, gi.type, gi.level
                FROM character_inventory ci
                LEFT JOIN items gi ON ci.item_id = gi.item_id AND ci.category = gi.category
                WHERE ci.id = ?";
        $item = Database::queryOne($sql, [$inventoryId]);
        return $item ?: null;
    }

    /**
     * 按 character_inventory.id 主键查询单条背包记录，JOIN items 表获取完整物品信息
     */
    public static function findInInventoryById(int $inventoryId): ?array
    {
        $sql = "SELECT ci.*, 
                       COALESCE(gi.id, g2.id, 0) as gi_id,
                       COALESCE(gi.name, g2.name, (SELECT name FROM items WHERE item_id = ci.item_id LIMIT 1), ci.item_id) as name,
                       COALESCE(gi.description, g2.description, (SELECT description FROM items WHERE item_id = ci.item_id LIMIT 1)) as description,
                       COALESCE(gi.type, g2.type, (SELECT type FROM items WHERE item_id = ci.item_id LIMIT 1), 'misc') as item_type, 
                       COALESCE(gi.type, g2.type, (SELECT type FROM items WHERE item_id = ci.item_id LIMIT 1), 'misc') as type,
                       COALESCE(gi.level, g2.level, (SELECT level FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as level,
                       COALESCE(gi.category, g2.category, (SELECT category FROM items WHERE item_id = ci.item_id LIMIT 1), '') as gi_category,
                       COALESCE(gi.unit, g2.unit, (SELECT unit FROM items WHERE item_id = ci.item_id LIMIT 1), '个') as unit,
                       COALESCE(gi.weight, g2.weight, (SELECT weight FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as weight,
                       COALESCE(gi.value, g2.value, (SELECT value FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as value,
                       COALESCE(gi.armor_type, g2.armor_type, (SELECT armor_type FROM items WHERE item_id = ci.item_id LIMIT 1), '') as armor_type,
                       COALESCE(gi.armor_value, g2.armor_value, (SELECT armor_value FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as armor_value,
                       COALESCE(gi.weapon_type, g2.weapon_type, (SELECT weapon_type FROM items WHERE item_id = ci.item_id LIMIT 1), '') as weapon_type,
                       COALESCE(gi.weapon_damage, g2.weapon_damage, (SELECT weapon_damage FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as weapon_damage,
                       COALESCE(gi.material, g2.material, (SELECT material FROM items WHERE item_id = ci.item_id LIMIT 1)) as material,
                       COALESCE(gi.quality, g2.quality, (SELECT quality FROM items WHERE item_id = ci.item_id LIMIT 1), 'normal') as quality,
                       COALESCE(gi.effects, g2.effects, (SELECT effects FROM items WHERE item_id = ci.item_id LIMIT 1)) as effects,
                       COALESCE(gi.no_drop, g2.no_drop, (SELECT no_drop FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as no_drop,
                       COALESCE(gi.no_sell, g2.no_sell, (SELECT no_sell FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as no_sell,
                       COALESCE(gi.no_store, g2.no_store, (SELECT no_store FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as no_store,
                       COALESCE(gi.bind_on_pickup, g2.bind_on_pickup, (SELECT bind_on_pickup FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as bind_on_pickup,
                       COALESCE(gi.stackable, g2.stackable, (SELECT stackable FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as stackable,
                       COALESCE(gi.max_stack, g2.max_stack, (SELECT max_stack FROM items WHERE item_id = ci.item_id LIMIT 1), 1) as max_stack,
                       COALESCE(gi.food_value, g2.food_value, (SELECT food_value FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as food_value,
                       COALESCE(gi.water_value, g2.water_value, (SELECT water_value FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as water_value,
                       COALESCE(gi.sen_heal, g2.sen_heal, (SELECT sen_heal FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as sen_heal,
                       COALESCE(gi.kee_heal, g2.kee_heal, (SELECT kee_heal FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as kee_heal,
                       COALESCE(gi.mana_heal, g2.mana_heal, (SELECT mana_heal FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as mana_heal,
                       COALESCE(gi.max_liquid, g2.max_liquid, (SELECT max_liquid FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as max_liquid,
                       COALESCE(gi.is_container, g2.is_container, (SELECT is_container FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as is_container,
                       COALESCE(gi.max_items, g2.max_items, (SELECT max_items FROM items WHERE item_id = ci.item_id LIMIT 1), 10) as max_items,
                       COALESCE(gi.max_encumbrance, g2.max_encumbrance, (SELECT max_encumbrance FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as max_encumbrance,
                       COALESCE(gi.female_only, g2.female_only, (SELECT female_only FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as female_only,
                       COALESCE(gi.no_wield, g2.no_wield, (SELECT no_wield FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as no_wield,
                       COALESCE(gi.flag, g2.flag, (SELECT flag FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as flag,
                       COALESCE(gi.str_bonus, g2.str_bonus, (SELECT str_bonus FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as str_bonus,
                       COALESCE(gi.con_bonus, g2.con_bonus, (SELECT con_bonus FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as con_bonus,
                       COALESCE(gi.dex_bonus, g2.dex_bonus, (SELECT dex_bonus FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as dex_bonus,
                       COALESCE(gi.int_bonus, g2.int_bonus, (SELECT int_bonus FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as int_bonus,
                       COALESCE(gi.spi_bonus, g2.spi_bonus, (SELECT spi_bonus FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as spi_bonus,
                       COALESCE(gi.fabao, g2.fabao, (SELECT fabao FROM items WHERE item_id = ci.item_id LIMIT 1), 0) as fabao,
                       COALESCE(gi.is_real, g2.is_real, (SELECT is_real FROM items WHERE item_id = ci.item_id LIMIT 1), 1) as is_real,
                       COALESCE(gi.trap_type, g2.trap_type, (SELECT trap_type FROM items WHERE item_id = ci.item_id LIMIT 1), 'none') as trap_type,
                       COALESCE(gi.trap_ratio, g2.trap_ratio, (SELECT trap_ratio FROM items WHERE item_id = ci.item_id LIMIT 1), 50) as trap_ratio,
                       COALESCE(gi.series_no, g2.series_no, (SELECT series_no FROM items WHERE item_id = ci.item_id LIMIT 1)) as series_no
                FROM character_inventory ci
                LEFT JOIN items gi ON ci.item_id = gi.item_id AND ci.category = gi.category
                LEFT JOIN items g2 ON ci.item_id = g2.item_id AND g2.category = ''
                WHERE ci.id = ?";
        $item = Database::queryOne($sql, [$inventoryId]);
        
        if ($item && !empty($item['series_no']) && !empty($item['char_id'])) {
            $isPlayerFabao = Database::queryOne(
                "SELECT 1 FROM character_fabao WHERE owner_id = ? AND series_no = ? LIMIT 1",
                [$item['char_id'], $item['series_no']]
            );
            if ($isPlayerFabao) {
                $item['no_give'] = 1;
                $item['no_sell'] = 1;
                $item['no_store'] = 1;
                $item['no_drop'] = 1;
                $item['is_player_fabao'] = 1;
            }
        }
        
        return $item ?: null;
    }

    /**
     * 按主键装备物品到指定槽位
     */
    public static function equipItemById(int $charId, int $inventoryId, string $slot): bool
    {
        // 先卸下该位置的旧装备
        Database::execute(
            "UPDATE character_inventory SET equipped = 0, equip_slot = '' WHERE char_id = ? AND equip_slot = ? AND equipped = 1",
            [$charId, $slot]
        );
        // 按主键装备
        return Database::execute(
            "UPDATE character_inventory SET equipped = 1, equip_slot = ? WHERE id = ? AND char_id = ?",
            [$slot, $inventoryId, $charId]
        ) > 0;
    }

    /**
     * 按主键卸下装备
     */
    public static function unequipItemById(int $charId, int $inventoryId): bool
    {
        return Database::execute(
            "UPDATE character_inventory SET equipped = 0, equip_slot = '' WHERE id = ? AND char_id = ?",
            [$inventoryId, $charId]
        ) > 0;
    }

    /**
     * 按主键从背包移除物品
     */
    public static function removeFromInventoryById(int $charId, int $inventoryId, int $quantity = 1): bool
    {
        $existing = Database::queryOne(
            "SELECT * FROM character_inventory WHERE id = ? AND char_id = ?",
            [$inventoryId, $charId]
        );
        if (!$existing) {
            return false;
        }
        if ($existing['quantity'] <= $quantity) {
            return Database::execute('DELETE FROM character_inventory WHERE id = ?', [$inventoryId]) > 0;
        } else {
            return Database::execute(
                'UPDATE character_inventory SET quantity = quantity - ? WHERE id = ?',
                [$quantity, $inventoryId]
            ) > 0;
        }
    }
}
