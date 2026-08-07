<?php

/**
 * 角色模型类
 */
class CharacterModel {
    
    /**
     * 根据ID查找角色
     */
    public static function find(int $id): ?array {
        $sql = "SELECT * FROM characters WHERE id = ?";
        return Database::queryOne($sql, [$id]);
    }
    
    /**
     * 根据用户ID查找角色列表
     */
    public static function findByUserId(int $userId): array {
        $sql = "SELECT * FROM characters WHERE user_id = ? ORDER BY id DESC";
        return Database::queryAll($sql, [$userId]);
    }
    
    /**
     * 根据用户ID获取角色（单个）
     */
    public static function getByUserId(int $userId): ?array {
        $sql = "SELECT * FROM characters WHERE user_id = ? ORDER BY id DESC LIMIT 1";
        return Database::queryOne($sql, [$userId]);
    }
    
    /**
     * 根据角色名查找角色
     */
    public static function findByName(string $name): ?array {
        $sql = "SELECT * FROM characters WHERE LOWER(name) = LOWER(?)";
        return Database::queryOne($sql, [$name]);
    }
    
    /**
     * 获取角色完整信息
     */
    public static function getFullInfo($charId) {
        $char = self::find($charId);
        
        if (!$char) {
            return null;
        }
        
        // 解析 JSON 字段
        if (!empty($char['rank_info']) && is_string($char['rank_info'])) {
            $decoded = json_decode($char['rank_info'], true);
            $char['rank_info'] = is_array($decoded) ? $decoded : [];
        } else {
            $char['rank_info'] = [];
        }
        
        return $char;
    }
    
    /**
     * 创建新角色
     * 对照原始 LPC logind.c 完整还原所有初始属性
     */
    public static function create(array $data): int {
        $now = time();
        $gender = $data['gender'] ?? 'male';

        $sql = "INSERT INTO characters 
                (user_id, name, race, gender, str, con, `int`, spi, cps, per, kar, cor,
                 potential, food, max_food, water, max_water, copper,
                 display_title, current_area, current_room, age, mud_age, age_modify, last_age_set)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, 14, 0, 0, ?)";

        Database::execute($sql, [
            $data['user_id'],
            $data['name'],
            $data['race'] ?? 'human',
            $gender,
            $data['str'] ?? 20,
            $data['con'] ?? 20,
            $data['int'] ?? 25,
            $data['spi'] ?? 25,
            // cps/per/kar/cor: 原始默认20（后面天赋分配时可随机调整）
            $data['cps'] ?? 20,
            $data['per'] ?? 20,
            $data['kar'] ?? 20,
            $data['cor'] ?? 20,
            // potential: 原始默认99（用于学习技能）
            99,
            // food/water: 创建时即满
            200, 200, 200, 200,
            // copper: 初始500铜钱（5两银子），足够买基础补给
            500,
            // display_title: 原始默认"普通百姓"
            '普通百姓',
            'city',
            'city/kezhan',
            $now
        ]);

        $charId = intval(Database::lastInsertId());

        // 给予基础技能（对齐原始项目：unarmed/parry/dodge/force 各 10 级）
        self::giveBasicSkills($charId);

        // 根据 con/str/age 计算初始气血上限（还原原始LPC逻辑）
        self::recalculateVitals($charId);

        // 发放初始装备（对照原始 autoload.c）
        self::giveStarterEquipment($charId, $gender);

