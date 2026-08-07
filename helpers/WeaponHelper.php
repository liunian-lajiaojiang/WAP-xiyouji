<?php
/**
 * 武器助手
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 功能:
 * - 定义武器类型
 * - 计算武器伤害
 * - 获取武器属性
 */
class WeaponHelper {
    
    // 武器标志常量
    const FLAG_TWO_HANDED = 1;
    const FLAG_SECONDARY  = 2;
    const FLAG_EDGED      = 4;
    
    /**
     * 加载武器配置文件（带缓存）
     */
    private static ?array $weaponConfig = null;
    
    private static function loadWeaponConfig(): array {
        if (self::$weaponConfig === null) {
            $configFile = __DIR__ . '/../config/weapon.php';
            if (file_exists($configFile)) {
                self::$weaponConfig = require $configFile;
            } else {
                self::$weaponConfig = [];
            }
        }
        return self::$weaponConfig;
    }
    
    /**
     * 获取武器类型配置（从 config/weapon.php 读取，带 fallback）
     */
    private static function getWeaponTypes(): array {
        $config = self::loadWeaponConfig();
        return $config['weapon_types'] ?? self::getFallbackWeaponTypes();
    }
    
    /**
     * Fallback：硬编码武器类型（向后兼容，config/weapon.php 不存在时使用）
     */
    private static function getFallbackWeaponTypes(): array {
        $FLAG_TWO_HANDED = 1;
        $FLAG_SECONDARY  = 2;
        $FLAG_EDGED      = 4;
        $FLAG_POINTED    = 8;
        $FLAG_LONG       = 16;
        
        return [
        'sword' => [
            'name' => '剑',
            'damage_type' => 'slash',
            'flags' => $FLAG_EDGED,
            'base_damage' => [5, 15],
        ],
        'blade' => [
            'name' => '刀',
            'damage_type' => 'slash',
            'flags' => $FLAG_EDGED,
            'base_damage' => [8, 18],
        ],
        'staff' => [
            'name' => '棍',
            'damage_type' => 'blunt',
            'flags' => $FLAG_LONG,
            'base_damage' => [4, 12],
        ],
        'hammer' => [
            'name' => '锤',
            'damage_type' => 'blunt',
            'flags' => $FLAG_TWO_HANDED,
            'base_damage' => [10, 25],
        ],
        'axe' => [
            'name' => '斧',
            'damage_type' => 'slash',
            'flags' => $FLAG_EDGED | $FLAG_TWO_HANDED,
            'base_damage' => [12, 28],
        ],
        'spear' => [
            'name' => '矛',
            'damage_type' => 'pierce',
            'flags' => $FLAG_POINTED | $FLAG_LONG,
            'base_damage' => [8, 20],
        ],
        'whip' => [
            'name' => '鞭',
            'damage_type' => 'blunt',
            'flags' => $FLAG_LONG,
            'base_damage' => [3, 10],
        ],
        'dagger' => [
            'name' => '匕首',
            'damage_type' => 'pierce',
            'flags' => $FLAG_POINTED | $FLAG_SECONDARY,
            'base_damage' => [3, 8],
        ],
        'fork' => [
            'name' => '叉',
            'damage_type' => 'pierce',
            'flags' => $FLAG_POINTED,
            'base_damage' => [6, 15],
        ],
        'mace' => [
            'name' => '短锤',
            'damage_type' => 'blunt',
            'flags' => $FLAG_SECONDARY,
            'base_damage' => [5, 12],
        ],
        'rake' => [
            'name' => '耙',
            'damage_type' => 'pierce',
            'flags' => $FLAG_POINTED | $FLAG_LONG,
            'base_damage' => [7, 18],
        ],
        'stick' => [
            'name' => '短棍',
            'damage_type' => 'blunt',
            'flags' => $FLAG_LONG,
            'base_damage' => [4, 14],
        ],
        'archery' => [
            'name' => '弓箭',
            'damage_type' => 'pierce',
            'flags' => $FLAG_TWO_HANDED,
            'base_damage' => [6, 16],
        ],
        'throwing' => [
            'name' => '暗器',
            'damage_type' => 'pierce',
            'flags' => $FLAG_SECONDARY,
            'base_damage' => [2, 6],
        ],
    ];

    }
    
