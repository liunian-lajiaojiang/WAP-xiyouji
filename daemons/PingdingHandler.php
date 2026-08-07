<?php
/**
 * 平顶山/压龙山/压龙洞 区域事件处理器
 * 
 * 参考原始LPC代码：
 *   参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 核心机制：
 * 1. 四个Boss各自死亡时设置击杀标记
 * 2. 每个Boss死亡后调用太上老君验证
 * 3. 全部四个击杀标记就绪 → 关卡完成
 * 4. 金角/银角大王死亡时掉落法宝（真品/赝品判断）
 * 5. 狐阿七大王死亡时法宝留在尸体上（真品/赝品判断）
 * 
 * 真假法宝机制：
 *   - 真品法宝具有全局唯一性（unique）
 *   - 如果真品已存在于任何玩家背包 → Boss携带赝品 → 掉落赝品
 *   - 如果真品不存在于世界 → Boss携带真品 → 掉落真品
 * 
 * obstacle/pingding 状态流转：
 *   (未设置) → done (击败全部4个Boss且满足条件)
 */

require_once __DIR__ . '/../includes/db.php';

class PingdingHandler
{
    // ==================== 常量定义 ====================

    /** 狐阿七大王 NPC npc_id */
    private const NPC_HUAQI = 'huaqidawang';
        
    /** 老奶奶（妖后）NPC npc_id */
    private const NPC_LAONAI = 'laonainai';
    
    /** 金角大王 NPC npc_id */
    private const NPC_JINJIAO = 'jinjiaodawang';
    
    /** 银角大王 NPC npc_id */
    private const NPC_YINJIAO = 'yinjiaodawang';
    
    /** 关卡ID */
    private const QUEST_ID = 'pingding';
    
    /** 关卡名称 */
    private const QUEST_NAME = '平顶山';
    
    /** 法宝映射：Boss npc_id → [真实法宝item_id, 赝品法宝item_id] */
    private const FABAO_MAP = [
        self::NPC_HUAQI   => ['shengreal',  'shengfake'],   // 幌金绳
        self::NPC_JINJIAO => ['hulureal',   'hulufake'],    // 紫金红葫芦
        self::NPC_YINJIAO => ['pingreal',   'pingfake'],    // 羊脂玉净瓶
    ];
    
    // ==================== Boss死亡处理入口 ====================

    /**
     * 处理平顶山Boss死亡事件
     * 由 CombatDaemon::handleNpcDeath() 调用
     * 
     * @param int    $npcId      NPC数据库ID
     * @param array  $npc        NPC数据
     * @param int    $killerId   击杀者角色ID
     * @param string $killerName 击杀者名称
     * @param string $roomId     当前房间ID
     */
    public static function handleBossDeath(int $npcId, array $npc, int $killerId, string $killerName, string $roomId): void
    {
        require_once __DIR__ . '/MessageDaemon.php';
        require_once __DIR__ . '/../models/Character.php';
        require_once __DIR__ . '/../models/Item.php';
        
        $npcIdVal = $npc['npc_id'] ?? '';
        $npcName  = $npc['name'] ?? '';
        
        if (!$killerId) {
            return;
        }
        
        $killer = CharacterModel::find($killerId);
        if (!$killer) {
            return;
        }
        
        // 根据NPC类型分发处理
        if ($npcIdVal === self::NPC_HUAQI) {
            self::handleHuaqiDeath($npcId, $npc, $killerId, $killerName, $roomId);
        } elseif ($npcIdVal === self::NPC_LAONAI) {
            self::handleLaonaiDeath($killerId, $killerName, $roomId);
        } elseif ($npcIdVal === self::NPC_JINJIAO) {
            self::handleJinjiaoDeath($npcId, $npc, $killerId, $killerName, $roomId);
        } elseif ($npcIdVal === self::NPC_YINJIAO) {
            self::handleYinjiaoDeath($npcId, $npc, $killerId, $killerName, $roomId);
        }
    }
    
    // ==================== 狐阿七大王死亡 ====================
    
