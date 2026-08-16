<?php
/**
 * 金平府/青龙山 区域事件处理器
 *
 * 参考 xyj2000/d/qujing/jinping/npc/fuling.c announce_success()
 * 参考 xyj2000/d/qujing/qinglong/npc/pi1.c, pi2.c, pi3.c die()
 *
 * 核心机制：
 * 1. 三大王（辟寒/辟暑/辟尘）各自死亡时设置击杀标记
 * 2. 每个大王死亡后召唤府令验证任务完成条件
 * 3. 条件：倒油>=10次 + 三大王全部击杀 + 战斗经验>=10000
 * 4. 满足条件后设置 obstacle/jinping="done"，通关计数+1
 *
 * obstacle/jinping 状态流转：
 *   (未设置) → done (倒油10次+击败三大王+满足经验)
 */

require_once __DIR__ . '/../includes/db.php';

class JinpingHandler
{
    // ==================== 常量定义 ====================

    /** 辟寒大王 NPC npc_id */
    private const NPC_PIHAN = 'pihandawang';

    /** 辟暑大王 NPC npc_id */
    private const NPC_PISHU = 'pishudawang';

    /** 辟尘大王 NPC npc_id */
    private const NPC_PICHEN = 'pichendawang';

    /** 关卡ID */
    private const QUEST_ID = 'jinping';

    /** 关卡名称 */
    private const QUEST_NAME = '金平府';

    /** 最低战斗经验 */
    private const COMBAT_EXP_MIN = 10000;

    /** 最低倒油次数 */
    private const OIL_MIN = 10;

    // ==================== Boss死亡处理入口 ====================

    /**
     * 处理金平府Boss死亡事件
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
        if ($npcIdVal === self::NPC_PIHAN) {
            self::handlePihanDeath($npcId, $npc, $killerId, $killerName, $roomId);
        } elseif ($npcIdVal === self::NPC_PISHU) {
            self::handlePishuDeath($npcId, $npc, $killerId, $killerName, $roomId);
        } elseif ($npcIdVal === self::NPC_PICHEN) {
            self::handlePichenDeath($npcId, $npc, $killerId, $killerName, $roomId);
        }
    }

    // ==================== 辟寒大王死亡 ====================

    /**
     * 处理辟寒大王死亡
     *
     * 原始LPC逻辑（pi1.c die()）：
     * 1. 设置临时标记 obstacle/jinping_pi1_killed = 1
     * 2. 显示"倒在地上，现原为一头犀牛"死亡消息
     * 3. 犀牛逃走消息
     * 4. 召唤府令验证任务完成
     */
    private static function handlePihanDeath(int $npcId, array $npc, int $killerId, string $killerName, string $roomId): void
    {
        require_once __DIR__ . '/MessageDaemon.php';

        // 1. 设置击杀标记
        self::setKillMark($killerId, 'obstacle/jinping_pi1_killed');

        // 2. 播放死亡动画消息
        $msg = HTML_HIMAG . "\n辟寒大王倒在地上，现出原形为一头犀牛！" . HTML_NOR . "\n" .
               HTML_HIYEL . "那犀牛精化作一阵风，一溜烟逃走了！" . HTML_NOR;
        MessageDaemon::sendToPlayer($killerId, $msg, 'combat');
        MessageDaemon::broadcastToRoom($roomId, $msg, $killerId, 'room');

        // 3. 府令验证
        self::fulingAnnounceSuccess($killerId, $killerName);

        log_game('JINPING_PIHAN', "辟寒大王 (ID: {$npcId}) 被{$killerName}击败");
    }

    // ==================== 辟暑大王死亡 ====================

    /**
     * 处理辟暑大王死亡
     *
     * 原始LPC逻辑（pi2.c die()）：
     * 1. 设置临时标记 obstacle/jinping_pi2_killed = 1
     * 2. 显示死亡消息序列
     * 3. 召唤府令验证
     */
    private static function handlePishuDeath(int $npcId, array $npc, int $killerId, string $killerName, string $roomId): void
    {
        require_once __DIR__ . '/MessageDaemon.php';

        // 1. 设置击杀标记
        self::setKillMark($killerId, 'obstacle/jinping_pi2_killed');

        // 2. 播放死亡动画消息
        $msg = HTML_HIMAG . "\n辟暑大王倒在地上，现出原形为一头犀牛！" . HTML_NOR . "\n" .
               HTML_HIYEL . "那犀牛精化作一阵风，一溜烟逃走了！" . HTML_NOR;
        MessageDaemon::sendToPlayer($killerId, $msg, 'combat');
        MessageDaemon::broadcastToRoom($roomId, $msg, $killerId, 'room');

        // 3. 府令验证
        self::fulingAnnounceSuccess($killerId, $killerName);

        log_game('JINPING_PISHU', "辟暑大王 (ID: {$npcId}) 被{$killerName}击败");
    }

    // ==================== 辟尘大王死亡 ====================

