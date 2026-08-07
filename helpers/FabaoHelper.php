<?php
/**
 * 法宝助手类
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 功能:
 * - 法宝防御机制(protect_qi/protect_shen)
 * - 法宝唯一性管理(series_no)
 * - 法宝特殊能力(困人/束缚)
 * - AP vs DP 成功判定
 * - 耐用度消耗与检查
 * - 真假法宝判定
 * - 数据库持久化困人状态
 */

require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../models/Character.php';

class FabaoHelper {
    
    /**
     * 法宝防御消息
     */
    private static array $defendMessages = [
        "只见\$N的\$n霞光一闪！\n",
        "只见\$N的\$n霞光再闪！\n",
        "只见\$N的\$n霞光又一闪！\n",
        "只见\$N的\$n霞光再一闪！\n"
    ];
    
    // ========== 已有接口（保持兼容） ==========
    
    /**
     * 检查是否为法宝
     * 
     * @param array $item 物品数据
     * @return bool
     */
    public static function isFabao(array $item): bool {
        return isset($item['fabao']) && $item['fabao'] == 1;
    }
    
    /**
     * 生成法宝序列号
     * 
     * @param string $fabaoId 法宝ID
     * @return string 唯一序列号
     */
    public static function generateSeriesNo(string $fabaoId): string {
        $prefix = strtoupper(substr($fabaoId, 0, 3));
        $timestamp = time();
        $random = mt_rand(1000, 9999);
        
        return "FABAO_{$prefix}_{$timestamp}_{$random}";
    }
    
    /** 每格充能可吸收的伤害值（LPC: def_unit = 20） */
    const DEF_UNIT = 20;
    
    /**
     * 法宝气伤害防御
     * 还原 LPC protect_qi()：每格充能吸收固定 20 点伤害，用完为止
     * 
     * @param array $fabao 法宝数据（需包含 defense_qi 当前充能格数 + fabao_id）
     * @param int $damageQi 气伤害
     * @return int 剩余伤害
     */
    public static function protectQi(array &$fabao, int $damageQi): int {
        if (!self::isFabao($fabao)) {
            return $damageQi;
        }
        
        $square = intval($fabao['defense_qi'] ?? 0);
        if ($square <= 0 || $damageQi <= 0) {
            return $damageQi;
        }
        
        $defUnit = self::DEF_UNIT;
        $i = intval($damageQi / $defUnit);
        
        if ($i >= $square) {
            // 伤害超出充能格数：耗尽所有充能，剩余伤害 = damage - square*20
            $fabao['defense_qi'] = 0;
            $remaining = $damageQi - $square * $defUnit;
            self::updateFabaoDefense($fabao, 'defense_qi', 0);
            return max(0, $remaining);
        } else {
            // 充能充足：消耗 i 格，完全挡下伤害
            $fabao['defense_qi'] = $square - $i;
            self::updateFabaoDefense($fabao, 'defense_qi', $square - $i);
            return 0;
        }
    }
    
    /**
     * 法宝神伤害防御
     * 还原 LPC protect_shen()：每格充能吸收固定 20 点伤害，用完为止
     * 
     * @param array $fabao 法宝数据（需包含 defense_shen 当前充能格数 + fabao_id）
     * @param int $damageShen 神伤害
     * @return int 剩余伤害
     */
    public static function protectShen(array &$fabao, int $damageShen): int {
        if (!self::isFabao($fabao)) {
            return $damageShen;
        }
        
        $square = intval($fabao['defense_shen'] ?? 0);
        if ($square <= 0 || $damageShen <= 0) {
            return $damageShen;
        }
        
        $defUnit = self::DEF_UNIT;
        $i = intval($damageShen / $defUnit);
        
        if ($i >= $square) {
            // 伤害超出充能格数：耗尽所有充能
            $fabao['defense_shen'] = 0;
            $remaining = $damageShen - $square * $defUnit;
            self::updateFabaoDefense($fabao, 'defense_shen', 0);
            return max(0, $remaining);
        } else {
            // 充能充足：消耗 i 格
            $fabao['defense_shen'] = $square - $i;
            self::updateFabaoDefense($fabao, 'defense_shen', $square - $i);
            return 0;
        }
    }
    
    /**
     * 更新 character_fabao 表的防御充能格数
     */
    private static function updateFabaoDefense(array $fabao, string $field, int $value): void {
        $fabaoId = intval($fabao['fabao_id'] ?? 0);
        if ($fabaoId <= 0) {
            return;
        }
        Database::execute(
            "UPDATE character_fabao SET {$field} = ? WHERE id = ?",
            [$value, $fabaoId]
        );
    }
    
    /**
     * 获取法宝防御消息
     * 
     * @param int $defendCount 防御次数
     * @return string 消息模板
     */
    public static function getDefendMessage(int $defendCount): string {
        $index = $defendCount % count(self::$defendMessages);
        return self::$defendMessages[$index];
    }
    
    /**
     * 应用法宝防御(在法术攻击中调用)
     * 还原 LPC spelld.c apply_damage() 逻辑：
     *   遍历所有已装备防具法宝，依次调用 protect_qi/protect_shen
     *   每格充能吸收 20 点伤害，耗尽后需重新充能
     * 
     * @param int $charId 角色ID
     * @param int &$damageQi 气伤害引用传参
     * @param int &$damageShen 神伤害引用传参
     * @return array ['defendCount' => int, 'messages' => string[]]
     */
    public static function applyFabaoDefense(int $charId, int &$damageQi, int &$damageShen): array {
        // 查询所有已装备的防具型法宝（series_no != 1，即非武器型）
        $fabaoRows = Database::queryAll(
            "SELECT cf.*, ci.equipped, i.fabao
             FROM character_fabao cf
             JOIN character_inventory ci ON ci.char_id = cf.owner_id AND ci.item_id = cf.item_id AND ci.series_no = cf.series_no
             JOIN items i ON i.item_id = cf.item_id
             WHERE cf.owner_id = ? AND cf.fabao_type = 'armor' AND ci.equipped = 1 AND i.fabao = 1",
            [$charId]
        );
        
        $defendCount = 0;
        $messages = [];
        
        foreach ($fabaoRows as $fabao) {
            // 注入 fabao_id 供 protectQi/protectShen 回写数据库
            $fabao['fabao_id'] = $fabao['id'];
            
            $originalQi = $damageQi;
            $originalShen = $damageShen;
            
            if ($damageQi > 0) {
                $damageQi = self::protectQi($fabao, $damageQi);
                if ($damageQi < $originalQi) {
                    $defendCount++;
                    $messages[] = self::getDefendMessage($defendCount);
                }
            }
            
            if ($damageShen > 0) {
                $damageShen = self::protectShen($fabao, $damageShen);
                if ($damageShen < $originalShen) {
                    $defendCount++;
                    $messages[] = self::getDefendMessage($defendCount);
                }
            }
            
            // 如果伤害已完全挡下，且第二个法宝也有防御力，继续尝试吸收（但伤害已是0）
            if ($damageQi <= 0 && $damageShen <= 0) {
                break;  // 伤害已完全挡下，不需要继续
            }
        }
        
        return ['defendCount' => $defendCount, 'messages' => $messages];
    }
    