    /**
     * 处理狐阿七大王死亡
     * 
     * 原始LPC逻辑（huaqi.c die()）：
     * 1. 设置临时标记 obstacle/pingding_huaqi_killed = 1
     * 2. 显示"淫叫几声化作一只妖狐精"死亡消息
     * 3. 法宝（幌金绳）留在尸体上（由 handleNpcDeath 的 Corpse::dropNpcItems 处理）
     * 4. 1秒后召唤太上老君验证
     */
    private static function handleHuaqiDeath(int $npcId, array $npc, int $killerId, string $killerName, string $roomId): void
    {
        require_once __DIR__ . '/MessageDaemon.php';
        
        // 1. 设置击杀标记
        self::setKillMark($killerId, 'pingding_huaqi_killed');
        
        // 2. 播放死亡动画消息
        $msg = HTML_HIMAG . "\n狐阿七大王淫叫几声倒在地上，现出一只妖狐精！" . HTML_NOR . "\n" .
               HTML_HIYEL . "妖狐精一闪，逃了个无踪无影。" . HTML_NOR;
        MessageDaemon::sendToPlayer($killerId, $msg, 'combat');
        MessageDaemon::broadcastToRoom($roomId, $msg, $killerId, 'room');
        
        // 3. 幌金绳真假判断：真品存在则掉落赝品，否则真品
        //    法宝掉落通过 npc_equipment 表管理，在 handleNpcDeath 的 dropNpcItems 中处理
        //    这里我们需要更新 npc_equipment 确保尸体上物品是正确版本
        self::ensureFabaoOnNpc($npcId, self::NPC_HUAQI);
        
        // 4. 太上老君验证
        self::taishangAnnounceSuccess($killerId, $killerName);
        
        log_game('PINGDING_HUAQI', "狐阿七大王 (ID: {$npcId}) 被{$killerName}击败");
    }
    
    // ==================== 老奶奶死亡 ====================
    
    /**
     * 处理老奶奶（妖后）死亡
     * 
     * 原始LPC逻辑（laonai.c die()）：
     * 1. 设置临时标记 obstacle/pingding_laonai_killed = 1
     * 2. 显示"淫叫几声化作一只妖狐精"死亡消息
     * 3. 生成尸体（无特殊法宝）
     * 4. 1秒后召唤太上老君验证
     */
    private static function handleLaonaiDeath(int $killerId, string $killerName, string $roomId): void
    {
        require_once __DIR__ . '/MessageDaemon.php';
        
        // 1. 设置击杀标记
        self::setKillMark($killerId, 'pingding_laonai_killed');
        
        // 2. 播放死亡动画消息
        $msg = HTML_HIMAG . "\n老奶奶淫叫几声倒在地上，现出一只妖狐精！" . HTML_NOR . "\n" .
               HTML_HIYEL . "妖狐精一闪，逃了个无踪无影。" . HTML_NOR;
        MessageDaemon::sendToPlayer($killerId, $msg, 'combat');
        MessageDaemon::broadcastToRoom($roomId, $msg, $killerId, 'room');
        
        // 3. 太上老君验证
        self::taishangAnnounceSuccess($killerId, $killerName);
        
        log_game('PINGDING_LAONAI', "老奶奶被{$killerName}击败");
    }
    
    // ==================== 金角大王死亡 ====================
    
    /**
     * 处理金角大王死亡
     * 
     * 原始LPC逻辑（jinjiao.c die()）：
     * 1. 设置临时标记 obstacle/pingding_jinjiao_killed = 1
     * 2. 显示"现出原形→太上老君的金童童子"死亡消息序列
     * 3. 法宝（紫金红葫芦）掉落到地面
     * 4. 1秒后召唤太上老君验证
     */
    private static function handleJinjiaoDeath(int $npcId, array $npc, int $killerId, string $killerName, string $roomId): void
    {
        require_once __DIR__ . '/MessageDaemon.php';
        
        // 1. 设置击杀标记
        self::setKillMark($killerId, 'pingding_jinjiao_killed');
        
        // 2. 播放死亡动画消息
        $msg = HTML_HIGRN . "\n金角大王现出了原形，竟是太上老君的金童童子！" . HTML_NOR . "\n" .
               HTML_HIYEL . "金童童子惊了个忙，一时答不上话来，太上老君也显露了真相。" . HTML_NOR . "\n" .
               HTML_HIMAG . "金童童子随着太上老君急急奔去。" . HTML_NOR;
        MessageDaemon::sendToPlayer($killerId, $msg, 'combat');
        MessageDaemon::broadcastToRoom($roomId, $msg, $killerId, 'room');
        
        // 3. 法宝掉落地面（紫金红葫芦）
        self::dropFabaoToRoom($npcId, self::NPC_JINJIAO, $killerId, $killerName, $roomId);
        
        // 4. 太上老君验证
        self::taishangAnnounceSuccess($killerId, $killerName);
        
        log_game('PINGDING_JINJIAO', "金角大王 (ID: {$npcId}) 被{$killerName}击败");
    }
    
