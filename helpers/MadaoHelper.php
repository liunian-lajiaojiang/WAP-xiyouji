<?php
/**
 * 马盗(Madao)助手类
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 负责：
 * - 玩家进入饮马峪时的拦路抢劫逻辑
 * - 警告倒计时（不给钱就逐步警告然后攻击）
 * - 收钱放行逻辑（accept_object）
 * - 离开限制（valid_leave，没付钱不让往西北走）
 * - 五庄观弟子免过路费
 * - 取经关卡≥2的特殊待遇
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';

class MadaoHelper {
    
    const NPC_ID = 522;
    const NPC_NAME = '马盗';
    const ROOM_ID = 'westway/yinma';
    const PAY_AMOUNT = 200; // 200铜钱
    const HAS_PAID_KEY = 'madao_has_paid';
    const ASKING_KEY = 'madao_asking';
    const WARNING1_KEY = 'madao_warning1';
    const WARNING2_KEY = 'madao_warning2';
    const WARNING3_KEY = 'madao_warning3';
    const KILLING_KEY = 'madao_killing';
    
    /**
     * 玩家进入饮马峪时调用
     * 检查是否触发拦路抢劫
     */
    public static function onPlayerEnter(int $charId): ?array {
        // 检查玩家是否在饮马峪
        $char = Database::queryOne("SELECT current_room, family, combat_exp FROM characters WHERE id = ?", [$charId]);
        if (!$char || $char['current_room'] !== self::ROOM_ID) {
            return null;
        }
        
        // 检查马盗是否还活着（简单检查，后续可完善）
        $deathKey = "npc_dead_" . self::NPC_ID;
        if (isset($_SESSION[$deathKey]) && $_SESSION[$deathKey] > time()) {
            return null; // 马盗已死
        }
        
        // 检查玩家是否已经付过钱
        $hasPaid = self::getTempState($charId, self::HAS_PAID_KEY);
        if ($hasPaid && intval($hasPaid) > 0) {
            // 已经付过钱了，拱手让道
            return null;
        }
        
        // 检查玩家是否是五庄观弟子
        $family = $char['family'] ?? '';
        if ($family === 'wzg' || $family === 'wuzhuang' || $family === '五庄观') {
            // 五庄观弟子，拱手让道
            $message = self::NPC_NAME . "对你一拱手，闪身让道。\n";
            return ['message' => $message, 'skip_attack' => true];
        }
        
        // 检查玩家取经关卡是否≥2
        $obstacleNumber = self::getObstacleNumber($charId);
        if ($obstacleNumber >= 2) {
            // 取经关卡≥2，直接放行
            $message = self::NPC_NAME . "对你一拱手，闪身让道。\n";
            return ['message' => $message, 'skip_attack' => true];
        }
        
        // 检查是否已经在询问状态
        $asking = self::getTempState($charId, self::ASKING_KEY);
        if ($asking) {
            return null; // 已经触发过了
        }
        
        // 设置询问状态和各个定时器
        $now = time();
        self::setTempState($charId, self::ASKING_KEY, '1', $now + 60); // 60秒后过期
        self::setTempState($charId, self::WARNING1_KEY, $now + 5 + mt_rand(0, 5), $now + 60);
        self::setTempState($charId, self::WARNING2_KEY, $now + 10 + mt_rand(0, 5), $now + 60);
        self::setTempState($charId, self::WARNING3_KEY, $now + 15 + mt_rand(0, 5), $now + 60);
        self::setTempState($charId, self::KILLING_KEY, $now + 25 + mt_rand(0, 5), $now + 60);
        
        // 发送拦路抢劫消息
        $message = self::NPC_NAME . "冲过来，对你大喝一声：要钱还是要命？\n";
        
        return ['message' => $message, 'triggered' => true];
    }
    
    /**
     * 检查定时器，处理警告和攻击
     * 在每次请求时调用
     */
    public static function checkTimers(int $charId): array {
        $messages = [];
        $now = time();
        
        // 检查玩家是否在饮马峪
        $char = Database::queryOne("SELECT current_room FROM characters WHERE id = ?", [$charId]);
        if (!$char || $char['current_room'] !== self::ROOM_ID) {
            // 不在饮马峪，清除所有状态
            self::clearAllStates($charId);
            return [];
        }
        
        // 检查是否已经付过钱
        $hasPaid = self::getTempState($charId, self::HAS_PAID_KEY);
        if ($hasPaid && intval($hasPaid) > 0) {
            // 已经付钱了，清除所有警告状态
            self::clearWarningStates($charId);
            return [];
        }
        
        // 检查是否在战斗中
        if (isset($_SESSION["combat_{$charId}"])) {
            return []; // 战斗中，不处理
        }
        
        // 检查马盗是否还活着
        $deathKey = "npc_dead_" . self::NPC_ID;
        if (isset($_SESSION[$deathKey]) && $_SESSION[$deathKey] > time()) {
            self::clearAllStates($charId);
            return []; // 马盗已死，清除状态
        }
        
        // 检查警告1
        $warning1Time = self::getTempState($charId, self::WARNING1_KEY);
        if ($warning1Time && intval($warning1Time) <= $now) {
            $messages[] = self::NPC_NAME . "大声喝道：快给钱！\n";
            self::deleteTempState($charId, self::WARNING1_KEY);
        }
        
        // 检查警告2
        $warning2Time = self::getTempState($charId, self::WARNING2_KEY);
        if ($warning2Time && intval($warning2Time) <= $now) {
            $messages[] = self::NPC_NAME . "又喊叫几声：拿钱来买命！\n";
            self::deleteTempState($charId, self::WARNING2_KEY);
        }
        
        // 检查警告3
        $warning3Time = self::getTempState($charId, self::WARNING3_KEY);
        if ($warning3Time && intval($warning3Time) <= $now) {
            $messages[] = self::NPC_NAME . "急了：你到底给不给钱？\n";
            self::deleteTempState($charId, self::WARNING3_KEY);
        }
        
        // 检查是否要攻击
        $killingTime = self::getTempState($charId, self::KILLING_KEY);
        if ($killingTime && intval($killingTime) <= $now) {
            // 发起攻击
            $attackResult = self::initiateAttack($charId);
            if ($attackResult) {
                $messages[] = $attackResult['message'];
            }
            self::deleteTempState($charId, self::KILLING_KEY);
            self::clearWarningStates($charId);
        }
        
        return $messages;
    }
    
    /**
     * 处理玩家给马盗东西（accept_object）
     */
    public static function handleGive(int $charId, array $item): ?array {
        // 检查物品总价值（单个价值 × 数量）
        $unitValue = intval($item['value'] ?? 0);
        $quantity = intval($item['quantity'] ?? 1);
        $totalValue = $unitValue * $quantity;
        
        if ($totalValue < self::PAY_AMOUNT) {
            // 钱不够，发怒
            return [
                'success' => true,
                'message' => self::NPC_NAME . "一瞪眼：就这点东西？不想活了？？？\n",
                'consume_item' => true,
            ];
        }
        
        // 钱够了，放行
        self::setTempState($charId, self::HAS_PAID_KEY, '2', time() + 300); // 5分钟有效期
        self::clearWarningStates($charId);
        
        // 解除所有仇恨（如果在战斗中）
        // TODO: 实现脱战逻辑
        
        return [
            'success' => true,
            'message' => self::NPC_NAME . "嘿嘿嘿几声怪笑，闪身让道。\n",
            'consume_item' => true,
        ];
    }
    
    /**
     * 检查离开限制（valid_leave）
     * 玩家从饮马峪往西北走时调用
     */
    public static function checkValidLeave(int $charId, string $direction): ?array {
        // 只检查西北方向
        if ($direction !== 'northwest' && $direction !== '西北') {
            return null;
        }
        
        // 检查玩家是否在饮马峪
        $char = Database::queryOne("SELECT current_room, family, combat_exp FROM characters WHERE id = ?", [$charId]);
        if (!$char || $char['current_room'] !== self::ROOM_ID) {
            return null;
        }
        
        // 检查马盗是否还活着
        $deathKey = "npc_dead_" . self::NPC_ID;
        if (isset($_SESSION[$deathKey]) && $_SESSION[$deathKey] > time()) {
            return null; // 马盗已死，可以走
        }
        
        // 检查玩家是否是五庄观弟子
        $family = $char['family'] ?? '';
        if ($family === 'wzg' || $family === 'wuzhuang' || $family === '五庄观') {
            return null; // 五庄观弟子，可以走
        }
        
        // 检查玩家取经关卡是否≥2
        $obstacleNumber = self::getObstacleNumber($charId);
        if ($obstacleNumber >= 2) {
            return null; // 取经关卡≥2，可以走
        }
        
        // 检查是否付过钱
        $hasPaid = self::getTempState($charId, self::HAS_PAID_KEY);
        if ($hasPaid && intval($hasPaid) > 0) {
            // 付过钱了，减1
            $newPaid = intval($hasPaid) - 1;
            if ($newPaid <= 0) {
                self::deleteTempState($charId, self::HAS_PAID_KEY);
            } else {
                self::setTempState($charId, self::HAS_PAID_KEY, strval($newPaid), time() + 300);
            }
            return null; // 可以走
        }
        
        // 没付钱，拦截
        // 1/3概率被揪住
        if (mt_rand(0, 2) === 0) {
            $message = self::NPC_NAME . "恶狠狠地劈胸一把揪住你：往哪儿跑！给钱！\n";
            return [
                'allowed' => false,
                'message' => $message . "马盗喊叫着：不给钱我要杀人啦！\n",
            ];
        }
        
        return [
            'allowed' => false,
            'message' => "马盗喊叫着：不给钱我要杀人啦！\n",
        ];
    }
    
    /**
     * 发起攻击
     */
    private static function initiateAttack(int $charId): ?array {
        require_once __DIR__ . '/NpcAiHelper.php';
        require_once __DIR__ . '/../daemons/CombatDaemon.php';
        
        $npc = Database::queryOne("SELECT * FROM npcs WHERE id = ?", [self::NPC_ID]);
        if (!$npc) return null;
        
        $result = CombatDaemon::startKill($charId, $npc['id'], 'npc', $npc['name']);
        
        $attackMsg = self::NPC_NAME . "怒喝一声，朝你扑来！\n";
        
        return [
            'message' => $attackMsg,
            'combat' => $result,
        ];
    }
    
    /**
     * 获取玩家的取经关卡数
     * 基于qujing_history表统计完成的取经次数
     */
    private static function getObstacleNumber(int $charId): int {
        $row = Database::queryOne(
            "SELECT COUNT(*) as cnt FROM qujing_history WHERE char_id = ?",
            [$charId]
        );
        return intval($row['cnt'] ?? 0);
    }
    
    /**
     * 获取临时状态
     */
    private static function getTempState(int $charId, string $key): ?string {
        $row = Database::queryOne(
            "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ?",
            [$charId, $key]
        );
        return $row ? ($row['state_value'] ?? null) : null;
    }
    
    /**
     * 设置临时状态
     */
    private static function setTempState(int $charId, string $key, string $value, int $expireTime): void {
        Database::execute(
            "INSERT INTO character_temp_states (char_id, state_key, state_value, expire_time) 
             VALUES (?, ?, ?, FROM_UNIXTIME(?))
             ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), expire_time = VALUES(expire_time)",
            [$charId, $key, $value, $expireTime]
        );
    }
    
    /**
     * 删除临时状态
     */
    private static function deleteTempState(int $charId, string $key): void {
        Database::execute(
            "DELETE FROM character_temp_states WHERE char_id = ? AND state_key = ?",
            [$charId, $key]
        );
    }
    
    /**
     * 清除所有警告状态
     */
    private static function clearWarningStates(int $charId): void {
        self::deleteTempState($charId, self::WARNING1_KEY);
        self::deleteTempState($charId, self::WARNING2_KEY);
        self::deleteTempState($charId, self::WARNING3_KEY);
        self::deleteTempState($charId, self::KILLING_KEY);
    }
    
    /**
     * 清除所有状态
     */
    private static function clearAllStates(int $charId): void {
        self::deleteTempState($charId, self::ASKING_KEY);
        self::clearWarningStates($charId);
        // 注意：不清除has_paid，因为付钱后应该可以通行一段时间
    }
}