    /**
     * 检查法宝是否可以被装备
     * 
     * @param array $fabao 法宝数据
     * @return array ['can_equip' => bool, 'reason' => string]
     */
    public static function canEquipFabao(array $fabao): array {
        if (!self::isFabao($fabao)) {
            return ['can_equip' => false, 'reason' => '这不是法宝'];
        }
        
        if (isset($fabao['no_equip']) && $fabao['no_equip']) {
            return ['can_equip' => false, 'reason' => '这个法宝不能被装备'];
        }
        
        return ['can_equip' => true, 'reason' => ''];
    }
    
    // ========== 新增方法 ==========
    
    /**
     * 检查是否为真法宝（检查items表的is_real字段）
     * 
     * @param array $item 物品数据
     * @return bool
     */
    public static function isRealFabao($item): bool {
        if (!is_array($item)) {
            return false;
        }
        
        // 优先使用已加载的字段
        if (isset($item['is_real'])) {
            return (bool)$item['is_real'];
        }
        
        // 如果item数据中没有is_real字段，则从数据库查询
        if (!empty($item['item_id'])) {
            $category = $item['category'] ?? '';
            $row = Database::queryOne(
                "SELECT is_real FROM items WHERE item_id = ? AND category = ?",
                [$item['item_id'], $category]
            );
            if ($row) {
                return (bool)($row['is_real'] ?? false);
            }
        }
        
        return false;
    }
    
    /**
     * AP vs DP 成功判定
     * 
     * AP = (经验/1000 + 法术^3/3) * 精神/最大精神 * kar/1000
     * DP 同理计算victim
     * 判定: mt_rand(0, intval(ap+dp)) > intval(dp) 则成功
     * 
     * @param array $attacker 攻击者数据
     * @param array $victim 受害者数据
     * @param array $fabao 法宝数据
     * @return bool 是否成功
     */
    public static function calculateSuccess(array $attacker, array $victim, array $fabao): bool {
        // 计算攻击者的AP
        $mySpells = ($attacker['spells_skill'] ?? 0) / 10;
        $myExp = ($attacker['combat_exp'] ?? 0) / 1000;
        $myKar = $attacker['kar'] ?? 100;
        $mySen = $attacker['sen'] ?? 1;
        $myMaxSen = max(1, $attacker['max_sen'] ?? 1);
        
        $ap = ($myExp + pow($mySpells, 3) / 3) * ($mySen / $myMaxSen);
        $ap = $ap * $myKar / 1000;
        
        // 计算受害者的DP
        $vicSpells = ($victim['spells_skill'] ?? 0) / 10;
        $vicExp = ($victim['combat_exp'] ?? 0) / 1000;
        $vicKar = $victim['kar'] ?? 100;
        $vicSen = $victim['sen'] ?? 1;
        $vicMaxSen = max(1, $victim['max_sen'] ?? 1);
        
        $dp = ($vicExp + pow($vicSpells, 3) / 3) * ($vicSen / $vicMaxSen);
        $dp = $dp * $vicKar / 1000;
        
        $total = intval($ap + $dp);
        if ($total <= 0) {
            return false;
        }
        
        return mt_rand(0, $total) > intval($dp);
    }
    
    /**
     * 检查法宝耐用度
     * 
     * 葫芦/净瓶型: interactive_usage > kar/2 时损坏
     * 绳子型: interactive_usage > kar/3 时损坏
     * 
     * @param int $charId 角色ID
     * @param array $fabao 法宝数据（需包含inventory_id或直接从inventory查询）
     * @return array ['ok' => bool, 'usage' => int, 'limit' => int, 'message' => string]
     */
    public static function checkDurability(int $charId, array $fabao): array {
        // 获取法宝的inventory记录
        $invRow = null;
        if (!empty($fabao['inventory_id'])) {
            $invRow = Database::queryOne(
                "SELECT * FROM character_inventory WHERE id = ?",
                [$fabao['inventory_id']]
            );
        } elseif (!empty($fabao['item_id'])) {
            $invRow = Database::queryOne(
                "SELECT * FROM character_inventory WHERE char_id = ? AND item_id = ? AND category = ? AND equipped = 1",
                [$charId, $fabao['item_id'], $fabao['category'] ?? '']
            );
        }
        
        if (!$invRow) {
            return ['ok' => false, 'usage' => 0, 'limit' => 0, 'message' => '未找到法宝装备记录'];
        }
        
        $usage = intval($invRow['interactive_usage'] ?? 0);
        
        // 获取法宝物品信息以确定类型
        $itemInfo = Database::queryOne(
            "SELECT * FROM items WHERE item_id = ? AND category = ?",
            [$invRow['item_id'], $invRow['category'] ?? '']
        );
        
        // 获取角色的kar属性
        $char = CharacterModel::find($charId);
        $kar = $char['kar'] ?? 30;
        
        // 判断法宝类型：绳子型 trap_type='bind'，葫芦/净瓶型 trap_type='trap'
        $trapType = $itemInfo['trap_type'] ?? 'none';
        
        if ($trapType === 'bind') {
            // 绳子型: interactive_usage > kar/3 时损坏
            $limit = intval($kar / 3);
        } else {
            // 葫芦/净瓶型（默认）: interactive_usage > kar/2 时损坏
            $limit = intval($kar / 2);
        }
        
        $limit = max(1, $limit);
        
        if ($usage > $limit) {
            return [
                'ok' => false,
                'usage' => $usage,
                'limit' => $limit,
                'message' => ($itemInfo['name'] ?? '法宝') . '灵光黯淡，已经无法再使用了。'
            ];
        }
        
        return [
            'ok' => true,
            'usage' => $usage,
            'limit' => $limit,
            'message' => ''
        ];
    }
    
    /**
     * 消耗法宝耐用度
     * interactive_usage += 1，超限则移除法宝
     * 
     * @param int $charId 角色ID
     * @param array $fabao 法宝数据
     * @return array ['removed' => bool, 'usage' => int, 'message' => string]
     */
    public static function consumeDurability(int $charId, array $fabao): array {
        // 获取inventory记录
        $invRow = null;
        if (!empty($fabao['inventory_id'])) {
            $invRow = Database::queryOne(
                "SELECT * FROM character_inventory WHERE id = ?",
                [$fabao['inventory_id']]
            );
        } elseif (!empty($fabao['item_id'])) {
            $invRow = Database::queryOne(
                "SELECT * FROM character_inventory WHERE char_id = ? AND item_id = ? AND category = ? AND equipped = 1",
                [$charId, $fabao['item_id'], $fabao['category'] ?? '']
            );
        }
        
        if (!$invRow) {
            return ['removed' => false, 'usage' => 0, 'message' => '未找到法宝装备记录'];
        }
        
        $newUsage = intval($invRow['interactive_usage'] ?? 0) + 1;
        
        // 更新interactive_usage
        Database::execute(
            "UPDATE character_inventory SET interactive_usage = ? WHERE id = ?",
            [$newUsage, $invRow['id']]
        );
        
        // 检查是否超限
        $fabao['inventory_id'] = $invRow['id'];
        $fabao['item_id'] = $invRow['item_id'];
        $fabao['category'] = $invRow['category'] ?? '';
        $durability = self::checkDurability($charId, $fabao);
        
        if (!$durability['ok']) {
            // 超限：移除法宝（取消装备）
            Database::execute(
                "UPDATE character_inventory SET equipped = 0, being_used = 0 WHERE id = ?",
                [$invRow['id']]
            );
            
            $itemInfo = Database::queryOne(
                "SELECT name FROM items WHERE item_id = ? AND category = ?",
                [$invRow['item_id'], $invRow['category'] ?? '']
            );
            $itemName = $itemInfo['name'] ?? '法宝';
            
            return [
                'removed' => true,
                'usage' => $newUsage,
                'message' => HTML_HIRED . $itemName . '灵光尽散，化为飞灰！' . HTML_NOR
            ];
        }
        
        return ['removed' => false, 'usage' => $newUsage, 'message' => ''];
    }
    
