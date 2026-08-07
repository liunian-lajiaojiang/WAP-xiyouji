<?php
/**
 * 金兜山区域事件处理器
 * 
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 核心机制：
 * 1. 青牛精战斗：独角兕大王周期性使用金刚琢攻击玩家
 * 2. Boss死亡：青牛精死亡时现原形，掉落金刚琢，召唤太上老君
 * 3. 任务完成：太上老君验证条件后设置obstacle/jindou="done"
 * 4. 金丹挖掘：洞内4号房间可挖掘金丹（10%成功率）
 * 
 * obstacle/jindou 状态流转：
 *   (未设置) → done (击败青牛精且满足条件)
 */

require_once __DIR__ . '/ActionHandler.php';

class JindouHandler extends ActionHandler
{
    // ==================== 常量定义（保留作为默认值） ====================

    /** 青牛精NPC的 npc_id 列表 */
    private const DUJIAO_IDS = [
        'dujiaosi_dawang',
        'dujiaosi',
        'dawang',
    ];

    /** 太上老君NPC的 npc_id 列表 */
    private const LI_LAOJUN_IDS = [
        'li_laojun',
        'li',
        'laojun',
    ];

    /** 可挖掘金丹的房间 */
    private const DIG_ROOM = 'qujing/jindou/dongnei4';

    /** 金丹物品ID */
    private const JINDAN_ITEM_ID = 'jindan';

    public function getDefaultConfig(): array {
        return [
            'dig_room'           => 'qujing/jindou/dongnei4',
            'jindan_item_id'     => 'jindan',
            'dig_success_chance' => 10,        // 金丹挖掘成功率(%)
            'dig_busy_seconds'   => 1,         // 挖掘忙碌秒数
            'combat_exp_min'     => 10000,     // 任务最低战斗经验
            'yao_mult_min'       => 1,         // 小妖倍率下限
            'yao_mult_max'       => 9,         // 小妖倍率上限
            'yao_age_base'       => 30,        // 小妖年龄基础值
            'yao_exp_base'       => 30000,     // 小妖经验基础值
            'yao_kee_base'       => 100,       // 小妖气血基础值
            'yao_force_base'     => 100,       // 小妖内力基础值
            'yao_skill_base'     => 10,        // 小妖技能基础值
            'yao_force_factor_base' => 10,     // 小妖内力系数基础值
            'yao_seed_interval'  => 300,       // 种子刷新间隔（秒）
        ];
    }

    /**
     * 执行金兜山区域动作
     * 根据 action_cmd 分发到具体处理方法
     */
    public function execute(int $charId, array $action, array $params = []): array
    {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../models/Character.php';
        require_once __DIR__ . '/../models/Room.php';
        require_once __DIR__ . '/MessageDaemon.php';

        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }

        $actionCmd = $action['action_cmd'] ?? '';