    // ==================== 银角大王死亡 ====================
    
    /**
     * 处理银角大王死亡
     * 
     * 原始LPC逻辑（yinjiao.c die()）：
     * 1. 设置临时标记 obstacle/pingding_yinjiao_killed = 1
     * 2. 显示"现出原形→太上老君的银童童子"死亡消息序列
     * 3. 法宝（羊脂玉净瓶）掉落到地面
     * 4. 1秒后召唤太上老君验证
     */
    private static function handleYinjiaoDeath(int $npcId, array $npc, int $killerId, string $killerName, string $roomId): void
    {
        require_once __DIR__ . '/MessageDaemon.php';
        
        // 1. 设置击杀标记
        self::setKillMark($killerId, 'pingding_yinjiao_killed');
        
        // 2. 播放死亡动画消息
        $msg = HTML_HIGRN . "\n银角大王现出了原形，竟是太上老君的银童童子！" . HTML_NOR . "\n" .
               HTML_HIYEL . "银童童子惊了个忙，一时答不上话来，太上老君也显露了真相。" . HTML_NOR . "\n" .
               HTML_HIMAG . "银童童子随着太上老君急急奔去。" . HTML_NOR;
        MessageDaemon::sendToPlayer($killerId, $msg, 'combat');
        MessageDaemon::broadcastToRoom($roomId, $msg, $killerId, 'room');
        
        // 3. 法宝掉落地面（羊脂玉净瓶）
        self::dropFabaoToRoom($npcId, self::NPC_YINJIAO, $killerId, $killerName, $roomId);
        
        // 4. 太上老君验证
        self::taishangAnnounceSuccess($killerId, $killerName);
        
        log_game('PINGDING_YINJIAO', "银角大王 (ID: {$npcId}) 被{$killerName}击败");
    }
    
    // ==================== 真假法宝机制 ====================
    
    /**
     * 判断法宝应该给真品还是赝品
     * 
     * 原始LPC逻辑：
     *   - huaqi.c:  if ("/d/.../shengreal"->in_mud()) carry shengfake else carry shengreal
     *   - jinjiao.c: if ("/d/.../hulureal"->in_mud()) carry hulufake else carry hulureal
     *   - yinjiao.c: if ("/d/.../pingreal"->in_mud()) carry pingfake else carry pingreal
     * 
     * 翻译：如果真品法宝已在游戏中存在（任何玩家持有），则携带赝品
     * 
     * @param string $bossId Boss的npc_id
     * @return string 法宝item_id (real or fake)
     */
    private static function resolveFabaoVersion(string $bossId): string
    {
        if (!isset(self::FABAO_MAP[$bossId])) {
            return '';
        }
        
        [$realItemId, $fakeItemId] = self::FABAO_MAP[$bossId];
        
        // 检查真品是否已在玩家背包中（unique物品存在于character_inventory）
        $existing = Database::queryOne(
            "SELECT id FROM character_inventory WHERE item_id = ? LIMIT 1",
            [$realItemId]
        );
        
        if ($existing) {
            // 真品已存在 → 给赝品
            return $fakeItemId;
        }
        
        // 检查真品是否在房间地面（room_items表）
        $onGround = Database::queryOne(
            "SELECT id FROM room_items WHERE item_id = ? LIMIT 1",
            [$realItemId]
        );
        
        if ($onGround) {
            return $fakeItemId;
        }
        
        // 真品不存在 → 给真品
        return $realItemId;
    }
    
    /**
     * 确保NPC身上装备的法宝版本正确
     * 更新 npc_equipment 表，将法宝替换为正确版本
     * 用于狐阿七大王（法宝留在尸体上）
     * 
     * @param int    $npcId  NPC数据库ID
     * @param string $bossId Boss的npc_id
     */
    private static function ensureFabaoOnNpc(int $npcId, string $bossId): void
    {
        if (!isset(self::FABAO_MAP[$bossId])) {
            return;
        }
        
        $correctVersion = self::resolveFabaoVersion($bossId);
        if (empty($correctVersion)) {
            return;
        }
        
        [$realItemId, $fakeItemId] = self::FABAO_MAP[$bossId];
        
        // 更新npc_equipment：统一设为正确的法宝版本
        // 同时更新所有法宝slot（misc装备位）
        Database::execute(
            "UPDATE npc_equipment SET item_id = ? WHERE npc_id = ? AND item_id IN (?, ?)",
            [$correctVersion, $npcId, $realItemId, $fakeItemId]
        );
    }
    