    /**
     * 法宝困人（数据库持久化）
     * 
     * @param array $trapper 施法者数据
     * @param array $victim 受害者数据
     * @param array $fabao 法宝数据
     * @return array ['success' => bool, 'message' => string]
     */
    public static function trapInFabao($trapper, $victim, $fabao): array {
        // 兼容旧接口：如果只传了fabao和victimCharId
        if (is_array($fabao) && is_int($victim)) {
            $victimCharId = $victim;
            $victim = CharacterModel::find($victimCharId);
            if (!$victim) {
                return ['success' => false, 'message' => '目标不存在'];
            }
            if (!self::isFabao($fabao)) {
                return ['success' => false, 'message' => '这不是法宝'];
            }
            if (!isset($fabao['trap_type']) || $fabao['trap_type'] !== 'trap') {
                if (!isset($fabao['trap_ability']) || !$fabao['trap_ability']) {
                    return ['success' => false, 'message' => '这个法宝没有困人能力'];
                }
            }
            $trapper = [];
        }
        
        // 验证法宝
        if (is_array($fabao) && !self::isFabao($fabao)) {
            return ['success' => false, 'message' => '这不是法宝'];
        }
        
        $trapType = $fabao['trap_type'] ?? 'trap';
        if ($trapType !== 'trap') {
            return ['success' => false, 'message' => '这个法宝没有困人能力'];
        }
        
        $victimId = $victim['id'] ?? 0;
        $trapperId = $trapper['id'] ?? 0;
        $trapRatio = intval($fabao['trap_ratio'] ?? 50);
        $trapRatio = max(1, min(99, $trapRatio));
        
        // 检查受害者是否已被困
        if (self::isTrapped($victimId)) {
            return ['success' => false, 'message' => '目标已经被困住了'];
        }
        
        // 检查法宝是否正在被使用
        if (!empty($fabao['being_used'])) {
            return ['success' => false, 'message' => '法宝正在使用中'];
        }
        
        // 计算释放时间: max(60, (50 - victim_kar) * 10) 秒
        $victimKar = $victim['kar'] ?? 30;
        $releaseSeconds = max(60, (50 - $victimKar) * 10);
        $releaseAt = date('Y-m-d H:i:s', time() + $releaseSeconds);
        $now = date('Y-m-d H:i:s');
        
        // 保存被困者当前属性
        $savedGin = $victim['gin'] ?? 100;
        $savedKee = $victim['kee'] ?? 100;
        $savedSen = $victim['sen'] ?? 100;
        $savedEffGin = $victim['eff_gin'] ?? 100;
        $savedEffKee = $victim['eff_kee'] ?? 100;
        $savedEffSen = $victim['eff_sen'] ?? 100;
        
        // 判断是NPC还是玩家
        $isNpc = isset($victim['npc_id']);
        
        // 保存原始位置
        if ($isNpc) {
            // NPC: 原始位置来自spawn_room（格式：area/room）
            $spawnRoom = $victim['spawn_room'] ?? 'city/kezhan';
            $parts = explode('/', $spawnRoom, 2);
            $originalRoomArea = $parts[0] ?? 'city';
            $originalRoomId = $spawnRoom;
        } else {
            $originalRoomArea = $victim['current_area'] ?? 'city';
            $originalRoomId = $victim['current_room'] ?? 'city/kezhan';
        }
        
        // 插入fabao_trap_state记录
        Database::execute(
            "INSERT INTO fabao_trap_state 
                (victim_id, trapper_id, fabao_item_id, trap_type, trapped_at, release_at,
                 saved_gin, saved_kee, saved_sen, saved_eff_gin, saved_eff_kee, saved_eff_sen,
                 original_room_area, original_room_id, is_released)
             VALUES (?, ?, ?, 'trap', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)",
            [
                $victimId, $trapperId, $fabao['item_id'] ?? '',
                $now, $releaseAt,
                $savedGin, $savedKee, $savedSen,
                $savedEffGin, $savedEffKee, $savedEffSen,
                $originalRoomArea, $originalRoomId
            ]
        );
        
        // 将被困者移入虚拟房间
        $fabaoRoomId = 'fabao_' . ($fabao['item_id'] ?? 'unknown');
        
        if ($isNpc) {
            // NPC：通过npc_temp表移动到虚拟房间（getNpcsInRoom会自动过滤掉）
            $virtualRoomJson = json_encode(['area' => 'fabao', 'room' => $fabaoRoomId]);
            Database::execute(
                "INSERT INTO npc_temp (npc_id, temp_key, temp_value, updated_at) 
                 VALUES (?, 'current_location', ?, ?)
                 ON DUPLICATE KEY UPDATE temp_value = ?, updated_at = ?",
                [$victimId, $virtualRoomJson, time(), $virtualRoomJson, time()]
            );
        } else {
            // 玩家：更新characters表
            Database::execute(
                "UPDATE characters SET current_area = 'fabao', current_room = ? WHERE id = ?",
                [$fabaoRoomId, $victimId]
            );
            
            // 降低被困者属性至trap_ratio%（仅对玩家有效）
            Database::execute(
                "UPDATE characters SET 
                    gin = gin * ? / 100,
                    kee = kee * ? / 100,
                    sen = sen * ? / 100
                 WHERE id = ?",
                [$trapRatio, $trapRatio, $trapRatio, $victimId]
            );
        }
        
        // 标记法宝being_used=1 并增加interactive_usage
        self::markFabaoUsed($trapperId, $fabao, 1);
        
        $victimName = $victim['name'] ?? '某人';
        $fabaoName = $fabao['item_name'] ?? $fabao['name'] ?? '法宝';
        
        return [
            'success' => true,
            'message' => HTML_HICYN . $victimName . '被吸入' . $fabaoName . '中！' . HTML_NOR,
            'release_at' => $releaseAt,
            'release_seconds' => $releaseSeconds
        ];
    }
    
