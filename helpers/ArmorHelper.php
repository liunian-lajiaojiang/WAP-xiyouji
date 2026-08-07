<?php
/**
 * 防具助手
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 功能:
 * - 定义防具类型和部位
 * - 计算防具减伤
 * - 管理装备槽位
 * - Apply属性加成
 */
class ArmorHelper {
    
    /**
     * 加载防具配置文件（带缓存）
     */
    private static ?array $armorConfig = null;
    
    private static function loadArmorConfig(): array {
        if (self::$armorConfig === null) {
            $configFile = __DIR__ . '/../config/armor.php';
            if (file_exists($configFile)) {
                self::$armorConfig = require $configFile;
            } else {
                self::$armorConfig = [];
            }
        }
        return self::$armorConfig;
    }
    
    /**
     * 获取防具类型配置（从 config/armor.php 读取，带 fallback）
     */
    private static function getArmorTypes(): array {
        $config = self::loadArmorConfig();
        return $config['armor_slots'] ?? self::getFallbackArmorTypes();
    }
    
    /**
     * 获取所有装备槽位列表（从 config/armor.php 读取）
     */
    private static function getAllSlotsList(): array {
        $config = self::loadArmorConfig();
        return $config['all_slots'] ?? [
            'head', 'neck', 'cloth', 'armor', 'surcoat',
            'waist', 'wrists', 'shield', 'finger', 'hands',
            'boots', 'weapon',
        ];
    }
    
    /**
     * Fallback：硬编码防具类型（向后兼容）
     */
    private static function getFallbackArmorTypes(): array {
        return [
        'head' => [
            'name' => '头部',
            'base_armor' => [2, 5],
            'apply_slots' => ['int', 'spi'],
        ],
        'neck' => [
            'name' => '颈部',
            'base_armor' => [1, 3],
            'apply_slots' => ['per', 'kar'],
        ],
        'cloth' => [
            'name' => '衣物',
            'base_armor' => [3, 8],
            'apply_slots' => ['dex', 'con'],
        ],
        'armor' => [
            'name' => '铠甲',
            'base_armor' => [8, 20],
            'apply_slots' => ['str', 'con'],
        ],
        'surcoat' => [
            'name' => '外袍',
            'base_armor' => [2, 6],
            'apply_slots' => ['int', 'per'],
        ],
        'waist' => [
            'name' => '腰部',
            'base_armor' => [1, 4],
            'apply_slots' => ['con', 'dex'],
        ],
        'wrists' => [
            'name' => '手腕',
            'base_armor' => [1, 3],
            'apply_slots' => ['dex', 'cps'],
        ],
        'shield' => [
            'name' => '盾牌',
            'base_armor' => [5, 12],
            'apply_slots' => ['con', 'str'],
        ],
        'finger' => [
            'name' => '戒指',
            'base_armor' => [0, 2],
            'apply_slots' => ['int', 'spi', 'kar'],
        ],
        'hands' => [
            'name' => '手套',
            'base_armor' => [1, 4],
            'apply_slots' => ['str', 'dex'],
        ],
        'boots' => [
            'name' => '靴子',
            'base_armor' => [2, 6],
            'apply_slots' => ['dex', 'con'],
        ],
    ];
    }
    
    /**
     * 获取防具部位信息
     * 
     * @param string $slot 防具部位
     * @return array|null 部位配置
     */
    public static function getSlotInfo(string $slot): ?array {
        return self::getArmorTypes()[$slot] ?? null;
    }
    
    /**
     * 获取防具部位的中文名称
     * 
     * @param string $slot 防具部位
     * @return string 部位名称
     */
    public static function getSlotName(string $slot): string {
        $info = self::getSlotInfo($slot);
        return $info ? $info['name'] : '未知部位';
    }
    
    /**
     * 获取防具的基础防御值范围
     * 
     * @param string $slot 防具部位
     * @return array [min, max] 防御值范围
     */
    public static function getBaseArmor(string $slot): array {
        $info = self::getSlotInfo($slot);
        return $info ? $info['base_armor'] : [0, 1];
    }
    