        return $charId;
    }

    /**
     * 给予新玩家基础技能
     * 对照原始 LPC 设计：角色天生具备基础武学素养
     *   unarmed（拳脚）— 扑击格斗之技
     *   parry（招架）  — 拆招卸力之法
     *   dodge（闪避）  — 基本轻功
     *   force（内功）  — 内功心法
     * 初始等级 15 级，经验 0，确保新角色有基本战斗能力
     */
    public static function giveBasicSkills(int $charId): void {
        $basicSkills = ['unarmed', 'parry', 'dodge', 'force'];
        foreach ($basicSkills as $skillId) {
            Database::execute(
                "INSERT INTO character_skills (char_id, skill_id, level, exp) VALUES (?, ?, 20, 0)
                 ON DUPLICATE KEY UPDATE level = GREATEST(level, 20)",
                [$charId, $skillId]
            );
        }
    }

    /**
     * 发放新玩家初始装备
     * 对照原始 feature/autoload.c：
     *   男性 → 粗布衣 (linen) + 木刀 (mudao)
     *   女性 → 轻纱长裙 (skirt) + 绣花小鞋 (xiuhuaxie) + 竹剑 (zhu_jian)
     * 新增初始武器，解决新玩家赤手空拳打不过NPC的问题。
     */
    public static function giveStarterEquipment(int $charId, string $gender): void {
        if ($gender === 'female' || $gender === '女性') {
            // 女性：轻纱长裙 (skirt) - 装备在 cloth 槽
            Database::execute(
                "INSERT INTO character_inventory (char_id, item_id, category, quantity, equipped, equip_slot)
                 VALUES (?, 'skirt', 'obj', 1, 1, 'cloth')",
                [$charId]
            );
            // 女性：绣花小鞋 (xiuhuaxie) - 装备在 boots 槽
            Database::execute(
                "INSERT INTO character_inventory (char_id, item_id, category, quantity, equipped, equip_slot)
                 VALUES (?, 'xiuhuaxie', 'city', 1, 1, 'boots')",
                [$charId]
            );
            // 女性：竹剑 (zhu_jian) - 初始武器（剑类，damage=25）
            Database::execute(
                "INSERT INTO character_inventory (char_id, item_id, category, quantity, equipped, equip_slot)
                 VALUES (?, 'zhu_jian', 'city', 1, 1, 'weapon')",
                [$charId]
            );
        } else {
            // 男性：粗布衣 (linen) - 装备在 cloth 槽
            Database::execute(
                "INSERT INTO character_inventory (char_id, item_id, category, quantity, equipped, equip_slot)
                 VALUES (?, 'linen', 'city', 1, 1, 'cloth')",
                [$charId]
            );
            // 男性：木刀 (mudao) - 初始武器（刀类，damage=12）
            Database::execute(
                "INSERT INTO character_inventory (char_id, item_id, category, quantity, equipped, equip_slot)
                 VALUES (?, 'mudao', 'city', 1, 1, 'weapon')",
                [$charId]
            );
        }
    }
    
    /**
     * 更新角色在线状态
     */
    public static function updateOnlineStatus(int $charId, bool $isOnline): bool {
        $sql = "UPDATE characters SET online = ? WHERE id = ?";
        return Database::execute($sql, [$isOnline ? 1 : 0, $charId]) > 0;
    }
    
    /**
     * 更新角色位置
     */
    public static function updatePosition(int $charId, string $area, string $roomId): bool {
        $sql = "UPDATE characters SET current_area = ?, current_room = ? WHERE id = ?";
        return Database::execute($sql, [$area, $roomId, $charId]) > 0;
    }
    
    /**
     * 更新角色属性
     */
    public static function update(int $charId, array $data): bool {
        $setClauses = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            $setClauses[] = "`{$key}` = ?";
            $params[] = $value;
        }
        
        if (empty($setClauses)) {
            return false;
        }
        
        $params[] = $charId;
        $sql = "UPDATE characters SET " . implode(', ', $setClauses) . " WHERE id = ?";
        
        return Database::execute($sql, $params) > 0;
    }
    
    /**
     * 获取角色技能
     */
    public static function getSkills($charId) {
        $sql = "SELECT cs.*, gs.name as skill_name, gs.type as skill_type 
                FROM character_skills cs
                JOIN skills gs ON cs.skill_id = gs.skill_id
                WHERE cs.char_id = ?";
        return Database::queryAll($sql, [$charId]);
    }
    
    /**
     * 获取角色背包
     */
    public static function getInventory($charId) {
        $sql = "SELECT ci.*, COALESCE(gi.name, ci.item_id) as item_name, COALESCE(gi.type, 'misc') as item_type, COALESCE(gi.type, 'misc') as type, gi.description,
                       gi.unit, gi.effects, gi.armor_value, gi.weapon_damage,
                       gi.armor_type, gi.flag, gi.female_only, gi.no_wield,
                       gi.str_bonus, gi.con_bonus, gi.dex_bonus, gi.int_bonus, 
                       gi.spi_bonus, gi.dodge_bonus, gi.parry_bonus, gi.stackable,
                       gi.fabao, gi.is_real, gi.trap_type, gi.trap_ratio, gi.series_no
                FROM character_inventory ci
                LEFT JOIN items gi ON ci.item_id = gi.item_id AND ci.category = gi.category
                WHERE ci.char_id = ?";
        return Database::queryAll($sql, [$charId]);
    }
    
    /**
     * 获取角色装备
     */
    public static function getEquipment($charId) {
        $sql = "SELECT ci.*, COALESCE(gi.name, ci.item_id) as item_name, COALESCE(gi.type, 'misc') as item_type, COALESCE(gi.type, 'misc') as type, gi.armor_type as armor_type
                FROM character_inventory ci
                LEFT JOIN items gi ON ci.item_id = gi.item_id AND ci.category = gi.category
                WHERE ci.char_id = ? AND ci.equipped = 1";
        return Database::queryAll($sql, [$charId]);
    }
    
    /**
     * 删除角色
     */
    public static function delete(int $id): bool {
        $sql = "DELETE FROM characters WHERE id = ?";
        return Database::execute($sql, [$id]) > 0;
    }
    
    /**
     * 重新计算角色的气血/精力/精神上限
     * 对照原始 LPC chard.c + skill.c 的完整逻辑
     *
     * max_kee = f(con, str, age) + max_force/4
     * max_gin = f(spi, age) + max_atman加成
     * max_sen = f(spi, int, age) + max_mana/4
     *
     * 注意：max_force 从数据库字段读取，它由 exercise（修炼内力）独立管理。
     * 原始 LPC 中 maximum_force 是修炼值，max_force = maximum_force（受 query_max_force 上限约束）。
     * 这里的 recalculateVitals 只负责用 max_force 加成气血，不修改 max_force 本身。
     */
    public static function recalculateVitals(int $charId): void {
        $char = self::find($charId);
        if (!$char) return;

        $age = intval($char['age'] ?? 14);

        // === max_kee (气血上限) ===
        $con = intval($char['con'] ?? 10);
        $str = intval($char['str'] ?? 10);
        $qi = intval(($con + $str) / 2);

        if ($age <= 14) {
            $maxKee = 10 * $qi;
        } elseif ($age <= 30) {
            $maxKee = ($age - 4) * $qi;
        } else {
            $maxKee = 26 * $qi;
        }
        // 内力加成
        $maxForce = intval($char['max_force'] ?? 0);
        if ($maxForce > 0) {
            $maxKee += intval($maxForce / 4);
        }
        $maxKee = max(100, $maxKee);

        // === max_gin (精力上限) ===
        $spi = intval($char['spi'] ?? 10);
        if ($age <= 14) {
            $maxGin = 100;
        } elseif ($age <= 30) {
            $maxGin = 100 + ($age - 14) * $spi;
        } elseif ($age <= 50) {
            $maxGin = 100 + 16 * $spi;
        } else {
            $maxGin = 100 + 16 * $spi - ($age - 50) * 5;
        }
        // 元神加成
        $maxAtman = intval($char['max_atman'] ?? 0);
        if ($maxAtman > 1000) {
            $maxGin += 250;
        } elseif ($maxAtman > 0) {
            $maxGin += intval($maxAtman / 4);
        }
        $maxGin = max(100, $maxGin);

        // === max_sen (精神上限) ===
        $int = intval($char['int'] ?? 10);
        $shen = intval(($spi + $int) / 2);

        if ($age <= 14) {
            $maxSen = 10 * $shen;
        } elseif ($age <= 30) {
            $maxSen = ($age - 4) * $shen;
        } else {
            $maxSen = 26 * $shen;
        }
        // 法力加成
        $maxMana = intval($char['max_mana'] ?? 0);
        if ($maxMana > 0) {
            $maxSen += intval($maxMana / 4);
        }
        $maxSen = max(100, $maxSen);

        // 更新上限，并将当前值限制在上限以内
        // 同时初始化 eff_kee 和 eff_sen（有效气血/精神上限）
        // 如果 eff_kee 为 0 或超过 max_kee，则设置为 max_kee
        // 如果 eff_sen 为 0 或超过 max_sen，则设置为 max_sen
        Database::execute(
            "UPDATE characters SET max_kee = ?, max_gin = ?, max_sen = ?,
             kee = LEAST(kee, ?), gin = LEAST(gin, ?), sen = LEAST(sen, ?),
             eff_kee = CASE WHEN eff_kee = 0 OR eff_kee > ? THEN ? ELSE eff_kee END,
             eff_sen = CASE WHEN eff_sen = 0 OR eff_sen > ? THEN ? ELSE eff_sen END
             WHERE id = ?",
            [$maxKee, $maxGin, $maxSen, $maxKee, $maxGin, $maxSen, 
             $maxKee, $maxKee, $maxSen, $maxSen, $charId]
        );
    }

    /**
     * 获取当前房间内的其他玩家（不包括自己）
     */
    public static function getRoomPlayers(string $area, string $roomId, int $currentCharId): array {
        $sql = "SELECT id, name, race, gender, level 
                FROM characters 
                WHERE current_area = ? AND current_room = ? AND id != ? AND online = 1
                ORDER BY name";
        return Database::queryAll($sql, [$area, $roomId, $currentCharId]);
    }
}