    /**
     * 法宝束缚（不移动位置）
     * 
     * @param array $trapper 施法者数据
     * @param array $victim 受害者数据
     * @param array $fabao 法宝数据
     * @return array ['success' => bool, 'message' => string]
     */
    public static function bindWithFabao(array $trapper, array $victim, array $fabao): array {
        if (!self::isFabao($fabao)) {
            return ['success' => false, 'message' => '这不是法宝'];
        }
        
        $trapType = $fabao['trap_type'] ?? 'none';
        if ($trapType !== 'bind') {
            return ['success' => false, 'message' => '这个法宝没有束缚能力'];
        }
        
        $victimId = $victim['id'] ?? 0;
        $trapperId = $trapper['id'] ?? 0;
        
        // 检查受害者是否已被困
        if (self::isTrapped($victimId)) {
            return ['success' => false, 'message' => '目标已经被束缚住了'];
        }
        
        // 检查法宝是否正在被使用
        if (!empty($fabao['being_used'])) {
            return ['success' => false, 'message' => '法宝正在使用中'];
        }
        
        // 计算释放时间: max(10, intval((50 - victim_kar) / 2)) 秒
        $victimKar = $victim['kar'] ?? 30;
        $releaseSeconds = max(10, intval((50 - $victimKar) / 2));
        $releaseAt = date('Y-m-d H:i:s', time() + $releaseSeconds);
        $now = date('Y-m-d H:i:s');
        
        // 保存当前属性
        $savedGin = $victim['gin'] ?? 100;
        $savedKee = $victim['kee'] ?? 100;
        $savedSen = $victim['sen'] ?? 100;
        $savedEffGin = $victim['eff_gin'] ?? 100;
        $savedEffKee = $victim['eff_kee'] ?? 100;
        $savedEffSen = $victim['eff_sen'] ?? 100;
        
        // 保存原始位置（束缚不移动，但仍记录）
        $originalRoomArea = $victim['current_area'] ?? 'city';
        $originalRoomId = $victim['current_room'] ?? 'city/kezhan';
        
        // 插入fabao_trap_state记录
        Database::execute(
            "INSERT INTO fabao_trap_state 
                (victim_id, trapper_id, fabao_item_id, trap_type, trapped_at, release_at,
                 saved_gin, saved_kee, saved_sen, saved_eff_gin, saved_eff_kee, saved_eff_sen,
                 original_room_area, original_room_id, is_released)
             VALUES (?, ?, ?, 'bind', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)",
            [
                $victimId, $trapperId, $fabao['item_id'] ?? '',
                $now, $releaseAt,
                $savedGin, $savedKee, $savedSen,
                $savedEffGin, $savedEffKee, $savedEffSen,
                $originalRoomArea, $originalRoomId
            ]
        );
        
        // 标记法宝being_used=1
        self::markFabaoUsed($trapperId, $fabao, 1);
        
        $victimName = $victim['name'] ?? '某人';
        $fabaoName = $fabao['item_name'] ?? $fabao['name'] ?? '法宝';
        
        return [
            'success' => true,
            'message' => HTML_HIYEL . $fabaoName . '飞出，将' . $victimName . '牢牢缚住！' . HTML_NOR,
            'release_at' => $releaseAt,
            'release_seconds' => $releaseSeconds
        ];
    }
    
    /**
     * 从法宝中释放被困者（从数据库读取）
     * 
     * @param int $victimId 受害者角色ID
     * @return array ['success' => bool, 'message' => string]
     */
    public static function releaseFromFabao(int $victimId): array {
        // 从fabao_trap_state读取被困记录
        $trapData = Database::queryOne(
            "SELECT * FROM fabao_trap_state WHERE victim_id = ? AND is_released = 0 ORDER BY id DESC LIMIT 1",
            [$victimId]
        );
        
        if (!$trapData) {
            return ['success' => false, 'message' => '没有被法宝困住'];
        }
        
        $victim = CharacterModel::find($victimId);
        $isNpc = false;
        if (!$victim) {
            // 检查是否在npcs表中（NPC/妖怪）
            $npc = Database::queryOne("SELECT * FROM npcs WHERE id = ?", [$victimId]);
            if ($npc) {
                $isNpc = true;
                $victim = $npc;
            } else {
                // 受害者既不是玩家也不是已知NPC，直接标记已释放
                Database::execute(
                    "UPDATE fabao_trap_state SET is_released = 1 WHERE id = ?",
                    [$trapData['id']]
                );
                // 清除法宝being_used标记
                self::markFabaoUsed(
                    intval($trapData['trapper_id'] ?? 0),
                    ['item_id' => $trapData['fabao_item_id'] ?? ''],
                    0
                );
                return ['success' => false, 'message' => '目标已不存在'];
            }
        }
        
        $trapType = $trapData['trap_type'] ?? 'trap';
        $isPlayer = !$isNpc;
        
        if ($isPlayer) {
            // 恢复属性（仅玩家，NPC属性由npcs表管理）
            // 玩家：恢复至保存值的25%
            $restoreRatio = 0.25;
            $restoreGin = intval($trapData['saved_gin'] * $restoreRatio);
            $restoreKee = intval($trapData['saved_kee'] * $restoreRatio);
            $restoreSen = intval($trapData['saved_sen'] * $restoreRatio);
            
            // 确保恢复值至少为1
            $restoreGin = max(1, $restoreGin);
            $restoreKee = max(1, $restoreKee);
            $restoreSen = max(1, $restoreSen);
            
            Database::execute(
                "UPDATE characters SET 
                    gin = ?, kee = ?, sen = ?
                 WHERE id = ?",
                [$restoreGin, $restoreKee, $restoreSen, $victimId]
            );
        }
        
        // 将角色移回original_room（仅trap类型需要，bind没有移动位置）
        if ($trapType === 'trap') {
            $origArea = $trapData['original_room_area'] ?? 'city';
            $origRoom = $trapData['original_room_id'] ?? 'city/kezhan';
            
            if ($isNpc) {
                // NPC：删除npc_temp记录，使其恢复spawn_room位置
                Database::execute(
                    "DELETE FROM npc_temp WHERE npc_id = ? AND temp_key = 'current_location'",
                    [$victimId]
                );
            } else {
                // 玩家：更新characters表位置
                Database::execute(
                    "UPDATE characters SET current_area = ?, current_room = ? WHERE id = ?",
                    [$origArea, $origRoom, $victimId]
                );
            }
        }
        
        // 标记is_released=1
        Database::execute(
            "UPDATE fabao_trap_state SET is_released = 1 WHERE id = ?",
            [$trapData['id']]
        );
        
        // 清除法宝being_used标记
        self::markFabaoUsed($trapData['trapper_id'] ?? 0, ['item_id' => $trapData['fabao_item_id']], 0);
        
        $victimName = $victim['name'] ?? '某人';
        
        if ($trapType === 'bind') {
            $msg = HTML_HICYN . $victimName . '身上的束缚消失了！' . HTML_NOR;
        } else {
            $msg = HTML_HICYN . $victimName . '从法宝中被释放出来！' . HTML_NOR;
        }
        
        // === 释放后检查是否需要恢复战斗 ===
        $trapperId = intval($trapData['trapper_id'] ?? 0);
        if ($trapperId > 0) {
            // 查找施法者（先查玩家，再查NPC）
            $trapper = Database::queryOne("SELECT * FROM characters WHERE id = ?", [$trapperId]);
            $trapperType = 'player';
            if (!$trapper) {
                $trapper = Database::queryOne("SELECT * FROM npcs WHERE id = ?", [$trapperId]);
                $trapperType = 'npc';
            }
            
            if ($trapper && ($trapper['gin'] ?? 0) > 0 && ($trapper['kee'] ?? 0) > 0) {
                // 检查施法者是否仍在被困者现在的房间
                $victimCurrent = CharacterModel::find($victimId);
                if ($victimCurrent) {
                    $victimArea = $victimCurrent['current_area'] ?? '';
                    $victimRoom = $victimCurrent['current_room'] ?? '';
                    $trapperArea = $trapper['current_area'] ?? '';
                    $trapperRoom = $trapper['current_room'] ?? '';
                    
                    if ($victimArea === $trapperArea && $victimRoom === $trapperRoom) {
                        // 双方在同一房间且都存活，恢复战斗
                        // 先清理可能残留的战斗记录
                        Database::execute(
                            "DELETE FROM active_combats WHERE char_id = ? OR target_id = ?",
                            [$victimId, $victimId]
                        );
                        if ($trapperType === 'player') {
                            Database::execute(
                                "DELETE FROM active_combats WHERE char_id = ? OR target_id = ?",
                                [$trapperId, $trapperId]
                            );
                        }
                        
                        // 判断被困者类型
                        $victimType = (empty($victim['is_npc']) || !$victim['is_npc']) ? 'player' : 'npc';
                        
                        // 计算双方最大血量
                        if ($victimType === 'player') {
                            $victimMaxHp = $victimCurrent['max_kee'] ?? 100;
                        } else {
                            $victimMaxHp = max(100, intval($victimCurrent['max_kee'] ?? 100));
                        }

                        if ($trapperType === 'player') {
                            $trapperMaxHp = $trapper['max_kee'] ?? 100;
                        } else {
                            $trapperMaxHp = max(100, intval($trapper['max_kee'] ?? 100));
                        }
                        
                        // 插入战斗记录（被困者视角）
                        Database::execute(
                            "INSERT INTO active_combats (char_id, target_id, target_type, target_current_hp, target_max_hp, is_friendly) 
                             VALUES (?, ?, ?, ?, ?, 0)",
                            [$victimId, $trapperId, $trapperType, $trapperMaxHp, $trapperMaxHp]
                        );
                        
                        // 插入战斗记录（施法者视角，仅施法者是玩家时）
                        if ($trapperType === 'player') {
                            Database::execute(
                                "INSERT INTO active_combats (char_id, target_id, target_type, target_current_hp, target_max_hp, is_friendly) 
                                 VALUES (?, ?, ?, ?, ?, 0)",
                                [$trapperId, $victimId, $victimType, $victimMaxHp, $victimMaxHp]
                            );
                        }
                        
                        // 广播消息
                        require_once __DIR__ . '/../daemons/MessageDaemon.php';
                        $trapperName = $trapper['name'] ?? '某人';
                        $roomId = $victimArea . '/' . $victimRoom;
                        $resumeMsg = HTML_HICYN . $victimName . '从法宝中脱出，与' . $trapperName . '再次对峙！' . HTML_NOR;
                        MessageDaemon::broadcastToRoom($roomId, $resumeMsg);
                        
                        $msg .= "\n" . $resumeMsg;
                    }
                }
            }
        }
        
        return ['success' => true, 'message' => $msg];
    }
    