    /**
     * 计算防具提供的总防御值
     * 
     * @param int $charId 角色ID
     * @return int 总防御值
     */
    public static function calculateTotalArmor(int $charId): int {
        $totalArmor = 0;
        
        // 获取角色所有装备
        $equipment = self::getCharacterEquipment($charId);
        
        // 获取装备耐久度信息
        $durabilityData = [];
        $invRows = Database::queryAll(
            "SELECT ci.item_id, ci.category, ci.durability
             FROM character_inventory ci
             WHERE ci.char_id = ? AND ci.equipped = 1",
            [$charId]
        );
        foreach ($invRows as $row) {
            $key = $row['item_id'] . '|' . ($row['category'] ?? '');
            $durabilityData[$key] = intval($row['durability'] ?? 100);
        }
        
        foreach ($equipment as $slot => $item) {
            if ($slot === 'weapon') continue; // 武器不提供防御
            
            $armorValue = self::calculateItemArmor($item);
            
            // 装备耐久系统：耐久为0时防御减半
            $itemId = $item['item_id'] ?? '';
            $itemCategory = $item['category'] ?? '';
            $durKey = $itemId . '|' . $itemCategory;
            if ($itemId && isset($durabilityData[$durKey]) && $durabilityData[$durKey] <= 0) {
                $armorValue = intval($armorValue / 2);
            }
            
            $totalArmor += $armorValue;
        }
        
        return $totalArmor;
    }
    
    /**
     * 计算单个防具物品的防御值
     * 
     * @param array $item 物品数据
     * @return int 防御值
     */
    public static function calculateItemArmor(array $item): int {
        // 使用数据库中的armor_value字段
        $armor = $item['armor_value'] ?? 0;
        
        // 如果没有设置armor_value，根据部位计算基础防御
        if ($armor <= 0) {
            $slot = $item['armor_type'] ?? '';
            $baseArmor = self::getBaseArmor($slot);
            $armor = rand($baseArmor[0], $baseArmor[1]);
        }
        
        // 品质修正（quality是字符串：poor/normal/good/excellent/legendary）
        $quality = $item['quality'] ?? '';
        if ($quality !== '' && $quality !== 'normal') {
            $qualityMap = [
                'poor' => 0.8,
                'good' => 1.1,
                'excellent' => 1.3,
                'legendary' => 1.6,
            ];
            $modifier = $qualityMap[$quality] ?? 1.0;
            $armor = intval($armor * $modifier);
        }
        
        // 装备耐久系统：耐久为0时防御减半
        if (isset($item['durability']) && $item['durability'] <= 0) {
            $armor = intval($armor / 2);
        }
        
        return max(0, $armor);
    }
    
    /**
     * 计算防具减伤后的最终伤害
     * 
     * @param int $originalDamage 原始伤害
     * @param int $charId 角色ID
     * @return int 减伤后的伤害
     */
    public static function applyArmorReduction(int $originalDamage, int $charId): int {
        $totalArmor = self::calculateTotalArmor($charId);
        
        // 减伤公式: 伤害 = 原始伤害 * (100 / (100 + 防御))
        // 例如: 100点伤害, 50点防御 -> 100 * (100/150) = 66.67
        if ($totalArmor > 0) {
            $reducedDamage = intval($originalDamage * (100 / (100 + $totalArmor)));
            return max(1, $reducedDamage); // 至少造成1点伤害
        }
        
        return $originalDamage;
    }

    /**
     * 获取角色总护甲值（用于创伤判定）
     * @param int $charId 角色ID
     * @return int 总护甲值
     */
    public static function getArmorValue(int $charId): int {
        return self::calculateTotalArmor($charId);
    }
    