    /**
     * 处理辟尘大王死亡
     *
     * 原始LPC逻辑（pi3.c die()）：
     * 1. 设置临时标记 obstacle/jinping_pi3_killed = 1
     * 2. 显示死亡消息序列
     * 3. 召唤府令验证
     */
    private static function handlePichenDeath(int $npcId, array $npc, int $killerId, string $killerName, string $roomId): void
    {
        require_once __DIR__ . '/MessageDaemon.php';

        // 1. 设置击杀标记
        self::setKillMark($killerId, 'obstacle/jinping_pi3_killed');

        // 2. 播放死亡动画消息
        $msg = HTML_HIMAG . "\n辟尘大王倒在地上，现出原形为一头犀牛！" . HTML_NOR . "\n" .
               HTML_HIYEL . "那犀牛精化作一阵风，一溜烟逃走了！" . HTML_NOR;
        MessageDaemon::sendToPlayer($killerId, $msg, 'combat');
        MessageDaemon::broadcastToRoom($roomId, $msg, $killerId, 'room');

        // 3. 府令验证
        self::fulingAnnounceSuccess($killerId, $killerName);

        log_game('JINPING_PICHEN', "辟尘大王 (ID: {$npcId}) 被{$killerName}击败");
    }

    // ==================== 府令验证 ====================

    /**
     * 府令宣布任务完成
     *
     * 原始LPC逻辑（fuling.c announce_success()）：
     * - 检查 combat_exp >= 10000
     * - 检查 obstacle/jinping != "done"
     * - 检查 obstacle/jinping_oil >= 10
     * - 检查 obstacle/jinping_pi1_killed
     * - 检查 obstacle/jinping_pi2_killed
     * - 检查 obstacle/jinping_pi3_killed
     * - obstacle/number += 1
     * - obstacle/jinping = "done"
     * - 道行奖励已被注释禁用
     *
     * @param int    $killerId   击杀者ID
     * @param string $killerName 击杀者名称
     */
    private static function fulingAnnounceSuccess(int $killerId, string $killerName): void
    {
        require_once __DIR__ . '/MessageDaemon.php';
        require_once __DIR__ . '/../models/Character.php';

        // 检查是否已完成
        $currentState = self::getObstacleState($killerId, 'jinping');
        if ($currentState === 'done') {
            return;
        }

        $killer = CharacterModel::find($killerId);
        if (!$killer) {
            return;
        }

        // 战斗经验检查
        $combatExp = intval($killer['combat_exp'] ?? 0);
        if ($combatExp < self::COMBAT_EXP_MIN) {
            return;
        }

        // 倒油次数检查
        $oilCount = intval(self::getTempState($killerId, 'obstacle/jinping_oil') ?? 0);
        if ($oilCount < self::OIL_MIN) {
            return;
        }

        // 三大王击杀标记检查
        $pi1Killed = self::getTempState($killerId, 'obstacle/jinping_pi1_killed');
        if (!$pi1Killed) {
            return;
        }
        $pi2Killed = self::getTempState($killerId, 'obstacle/jinping_pi2_killed');
        if (!$pi2Killed) {
            return;
        }
        $pi3Killed = self::getTempState($killerId, 'obstacle/jinping_pi3_killed');
        if (!$pi3Killed) {
            return;
        }

        // 通关计数+1
        $currentNumber = intval(self::getTempState($killerId, 'obstacle/number') ?? 0);
        self::setTempState($killerId, 'obstacle/number', strval($currentNumber + 1));

        // 设置完成状态
        self::setObstacleState($killerId, 'jinping', 'done');

        // 府令出现并宣布
        $fulingMsg = HTML_HIGRN . '府令出现在你面前，向你拱手道：' . HTML_NOR . "\n" .
                     HTML_HIYEL . '"多谢' . $killer['name'] . '除灭犀牛精，解了金平府灯会之难！' . "\n" .
                     '青龙山三大王已除，百姓从此安居乐业！"' . HTML_NOR;
        MessageDaemon::sendToPlayer($killerId, $fulingMsg, 'self_event');

        // 全服广播
        $globalMsg = HTML_HIGRN . '【金平府】' . HTML_HIYEL . $killer['name'] . '降服犀牛精，顺利闯过西行又一关！' . HTML_NOR;
        MessageDaemon::broadcastToAll($globalMsg);

        log_game('JINPING_DONE', "{$killer['name']} 完成金平府障碍，总通关数=" . ($currentNumber + 1));
    }

    // ==================== 静态工具方法 ====================

    /**
     * 设置击杀标记
     */
    public static function setKillMark(int $charId, string $markKey): void
    {
        self::setTempState($charId, $markKey, '1');
    }

    /**
     * 获取临时状态
     */
    public static function getTempState(int $charId, string $key): ?string
    {
        $row = Database::queryOne(
            "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ?",
            [$charId, $key]
        );
        return $row ? ($row['state_value'] ?? null) : null;
    }

    /**
     * 设置临时状态
     */
    public static function setTempState(int $charId, string $key, string $value): void
    {
        Database::execute(
            'INSERT INTO character_temp_states (char_id, state_key, state_value, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), updated_at = NOW()',
            [$charId, $key, $value]
        );
    }

    /**
     * 获取关卡状态
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
     * 设置关卡状态
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
}