    /**
     * 检查并释放已过期的被困者
     * 查询release_at已过期且is_released=0的记录，逐一释放
     * 
     * @return array 释放结果列表
     */
    public static function checkAndReleaseExpired(): array {
        $now = date('Y-m-d H:i:s');
        
        $expiredRecords = Database::queryAll(
            "SELECT * FROM fabao_trap_state WHERE release_at <= ? AND is_released = 0",
            [$now]
        );
        
        $results = [];
        
        foreach ($expiredRecords as $record) {
            $victimId = intval($record['victim_id']);
            $result = self::releaseFromFabao($victimId);
            $results[] = [
                'victim_id' => $victimId,
                'trap_type' => $record['trap_type'] ?? 'unknown',
                'success' => $result['success'],
                'message' => $result['message']
            ];
        }
        
        return $results;
    }
    
    /**
     * 检查角色是否被困/束缚
     * 
     * @param int $charId 角色ID
     * @return array|false 被困信息数组或false
     */
    public static function isTrapped(int $charId) {
        $trapData = Database::queryOne(
            "SELECT * FROM fabao_trap_state WHERE victim_id = ? AND is_released = 0 ORDER BY id DESC LIMIT 1",
            [$charId]
        );
        
        if (!$trapData) {
            return false;
        }
        
        // ★ 检查是否已过期，过期则自动释放（等价于LPC的call_out("releasing")）
        $now = date('Y-m-d H:i:s');
        if ($trapData['release_at'] <= $now) {
            // 过期了，执行释放并清除being_used
            self::releaseFromFabao($charId);
            return false;
        }
        
        return $trapData;
    }
    
    /**
     * 验证法宝使用前置条件
     * 
     * @param array $char 角色数据
     * @param array $fabao 法宝数据
     * @return array ['can_use' => bool, 'reason' => string]
     */
    public static function canUseFabao(array $char, array $fabao): array {
        if (!self::isFabao($fabao)) {
            return ['can_use' => false, 'reason' => '这不是法宝'];
        }
        
        // 检查法力(gin) >= 500
        $gin = $char['gin'] ?? 0;
        if ($gin < 500) {
            return ['can_use' => false, 'reason' => '你的法力不足，无法驱动法宝。'];
        }
        
        // 检查精神(sen) >= 500
        $sen = $char['sen'] ?? 0;
        if ($sen < 500) {
            return ['can_use' => false, 'reason' => '你的精神不足，无法驱动法宝。'];
        }
        
        // 检查法宝是否已被使用
        if (!empty($fabao['being_used'])) {
            return ['can_use' => false, 'reason' => '法宝正在使用中，无法再次发动。'];
        }
        
        return ['can_use' => true, 'reason' => ''];
    }
    
    // ========== 私有辅助方法 ==========
    
    /**
     * 标记法宝being_used状态
     * 
     * @param int $charId 持有者ID（用于定位inventory记录）
     * @param array $fabao 法宝数据
     * @param int $value being_used值(0或1)
     */
    private static function markFabaoUsed(int $charId, array $fabao, int $value): void {
        $itemId = $fabao['item_id'] ?? '';
        if (empty($itemId)) {
            return;
        }
        
        if (!empty($fabao['inventory_id'])) {
            Database::execute(
                "UPDATE character_inventory SET being_used = ? WHERE id = ?",
                [$value, $fabao['inventory_id']]
            );
        } else {
            // 通过char_id + item_id + category定位
            // ★ 如果传了category就精确匹配，否则不限制category
            //    （releaseFromFabao只传item_id没传category，修复being_used清不掉的问题）
            $category = $fabao['category'] ?? '';
            if ($category !== '') {
                Database::execute(
                    "UPDATE character_inventory SET being_used = ? WHERE char_id = ? AND item_id = ? AND category = ? AND equipped = 1",
                    [$value, $charId, $itemId, $category]
                );
            } else {
                Database::execute(
                    "UPDATE character_inventory SET being_used = ? WHERE char_id = ? AND item_id = ? AND equipped = 1",
                    [$value, $charId, $itemId]
                );
            }
        }
    }