    /**
     * 获取武器类型信息
     * 
     * @param string $weaponType 武器类型
     * @return array|null 武器类型配置
     */
    public static function getWeaponTypeInfo(string $weaponType): ?array {
        $types = self::getWeaponTypes();
        return $types[$weaponType] ?? null;
    }
    
    /**
     * 获取武器类型的中文名称
     * 
     * @param string $weaponType 武器类型
     * @return string 武器名称
     */
    public static function getWeaponTypeName(string $weaponType): string {
        $info = self::getWeaponTypeInfo($weaponType);
        return $info ? $info['name'] : '未知武器';
    }
    
    /**
     * 获取武器的基础伤害范围
     * 
     * @param string $weaponType 武器类型
     * @return array [min, max] 伤害范围
     */
    public static function getBaseDamage(string $weaponType): array {
        $info = self::getWeaponTypeInfo($weaponType);
        return $info ? $info['base_damage'] : [1, 5];
    }
    
    /**
     * 计算武器伤害
     * 
     * @param string $weaponType 武器类型
     * @param int $skillLevel 技能等级
     * @param array $weaponData 武器具体数据(可选)
     * @return int 最终伤害值
     */
    public static function calculateDamage(string $weaponType, int $skillLevel, array $weaponData = []): int {
        // 使用数据库中的weapon_damage字段作为基础伤害
        $baseDamage = $weaponData['weapon_damage'] ?? 0;
        
        // 如果没有设置weapon_damage，根据武器类型计算基础伤害
        if ($baseDamage <= 0) {
            $baseRange = self::getBaseDamage($weaponType);
            $baseDamage = rand($baseRange[0], $baseRange[1]);
        }
        
        // 装备耐久系统：耐久为0时伤害减半
        if (isset($weaponData['durability']) && $weaponData['durability'] <= 0) {
            $baseDamage = intval($baseDamage / 2);
        }
        
        // 技能加成
        $skillBonus = intval($skillLevel * 0.5);
        
        // 最终伤害 = 基础伤害 + 技能加成 + 随机波动(-20% ~ +20%)
        $randomFactor = 0.8 + (rand(0, 40) / 100);
        $damage = intval(($baseDamage + $skillBonus) * $randomFactor);
        
        // 武器品质修正（quality是字符串：poor/normal/good/excellent/legendary）
        $quality = $weaponData['quality'] ?? '';
        if ($quality !== '' && $quality !== 'normal') {
            $qualityMap = [
                'poor' => 0.8,
                'good' => 1.1,
                'excellent' => 1.3,
                'legendary' => 1.6,
            ];
            $modifier = $qualityMap[$quality] ?? 1.0;
            $damage = intval($damage * $modifier);
        }
        
        return max(1, $damage);
    }
    
    /**
     * 获取伤害类型对应的消息类型
     * 
     * @param string $weaponType 武器类型
     * @return string 伤害消息类型 (slash/pierce/blunt)
     */
    public static function getDamageMessageType(string $weaponType): string {
        $info = self::getWeaponTypeInfo($weaponType);
        return $info ? $info['damage_type'] : 'blunt';
    }
    
    /**
     * 检查武器是否有某个属性标志
     * 
     * @param string $weaponType 武器类型
     * @param int $flag 要检查的标志
     * @return bool 是否有该标志
     */
    public static function hasFlag(string $weaponType, int $flag): bool {
        $info = self::getWeaponTypeInfo($weaponType);
        return $info ? (($info['flags'] & $flag) !== 0) : false;
    }
    
    /**
     * 检查是否是双手武器
     * 
     * @param string $weaponType 武器类型
     * @return bool
     */
    public static function isTwoHanded(string $weaponType): bool {
        return self::hasFlag($weaponType, 1); // FLAG_TWO_HANDED
    }
    
