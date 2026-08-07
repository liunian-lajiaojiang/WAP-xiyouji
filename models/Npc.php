<?php
/**
 * NPC模型
 */
class NpcModel {

    /**
     * 种族英文标识到中文种族名的映射
     */
    private static array $raceMapping = [
        'human'   => '人类',
        'monster' => '野兽',
        'demon'   => '妖魔',
    ];

    /**
     * 需要初始化的属性字段列表
     * 对应 race.php 中 attributes 配置的字段，
     * 仅包含数据库中实际存在的列
     */
    private static array $attributeFields = ['str', 'int', 'con', 'dex', 'per'];

    /**
     * 根据ID查找NPC
     */
    public static function find(int $id): ?array {
        // DEBUG: 记录查询请求
        error_log("[NPC_DEBUG] find() called with ID: " . $id);
        
        $sql = "SELECT * FROM npcs WHERE id = ?";
        $npc = Database::queryOne($sql, [$id]);
        
        if (!$npc) {
            error_log("[NPC_DEBUG] Database query returned NULL for ID: " . $id);
            return null;
        }
        
        error_log("[NPC_DEBUG] Raw NPC data: " . print_r($npc, true));
        
        $result = self::initializeAttributes($npc);
        
        if (!$result) {
            error_log("[NPC_DEBUG] initializeAttributes() returned NULL/empty for ID: " . $id);
            return null;
        }
        
        error_log("[NPC_DEBUG] Final result: " . (is_array($result) ? 'Array with ' . count($result) . ' keys' : gettype($result)));
        return $result;
    }

    /**
     * 根据NPC标识查找
     */
    public static function findByNpcId(string $npcId): ?array {
        $sql = "SELECT * FROM npcs WHERE npc_id = ?";
        $npc = Database::queryOne($sql, [$npcId]);
        return $npc ? self::initializeAttributes($npc) : null;
    }

    /**
     * 获取NPC技能
     */
    public static function getSkills(int $npcId): array {
        $sql = "SELECT ns.*, gs.name as skill_name
                FROM npc_skills ns
                JOIN skills gs ON ns.skill_name = gs.skill_id
                WHERE ns.npc_id = ?";
        return Database::queryAll($sql, [$npcId]);
    }

    /**
     * 获取NPC装备
     */
    public static function getEquipment(int $npcId): array {
        $sql = "SELECT ne.*, gi.name as item_name
                FROM npc_equipment ne
                JOIN items gi ON ne.item_id = gi.item_id AND ne.category = gi.category
                WHERE ne.npc_id = ?";
        return Database::queryAll($sql, [$npcId]);
    }

    /**
     * 获取区域内的所有NPC
     */
    public static function getByArea(string $area): array {
        $sql = "SELECT * FROM npcs WHERE spawn_area = ?";
        $npcs = Database::queryAll($sql, [$area]);
        foreach ($npcs as &$npc) {
            $npc = self::initializeAttributes($npc);
        }
        return $npcs;
    }

    /**
     * 获取房间内的所有NPC
     * @param string $roomId 房间ID
     * @return array NPC列表
     */
    public static function getByRoom(string $roomId): array {
        $sql = "SELECT * FROM npcs WHERE spawn_room = ?";
        $npcs = Database::queryAll($sql, [$roomId]);
        foreach ($npcs as &$npc) {
            $npc = self::initializeAttributes($npc);
        }
        return $npcs;
    }

    /**
     * 解析 NPC 的 actions 字段（JSON），
     * 提取接受物品 / 任务给予等信息
     */
    public static function parseActions(array $npc): array {
        $actionsRaw = $npc['actions'] ?? null;
        if (!$actionsRaw || !is_string($actionsRaw) || trim($actionsRaw) === '') {
            return $npc;
        }

        $decoded = json_decode($actionsRaw, true);
        if (!is_array($decoded)) {
            return $npc;
        }

        $acceptItems = [];
        $questGive = false;

        foreach ($decoded as $action) {
            if (!is_array($action)) {
                continue;
            }
            $type = $action['type'] ?? '';
            // 接受物品动作
            if ($type === 'accept_object' || ($action['action_name'] ?? '') === 'Accept Item') {
                if (isset($action['accepted_items']) && is_array($action['accepted_items'])) {
                    $acceptItems = array_merge($acceptItems, $action['accepted_items']);
                } elseif (isset($action['accepted_items'])) {
                    $acceptItems[] = $action['accepted_items'];
                }
            }
            // 任务给予
            if ($type === 'quest_give') {
                $questGive = true;
            }
        }

        if (!empty($acceptItems)) {
            $npc['accept_items'] = $acceptItems;
        }
        if ($questGive) {
            $npc['quest_give'] = true;
        }

        return $npc;
    }

    public static function initializeAttributes(array $npc): array {
        // 加载种族配置
        $raceConfig = require __DIR__ . '/../config/race.php';
        $races = $raceConfig['races'] ?? [];

        // 获取NPC的种族标识
        $raceKey = $npc['race'] ?? 'human';
        
        // 如果种族标识已经是中文，则直接使用
        if (isset($races[$raceKey])) {
            $raceName = $raceKey;
        } else {
            // 否则尝试通过英文映射转换
            $raceName = self::$raceMapping[$raceKey] ?? null;
        }

        // 如果找不到对应种族配置，只解析 actions 字段
        if (!$raceName || !isset($races[$raceName])) {
            return self::parseActions($npc);
        }

        $raceAttributes = $races[$raceName]['attributes'] ?? [];

        // 遍历需要初始化的属性字段
        foreach (self::$attributeFields as $field) {
            // 跳过 race.php 中未配置的字段
            if (!isset($raceAttributes[$field])) {
                continue;
            }

            $value = $npc[$field] ?? null;

            // 判断属性是否为空或0（null、空字符串、0 都视为未初始化）
            if ($value === null || $value === '' || $value == 0) {
                $min = (int) ($raceAttributes[$field]['min'] ?? 0);
                $max = (int) ($raceAttributes[$field]['max'] ?? 0);

                // 生成 [min, max] 范围内的随机整数
                $npc[$field] = mt_rand($min, $max);
            }
        }

        return self::parseActions($npc);
    }
}