    /**
     * 获取角色的Apply属性加成总和
     * 
     * @param int $charId 角色ID
     * @return array 属性加成 ['str' => 5, 'int' => 3, ...]
     */
    public static function getApplyBonuses(int $charId): array {
        $bonuses = [
            'str' => 0, 'int' => 0, 'con' => 0, 'dex' => 0,
            'cor' => 0, 'cps' => 0, 'per' => 0, 'spi' => 0, 'kar' => 0,
            'dodge' => 0, 'parry' => 0
        ];
        
        // 获取角色所有装备
        $equipment = self::getCharacterEquipment($charId);
        
        foreach ($equipment as $slot => $item) {
            // 使用数据库中的属性加成字段
            $bonusFields = [
                'str' => 'str_bonus',
                'int' => 'int_bonus',
                'con' => 'con_bonus',
                'dex' => 'dex_bonus',
                'spi' => 'spi_bonus',
                'dodge' => 'dodge_bonus',
                'parry' => 'parry_bonus'
            ];
            
            foreach ($bonusFields as $attr => $field) {
                if (isset($item[$field]) && $item[$field] > 0) {
                    $bonuses[$attr] += intval($item[$field]);
                }
            }
            
            // 兼容旧的apply字段 (JSON格式: {"str": 5, "int": 3})
            if (!empty($item['apply'])) {
                $applies = json_decode($item['apply'], true);
                if (is_array($applies)) {
                    foreach ($applies as $attr => $value) {
                        if (isset($bonuses[$attr])) {
                            $bonuses[$attr] += intval($value);
                        }
                    }
                }
            }
        }
        
        return $bonuses;
    }
    
    /**
     * 获取角色某个属性的总加成(包括装备)
     * 
     * @param int $charId 角色ID
     * @param string $attribute 属性名
     * @return int 总加成值
     */
    public static function getAttributeBonus(int $charId, string $attribute): int {
        $bonuses = self::getApplyBonuses($charId);
        return $bonuses[$attribute] ?? 0;
    }
    
    /**
     * 获取角色的装备列表
     * 
     * @param int $charId 角色ID
     * @return array ['head' => item, 'weapon' => item, ...]
     */
    public static function getCharacterEquipment(int $charId): array {
        // 首先尝试从Session获取装备信息
        if (isset($_SESSION["equipment_{$charId}"]) && !empty($_SESSION["equipment_{$charId}"])) {
            $equipment = $_SESSION["equipment_{$charId}"];
            
            // 将装备ID转换为完整物品数据
            $fullEquipment = [];
            foreach ($equipment as $slot => $sessionItem) {
                if (!empty($sessionItem)) {
                    // 支持新格式(array with item_id + category)和旧格式(string itemId)
                    if (is_array($sessionItem)) {
                        $sItemId = $sessionItem['item_id'] ?? '';
                        $sCategory = $sessionItem['category'] ?? '';
                        if (!empty($sItemId)) {
                            if (!empty($sCategory)) {
                                $item = Database::queryOne(
                                    "SELECT * FROM items WHERE item_id = ? AND category = ?",
                                    [$sItemId, $sCategory]
                                );
                            } else {
                                $item = Database::queryOne(
                                    "SELECT * FROM items WHERE item_id = ?",
                                    [$sItemId]
                                );
                            }
                            if ($item) {
                                $fullEquipment[$slot] = $item;
                            }
                        }
                    } else {
                        $item = Database::queryOne(
                            "SELECT * FROM items WHERE item_id = ?",
                            [$sessionItem]
                        );
                        if ($item) {
                            $fullEquipment[$slot] = $item;
                        }
                    }
                }
            }
            
            return $fullEquipment;
        }
        
        // 如果Session中没有，从数据库读取
        $dbEquipment = Database::queryAll(
            "SELECT ci.equip_slot, gi.* 
             FROM character_inventory ci
             JOIN items gi ON ci.item_id = gi.item_id AND ci.category = gi.category
             WHERE ci.char_id = ? AND ci.equipped = 1",
            [$charId]
        );
        
        $fullEquipment = [];
        foreach ($dbEquipment as $item) {
            $slot = $item['equip_slot'];
            if (!empty($slot)) {
                $fullEquipment[$slot] = $item;
            }
        }
        
        // 更新Session（存储 item_id 和 category 用于后续查询）
        if (!empty($fullEquipment)) {
            $_SESSION["equipment_{$charId}"] = [];
            foreach ($fullEquipment as $slot => $item) {
                $_SESSION["equipment_{$charId}"][$slot] = [
                    'item_id' => $item['item_id'],
                    'category' => $item['category'] ?? ''
                ];
            }
        }
        
        return $fullEquipment;
    }
    