    /**
     * 检查是否是副手武器
     * 
     * @param string $weaponType 武器类型
     * @return bool
     */
    public static function isSecondary(string $weaponType): bool {
        return self::hasFlag($weaponType, 2); // FLAG_SECONDARY
    }
    
    /**
     * 获取所有武器类型列表
     * 
     * @return array 武器类型数组
     */
    public static function getAllWeaponTypes(): array {
        return array_keys(self::getWeaponTypes());
    }
    
    /**
     * 根据物品ID获取武器类型
     * 
     * @param string $itemId 物品ID
     * @return string|null 武器类型，如果不是武器则返回null
     */
    public static function getWeaponTypeByItemId(string $itemId, string $category = ''): ?string {
        // 从数据库查询物品的weapon_type字段
        if (!empty($category)) {
            $item = Database::queryOne(
                "SELECT weapon_type FROM items WHERE item_id = ? AND category = ?",
                [$itemId, $category]
            );
        } else {
            $item = Database::queryOne(
                "SELECT weapon_type FROM items WHERE item_id = ?",
                [$itemId]
            );
        }
        
        if ($item && !empty($item['weapon_type'])) {
            return $item['weapon_type'];
        }
        
        // 尝试从物品ID推断(例如: sword_001 -> sword)
        if (preg_match('/^(\w+)_/', $itemId, $matches)) {
            $type = $matches[1];
            $types = self::getWeaponTypes();
            if (isset($types[$type])) {
                return $type;
            }
        }
        
        return null;
    }
    
    /**
     * 获取角色当前装备的主手武器
     * 
     * @param int $charId 角色ID
     * @return array|null 武器数据，如果没有装备则返回null
     */
    public static function getEquippedWeapon(int $charId): ?array {
        // 从Session中获取装备信息
        if (isset($_SESSION["equipment_{$charId}"])) {
            $equipment = $_SESSION["equipment_{$charId}"];
            
            if (isset($equipment['weapon']) && is_array($equipment['weapon'])) {
                return $equipment['weapon'];
            }
        }
        
        return null;
    }
    
    /**
     * 获取角色当前装备的副手武器
     * 
     * @param int $charId 角色ID
     * @return array|null 武器数据，如果没有装备则返回null
     */
    public static function getEquippedSecondaryWeapon(int $charId): ?array {
        // 从Session中获取装备信息
        if (isset($_SESSION["equipment_{$charId}"])) {
            $equipment = $_SESSION["equipment_{$charId}"];
            
            if (isset($equipment['secondary_weapon']) && is_array($equipment['secondary_weapon'])) {
                return $equipment['secondary_weapon'];
            }
        }
        
        return null;
    }
    