        switch ($actionCmd) {
            case 'dig_jindan':
                return $this->handleDigJindan($charId, $char, $action);
            default:
                return ['success' => false, 'message' => '这里不能这样做。'];
        }
    }

    // ==================== 青牛精Boss死亡逻辑 ====================

    /**
     * 处理青牛精死亡事件
     * 由 CombatDaemon::handleNpcDeath() 调用
     * 
     * 原始LPC逻辑（dujiao.c die()）：
     * 1. 设置临时标记 obstacle/jindou_killed = 1
     * 2. 播放青牛现原形消息序列
     * 3. 掉落金刚琢（如果存在）
     * 4. 召唤太上老君（延迟1秒）
     * 5. NPC移动到空对象并销毁
     */
    public static function handleDujiaoDeath(int $npcId, array $npc, ?int $killerId, ?string $killerName): void
    {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../models/Character.php';
        require_once __DIR__ . '/MessageDaemon.php';

        if (!$killerId) {
            return;
        }

        $roomId = $npc['spawn_room'] ?? 'qujing/jindou/dongnei1';
        $killer = CharacterModel::find($killerId);
        if (!$killer) {
            return;
        }

        // 1. 设置临时标记：已击杀青牛精
        self::setTempStateStatic($killerId, 'obstacle/jindou_killed', '1');

        // 2. 播放死亡动画消息序列
        $messages = [
            HTML_HIYEL . "\n一阵风吹来，那妖魔现出原形，原来是一头青牛！" . HTML_NOR,
            HTML_HICYN . "\n独角兕大王恢复了原形，竟然是一头青牛。" . HTML_NOR,
            HTML_HIGRN . "这青牛向太上老君的方向奔去，留下一只金刚琢。" . HTML_NOR,
        ];

        $fullMsg = implode("\n", $messages);

        // 广播给房间
        MessageDaemon::broadcastToRoom($roomId, $fullMsg, $killerId, 'room');

        // 发送给击杀者
        if ($killerId > 0) {
            MessageDaemon::sendToPlayer($killerId, $fullMsg, 'combat');
        }

        // 3. 掉落金刚琢（添加到房间或玩家背包）
        // 注意：这里简化处理，直接给玩家金刚琢
        // 如果需要更真实的逻辑，应该先掉落到房间，让玩家拾取
        require_once __DIR__ . '/../models/Item.php';
        ItemModel::addToInventory($killerId, 'zhuo_real', 1);

        $getItemMsg = HTML_HIMAG . '你获得了：金刚琢（真）' . HTML_NOR;
        MessageDaemon::sendToPlayer($killerId, $getItemMsg, 'self_event');

        // 4. 召唤太上老君并宣布任务完成
        self::liAnnounceSuccess($killerId, $killer);

        // 5. 删除青牛精NPC记录（因为是特殊Boss，不重生）
        Database::execute("DELETE FROM npcs WHERE id = ?", [$npcId]);

        // 6. 确保没有残留的尸体
        Database::execute("DELETE FROM corpses WHERE owner_type = 'npc' AND owner_id = ?", [$npcId]);

        log_game('JINDOU_BOSS', "青牛精 (ID: {$npcId}) 被{$killerName}击败，触发金兜山事件");
    }

    /**
     * 太上老君宣布任务完成
     * 
     * 原始LPC逻辑（lilao.c announce_success()）：
     * - 检查 combat_exp >= 10000
     * - 检查 obstacle/jindou 不等于 "done"
     * - 检查 obstacle/jindou_killed 临时标记
     * - obstacle/number += 1
     * - obstacle/jindou = "done"
     * - 道行奖励已被注释禁用
     */
    private static function liAnnounceSuccess(int $killerId, array $killer): void
    {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/MessageDaemon.php';

        // 战斗经验检查（从配置读取阈值）
        $cfg = (new self())->getDefaultConfig();
        $combatExp = intval($killer['combat_exp'] ?? 0);
        if ($combatExp < $cfg['combat_exp_min']) {
            // 经验不足，不处理
            return;
        }

        // 已完成检查
        $currentState = self::getObstacleState($killerId, 'jindou');
        if ($currentState === 'done') {
            return;
        }

        // 击杀标记检查
        $killedFlag = self::getTempStateStatic($killerId, 'obstacle/jindou_killed');
        if (!$killedFlag) {
            return;
        }

        // 通关计数+1
        $currentNumber = intval(self::getTempStateStatic($killerId, 'obstacle/number') ?? 0);
        self::setTempStateStatic($killerId, 'obstacle/number', strval($currentNumber + 1));

        // 设置完成状态
        self::setObstacleState($killerId, 'jindou', 'done');

        // 太上老君出现并宣布
        $liMsg = HTML_HIGRN . '太上老君出现在你面前，向你稽首道：' . HTML_NOR . "\n" .
                 HTML_HIYEL . '"多谢' . $killer['name'] . '助我收回青牛坐骑！' . "\n" .
                 '此怪原是兜率宫看牛的孽畜，偷走我的金刚琢下界为恶．．．"' . HTML_NOR;
        MessageDaemon::sendToPlayer($killerId, $liMsg, 'self_event');

        // 全服广播
        $globalMsg = HTML_HIGRN . '【金兜山】' . HTML_HIYEL . $killer['name'] . '降服青牛精，顺利闯过西行又一关！' . HTML_NOR;
        MessageDaemon::broadcastToAll($globalMsg);

        log_game('JINDOU_DONE', "{$killer['name']} 完成金兜山障碍，总通关数=" . ($currentNumber + 1));
    }

    // ==================== 金丹挖掘机制 ====================

    /**
     * 处理金丹挖掘动作（dig_jindan）
     * 
     * 原始LPC逻辑（dongnei4.c do_dig()）：
     * - 检查是否在战斗中
     * - 检查是否忙碌
     * - 检查房间是否已挖掘过
     * - 90%概率失败，10%概率获得金丹
     * - 成功后标记房间已挖掘
     */
    public function handleDigJindan(int $charId, array $char, array $action = []): array
    {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../models/Character.php';
        require_once __DIR__ . '/../models/Item.php';
        require_once __DIR__ . '/MessageDaemon.php';

        $cfg = $this->mergeConfig($this->getDefaultConfig(), $this->parseConfig($action));

        $roomId = $char['current_room'] ?? '';

        // 验证是否在可挖掘房间
        if ($roomId !== $cfg['dig_room']) {
            return ['success' => false, 'message' => '这里没有什么可挖掘的。'];
        }

        // 检查是否在战斗中
        if (CombatDaemon::isInCombat($charId)) {
            return ['success' => false, 'message' => '你正在战斗之中，无法挖掘。'];
        }

        // 检查是否忙碌
        if (is_player_busy($charId)) {
            return ['success' => false, 'message' => '你正忙着呢。'];
        }

        // 检查房间是否已挖掘过
        $hasDigged = $this->getRoomState($roomId, 'has_digged');
        if ($hasDigged) {
            $msg = HTML_HIYEL . '你在洞里的地上仔细地挖了一遍，但什么也没找到。' . HTML_NOR;
            return ['success' => true, 'message' => $msg];
        }

        // 从配置读取成功率
        if (mt_rand(1, 100) > $cfg['dig_success_chance']) {
            // 失败
            $msg = HTML_HIYEL . '你在洞里的地上仔细地挖了一遍，没有找到什么东西。' . HTML_NOR;
            
            set_player_busy($charId, $cfg['dig_busy_seconds']);
            
            return ['success' => true, 'message' => $msg];
        }

        // 成功：获得金丹
        ItemModel::addToInventory($charId, $cfg['jindan_item_id'], 1);

        // 标记房间已挖掘
        $this->setRoomState($roomId, 'has_digged', '1');

        $msg = HTML_HIYEL . '你在洞里的地上双手一挖，挖出一个金丹！' . HTML_NOR . "\n";
        $msg .= HTML_HIGRN . '（获得：金丹）' . HTML_NOR;

        // 广播挖掘成功消息
        MessageDaemon::broadcastToRoom($roomId,
            HTML_HIYEL . $char['name'] . '在洞里挖掘，似乎找到了什么东西。' . HTML_NOR,
            $charId, 'room');

        return ['success' => true, 'message' => $msg];
    }

    // ==================== 小妖动态随机生成 ====================

    /**
     * 随机化金兜山小妖NPC（名称、属性、装备、技能）
     * 
     * 参考原版LPC yao.c：
     * - 9种妖怪名随机选一个
     * - i = random(9)+1 作为倍率(1~9)，影响所有属性和技能
     * - 固定穿8件防具（全套）
     * - 随机装备一把武器（5选1）
     * 
     * 由 Room::getNpcsInRoom() 在加载 jindou 房间时调用
     * 使用Session存储种子，保证一次访问内NPC属性一致
     * 
     * @param int $charId 当前角色ID
     * @param array $npcs NPC列表（引用传递，就地修改）
     */
    public static function randomizeYaoDemons(int $charId, array &$npcs): void
    {
        $cfg = (new self())->getDefaultConfig();

        // 小妖NPC的 npc_id 前缀
        const YAO_PREFIXES = ['tongtian_yuyao_', 'jindou_yao_'];
        
        // 妖怪名称池（对应原版 yao.c names 数组）
        const YAO_NAMES = [
            '先锋', '总兵', '元帅', '都管', '统领',
            '总管', '参将', '校尉', '偏将',
        ];
        
        // 可选武器（5种）
        const YAO_WEAPONS = [
            ['item_id' => 'weapon_jindou_0', 'name' => '鱼叉', 'skill' => 'fork'],
            ['item_id' => 'weapon_jindou_1', 'name' => '铁棒', 'skill' => 'stick'],
            ['item_id' => 'weapon_jindou_2', 'name' => '钢刀', 'skill' => 'blade'],
            ['item_id' => 'weapon_jindou_3', 'name' => '长剑', 'skill' => 'sword'],
            ['item_id' => 'weapon_jindou_4', 'name' => '铜锤', 'skill' => 'hammer'],
        ];
        
        // 防具套装（8件）
        const YAO_ARMORS = [
            'jindou_boots', 'jindou_finger', 'jindou_hands', 'jindou_head',
            'jindou_waist', 'jindou_neck', 'jindou_wrists', 'jindou_armor',
        ];
        
        // 仅处理小妖NPC
        $yaoIndices = [];
        foreach ($npcs as $idx => $npc) {
            foreach (YAO_PREFIXES as $prefix) {
                if (strpos($npc['npc_id'] ?? '', $prefix) === 0) {
                    $yaoIndices[] = $idx;
                    break;
                }
            }
        }
        
        if (empty($yaoIndices)) {
            return;
        }
        
        // Session种子管理：每次进入房间刷新（从配置读取间隔）
        $seedKey = 'jindou_yao_seed_' . $charId;
        $timeKey = 'jindou_yao_time_' . $charId;
        $now = time();
        $lastTime = $_SESSION[$timeKey] ?? 0;
        
        if (!isset($_SESSION[$seedKey]) || ($now - $lastTime) > $cfg['yao_seed_interval']) {
            $_SESSION[$seedKey] = mt_rand();
            $_SESSION[$timeKey] = $now;
        }
        
        $seed = $_SESSION[$seedKey];
        
        require_once __DIR__ . '/../includes/db.php';
        
        foreach ($yaoIndices as $idx) {
            // 每个NPC用不同的种子偏移
            $npcSeed = $seed + $npcs[$idx]['id'];
            mt_srand($npcSeed);
            
            // 随机倍率（从配置读取范围）
            $mult = mt_rand($cfg['yao_mult_min'], $cfg['yao_mult_max']);
            
            // 随机名称
            $name = YAO_NAMES[mt_rand(0, count(YAO_NAMES) - 1)];
            $npcs[$idx]['name'] = $name;
            
            // 随机属性（倍率缩放，基础值从配置读取）
            $npcs[$idx]['age'] = $cfg['yao_age_base'] * $mult;
            $npcs[$idx]['combat_exp'] = $cfg['yao_exp_base'] * $mult;
            $npcs[$idx]['max_kee'] = $cfg['yao_kee_base'] * $mult;
            $npcs[$idx]['kee'] = $cfg['yao_kee_base'] * $mult;
            $npcs[$idx]['max_gin'] = $cfg['yao_kee_base'] * $mult;
            $npcs[$idx]['gin'] = $cfg['yao_kee_base'] * $mult;
            $npcs[$idx]['max_sen'] = $cfg['yao_kee_base'] * $mult;
            $npcs[$idx]['sen'] = $cfg['yao_kee_base'] * $mult;
            $npcs[$idx]['force'] = $cfg['yao_force_base'] * $mult;
            $npcs[$idx]['max_force'] = $cfg['yao_force_base'] * $mult;
            $npcs[$idx]['max_mana'] = $cfg['yao_force_base'] * $mult;
            $npcs[$idx]['mana'] = $cfg['yao_force_base'] * $mult;
            $npcs[$idx]['force_factor'] = $cfg['yao_force_factor_base'] * $mult;
            
            // 随机武器（5选1）
            $weapon = YAO_WEAPONS[mt_rand(0, count(YAO_WEAPONS) - 1)];
            
            // 更新数据库（供NPC详情页和战斗系统使用）
            $npcDbId = intval($npcs[$idx]['id']);
            Database::execute(
                "UPDATE npcs SET name = ?, combat_exp = ?, max_kee = ?, kee = ?, 
                 max_gin = ?, gin = ?, max_sen = ?, sen = ?, `force` = ?, max_force = ?, 
                 max_mana = ?, mana = ?, force_factor = ?, age = ?
                 WHERE id = ?",
                [$name, $cfg['yao_exp_base'] * $mult, $cfg['yao_kee_base'] * $mult, $cfg['yao_kee_base'] * $mult,
                 $cfg['yao_kee_base'] * $mult, $cfg['yao_kee_base'] * $mult, $cfg['yao_kee_base'] * $mult, $cfg['yao_kee_base'] * $mult,
                 $cfg['yao_force_base'] * $mult, $cfg['yao_force_base'] * $mult, $cfg['yao_force_base'] * $mult, $cfg['yao_force_base'] * $mult,
                 $cfg['yao_force_factor_base'] * $mult, $cfg['yao_age_base'] * $mult,
                 $npcDbId]
            );
            
            // 更新 npc_equipment（先清理旧装备）
            Database::execute("DELETE FROM npc_equipment WHERE npc_id = ?", [$npcDbId]);
            
            // 添加8件防具
            foreach (YAO_ARMORS as $armorId) {
                // 根据物品ID判断装备槽位
                $slotMap = [
                    'jindou_boots' => 'boots',
                    'jindou_finger' => 'finger',
                    'jindou_hands' => 'hands',
                    'jindou_head' => 'helmet',
                    'jindou_waist' => 'waist',
                    'jindou_neck' => 'neck',
                    'jindou_wrists' => 'wrists',
                    'jindou_armor' => 'armor',
                ];
                $slot = $slotMap[$armorId] ?? 'armor';
                Database::execute(
                    "INSERT INTO npc_equipment (npc_id, item_id, equip_slot, worn) VALUES (?, ?, ?, 1)",
                    [$npcDbId, $armorId, $slot]
                );
            }
            
            // 添加武器
            Database::execute(
                "INSERT INTO npc_equipment (npc_id, item_id, equip_slot, worn) VALUES (?, ?, 'weapon', 1)",
                [$npcDbId, $weapon['item_id']]
            );
            
            // 更新 npc_skills（先清理旧技能）
            Database::execute("DELETE FROM npc_skills WHERE npc_id = ?", [$npcDbId]);
            $baseSkill = $cfg['yao_skill_base'] * $mult;
            
            // 基础技能
            Database::execute(
                "INSERT INTO npc_skills (npc_id, skill_name, skill_level) VALUES (?, 'parry', ?)",
                [$npcDbId, $baseSkill]
            );
            Database::execute(
                "INSERT INTO npc_skills (npc_id, skill_name, skill_level) VALUES (?, 'unarmed', ?)",
                [$npcDbId, $baseSkill]
            );
            Database::execute(
                "INSERT INTO npc_skills (npc_id, skill_name, skill_level) VALUES (?, 'dodge', ?)",
                [$npcDbId, $baseSkill]
            );
            
            // 多种武器技能
            $weapons = ['blade', 'fork', 'mace', 'spear', 'sword', 'whip', 'axe', 'hammer', 'rake', 'stick', 'staff', 'dagger'];
            foreach ($weapons as $w) {
                Database::execute(
                    "INSERT INTO npc_skills (npc_id, skill_name, skill_level) VALUES (?, ?, ?)",
                    [$npcDbId, $w, $baseSkill]
                );
            }
            
            // 装备信息附加到NPC数组（供房间显示用）
            $npcs[$idx]['equipped_weapon'] = $weapon['name'];
            
            log_game('JINDOU_YAO', "小妖#{$npcDbId} 随机化: {$name} 倍率={$mult} 武器={$weapon['name']}");
        }
        
        // 恢复随机种子
        mt_srand();
    }

    // ==================== 辅助方法 ====================

    /**
     * 获取角色临时状态（静态版）
     */
    private static function getTempStateStatic(int $charId, string $key): ?string
    {
        $row = Database::queryOne(
            "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ?",
            [$charId, $key]
        );
        return $row ? ($row['state_value'] ?? null) : null;
    }

    /**
     * 设置角色临时状态（静态版）
     */
    private static function setTempStateStatic(int $charId, string $key, string $value): void
    {
        Database::execute(
            'INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at) 
             VALUES (?, ?, ?, NOW(), NOW()) 
             ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), updated_at = NOW()',
            [$charId, $key, $value]
        );
    }

    /**
     * 获取障碍物状态
     */
    public static function getObstacleState(int $charId, string $obstacle): ?string
    {
        $row = Database::queryOne(
            "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ?",
            [$charId, 'obstacle/' . $obstacle]
        );
        return $row ? ($row['state_value'] ?? null) : null;
    }

    /**
     * 设置障碍物状态
     */
    public static function setObstacleState(int $charId, string $obstacle, string $state): void
    {
        $key = 'obstacle/' . $obstacle;
        Database::execute(
            'INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at) 
             VALUES (?, ?, ?, NOW(), NOW()) 
             ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), updated_at = NOW()',
            [$charId, $key, $state]
        );
    }

    /**
     * 获取房间状态（用于挖掘标记等）
     */
    private function getRoomState(string $roomId, string $key): ?string
    {
        $row = Database::queryOne(
            "SELECT state_value FROM room_states WHERE room_id = ? AND state_key = ?",
            [$roomId, $key]
        );
        return $row ? ($row['state_value'] ?? null) : null;
    }

    /**
     * 设置房间状态
     */
    private function setRoomState(string $roomId, string $key, string $value): void
    {
        Database::execute(
            'INSERT INTO room_states (room_id, state_key, state_value, created_at, updated_at) 
             VALUES (?, ?, ?, NOW(), NOW()) 
             ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), updated_at = NOW()',
            [$roomId, $key, $value]
        );
    }
}