    /**
     * 获取角色指定部位的装备
     * 
     * @param int $charId 角色ID
     * @param string $slot 装备槽位
     * @return array|null 装备数据，如果没有装备则返回null
     */
    public static function getEquippedItem(int $charId, string $slot): ?array {
        $equipment = self::getCharacterEquipment($charId);
        return $equipment[$slot] ?? null;
    }
    
    /**
     * 检查槽位是否可以装备该物品
     * 
     * @param string $slot 槽位
     * @param string $itemType 物品类型
     * @return bool
     */
    public static function canEquipInSlot(string $slot, string $itemType): bool {
        // 武器只能装备在weapon槽位
        if ($slot === 'weapon') {
            return $itemType === 'weapon';
        }
        
        // 防具类型匹配 - 所有armor类型都可以装备到对应的防具槽位
        if ($itemType === 'armor') {
            $slots = self::getAllSlotsList();
            return in_array($slot, $slots) && $slot !== 'weapon';
        }
        
        return false;
    }
    
    /**
     * 穿戴装备
     * 
     * @param int $charId 角色ID
     * @param string $itemId 物品ID
     * @param string $slot 装备槽位
     * @return array ['success' => bool, 'message' => string]
     */
    public static function equipItem(int $charId, string $itemId, string $slot, string $category = ''): array {
        // 检查槽位是否有效
        if (!in_array($slot, self::getAllSlotsList())) {
            return ['success' => false, 'message' => '无效的装备槽位'];
        }
        
        // 获取物品信息
        if (!empty($category)) {
            $item = Database::queryOne(
                "SELECT * FROM items WHERE item_id = ? AND category = ?",
                [$itemId, $category]
            );
        } else {
            $item = Database::queryOne(
                "SELECT * FROM items WHERE item_id = ?",
                [$itemId]
            );
        }
        
        if (!$item) {
            return ['success' => false, 'message' => '物品不存在'];
        }
        
        // 检查是否可以装备在该槽位
        $itemType = $item['type'] ?? '';
        if (!self::canEquipInSlot($slot, $itemType)) {
            return ['success' => false, 'message' => '该物品不能装备在此位置'];
        }
        
        // 先卸下该槽位已装备的物品
        if (!empty($category)) {
            $existingEquip = Database::queryOne(
                "SELECT item_id, category FROM character_inventory 
                 WHERE char_id = ? AND equip_slot = ? AND equipped = 1 AND NOT (item_id = ? AND category = ?)",
                [$charId, $slot, $itemId, $category]
            );
        } else {
            $existingEquip = Database::queryOne(
                "SELECT item_id, category FROM character_inventory 
                 WHERE char_id = ? AND equip_slot = ? AND equipped = 1 AND item_id != ?",
                [$charId, $slot, $itemId]
            );
        }
        
        if ($existingEquip) {
            // 移除旧装备的属性加成
            $existCategory = $existingEquip['category'] ?? '';
            $oldItemInfo = null;
            if (!empty($existCategory)) {
                $oldItemInfo = Database::queryOne(
                    "SELECT i.* FROM items i WHERE i.item_id = ? AND i.category = ?",
                    [$existingEquip['item_id'], $existCategory]
                );
            } else {
                $oldItemInfo = Database::queryOne(
                    "SELECT i.* FROM items i WHERE i.item_id = ?",
                    [$existingEquip['item_id']]
                );
            }
            if ($oldItemInfo) {
                self::removeArmorProperties($charId, $oldItemInfo);
            }

            // 卸下旧装备（只更新已装备的记录）
            if (!empty($existCategory)) {
                Database::execute(
                    "UPDATE character_inventory SET equipped = 0, equip_slot = NULL 
                     WHERE char_id = ? AND item_id = ? AND category = ? AND equipped = 1 LIMIT 1",
                    [$charId, $existingEquip['item_id'], $existCategory]
                );
            } else {
                Database::execute(
                    "UPDATE character_inventory SET equipped = 0, equip_slot = NULL 
                     WHERE char_id = ? AND item_id = ? AND equipped = 1 LIMIT 1",
                    [$charId, $existingEquip['item_id']]
                );
            }
            
            // 更新Session
            unset($_SESSION["equipment_{$charId}"][$slot]);
        }
        
        // 更新Session中的装备信息
        if (!isset($_SESSION["equipment_{$charId}"])) {
            $_SESSION["equipment_{$charId}"] = [];
        }
        
        $_SESSION["equipment_{$charId}"][$slot] = [
            'item_id' => $itemId,
            'category' => $category
        ];
        
        // 更新数据库中的装备状态（只更新未装备的记录）
        if (!empty($category)) {
            Database::execute(
                "UPDATE character_inventory SET equipped = 1, equip_slot = ? 
                 WHERE char_id = ? AND item_id = ? AND category = ? AND equipped = 0 LIMIT 1",
                [$slot, $charId, $itemId, $category]
            );
        } else {
            Database::execute(
                "UPDATE character_inventory SET equipped = 1, equip_slot = ? 
                 WHERE char_id = ? AND item_id = ? AND equipped = 0 LIMIT 1",
                [$slot, $charId, $itemId]
            );
        }

        // 同步防具属性加成到 char_apply session
        self::applyArmorProperties($charId, $item);

        $itemName = $item['name'] ?? $itemId;
        $slotName = self::getSlotName($slot);
        
        return [
            'success' => true,
            'message' => "你将{$itemName}装备在{$slotName}。"
        ];
    }
    