    /**
     * 装备武器
     * 
     * @param int $charId 角色ID
     * @param string $itemId 物品ID
     * @param string $slot 槽位 'main' 或 'secondary'
     * @return bool 是否成功
     */
    public static function equipWeapon(int $charId, string $itemId, string $slot = 'main', string $category = ''): bool {
        // 获取物品信息（$itemId 是 item_id 字符串标识，如 'iron-sword'）
        $item = ItemModel::findByItemId($itemId, $category);
        
        if (!$item) {
            // 如果物品不在items表中，从背包中获取
            $sql = "SELECT ci.* FROM character_inventory ci WHERE ci.char_id = ? AND ci.item_id = ? AND ci.category = ? AND ci.equipped = 0 LIMIT 1";
            $invItem = Database::queryOne($sql, [$charId, $itemId, $category]);
            
            if (!$invItem) {
                return false;
            }
            
            $item = [
                'item_id' => $itemId,
                'name' => $itemId,
                'type' => 'weapon',
                'category' => $category,
                'unit' => '把',
                'effects' => '',
                'flag' => 0,
                'weapon_damage' => 0,
                'str_bonus' => 0,
                'dex_bonus' => 0,
            ];
        } else {
            // 检查是否为武器类型
            if ($item['type'] !== 'weapon') {
                return false;
            }
        }
        
        // 初始化装备数据
        if (!isset($_SESSION["equipment_{$charId}"])) {
            $_SESSION["equipment_{$charId}"] = [];
        }
        
        // 设置装备状态
        if ($slot === 'main') {
            $_SESSION["equipment_{$charId}"]['weapon'] = $item;
        } else if ($slot === 'secondary') {
            $_SESSION["equipment_{$charId}"]['secondary_weapon'] = $item;
        }
        
        // 更新数据库中的装备状态（加 LIMIT 1 防止有多条同 item_id 时全部被标记）
        // ★ 同时设置 equip_slot，确保 rebuildWeaponApply 能正确识别主/副手
        if (!empty($category)) {
            Database::execute(
                "UPDATE character_inventory SET equipped = 1, equip_slot = ? WHERE char_id = ? AND item_id = ? AND category = ? AND equipped = 0 LIMIT 1",
                [$slot, $charId, $itemId, $category]
            );
        } else {
            Database::execute(
                "UPDATE character_inventory SET equipped = 1, equip_slot = ? WHERE char_id = ? AND item_id = ? AND equipped = 0 LIMIT 1",
                [$slot, $charId, $itemId]
            );
        }
        
        // 应用武器属性加成到角色
        self::applyWeaponProperties($charId, $item);
        
        return true;
    }
    
    /**
     * 卸下武器
     * 
     * @param int $charId 角色ID
     * @param string $slot 槽位 'main' 或 'secondary'
     * @return bool 是否成功
     */
    public static function unequipWeapon(int $charId, string $slot = 'main'): bool {
        if (!isset($_SESSION["equipment_{$charId}"])) {
            return false;
        }
        
        $weapon = null;
        if ($slot === 'main') {
            $weapon = $_SESSION["equipment_{$charId}"]['weapon'] ?? null;
            unset($_SESSION["equipment_{$charId}"]['weapon']);
        } else if ($slot === 'secondary') {
            $weapon = $_SESSION["equipment_{$charId}"]['secondary_weapon'] ?? null;
            unset($_SESSION["equipment_{$charId}"]['secondary_weapon']);
        }
        
        if (!$weapon) {
            return false;
        }
        
        // 从武器数据中获取分类，用于复合键查询
        $category = $weapon['category'] ?? '';
        
        // 更新数据库中的装备状态（加 LIMIT 1 防止同 item_id 多条记录被批量卸下）
        if (!empty($category)) {
            Database::execute(
                "UPDATE character_inventory SET equipped = 0 WHERE char_id = ? AND item_id = ? AND category = ? LIMIT 1",
                [$charId, $weapon['item_id'], $category]
            );
        } else {
            Database::execute(
                "UPDATE character_inventory SET equipped = 0 WHERE char_id = ? AND item_id = ? LIMIT 1",
                [$charId, $weapon['item_id']]
            );
        }
        
        // 移除武器属性加成
        self::removeWeaponProperties($charId, $weapon);
        
        // 如果是主手武器被卸下，且有副手武器，自动切换
        if ($slot === 'main') {
            $secondaryWeapon = $_SESSION["equipment_{$charId}"]['secondary_weapon'] ?? null;
            if ($secondaryWeapon) {
                // 副手武器切换到主手
                unset($_SESSION["equipment_{$charId}"]['secondary_weapon']);
                $_SESSION["equipment_{$charId}"]['weapon'] = $secondaryWeapon;
                
                // 重新应用属性
                self::removeWeaponProperties($charId, $secondaryWeapon);
                self::applyWeaponProperties($charId, $secondaryWeapon);
            }
        }
        
        return true;
    }
    