    // ========== 自造法宝战斗攻击（ji 四阶段判定） ==========

    /**
     * 自造法宝战斗攻击（还原 LPC obj/fabao.c ji() 四阶段判定）
     * 
     * Stage 1: 道行对抗 — 防守方道行足够则完全免疫
     * Stage 2: 经验闪避 — 防守方 combat_exp/3 作为有效道行尝试闪避
     * Stage 3: 法力收取 — 防守方法力足够时，尝试用防御法宝收取攻击法宝
     * Stage 4: 命中伤害 — 攻击成功，计算伤害
     * 
     * @param array $attacker 攻击者数据（需包含: id, daoxing, mana, mana_factor, combat_exp, name, gin, force）
     * @param array $defender 防守者数据（需包含: id, daoxing, mana, mana_factor, combat_exp, name, gin, force）
     * @param array $fabao 武器型法宝数据（需包含: attack_qi, max_attack_qi, attack_shen, max_attack_shen, name, series_no）
     * @param string $defenderType 防守者类型 'npc'|'player'
     * @return array ['success' => bool, 'stage' => int, 'messages' => string[], 'damage' => int, 'damage_type' => string, 'fabao_damaged' => bool]
     */
    public static function fabaoJiAttack(array $attacker, array $defender, array $fabao, string $defenderType = 'npc'): array {
        $messages = [];
        $attackerName = $attacker['name'] ?? '某人';
        $defenderName = $defender['name'] ?? '目标';
        $fabaoName = $fabao['name'] ?? '法宝';
        
        // === 前置检查 ===
        // 检查攻击法宝充能（至少需要 attack_qi 或 attack_shen 有充能值）
        $attackQi = intval($fabao['attack_qi'] ?? 0);
        $attackShen = intval($fabao['attack_shen'] ?? 0);
        if ($attackQi <= 0 && $attackShen <= 0) {
            return [
                'success' => false,
                'stage' => 0,
                'messages' => [HTML_HIRED . $fabaoName . '灵气不足，无法攻击！请先充能。' . HTML_NOR],
                'damage' => 0,
                'damage_type' => '',
                'fabao_damaged' => false
            ];
        }
        
        // 获取攻防属性
        $aDx = intval($attacker['daoxing'] ?? 0);
        $aFali = intval($attacker['mana'] ?? 0);
        $aEnchant = intval($attacker['mana_factor'] ?? 0);
        
        $dDx = intval($defender['daoxing'] ?? 0);
        $dExp = intval($defender['combat_exp'] ?? 0);
        $dFali = intval($defender['mana'] ?? 0);
        $dEnchant = intval($defender['mana_factor'] ?? 0);
        
        // ===== Stage 1: 道行对抗 =====
        // 公式: d_dx * 100 / (a_dx + d_dx) > random(100)
        // 防守方道行足够 → 攻击完全无效
        $dxDenom = $aDx + $dDx;
        if ($dxDenom > 0) {
            $dxChance = intval($dDx * 100 / $dxDenom);
            if ($dxChance > mt_rand(0, 99)) {
                $messages[] = HIW . "结果{$defenderName}轻一挥手，嘿嘿笑了几声：想跟我斗？再去修个三五百年吧！" . NOR;
                $messages[] = HIW . "只见{$fabaoName}几个翻滚，又回到了{$attackerName}的手中。" . NOR;
                return [
                    'success' => false,
                    'stage' => 1,
                    'messages' => $messages,
                    'damage' => 0,
                    'damage_type' => '',
                    'fabao_damaged' => false
                ];
            }
        }
        
        // ===== Stage 2: 经验闪避 =====
        // 公式: (d_exp/3) * 100 / (a_dx + d_exp/3) > random(100)
        $dEffDx = intval($dExp / 3);
        $expDenom = $aDx + $dEffDx;
        if ($expDenom > 0 && $dEffDx > 0) {
            $expChance = intval($dEffDx * 100 / $expDenom);
            if ($expChance > mt_rand(0, 99)) {
                $messages[] = HIC . "结果{$defenderName}身形急闪，躲过了{$fabaoName}的攻势。" . NOR;
                return [
                    'success' => false,
                    'stage' => 2,
                    'messages' => $messages,
                    'damage' => 0,
                    'damage_type' => '',
                    'fabao_damaged' => false
                ];
            }
        }
        
        // ===== Stage 3: 法力收取（防守方尝试用法宝收走攻击法宝） =====
        // 公式: d_fali * 100 / (a_fali + d_fali) > random(100)
        $faliDenom = $aFali + $dFali;
        $fabaoDamaged = false;
        $fabaoStolen = false;
        
        if ($faliDenom > 0 && $dFali > 0) {
            $faliChance = intval($dFali * 100 / $faliDenom);
            if ($faliChance > mt_rand(0, 99)) {
                // 防守方尝试用法宝收取
                $messages[] = HIC . "{$defenderName}哼了一声：米粒之珠，也放光华？看我的法宝！" . NOR;
                
                // 查询防守方的防具型法宝（series_no != 1，最多2个）
                $dFabaoRows = Database::queryAll(
                    "SELECT * FROM character_fabao WHERE owner_id = ? AND fabao_type = 'armor' AND equipped = 1",
                    [$defender['id']]
                );
                
                $dFabao1 = $dFabaoRows[0] ?? null;
                $dFabao2 = $dFabaoRows[1] ?? null;
                $dFabaoPower = 0;
                
                if ($dFabao1) {
                    $dFabaoPower += intval($dFabao1['max_defense_shou'] ?? 0);
                    $messages[] = HIW . "只见霞光一闪，{$defenderName}的{$dFabao1['name']}已跟{$fabaoName}斗在一起！" . NOR;
                }
                if ($dFabao2) {
                    $dFabaoPower += intval($dFabao2['max_defense_shou'] ?? 0);
                    $messages[] = HIW . "只见霞光一闪，{$defenderName}的{$dFabao2['name']}已跟{$fabaoName}斗在一起！" . NOR;
                }
                
                if ($dFabaoPower > 0) {
                    $aFabaoPower = intval($fabao['max_attack_qi'] ?? 0) + intval($fabao['max_attack_shen'] ?? 0);
                    
                    if ($aFabaoPower > $dFabaoPower) {
                        // 攻击法宝更强：可能损坏防御法宝
                        if (mt_rand(0, max(1, $aFabaoPower - $dFabaoPower) - 1) > 3) {
                            $damagedFabao = self::damageDefenseFabao($dFabao1, $dFabao2, $defender['id']);
                            if ($damagedFabao) {
                                $messages[] = HIC . "结果血光大盛，{$damagedFabao}发出一声哀鸣退了下来。" . NOR;
                                $fabaoDamaged = true;
                            }
                            return [
                                'success' => false,
                                'stage' => 3,
                                'messages' => $messages,
                                'damage' => 0,
                                'damage_type' => '',
                                'fabao_damaged' => $fabaoDamaged
                            ];
                        }
                    } elseif ($dFabaoPower > $aFabaoPower) {
                        // 防御法宝更强：可能损坏并收走攻击法宝
                        if (mt_rand(0, max(1, $dFabaoPower - $aFabaoPower) - 1) > 3) {
                            // 损坏攻击法宝
                            self::damageAttackFabao($fabao);
                            // 卸下攻击法宝
                            Database::execute(
                                "UPDATE character_fabao SET equipped = 0 WHERE id = ?",
                                [$fabao['id']]
                            );
                            $messages[] = HIC . "结果血光大盛，{$fabaoName}发出一声哀鸣，居然被{$defenderName}收了过去！" . NOR;
                            return [
                                'success' => false,
                                'stage' => 3,
                                'messages' => $messages,
                                'damage' => 0,
                                'damage_type' => '',
                                'fabao_damaged' => true,
                                'fabao_stolen' => true
                            ];
                        }
                    }
                    
                    // 旗鼓相当，各自收回
                    $messages[] = HIC . "结果双方的法宝斗了个旗鼓相当，只好各自收回。" . NOR;
                    return [
                        'success' => false,
                        'stage' => 3,
                        'messages' => $messages,
                        'damage' => 0,
                        'damage_type' => '',
                        'fabao_damaged' => false
                    ];
                }
            }
        }
        
        // ===== Stage 4: 命中伤害 =====
        // LPC 公式:
        //   气攻: damage = attack_qi * attacker.spi + a_enchant - (d_enchant/2 + random(d_enchant/2))
        //   神攻: damage = attack_shen * 30 + a_enchant - (d_enchant/2 + random(d_enchant/2))
        //   双攻: damage = attack_shen * 30 + a_enchant - (d_enchant/2 + random(d_enchant/2))
        // 充能消耗后清零
        
        $dEnchantDef = intval($dEnchant / 2) + mt_rand(0, intval($dEnchant / 2));
        $hitMsg = HIC . "结果{$fabaoName}打了个正着！" . NOR;
        
        if ($attackQi > $attackShen) {
            // 气攻为主
            $attackerSpi = intval($attacker['spi'] ?? $attacker['gin'] ?? 10);
            $damage = $attackQi * $attackerSpi + $aEnchant - $dEnchantDef;
            $damageType = 'qi';
            
            // 消耗充能
            Database::execute(
                "UPDATE character_fabao SET attack_qi = 0 WHERE id = ?",
                [$fabao['id']]
            );
            
            $messages[] = $hitMsg;
            
            if ($damage > 0) {
                return [
                    'success' => true,
                    'stage' => 4,
                    'messages' => $messages,
                    'damage' => $damage,
                    'damage_type' => $damageType,
                    'fabao_damaged' => false
                ];
            }
        } elseif ($attackShen > $attackQi) {
            // 神攻为主
            $damage = $attackShen * 30 + $aEnchant - $dEnchantDef;
            $damageType = 'shen';
            
            // 消耗充能
            Database::execute(
                "UPDATE character_fabao SET attack_shen = 0 WHERE id = ?",
                [$fabao['id']]
            );
            
            $messages[] = $hitMsg;
            
            if ($damage > 0) {
                return [
                    'success' => true,
                    'stage' => 4,
                    'messages' => $messages,
                    'damage' => $damage,
                    'damage_type' => $damageType,
                    'fabao_damaged' => false
                ];
            }
        } else {
            // 气神双攻
            $damage = $attackShen * 30 + $aEnchant - $dEnchantDef;
            $damageType = 'both';
            
            // 消耗双充能
            Database::execute(
                "UPDATE character_fabao SET attack_qi = 0, attack_shen = 0 WHERE id = ?",
                [$fabao['id']]
            );
            
            $messages[] = $hitMsg;
            
            if ($damage > 0) {
                return [
                    'success' => true,
                    'stage' => 4,
                    'messages' => $messages,
                    'damage' => $damage,
                    'damage_type' => $damageType,
                    'fabao_damaged' => false
                ];
            }
        }
        
        // 伤害 <= 0，毫发无伤
        $messages[] = HIC . "结果{$defenderName}硬受{$fabaoName}一记，却是毫发无伤！" . NOR;
        return [
            'success' => false,
            'stage' => 4,
            'messages' => $messages,
            'damage' => 0,
            'damage_type' => '',
            'fabao_damaged' => false
        ];
    }
    