    /**
     * 将法宝掉落到房间地面
     * 用于金角/银角大王（法宝从身上掉落）
     * 
     * @param int    $npcId      NPC数据库ID
     * @param string $bossId     Boss的npc_id
     * @param int    $killerId   击杀者ID
     * @param string $killerName 击杀者名称
     * @param string $roomId     房间ID
     */
    private static function dropFabaoToRoom(int $npcId, string $bossId, int $killerId, string $killerName, string $roomId): void
    {
        // 先从npc_equipment中移除法宝（防止重复掉落）
        if (isset(self::FABAO_MAP[$bossId])) {
            [$realItemId, $fakeItemId] = self::FABAO_MAP[$bossId];
            Database::execute(
                "DELETE FROM npc_equipment WHERE npc_id = ? AND item_id IN (?, ?)",
                [$npcId, $realItemId, $fakeItemId]
            );
        }
        
        $fabaoItemId = self::resolveFabaoVersion($bossId);
        if (empty($fabaoItemId)) {
            return;
        }
        
        // 查找法宝名称
        $itemInfo = Database::queryOne(
            "SELECT name, category FROM items WHERE item_id = ? AND category = ? LIMIT 1",
            [$fabaoItemId, 'qujing']
        );
        $fabaoName = $itemInfo ? $itemInfo['name'] : '法宝';
        
        // 掉落到房间：先解析 rooms.id（room_items.room_id 是 INT 外键，不能直接用路径字符串）
        $roomRow = Database::queryOne("SELECT id FROM rooms WHERE room_id = ? LIMIT 1", [$roomId]);
        $roomDbId = $roomRow['id'] ?? 0;
        if ($roomDbId <= 0) {
            return;
        }
        
        $existing = Database::queryOne(
            "SELECT id FROM room_items WHERE room_id = ? AND item_id = ? AND category = ?",
            [$roomDbId, $fabaoItemId, 'qujing']
        );
        
        if ($existing) {
            Database::execute(
                "UPDATE room_items SET quantity = quantity + 1 WHERE id = ?",
                [$existing['id']]
            );
        } else {
            Database::execute(
                "INSERT INTO room_items (room_id, item_id, category, quantity) VALUES (?, ?, 'qujing', 1)",
                [$roomDbId, $fabaoItemId]
            );
        }
        
        // 通知击杀者
        $isReal = (strpos($fabaoItemId, 'real') !== false);
        $dropMsg = HTML_HIMAG . "{$fabaoName}掉在了地上！" . HTML_NOR;
        
        require_once __DIR__ . '/MessageDaemon.php';
        MessageDaemon::sendToPlayer($killerId, "\n" . $dropMsg, 'combat');
        MessageDaemon::broadcastToRoom($roomId, "\n" . $dropMsg, $killerId, 'room');
        
        log_game('PINGDING_FABAO', "{$fabaoName}掉落(ID:{$fabaoItemId})→{$roomId}, isReal=" . ($isReal ? 'true' : 'false'));
    }
    
    // ==================== 太上老君验证 ====================
    