    /**
     * 卸下装备
     * 
     * @param int $charId 角色ID
     * @param string $slot 装备槽位
     * @return array ['success' => bool, 'message' => string]
     */
    public static function unequipItem(int $charId, string $slot): array {
        // 先从Session获取装备信息
        $equipment = self::getCharacterEquipment($charId);
        
        if (!isset($equipment[$slot])) {
            return ['success' => false, 'message' => '该位置没有装备物品'];
        }
        
        $item = $equipment[$slot];
        $itemId = $item['item_id'];
        $category = $item['category'] ?? '';

        // 移除防具属性加成
        self::removeArmorProperties($charId, $item);
        
        // 更新Session
        if (isset($_SESSION["equipment_{$charId}"][$slot])) {
            unset($_SESSION["equipment_{$charId}"][$slot]);
        }
        
        // 更新数据库（只更新已装备的记录）
        if (!empty($category)) {
            Database::execute(
                "UPDATE character_inventory SET equipped = 0, equip_slot = NULL 
                 WHERE char_id = ? AND item_id = ? AND category = ? AND equipped = 1 LIMIT 1",
                [$charId, $itemId, $category]
            );
        } else {
            Database::execute(
                "UPDATE character_inventory SET equipped = 0, equip_slot = NULL 
                 WHERE char_id = ? AND item_id = ? AND equipped = 1 LIMIT 1",
                [$charId, $itemId]
            );
        }
        
        $itemName = $item['name'] ?? $itemId;
        $slotName = self::getSlotName($slot);
        
        return [
            'success' => true,
            'message' => "你卸下了{$slotName}的{$itemName}。"
        ];
    }
    
    /**
     * 获取所有装备槽位列表
     * 
     * @return array
     */
    public static function getAllSlots(): array {
        return self::getAllSlotsList();
    }

    /**
     * 按 inventory_id 主键装备物品到指定槽位
     *
     * @param int $charId      角色ID
     * @param int $inventoryId 背包记录主键 (character_inventory.id)
     * @param string $slot     目标装备槽位
     * @return bool            是否成功
     */
    public static function equipItemById(int $charId, int $inventoryId, string $slot): bool
    {
        // 卸下同槽位旧装备并移除属性加成
        $oldItem = Database::queryOne(
            "SELECT ci.*, i.str_bonus, i.int_bonus, i.con_bonus, i.dex_bonus,
                    i.spi_bonus, i.dodge_bonus, i.parry_bonus
             FROM character_inventory ci
             LEFT JOIN items i ON ci.item_id = i.item_id
             WHERE ci.char_id = ? AND ci.equip_slot = ? AND ci.equipped = 1",
            [$charId, $slot]
        );
        if ($oldItem) {
            self::removeArmorProperties($charId, $oldItem);
            unset($_SESSION["equipment_{$charId}"][$slot]);
        }
        Database::execute(
            "UPDATE character_inventory SET equipped = 0, equip_slot = NULL WHERE char_id = ? AND equip_slot = ? AND equipped = 1",
            [$charId, $slot]
        );

        // 获取新物品信息
        $newItem = Database::queryOne(
            "SELECT ci.*, i.name, i.str_bonus, i.int_bonus, i.con_bonus, i.dex_bonus,
                    i.spi_bonus, i.dodge_bonus, i.parry_bonus,
                    i.qi_defense, i.shen_defense
             FROM character_inventory ci
             LEFT JOIN items i ON ci.item_id = i.item_id
             WHERE ci.id = ? AND ci.char_id = ?",
            [$inventoryId, $charId]
        );
        if (!$newItem) {
            return false;
        }

        // 按主键装备新物品
        $result = Database::execute(
            "UPDATE character_inventory SET equipped = 1, equip_slot = ? WHERE id = ? AND char_id = ?",
            [$slot, $inventoryId, $charId]
        ) > 0;

        if ($result) {
            // 同步 session 和属性加成
            if (!isset($_SESSION["equipment_{$charId}"])) {
                $_SESSION["equipment_{$charId}"] = [];
            }
            $_SESSION["equipment_{$charId}"][$slot] = [
                'item_id' => $newItem['item_id'],
                'category' => $newItem['category'] ?? ''
            ];
            self::applyArmorProperties($charId, $newItem);
        }

        return $result;
    }