    /**
     * 应用武器属性加成
     */
    private static function applyWeaponProperties(int $charId, array $weapon): void {
        // 将武器属性添加到角色的临时属性中
        if (!isset($_SESSION["char_apply_{$charId}"])) {
            $_SESSION["char_apply_{$charId}"] = [];
        }
        
        // 使用数据库中的属性加成字段
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
            if (isset($weapon[$field]) && $weapon[$field] > 0) {
                if (!isset($_SESSION["char_apply_{$charId}"][$attr])) {
                    $_SESSION["char_apply_{$charId}"][$attr] = 0;
                }
                $_SESSION["char_apply_{$charId}"][$attr] += intval($weapon[$field]);
            }
        }
        
        // 兼容旧的weapon_prop字段
        if (isset($weapon['weapon_prop']) && is_array($weapon['weapon_prop'])) {
            foreach ($weapon['weapon_prop'] as $attr => $value) {
                if (!isset($_SESSION["char_apply_{$charId}"][$attr])) {
                    $_SESSION["char_apply_{$charId}"][$attr] = 0;
                }
                $_SESSION["char_apply_{$charId}"][$attr] += intval($value);
            }
        }
    }
    
    /**
     * 按 inventory_id 主键装备武器到指定槽位
     *
     * @param int $charId      角色ID
     * @param int $inventoryId 背包记录主键 (character_inventory.id)
     * @param string $slot     槽位 'main' 或 'secondary'
     * @return bool            是否成功
     */
    public static function equipWeaponById(int $charId, int $inventoryId, string $slot = 'main'): bool
    {
        // 获取要装备的物品信息
        $invItem = Database::queryOne(
            "SELECT ci.*, i.weapon_damage, i.str_bonus, i.int_bonus, i.con_bonus, i.dex_bonus,
                    i.spi_bonus, i.dodge_bonus, i.parry_bonus, i.name, i.type,
                    i.category as i_category, i.unit, i.effects, i.flag
             FROM character_inventory ci
             LEFT JOIN items i ON ci.item_id = i.item_id
             WHERE ci.id = ? AND ci.char_id = ?",
            [$inventoryId, $charId]
        );
        if (!$invItem) {
            return false;
        }

        // 卸下旧装备并移除其属性加成
        if ($slot === 'main') {
            $oldMain = Database::queryOne(
                "SELECT ci.*, i.weapon_damage, i.str_bonus, i.int_bonus, i.con_bonus, i.dex_bonus,
                        i.spi_bonus, i.dodge_bonus, i.parry_bonus
                 FROM character_inventory ci
                 LEFT JOIN items i ON ci.item_id = i.item_id
                 WHERE ci.char_id = ? AND ci.equip_slot = 'main' AND ci.equipped = 1",
                [$charId]
            );
            if ($oldMain) {
                self::removeWeaponProperties($charId, $oldMain);
            }
            Database::execute(
                "UPDATE character_inventory SET equipped = 0, equip_slot = '' WHERE char_id = ? AND equip_slot = 'main' AND equipped = 1",
                [$charId]
            );
        } elseif ($slot === 'secondary') {
            $oldSec = Database::queryOne(
                "SELECT ci.*, i.weapon_damage, i.str_bonus, i.int_bonus, i.con_bonus, i.dex_bonus,
                        i.spi_bonus, i.dodge_bonus, i.parry_bonus
                 FROM character_inventory ci
                 LEFT JOIN items i ON ci.item_id = i.item_id
                 WHERE ci.char_id = ? AND ci.equip_slot = 'secondary' AND ci.equipped = 1",
                [$charId]
            );
            if ($oldSec) {
                self::removeWeaponProperties($charId, $oldSec);
            }
            Database::execute(
                "UPDATE character_inventory SET equipped = 0, equip_slot = '' WHERE char_id = ? AND equip_slot = 'secondary' AND equipped = 1",
                [$charId]
            );
        }

        // 按主键装备
        $result = Database::execute(
            "UPDATE character_inventory SET equipped = 1, equip_slot = ? WHERE id = ? AND char_id = ?",
            [$slot, $inventoryId, $charId]
        ) > 0;

        if ($result) {
            // 同步 session：装备数据
            if (!isset($_SESSION["equipment_{$charId}"])) {
                $_SESSION["equipment_{$charId}"] = [];
            }
            $itemData = [
                'item_id' => $invItem['item_id'],
                'name' => $invItem['name'] ?? $invItem['item_id'],
                'type' => $invItem['type'] ?? 'weapon',
                'category' => $invItem['i_category'] ?? $invItem['category'] ?? '',
                'unit' => $invItem['unit'] ?? '把',
                'effects' => $invItem['effects'] ?? '',
                'flag' => $invItem['flag'] ?? 0,
                'weapon_damage' => $invItem['weapon_damage'] ?? 0,
                'str_bonus' => $invItem['str_bonus'] ?? 0,
                'int_bonus' => $invItem['int_bonus'] ?? 0,
                'con_bonus' => $invItem['con_bonus'] ?? 0,
                'dex_bonus' => $invItem['dex_bonus'] ?? 0,
                'spi_bonus' => $invItem['spi_bonus'] ?? 0,
                'dodge_bonus' => $invItem['dodge_bonus'] ?? 0,
                'parry_bonus' => $invItem['parry_bonus'] ?? 0,
                'qi_defense' => $invItem['qi_defense'] ?? 0,
                'shen_defense' => $invItem['shen_defense'] ?? 0,
                'weapon_prop' => $invItem['weapon_prop'] ?? null,
            ];
            if ($slot === 'main') {
                $_SESSION["equipment_{$charId}"]['weapon'] = $itemData;
            } else {
                $_SESSION["equipment_{$charId}"]['secondary_weapon'] = $itemData;
            }
            // 应用武器属性加成
            self::applyWeaponProperties($charId, $itemData);
        }

        return $result;
    }

    /**
     * 按 inventory_id 主键卸下已装备武器
     *
     * @param int $charId      角色ID
     * @param int $inventoryId 背包记录主键 (character_inventory.id)
     * @return bool            是否成功
     */
    public static function unequipWeaponById(int $charId, int $inventoryId): bool
    {
        // 获取要卸下的物品信息（含属性加成）
        $invItem = Database::queryOne(
            "SELECT ci.*, i.weapon_damage, i.str_bonus, i.int_bonus, i.con_bonus, i.dex_bonus,
                    i.spi_bonus, i.dodge_bonus, i.parry_bonus, i.name,
                    i.category as i_category
             FROM character_inventory ci
             LEFT JOIN items i ON ci.item_id = i.item_id
             WHERE ci.id = ? AND ci.char_id = ?",
            [$inventoryId, $charId]
        );
        if (!$invItem) {
            return false;
        }

        $result = Database::execute(
            "UPDATE character_inventory SET equipped = 0, equip_slot = '' WHERE id = ? AND char_id = ?",
            [$inventoryId, $charId]
        ) > 0;

        if ($result) {
            // 移除武器属性加成
            $itemData = [
                'item_id' => $invItem['item_id'],
                'name' => $invItem['name'] ?? $invItem['item_id'],
                'category' => $invItem['i_category'] ?? $invItem['category'] ?? '',
                'str_bonus' => $invItem['str_bonus'] ?? 0,
                'int_bonus' => $invItem['int_bonus'] ?? 0,
                'con_bonus' => $invItem['con_bonus'] ?? 0,
                'dex_bonus' => $invItem['dex_bonus'] ?? 0,
                'spi_bonus' => $invItem['spi_bonus'] ?? 0,
                'dodge_bonus' => $invItem['dodge_bonus'] ?? 0,
                'parry_bonus' => $invItem['parry_bonus'] ?? 0,
                'qi_defense' => $invItem['qi_defense'] ?? 0,
                'shen_defense' => $invItem['shen_defense'] ?? 0,
                'weapon_prop' => $invItem['weapon_prop'] ?? null,
            ];
            self::removeWeaponProperties($charId, $itemData);

            // 清理 session 装备记录
            if (isset($_SESSION["equipment_{$charId}"])) {
                $slot = $invItem['equip_slot'] ?? '';
                if ($slot === 'secondary') {
                    unset($_SESSION["equipment_{$charId}"]['secondary_weapon']);
                } else {
                    unset($_SESSION["equipment_{$charId}"]['weapon']);
                }
            }
        }

        return $result;
    }

    /**
     * 移除武器属性加成
     */
    private static function removeWeaponProperties(int $charId, array $weapon): void {
        if (!isset($_SESSION["char_apply_{$charId}"])) {
            return;
        }
        
        // 使用数据库中的属性加成字段
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
            if (isset($weapon[$field]) && $weapon[$field] > 0) {
                if (isset($_SESSION["char_apply_{$charId}"][$attr])) {
                    $_SESSION["char_apply_{$charId}"][$attr] -= intval($weapon[$field]);
                }
            }
        }
        
        // 兼容旧的weapon_prop字段
        if (isset($weapon['weapon_prop']) && is_array($weapon['weapon_prop'])) {
            foreach ($weapon['weapon_prop'] as $attr => $value) {
                if (isset($_SESSION["char_apply_{$charId}"][$attr])) {
                    $_SESSION["char_apply_{$charId}"][$attr] -= intval($value);
                }
            }
        }
    }

    /**
     * 从数据库重建角色的武器装备属性加成到 Session
     * 用于登录时恢复装备属性，以及 Session 丢失后的重建
     * 注意：防具由 ArmorHelper::rebuildArmorApply() 单独处理
     *
     * @param int $charId 角色ID
     */
    public static function rebuildWeaponApply(int $charId): void {
        // 清空当前 char_apply_ 中的武器属性（保留非装备的加成如运功 buff）
        if (isset($_SESSION["char_apply_{$charId}"])) {
            $equipKeys = ['str', 'int', 'con', 'dex', 'spi', 'dodge', 'parry'];
            foreach ($equipKeys as $key) {
                unset($_SESSION["char_apply_{$charId}"][$key]);
            }
        }

        // 查询所有已装备的武器
        // ★ 修复：去掉 ci.category = i.category 的 JOIN 条件
        // 因为 character_inventory.category 可能为空字符串或与 items.category 不一致
        $equippedWeapons = Database::queryAll(
            "SELECT ci.*, i.weapon_damage,
                    i.str_bonus, i.int_bonus, i.con_bonus, i.dex_bonus,
                    i.spi_bonus, i.dodge_bonus, i.parry_bonus,
                    i.qi_defense, i.shen_defense,
                    i.name, i.type, i.category as i_category, i.unit, i.effects, i.flag
             FROM character_inventory ci
             LEFT JOIN items i ON ci.item_id = i.item_id
             WHERE ci.char_id = ? AND ci.equipped = 1 AND i.type = 'weapon'",
            [$charId]
        );

        if (empty($equippedWeapons)) {
            return;
        }

        // 初始化 Session 装备数据
        if (!isset($_SESSION["equipment_{$charId}"])) {
            $_SESSION["equipment_{$charId}"] = [];
        }

        foreach ($equippedWeapons as $invItem) {
            $slot = $invItem['equip_slot'] ?? '';
            $category = $invItem['i_category'] ?? $invItem['category'] ?? '';

            // 构建武器数据（包含完整属性加成字段）
            $itemData = [
                'item_id'       => $invItem['item_id'],
                'name'          => $invItem['name'] ?? $invItem['item_id'],
                'type'          => 'weapon',
                'category'      => $category,
                'unit'          => $invItem['unit'] ?? '把',
                'effects'       => $invItem['effects'] ?? '',
                'flag'          => $invItem['flag'] ?? 0,
                'weapon_damage' => $invItem['weapon_damage'] ?? 0,
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

            // 根据槽位设置 Session
            if ($slot === 'main') {
                $_SESSION["equipment_{$charId}"]['weapon'] = $itemData;
            } elseif ($slot === 'secondary') {
                $_SESSION["equipment_{$charId}"]['secondary_weapon'] = $itemData;
            }
            // 应用武器属性加成到 char_apply_
            self::applyWeaponProperties($charId, $itemData);
        }
    }
}