    /**
     * 太上老君宣布任务完成
     * 
     * 原始LPC逻辑（taishang.c announce_success()）：
     * - 检查 combat_exp >= 10000
     * - 检查 obstacle/pingding != "done"
     * - 检查 ALL 4 击杀标记
     *   - pingding_huaqi_killed
     *   - pingding_laonai_killed
     *   - pingding_jinjiao_killed
     *   - pingding_yinjiao_killed
     * - obstacle/number += 1
     * - obstacle/pingding = "done"
     * - 道行奖励已被注释禁用
     * 
     * @param int    $killerId   击杀者ID
     * @param string $killerName 击杀者名称
     */
    private static function taishangAnnounceSuccess(int $killerId, string $killerName): void
    {
        require_once __DIR__ . '/MessageDaemon.php';
        require_once __DIR__ . '/../models/Character.php';
        
        // 检查是否已完成
        $currentState = self::getObstacleState($killerId, 'pingding');
        if ($currentState === 'done') {
            return;
        }
        
        // 检查经验门槛（combat_exp >= 10000）
        $killer = CharacterModel::find($killerId);
        if (!$killer) {
            return;
        }
        
        $combatExp = intval($killer['combat_exp'] ?? 0);
        if ($combatExp < 10000) {
            return;
        }
        
        // 检查全部4个击杀标记
        $requiredMarks = [
            'pingding_huaqi_killed',
            'pingding_laonai_killed',
            'pingding_jinjiao_killed',
            'pingding_yinjiao_killed',
        ];
        
        foreach ($requiredMarks as $mark) {
            if (!self::hasKillMark($killerId, $mark)) {
                // 还有Boss未击杀，不触发完成
                return;
            }
        }
        
        // === 全部条件满足，宣布完成 ===
        
        // 通关计数+1
        $currentNumber = intval(self::getTempState($killerId, 'obstacle/number') ?? 0);
        self::setTempState($killerId, 'obstacle/number', strval($currentNumber + 1));
        
        // 设置完成状态
        self::setObstacleState($killerId, 'pingding', 'done');
        
        // 太上老君出现消息
        $liMsg = HTML_HIGRN . '太上老君来到你的面前，向你稽首道：' . HTML_NOR . "\n" .
                 HTML_HIYEL . '"多谢' . $killerName . '助我收回金童银童！' . "\n" .
                 '这两位童子原是我兜率宫看炉的童子，偷了我的宝贝下界为妖．．．"' . HTML_NOR;
        MessageDaemon::sendToPlayer($killerId, "\n" . $liMsg, 'self_event');
        
        // 法宝回收提示
        $recallMsg = HTML_HIGRN . '太上老君大袖一挥，将桌上的幌金绳、紫金红葫芦、羊脂玉净瓶一并收走。' . HTML_NOR;
        MessageDaemon::sendToPlayer($killerId, $recallMsg, 'self_event');
        
        // 全服广播
        $globalMsg = HTML_HIGRN . '【平顶山】' . HTML_HIYEL . $killerName . '降服金角银角大王，顺利闯过西行又一关！' . HTML_NOR;
        MessageDaemon::broadcastToAll($globalMsg);
        
        log_game('PINGDING_DONE', "{$killerName} 完成平顶山障碍，总通关数=" . ($currentNumber + 1));
    }
    
    // ==================== 辅助方法 ====================
    
    /**
     * 设置击杀标记
     */
    public static function setKillMark(int $charId, string $markKey): void
    {
        $existing = Database::queryOne(
            "SELECT id FROM character_temp_states WHERE char_id = ? AND state_key = ?",
            [$charId, $markKey]
        );
        
        if ($existing) {
            Database::execute(
                "UPDATE character_temp_states SET state_value = '1', updated_at = NOW() WHERE id = ?",
                [$existing['id']]
            );
        } else {
            Database::execute(
                "INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at) VALUES (?, ?, '1', NOW(), NOW())",
                [$charId, $markKey]
            );
        }
    }
    
    /**
     * 检查击杀标记
     */
    public static function hasKillMark(int $charId, string $markKey): bool
    {
        $result = Database::queryOne(
            "SELECT id FROM character_temp_states WHERE char_id = ? AND state_key = ? AND state_value = '1'",
            [$charId, $markKey]
        );
        return !empty($result);
    }
    
    /**
     * 获取障碍物状态（obstacle/xxx）
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
     * 获取角色临时状态
     */
    private static function getTempState(int $charId, string $key): ?string
    {
        $row = Database::queryOne(
            "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ?",
            [$charId, $key]
        );
        return $row ? ($row['state_value'] ?? null) : null;
    }
    
    /**
     * 设置角色临时状态
     */
    private static function setTempState(int $charId, string $key, string $value): void
    {
        Database::execute(
            'INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at) 
             VALUES (?, ?, ?, NOW(), NOW()) 
             ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), updated_at = NOW()',
            [$charId, $key, $value]
        );
    }
    
    /**
     * 获取关卡完成进度（供查询用）
     * 
     * @param int $charId 角色ID
     * @return array [total, killed, names]
     */
    public static function getProgress(int $charId): array
    {
        $marks = [
            'pingding_huaqi_killed'  => '狐阿七大王',
            'pingding_laonai_killed' => '老奶奶',
            'pingding_jinjiao_killed'=> '金角大王',
            'pingding_yinjiao_killed'=> '银角大王',
        ];
        
        $killed = [];
        foreach ($marks as $key => $name) {
            if (self::hasKillMark($charId, $key)) {
                $killed[] = $name;
            }
        }
        
        return [
            'total'  => count($marks),
            'killed' => count($killed),
            'names'  => $killed,
        ];
    }
}