    /**
     * 损坏防御法宝（随机降低防御属性上限）
     * 还原 LPC: random(2)==0 时降 1 级
     */
    private static function damageDefenseFabao(?array $dFabao1, ?array $dFabao2, int $ownerId): ?string {
        $targetFabao = null;
        
        // 优先损坏 dFabao2（如果有2个防具），否则 dFabao1
        if ($dFabao2) {
            $targetFabao = $dFabao2;
        } elseif ($dFabao1) {
            $targetFabao = $dFabao1;
        }
        
        if (!$targetFabao) {
            return null;
        }
        
        $updates = [];
        
        // LPC: if max_defense_qi > 1 && random(2) == 0 → max_defense_qi -= 1
        if (intval($targetFabao['max_defense_qi'] ?? 0) > 1 && mt_rand(0, 1) === 0) {
            $updates[] = 'max_defense_qi = GREATEST(1, max_defense_qi - 1)';
        }
        // LPC: if max_defense_shen > 1 && random(2) == 0 → max_defense_shen -= 1
        if (intval($targetFabao['max_defense_shen'] ?? 0) > 1 && mt_rand(0, 1) === 0) {
            $updates[] = 'max_defense_shen = GREATEST(1, max_defense_shen - 1)';
        }
        // LPC: if max_defense_shou > 1 && random(2) == 0 → max_defense_shou -= 1
        if (intval($targetFabao['max_defense_shou'] ?? 0) > 1 && mt_rand(0, 1) === 0) {
            $updates[] = 'max_defense_shou = GREATEST(1, max_defense_shou - 1)';
        }
        
        if (!empty($updates)) {
            $sql = "UPDATE character_fabao SET " . implode(', ', $updates) . " WHERE id = ?";
            Database::execute($sql, [$targetFabao['id']]);
            
            // 卸下受损法宝
            Database::execute(
                "UPDATE character_fabao SET equipped = 0 WHERE id = ?",
                [$targetFabao['id']]
            );
        }
        
        return $targetFabao['name'] ?? null;
    }
    
    /**
     * 损坏攻击法宝（降低攻击属性上限）
     * 还原 LPC: max_attack_qi > 1 → -1, max_attack_shen > 1 → -1
     */
    private static function damageAttackFabao(array $fabao): void {
        $updates = [];
        
        if (intval($fabao['max_attack_qi'] ?? 0) > 1) {
            $updates[] = 'max_attack_qi = GREATEST(1, max_attack_qi - 1)';
        }
        if (intval($fabao['max_attack_shen'] ?? 0) > 1) {
            $updates[] = 'max_attack_shen = GREATEST(1, max_attack_shen - 1)';
        }
        
        if (!empty($updates)) {
            $sql = "UPDATE character_fabao SET " . implode(', ', $updates) . " WHERE id = ?";
            Database::execute($sql, [$fabao['id']]);
        }
    }