    /**
     * 按 inventory_id 主键卸下已装备物品
     *
     * @param int $charId      角色ID
     * @param int $inventoryId 背包记录主键 (character_inventory.id)
     * @return bool            是否成功
     */
    public static function unequipItemById(int $charId, int $inventoryId): bool
    {
        // 获取要卸下的物品信息
        $invItem = Database::queryOne(
            "SELECT ci.*, i.str_bonus, i.int_bonus, i.con_bonus, i.dex_bonus,
                    i.spi_bonus, i.dodge_bonus, i.parry_bonus,
                    i.qi_defense, i.shen_defense
             FROM character_inventory ci
             LEFT JOIN items i ON ci.item_id = i.item_id
             WHERE ci.id = ? AND ci.char_id = ?",
            [$inventoryId, $charId]
        );
        if (!$invItem) {
            return false;
        }

        $result = Database::execute(
            "UPDATE character_inventory SET equipped = 0, equip_slot = NULL WHERE id = ? AND char_id = ?",
            [$inventoryId, $charId]
        ) > 0;

        if ($result) {
            // 移除属性加成和 session
            self::removeArmorProperties($charId, $invItem);
            $slot = $invItem['equip_slot'] ?? '';
            if ($slot && isset($_SESSION["equipment_{$charId}"][$slot])) {
                unset($_SESSION["equipment_{$charId}"][$slot]);
            }
        }

        return $result;
    }

    /**
     * 应用防具属性加成到 char_apply session（公开方法，供外部调用）
     */
    public static function applyArmorPropertiesPublic(int $charId, array $item): void
    {
        self::applyArmorProperties($charId, $item);
    }

    /**
     * 应用防具属性加成到 char_apply session
     */
    private static function applyArmorProperties(int $charId, array $item): void
    {
        if (!isset($_SESSION["char_apply_{$charId}"])) {
            $_SESSION["char_apply_{$charId}"] = [];
        }
        $bonusFields = [
            'str' => 'str_bonus',
            'int' => 'int_bonus',
            'con' => 'con_bonus',
            'dex' => 'dex_bonus',
            'spi' => 'spi_bonus',
            'dodge' => 'dodge_bonus',
            'parry' => 'parry_bonus',
            'qi_defense' => 'qi_defense',
            'shen_defense' => 'shen_defense',
        ];
        foreach ($bonusFields as $attr => $field) {
            if (isset($item[$field]) && $item[$field] > 0) {
                if (!isset($_SESSION["char_apply_{$charId}"][$attr])) {
                    $_SESSION["char_apply_{$charId}"][$attr] = 0;
                }
                $_SESSION["char_apply_{$charId}"][$attr] += intval($item[$field]);
            }
        }
        // 兼容旧的 weapon_prop/apply 字段
        if (isset($item['weapon_prop']) && is_array($item['weapon_prop'])) {
            foreach ($item['weapon_prop'] as $attr => $value) {
                if (!isset($_SESSION["char_apply_{$charId}"][$attr])) {
                    $_SESSION["char_apply_{$charId}"][$attr] = 0;
                }
                $_SESSION["char_apply_{$charId}"][$attr] += intval($value);
            }
        }
    }