    // ========== 金刚琢套走装备功能 ==========

    /**
     * 套走目标武器/装备（金刚琢特殊功能）
     * 
     * @param array $attacker 攻击者数据
     * @param array $victim 受害者数据
     * @param array $fabao 法宝数据
     * @return array ['success' => bool, 'stolen_item' => array|null, 'message' => string]
     */
    public static function stealWeapon(array $attacker, array $victim, array $fabao): array {
        $victimId = $victim['id'] ?? 0;
        $victimType = $victim['is_npc'] ?? false ? 'npc' : 'player';
        $victimName = $victim['name'] ?? '目标';
        
        // 1. 检查受害者是否有装备武器
        $equippedWeapon = self::getVictimEquippedWeapon($victimId, $victimType);
        if (!$equippedWeapon) {
            return [
                'success' => false,
                'stolen_item' => null,
                'message' => HTML_HIRED . $victimName . '身上没有装备武器，金刚琢无法套取！' . HTML_NOR
            ];
        }
        
        // 2. 检查法宝容器（金刚琢）是否还有空间
        $fabaoInventoryId = $fabao['inventory_id'] ?? 0;
        if (!$fabaoInventoryId) {
            // 如果没有inventory_id，尝试通过char_id和item_id查找
            $invRow = Database::queryOne(
                "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = ? AND category = ? AND equipped = 1",
                [$attacker['id'] ?? 0, $fabao['item_id'] ?? '', $fabao['category'] ?? '']
            );
            $fabaoInventoryId = $invRow['id'] ?? 0;
        }
        
        if (!$fabaoInventoryId) {
            return [
                'success' => false,
                'stolen_item' => null,
                'message' => HTML_HIRED . '无法定位金刚琢装备记录！' . HTML_NOR
            ];
        }
        
        // 3. 从受害者身上移除装备
        $removeResult = self::removeWeaponFromVictim($victimId, $victimType, $equippedWeapon);
        if (!$removeResult['success']) {
            return [
                'success' => false,
                'stolen_item' => null,
                'message' => $removeResult['message']
            ];
        }
        
        // 4. 将装备存入金刚琢容器
        $storeResult = self::storeItemInFabaoContainer($fabaoInventoryId, $equippedWeapon, $victimId, $victimType, $victimName);
        if (!$storeResult['success']) {
            return [
                'success' => false,
                'stolen_item' => null,
                'message' => $storeResult['message']
            ];
        }
        
        return [
            'success' => true,
            'stolen_item' => $equippedWeapon,
            'message' => HTML_HIYEL . '金刚琢金光一闪，' . $victimName . '的' . $equippedWeapon['name'] . '被套入了金刚琢之中！' . HTML_NOR
        ];
    }

    /**
     * 获取受害者装备的武器
     */
    private static function getVictimEquippedWeapon(int $victimId, string $victimType): ?array {
        if ($victimType === 'npc') {
            // NPC装备查询
            $weapon = Database::queryOne(
                "SELECT ne.*, i.name, i.item_id, i.type, i.weapon_damage, i.value
                 FROM npc_equipment ne
                 JOIN items i ON ne.item_id = i.item_id AND ne.category = i.category
                 WHERE ne.npc_id = ? AND ne.equip_slot = 'weapon' AND ne.worn = 1",
                [$victimId]
            );
            return $weapon ?: null;
        } else {
            // 玩家装备查询
            $weapon = Database::queryOne(
                "SELECT ci.*, i.name, i.item_id, i.type, i.weapon_damage, i.value
                 FROM character_inventory ci
                 JOIN items i ON ci.item_id = i.item_id AND ci.category = i.category
                 WHERE ci.char_id = ? AND ci.equipped = 1 AND ci.equip_slot = 'weapon'",
                [$victimId]
            );
            return $weapon ?: null;
        }
    }

    /**
     * 从受害者身上移除武器
     */
    private static function removeWeaponFromVictim(int $victimId, string $victimType, array $weapon): array {
        if ($victimType === 'npc') {
            // NPC：更新npc_equipment表
            Database::execute(
                "UPDATE npc_equipment SET worn = 0 WHERE npc_id = ? AND item_id = ? AND category = ? AND equip_slot = 'weapon'",
                [$victimId, $weapon['item_id'], $weapon['category'] ?? '']
            );
        } else {
            // 玩家：更新character_inventory表
            Database::execute(
                "UPDATE character_inventory SET equipped = 0, equip_slot = '' WHERE char_id = ? AND item_id = ? AND category = ?",
                [$victimId, $weapon['item_id'], $weapon['category'] ?? '']
            );
        }
        
        return ['success' => true, 'message' => ''];
    }

    /**
     * 将物品存入法宝容器
     */
    private static function storeItemInFabaoContainer(int $fabaoInventoryId, array $item, int $ownerId, string $ownerType, string $ownerName): array {
        // 插入到法宝容器表
        $result = Database::execute(
            "INSERT INTO fabao_container_items (fabao_inventory_id, item_id, item_name, item_type, 
             original_owner_type, original_owner_id, original_owner_name, is_equipped, equip_slot, durability)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $fabaoInventoryId,
                $item['item_id'],
                $item['name'],
                $item['type'] ?? 'weapon',
                $ownerType,
                $ownerId,
                $ownerName,
                $item['equipped'] ?? 0,
                $item['equip_slot'] ?? '',
                $item['durability'] ?? 100
            ]
        );
        
        if ($result) {
            return ['success' => true, 'message' => ''];
        } else {
            return [
                'success' => false,
                'message' => HTML_HIRED . '法宝容器存储失败！' . HTML_NOR
            ];
        }
    }

    /**
     * 获取法宝容器中的物品列表
     */
    public static function getFabaoContainerItems(int $fabaoInventoryId): array {
        return Database::queryAll(
            "SELECT * FROM fabao_container_items WHERE fabao_inventory_id = ? ORDER BY steal_time DESC",
            [$fabaoInventoryId]
        );
    }

    /**
     * 从法宝容器中取出物品
     */
    public static function retrieveItemFromFabao(int $fabaoInventoryId, int $containerItemId, int $targetCharId): array {
        // 获取容器物品信息
        $containerItem = Database::queryOne(
            "SELECT * FROM fabao_container_items WHERE id = ? AND fabao_inventory_id = ?",
            [$containerItemId, $fabaoInventoryId]
        );
        
        if (!$containerItem) {
            return ['success' => false, 'message' => '未找到该物品！'];
        }
        
        // 将物品添加到目标角色背包
        $addResult = ItemModel::addToInventory($targetCharId, $containerItem['item_id'], 1, $containerItem['category'] ?? '');
        if (!$addResult) {
            return ['success' => false, 'message' => '背包空间不足！'];
        }
        
        // 从容器中删除
        Database::execute(
            "DELETE FROM fabao_container_items WHERE id = ?",
            [$containerItemId]
        );
        
        return [
            'success' => true,
            'message' => HTML_HIGRN . '你从金刚琢中取出了：' . $containerItem['item_name'] . HTML_NOR
        ];
    }
}