    /**
     * 从数据库重建角色的所有防具装备属性加成到 Session
     * 用于登录时恢复装备属性
     *
     * @param int $charId 角色ID
     */
    public static function rebuildArmorApply(int $charId): void {
        // 查询所有已装备的防具
        // ★ 修复：去掉 ci.category = i.category 的 JOIN 条件
        $equippedArmors = Database::queryAll(
            "SELECT ci.*, i.armor_value,
                    i.str_bonus, i.int_bonus, i.con_bonus, i.dex_bonus,
                    i.spi_bonus, i.dodge_bonus, i.parry_bonus,
                    i.qi_defense, i.shen_defense,
                    i.name, i.type, i.category as i_category, i.unit, i.effects, i.flag
             FROM character_inventory ci
             LEFT JOIN items i ON ci.item_id = i.item_id
             WHERE ci.char_id = ? AND ci.equipped = 1 AND i.type = 'armor'",
            [$charId]
        );

        if (empty($equippedArmors)) {
            return;
        }

        // 初始化 Session 装备数据
        if (!isset($_SESSION["equipment_{$charId}"])) {
            $_SESSION["equipment_{$charId}"] = [];
        }

        foreach ($equippedArmors as $invItem) {
            $slot = $invItem['equip_slot'] ?? '';
            $category = $invItem['i_category'] ?? $invItem['category'] ?? '';

            $itemData = [
                'item_id'       => $invItem['item_id'],
                'name'          => $invItem['name'] ?? $invItem['item_id'],
                'type'          => 'armor',
                'category'      => $category,
                'unit'          => $invItem['unit'] ?? '件',
                'effects'       => $invItem['effects'] ?? '',
                'flag'          => $invItem['flag'] ?? 0,
                'armor_value'   => $invItem['armor_value'] ?? 0,
                'str_bonus'     => $invItem['str_bonus'] ?? 0,
                'int_bonus'     => $invItem['int_bonus'] ?? 0,
                'con_bonus'     => $invItem['con_bonus'] ?? 0,
                'dex_bonus'     => $invItem['dex_bonus'] ?? 0,
                'spi_bonus'     => $invItem['spi_bonus'] ?? 0,
                'dodge_bonus'   => $invItem['dodge_bonus'] ?? 0,
                'parry_bonus'   => $invItem['parry_bonus'] ?? 0,
                'qi_defense'    => $invItem['qi_defense'] ?? 0,
                'shen_defense'  => $invItem['shen_defense'] ?? 0,
                'weapon_prop'   => $invItem['weapon_prop'] ?? null,
            ];

            // 设置 Session
            $_SESSION["equipment_{$charId}"][$slot] = $itemData;
            self::applyArmorProperties($charId, $itemData);
        }
    }

    /**
     * 移除防具属性加成从 char_apply session
     */
    private static function removeArmorProperties(int $charId, array $item): void
    {
        if (!isset($_SESSION["char_apply_{$charId}"])) {
            return;
        }
        $bonusFields = [
            'str' => 'str_bonus',
            'int' => 'int_bonus',
            'con' => 'con_bonus',
            'dex' => 'dex_bonus',
            'spi' => 'spi_bonus',
            'dodge' => 'dodge_bonus',
            'parry' => 'parry_bonus',
            'qi_defense' => 'qi_defense',
            'shen_defense' => 'shen_defense',
        ];
        foreach ($bonusFields as $attr => $field) {
            if (isset($item[$field]) && $item[$field] > 0) {
                if (isset($_SESSION["char_apply_{$charId}"][$attr])) {
                    $_SESSION["char_apply_{$charId}"][$attr] -= intval($item[$field]);
                }
            }
        }
        if (isset($item['weapon_prop']) && is_array($item['weapon_prop'])) {
            foreach ($item['weapon_prop'] as $attr => $value) {
                if (isset($_SESSION["char_apply_{$charId}"][$attr])) {
                    $_SESSION["char_apply_{$charId}"][$attr] -= intval($value);
                }
            }
        }
    }
}

