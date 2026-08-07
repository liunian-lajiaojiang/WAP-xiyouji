<?php
/**
 * AI 玩家辅助类（增强版）
 * 
 * 负责：
 * - AI 玩家的行为决策（自主移动、战斗、修炼、任务等）
 * - AI 玩家的定时触发与行为调度
 * - AI 玩家的状态管理
 * 
 * AI 玩家行为策略（增强版）：
 * 1. 探索移动：根据当前房间的出口随机选择方向移动
 * 2. 战斗：遇到 aggressive/killer NPC 时自动应战
 * 3. 休息恢复：血量/精力低时自动恢复
 * 4. 修炼：空闲时练习技能/打坐
 * 5. 灭妖任务：在长安城时接取灭妖任务
 * 6. 开封任务：在开封府时接取开封任务
 * 7. 买药/回复：血量低且有钱时找药铺买药
 * 8. 学习技能：在门派师父处学习技能
 * 9. 社交：偶尔在房间说话
 * 
 * 触发机制：由 chat.php 轮询驱动（tickRandomOne）
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/../models/Room.php';

class AiPlayerHelper {
    
    /** AI 玩家行为权重配置 */
    const MOVE_WEIGHT = 15;          // 移动概率权重（降低，让AI更多做任务）
    const REST_WEIGHT = 10;          // 休息恢复权重
    const TRAIN_WEIGHT = 10;         // 修炼权重
    const TASK_WEIGHT = 25;          // 任务相关权重（提高：灭妖/开封）
    const HEAL_BUY_WEIGHT = 10;      // 买药/回复权重
    const LEARN_WEIGHT = 10;         // 学习技能权重
    const CHAT_WEIGHT = 5;           // 聊天权重
    const STAY_WEIGHT = 5;           // 停留不动权重
    const QUEST_NAV_WEIGHT = 10;     // 无任务时导航去接任务权重
    
    /** 休息触发阈值 */
    const REST_KEE_THRESHOLD = 0.4;   // 气血低于40%时优先休息
    const REST_GIN_THRESHOLD = 0.3;   // 精力低于30%时优先休息
    const AUTO_RECOVER_KEE_THRESHOLD = 0.35; // 残血时优先运功恢复
    const AUTO_RECOVER_GIN_THRESHOLD = 0.25; // 精神过低时优先运功恢复
    
    /** 买药触发阈值 */
    const CRITICAL_KEE_THRESHOLD = 0.15;  // 前期气血低于15%时走恢复流程（不传送，死就死）
    const LATE_CRITICAL_KEE_THRESHOLD = 0.25;  // 后期气血低于25%时紧急传送回客栈（极力避免死亡）
    const BUY_MED_KEE_THRESHOLD = 0.5;   // 气血低于50%时考虑买药
    const BUY_MED_GIN_THRESHOLD = 0.35;  // 精力低于35%时考虑买药
    
    /** 游戏阶段判定 */
    const LATE_GAME_COMBAT_EXP = 10000;  // 战斗经验>=10000视为后期（筋斗云任务门槛）
    
    /** 动作冷却时间（秒） */
    const ACTION_COOLDOWN = 3;        // 基础动作冷却
    
    /**
     * 获取所有 AI 玩家角色列表
     */
    public static function getAiPlayers(): array {
        return Database::queryAll(
            "SELECT * FROM characters WHERE is_ai_player = 1 AND online = 1"
        );
    }
    
    /**
     * 获取所有在线且未被暂停的 AI 玩家 ID 列表
     */
    public static function getAiPlayerIds(): array {
        $rows = Database::queryAll(
            "SELECT id FROM characters WHERE is_ai_player = 1 AND online = 1 AND (ai_paused = 0 OR ai_paused IS NULL)"
        );
        return array_column($rows, 'id');
    }
    
    /**
     * 获取所有在线 AI 玩家（含暂停的，供管理面板使用）
     */
    public static function getAiPlayerIdsAll(): array {
        $rows = Database::queryAll(
            "SELECT id FROM characters WHERE is_ai_player = 1 AND online = 1"
        );
        return array_column($rows, 'id');
    }

    /**
     * AI 详细调试模式开关
     */
    private static bool $debug = false;

    public static function setDebug(bool $debug): void {
        self::$debug = $debug;
    }

    private static function logDebug(string $message): void {
        if (!self::$debug) {
            return;
        }
        error_log('[AI_PLAYER DEBUG] ' . $message);
    }
    
    /**
     * 暂停指定 AI 玩家的行为
     */
    public static function pauseAiPlayer(int $charId): bool {
        return Database::execute(
            "UPDATE characters SET ai_paused = 1 WHERE id = ? AND is_ai_player = 1",
            [$charId]
        ) > 0;
    }
    
    /**
     * 恢复指定 AI 玩家的行为
     */
    public static function resumeAiPlayer(int $charId): bool {
        return Database::execute(
            "UPDATE characters SET ai_paused = 0 WHERE id = ? AND is_ai_player = 1",
            [$charId]
        ) > 0;
    }
    
    /**
     * 暂停所有 AI 玩家
     */
    public static function pauseAllAiPlayers(): int {
        return Database::execute(
            "UPDATE characters SET ai_paused = 1 WHERE is_ai_player = 1"
        );
    }
    
    /**
     * 恢复所有 AI 玩家
     */
    public static function resumeAllAiPlayers(): int {
        return Database::execute(
            "UPDATE characters SET ai_paused = 0 WHERE is_ai_player = 1"
        );
    }
    
    /**
     * 检查 AI 玩家是否被暂停
     */
    public static function isAiPaused(int $charId): bool {
        $row = Database::queryOne(
            "SELECT ai_paused FROM characters WHERE id = ?",
            [$charId]
        );
        return !empty($row) && intval($row['ai_paused'] ?? 0) === 1;
    }
    
    /**
     * 获取 AI 暂停状态摘要
     */
    public static function getAiPauseStatus(): array {
        $total = Database::queryOne(
            "SELECT COUNT(*) as cnt FROM characters WHERE is_ai_player = 1"
        );
        $paused = Database::queryOne(
            "SELECT COUNT(*) as cnt FROM characters WHERE is_ai_player = 1 AND ai_paused = 1"
        );
        return [
            'total' => intval($total['cnt'] ?? 0),
            'paused' => intval($paused['cnt'] ?? 0),
            'active' => intval($total['cnt'] ?? 0) - intval($paused['cnt'] ?? 0),
        ];
    }
    
    /**
     * 将指定角色标记为 AI 玩家
     */
    public static function markAsAiPlayer(int $charId): bool {
        return Database::execute(
            "UPDATE characters SET is_ai_player = 1 WHERE id = ?",
            [$charId]
        ) > 0;
    }
    
    /**
     * 取消 AI 玩家标记
     */
    public static function unmarkAiPlayer(int $charId): bool {
        return Database::execute(
            "UPDATE characters SET is_ai_player = 0 WHERE id = ?",
            [$charId]
        ) > 0;
    }
    
    /**
     * 检查指定角色是否是 AI 玩家
     */
    public static function isAiPlayer(int $charId): bool {
        $row = Database::queryOne(
            "SELECT is_ai_player FROM characters WHERE id = ?",
            [$charId]
        );
        return !empty($row) && intval($row['is_ai_player'] ?? 0) === 1;
    }
    
    /**
     * AI 玩家主行为调度入口
     */
    public static function tick(int $charId): array {
        // 1. 加载角色信息
        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'char_id' => $charId, 'char_name' => 'Unknown', 'message' => 'AI角色不存在'];
        }
        
        $charName = $char['name'] ?? 'Unknown';
        
        // 1.5. 修正可能的脏数据
        $currentRoom = $char['current_room'] ?? '';
        $currentArea = $char['current_area'] ?? '';
        
        // 1.5a. 去除 /d/ 前缀：/d/kaifeng/tianpeng → kaifeng/tianpeng
        if (strpos($currentRoom, '/d/') !== false) {
            $currentRoom = preg_replace('#^/d/#', '', $currentRoom);
            if (strpos($currentRoom, '/') === false && !empty($currentArea)) {
                $currentRoom = $currentArea . '/' . $currentRoom;
            }
        }
        
        // 1.5b. 修复重复前缀：city/city/kezhan → city/kezhan
        // 当 room 以 "area/" 开头时，去掉重复的 area 前缀
        if (!empty($currentArea) && preg_match('#^' . preg_quote($currentArea, '#') . '/(' . preg_quote($currentArea, '#') . '/)#', $currentRoom)) {
            $currentRoom = preg_replace('#^' . preg_quote($currentArea, '#') . '/#', '', $currentRoom, 1);
        }
        
        if ($currentRoom !== ($char['current_room'] ?? '')) {
            CharacterModel::updatePosition($charId, $currentArea, $currentRoom);
            $char['current_room'] = $currentRoom;
        }
        
        self::logDebug("Tick start for {$charName} (ID:{$charId}), location={$currentArea}/{$currentRoom}, kee={$char['kee']} max_kee={$char['max_kee']}, gin={$char['gin']} max_gin={$char['max_gin']}");
        
        // 2. 检查是否是 AI 玩家
        if (intval($char['is_ai_player'] ?? 0) !== 1) {
            return ['success' => false, 'char_id' => $charId, 'char_name' => $charName, 'message' => '非AI玩家'];
        }
        
        // 3. 检查是否在线
        if (intval($char['online'] ?? 0) !== 1) {
            return ['success' => false, 'char_id' => $charId, 'char_name' => $charName, 'message' => 'AI玩家离线'];
        }
        
        // 4. 检查是否被暂停
        if (intval($char['ai_paused'] ?? 0) === 1) {
            return ['success' => false, 'char_id' => $charId, 'char_name' => $charName, 'message' => 'AI已暂停'];
        }
        
        // 5. 检查冷却时间
        $lastAction = intval($char['ai_last_action'] ?? 0);
        if (time() - $lastAction < self::ACTION_COOLDOWN) {
            return ['success' => false, 'char_id' => $charId, 'char_name' => $charName, 'message' => '冷却中'];
        }
        
        // 5. 检查特殊状态
        if (!empty($char['unconscious_state']) && $char['unconscious_state'] == 1) {
            self::updateLastAction($charId);
            return ['success' => true, 'char_id' => $charId, 'char_name' => $charName, 'message' => 'AI昏迷中，等待苏醒'];
        }
        
        if (!empty($char['sleep_state']) && $char['sleep_state'] == 1) {
            $sleepEnd = intval($char['sleep_end_time'] ?? 0);
            if (time() < $sleepEnd) {
                self::updateLastAction($charId);
                return ['success' => true, 'char_id' => $charId, 'char_name' => $charName, 'message' => 'AI睡眠中'];
            }
            if (function_exists('wakeup_player')) {
                wakeup_player($charId);
            }
        }

        // === 提前读取 ai_ghost_flow 状态（用于扩展 handleGhostFlow 触发条件）===
        $ghostFlowStateRow = Database::queryOne(
            "SELECT temp_value FROM character_temp WHERE char_id=? AND temp_key='ai_ghost_flow'",
            [$charId]
        );
        $ghostFlowState = $ghostFlowStateRow ? json_decode($ghostFlowStateRow['temp_value'] ?? '', true) : null;
        $ghostPhase = $ghostFlowState['phase'] ?? '';

        // === 鬼魂 stale 状态提前清理 ===
        // 角色已复活但 ai_ghost_flow 残留 → 在进入 handleGhostFlow 之前就清理
        // （handleGhostFlow 的准入条件是 is_ghost=1 或 hell_enter_time>0，
        //   复活后两者都=0，handleGhostFlow 永不调用 → 状态永久残留）
        $isGhost = intval($char['is_ghost'] ?? 0) === 1;
        $hasHellTime = intval($char['hell_enter_time'] ?? 0) > 0;
        $keeCheck = intval($char['kee'] ?? 0);
        // 还魂完成但状态残留（exit_nav/exit_out 阶段因故未执行完）
        $hasExitPhase = in_array($ghostPhase, ['exit_nav', 'exit_out']);
        if (!$isGhost && !$hasHellTime && $keeCheck > 1 && !$hasExitPhase) {
            if ($ghostFlowState) {
                self::logDebug("Ghost flow state stale for char {$charId} (alive, kee>1, phase={$ghostPhase}), auto-clearing");
                self::clearGhostFlowState($charId);
                self::clearNavigationTarget($charId);
                self::completeGoal($charId, 'revive');
            }
        }

        // 检查鬼魂死亡复活状态 — 多阶段还魂流程
        // 触发条件：
        //   1. is_ghost=1（鬼魂）
        //   2. hell_enter_time>0且is_ghost=0（刚穿墙，在酆都城）
        //   3. ai_ghost_flow 存在且 phase=exit_nav/exit_out（已还魂，但还需完成跨出栏杆）
        $shouldHandleGhost = $isGhost || ($hasHellTime && !$isGhost) || $hasExitPhase;
        if ($shouldHandleGhost) {
            // 如果是因为 exit_nav/exit_out 触发，临时修正 isGhost/hasHellTime 让 handleGhostFlow 正确处理
            if ($hasExitPhase && !$isGhost && !$hasHellTime) {
                // 角色已还魂，但 ghost flow 还在 exit_nav/exit_out 阶段
                // 直接调用 handleGhostFlow（它会读取 $ghostFlowState 并处理）
                $char['_ghost_exit_phase'] = true; // 标记：这是 exit 阶段，不是鬼魂阶段
            }
            $ghostResult = self::handleGhostFlow($charId, $char);
            if ($ghostResult !== null) {
                $ghostResult['char_id'] = $charId;
                $ghostResult['char_name'] = $charName;
                self::updateLastAction($charId);
                $action = $ghostResult['action'] ?? '';
                self::logAiAction($charId, $charName, $action, $ghostResult);

                // 鬼魂流程导航类动作：走 processNavigationTarget 但跳过正常决策
                // （鬼魂流程不能叫 decideAction，否则 move_to_safe 会把鬼魂传回 city）
                $navActions = ['ghost_wall_nav', 'ghost_nav', 'ghost_city_nav', 'ghost_exit_nav'];
                if (in_array($action, $navActions)) {
                    $char = CharacterModel::find($charId) ?: $char;
                    // 走导航系统实现实际移动
                    $navStep = self::processNavigationTarget($charId, $char);
                    if ($navStep !== null) {
                        $navStep['char_id'] = $charId;
                        $navStep['char_name'] = $charName;
                        $navStep['_ghost_nav'] = true;
                        self::updateLastAction($charId);
                        self::logAiAction($charId, $charName, 'ghost_nav_step', $navStep);
                        return $navStep;
                    }
                    // 导航到目的地了或其他情况，返回 ghost 流程结果
                    return $ghostResult;
                } elseif ($action === 'ghost_wall_pass' || $action === 'ghost_reincarnate') {
                    // 穿墙/还魂后角色位置变了，重新加载
                    $char = CharacterModel::find($charId) ?: $char;
                    // 如果是穿墙且当前还在 death 区域，继续让导航系统走一步
                    $postArea = $char['current_area'] ?? '';
                    if ($action === 'ghost_wall_pass' && $postArea === 'death') {
                        // 继续流程让导航朝着 zhengtang 走
                    } else {
                        return $ghostResult;
                    }
                } else {
                    return $ghostResult;
                }
            }
        }

        // 检查是否在战斗中
        require_once __DIR__ . '/../daemons/CombatDaemon.php';
        if (CombatDaemon::isInCombat($charId)) {
            $result = self::doCombatAction($charId, $char);
            $result['char_id'] = $charId;
            $result['char_name'] = $charName;
            self::updateLastAction($charId);
            return $result;
        }

        // 残血时优先运功恢复，避免直接追打或乱跑
        $autoRecover = self::tryAutoRecover($charId, $char);
        if ($autoRecover !== null) {
            $autoRecover['char_id'] = $charId;
            $autoRecover['char_name'] = $charName;
            self::logDebug('tryAutoRecover result: ' . json_encode($autoRecover, JSON_UNESCAPED_UNICODE));
            // 如果只是运功恢复（action=recover）或普通休息（action=rest），应用恢复并继续后续决策
            if (!empty($autoRecover['action']) && ($autoRecover['action'] === 'recover' || $autoRecover['action'] === 'rest')) {
                self::updateLastAction($charId);
                self::logAiAction($charId, $charName, 'recover', $autoRecover);
                // 重新加载角色数据以反映恢复后的状态，继续后续决策
                $char = CharacterModel::find($charId) ?: $char;
            } else {
                self::updateLastAction($charId);
                return $autoRecover;
            }
        }
        
        // 检查当前房间是否有可攻击的 NPC，尝试自动触发战斗
        $combatResult = self::tryInitiateCombat($charId, $char);
        if ($combatResult !== null) {
            $combatResult['char_id'] = $charId;
            $combatResult['char_name'] = $charName;
            self::updateLastAction($charId);
            return $combatResult;
        }

        // 检查当前房间是否有自己的灭妖任务妖怪，AI 自动发起击杀
        $mieyaoResult = self::tryAutoKillMieyao($charId, $char);
        if ($mieyaoResult !== null) {
            $mieyaoResult['char_id'] = $charId;
            $mieyaoResult['char_name'] = $charName;
            self::updateLastAction($charId);
            return $mieyaoResult;
        }
        
        // 6. 检查是否有"导航目标"（之前设置的目的地），如有则每 tick 走一步（不直接跳转）
        $navResult = self::processNavigationTarget($charId, $char);
        if ($navResult !== null) {
            // navResult 可能是：
            // - 方向字符串（如 'north', 'west'）：stepTowards 已移动，直接记录日志
            // - 'fly_away' 字符串：需要 executeAction 执行飞离动作
            // - 'mufa_ok' 字符串：tryExecuteMufaAction 已执行登船/下船，角色已移动，跳过 executeAction
            // - 数组（如 mufa rest 结果）：让 tick 继续正常决策（不动）
            if (is_string($navResult) && !in_array($navResult, ['fly_away', 'mufa_ok'])) {
                // 方向字符串：角色已在 RoomNavHelper::doStep 中移动过，跳过 executeAction
                // 直接返回成功结果供日志记录
                $dirNames = [
                    'north' => '北', 'south' => '南', 'east' => '东', 'west' => '西',
                    'northwest' => '西北', 'northeast' => '东北',
                    'southwest' => '西南', 'southeast' => '东南',
                    'up' => '上', 'down' => '下', 'out' => '出去', 'in' => '进入',
                ];
                $dirName = $dirNames[$navResult] ?? $navResult;
                $execResult = [
                    'success' => true,
                    'message' => "向{$dirName}走去。",
                    'action' => 'move',
                    'ai_detail' => "方向{$navResult}",
                ];
                $execResult['char_id'] = $charId;
                $execResult['char_name'] = $charName;
                $execResult['_nav_step'] = true;
                self::recordAction($charId, 'nav_step', 'success', $execResult['ai_detail'] ?? '');
                self::updateLastAction($charId);
                self::logAiAction($charId, $charName, 'nav_' . $navResult, $execResult);
                return $execResult;
            }
            // 'fly_away' 或 mufa 特殊动作 或数组：需要 executeAction 执行
            $navAction = is_array($navResult) ? ($navResult['action'] ?? null) : $navResult;
            // 'mufa_ok' / 'board_mufa' / 'disembark_mufa'：木筏动作已完成，角色已移动
            if (in_array($navResult, ['mufa_ok', 'board_mufa', 'disembark_mufa'])) {
                $execResult = [
                    'success' => true,
                    'message' => '乘坐木筏...',
                    'action' => 'move',
                    'ai_detail' => "已通过木筏移动",
                ];
                $execResult['char_id'] = $charId;
                $execResult['char_name'] = $charName;
                $execResult['_nav_step'] = true;
                self::recordAction($charId, 'nav_mufa', 'success', '木筏移动完成');
                self::updateLastAction($charId);
                self::logAiAction($charId, $charName, 'nav_mufa', $execResult);
                return $execResult;
            }
            if ($navAction !== null) {
                $execResult = self::executeAction($charId, $char, $navAction);
                $execResult['char_id'] = $charId;
                $execResult['char_name'] = $charName;
                $execResult['_nav_step'] = true;
                $actionResult = ($execResult['success'] ?? false) ? 'success' : 'failed';
                self::recordAction($charId, $navAction, $actionResult, $execResult['ai_detail'] ?? '');
                self::updateLastAction($charId);
                self::logAiAction($charId, $charName, 'nav_' . $navAction, $execResult);
                return $execResult;
            }
            // navResult 有内容但没有 action 字段（如 mufa 等待状态），继续正常决策
        }

        // 7. 检查持久化目标：如果有未完成的目标，优先执行
        $goal = self::getGoal($charId);
        if ($goal !== null) {
            $goalAction = self::executeGoalAction($charId, $char, $goal);
            if ($goalAction !== null) {
                $goalAction['char_id'] = $charId;
                $goalAction['char_name'] = $charName;
                self::updateLastAction($charId);
                self::logAiAction($charId, $charName, 'goal_' . ($goal['type'] ?? 'unknown'), $goalAction);
                return $goalAction;
            }
            // 目标无法执行（如目标已失效），清除目标
            self::completeGoal($charId);
        }

        // 8. 根据状态和位置决策下一步行为
        $action = self::decideAction($char);
        $result = self::executeAction($charId, $char, $action);
        $result['char_id'] = $charId;
        $result['char_name'] = $charName;

        // 9. 记录行为到学习记忆
        $actionResult = ($result['success'] ?? false) ? 'success' : 'failed';
        self::recordAction($charId, $action, $actionResult, $result['ai_detail'] ?? '');

        // 10. 检测是否卡住：如果是 move 且连续多次 → 尝试设定探索目标
        if ($action === 'move' && self::isStuck($charId, $char)) {
            self::logDebug("AI {$charName}({$charId}) seems stuck — trying alternative action next tick");
            self::recordAction($charId, 'stuck_detected', 'warning', '连续多次移动，可能卡住');
        }

        // 11. 更新最后动作时间
        self::updateLastAction($charId);

        // 12. 记录 AI 行为日志
        self::logAiAction($charId, $charName, $action, $result);

        return $result;
    }

    /**
     * 处理 AI 的导航目标：每 tick 走一步，朝目标方向走（不直接跳转）
     *
     * - 检查 character_temp 表中是否有 ai_nav_target
     * - 格式：JSON {"room":"kaifeng/tianpeng","area":"kaifeng","set_at":1234567890}
     * - 每 tick 调用 RoomNavHelper::stepTowards 走一步
     * - 到达目标后清除标记
     *
     * @return string|null 方向字符串（已在 stepTowards/doStep 中移动，跳过 executeAction）；无目标返回 null
     * @return string 'fly_away'：孤立房间需要飞离
     * @return string 'mufa_ok'：木筏动作已执行（角色已移动）
     * @return array ['action' => 'rest', ...]：等待状态，让 tick 继续正常决策
     */
    private static function processNavigationTarget(int $charId, array $char) {
        $navRow = Database::queryOne(
            "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = 'ai_nav_target'",
            [$charId]
        );
        if (!$navRow) {
            return null;
        }

        $nav = json_decode($navRow['temp_value'] ?? '', true);
        if (!is_array($nav) || empty($nav['room'])) {
            // 标记损坏，清除
            Database::execute(
                "DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'ai_nav_target'",
                [$charId]
            );
            return null;
        }

        $targetRoom = $nav['room'] ?? '';
        $targetArea = $nav['area'] ?? '';
        $setAt      = intval($nav['set_at'] ?? 0);
        $stepCount  = intval($nav['step_count'] ?? 0);
        $purpose    = $nav['purpose'] ?? '';
        $lastRooms  = $nav['last_rooms'] ?? [];
        $lastStepRoom = $nav['last_step_room'] ?? '';

        // 先计算当前完整房间路径
        $currentArea = $char['current_area'] ?? '';
        $currentRoom = $char['current_room'] ?? '';
        $fullRoom    = (strpos($currentRoom, '/') !== false) ? $currentRoom : $currentArea . '/' . $currentRoom;
        $normalizedFullRoom = self::normalizeRoomPath($fullRoom);
        $normalizedTargetRoom = self::normalizeRoomPath($targetRoom);

        // === 手动移动检测 ===
        // 如果上次导航走过一步，记录了预期到达的房间，但当前房间与预期不符→被手动移动过→清除导航
        if (!empty($lastStepRoom) && $normalizedFullRoom !== self::normalizeRoomPath($lastStepRoom)) {
            self::logDebug("Nav manual move detected: char {$charId}, expected={$lastStepRoom}, actual={$normalizedFullRoom}, clearing nav");
            Database::execute(
                "DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'ai_nav_target'",
                [$charId]
            );
            return null;
        }

        // === 导航死循环检测 ===
        // 多重检测策略，确保任何循环都被捕获
        $navCleared = false;

        // 策略1：AB→A 振荡检测（1个来回即触发）
        if (count($lastRooms) >= 3) {
            $recent3 = array_slice($lastRooms, -3);
            if (count(array_unique($recent3)) <= 2 && $recent3[0] === $recent3[2] && $recent3[0] !== $recent3[1]) {
                $oscCount = 0;
                for ($i = count($lastRooms) - 2; $i >= 1; $i -= 2) {
                    if ($lastRooms[$i] === $recent3[1] && $lastRooms[$i - 1] === $recent3[0]) {
                        $oscCount++;
                    } else {
                        break;
                    }
                }
                if ($oscCount >= 1) {
                    self::logDebug("Nav oscillation: char {$charId} cycling {$recent3[0]}<->{$recent3[1]} (x{$oscCount}), clearing nav");
                    self::recordAction($charId, 'nav_oscillation', 'failed',
                        "位置振荡: {$recent3[0]}↔{$recent3[1]}, 走了{$oscCount}个来回");
                    $navCleared = true;
                }
            }
        }

        // 策略2：同房间反复出现检测（AI在3+个房间间兜圈子）
        if (!$navCleared && count($lastRooms) >= 5 && $stepCount >= 4) {
            $roomFreq = array_count_values($lastRooms);
            arsort($roomFreq);
            $topFreq = reset($roomFreq);
            $topRoom = key($roomFreq);
            if ($topFreq >= 3) {
                self::logDebug("Nav looping: char {$charId} visited {$topRoom} {$topFreq} times in last " . count($lastRooms) . " steps, clearing nav");
                self::recordAction($charId, 'nav_looping', 'failed',
                    "循环兜圈: 在{$topRoom}出现了{$topFreq}次，共走了{$stepCount}步");
                $navCleared = true;
            }
        }

        if ($navCleared) {
            // 将失败的目标加入黑名单，防止 decideAction 再次设置相同目标导致无限振荡
            self::addToNavBlacklist($charId, $targetRoom);
            Database::execute(
                "DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'ai_nav_target'",
                [$charId]
            );
            return null;
        }

        // 注意：不再在导航过程中因灭妖任务清除导航目标
        // 之前的逻辑会在每 tick 删除导航 → 导致 AI 永远无法走出一步
        // 灭妖导航由 decideAction → checkMieyaoNavigation → doMieyaoNavigate 统一管理

        // 目标过期（超过 10 分钟未到达）→ 清除标记，重新决策
        if ($setAt > 0 && (time() - $setAt) > 600) {
            Database::execute(
                "DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'ai_nav_target'",
                [$charId]
            );
            return null;
        }

        // 已到达目标：清除标记，让正常决策接管
        if ($normalizedFullRoom === $normalizedTargetRoom) {
            Database::execute(
                "DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'ai_nav_target'",
                [$charId]
            );
            // AI玩家不发送移动消息
            // require_once __DIR__ . '/../daemons/MessageDaemon.php';
            // $msg = HTML_HIGRN . "你抵达了目的地。" . HTML_NOR;
            // MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
            return null; // 让正常决策执行后续动作
        }

        // 尝试 BFS 走一步（支持跨区域路径，如 dntg → changan → city）
        // stepTowards 已内部调用 doStep → moveCharacter 移动了角色，返回方向字符串供日志记录
        require_once __DIR__ . '/RoomNavHelper.php';
        $stepDir = RoomNavHelper::stepTowards($charId, $char, $targetRoom);
        // stepTowards 返回字符串：成功移动了一步；返回 null：没有普通出口通往目标
        if ($stepDir !== null) {
            // BFS 成功移动了一步，更新导航状态（步数+位置记录）
            self::updateNavState($charId, $nav, $stepCount, $lastRooms, $stepDir);
            // 返回方向字符串让 tick 记录日志（移动已在 doStep 中完成）
            return $stepDir;
        }

        // ========== 木筏特殊处理：BFS 走不通时通过木筏跨海 ==========
        // 木筏状态机：at_shore →(15s)→ sailing_away →(35s)→ at_dest →(55s)→ sailing_back →(65s)→ at_shore
        if (in_array($normalizedFullRoom, ['changan/eastseashore', 'changan/aolaiws', 'changan/mufa'])) {
            $mufaResult = self::handleMufaNavigation($charId, $char, $normalizedFullRoom, $targetRoom, $nav, $stepCount, $lastRooms);
            if ($mufaResult !== null) {
                return $mufaResult;
            }
            // mufaResult === null → 需要等木筏到岸
            // 计数卡住次数，超过 12 次（12 × 5s = 60s）则放弃导航
            $stuckTicks = intval($nav['stuck_ticks'] ?? 0) + 1;
            $nav['stuck_ticks'] = $stuckTicks;
            Database::execute(
                "UPDATE character_temp SET temp_value = ? WHERE char_id = ? AND temp_key = 'ai_nav_target'",
                [json_encode($nav), $charId]
            );
            if ($stuckTicks > 12) {
                self::logDebug("Nav mufa stuck: char {$charId} waited {$stuckTicks} ticks for mufa at {$normalizedFullRoom}, clearing nav");
                self::recordAction($charId, 'nav_mufa_stuck', 'failed',
                    "木筏等待超时: 在{$normalizedFullRoom}等了{$stuckTicks}次木筏，放弃");
                Database::execute(
                    "DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'ai_nav_target'",
                    [$charId]
                );
                return null;
            }
            return ['action' => 'rest', 'target' => null, 'message' => '等待木筏到岸...'];
        }
        
        // 跨区域且当前区域不对：尝试 BFS 找路（支持经中转区域的多跳路径）
        $currentAreaTop = explode('/', $normalizedFullRoom)[0] ?? '';
        $targetAreaTop  = explode('/', $normalizedTargetRoom)[0] ?? '';
        
        // 检查是否卡住：超过 30 步跨区域无进展 → 清除导航目标
        if ($currentAreaTop !== $targetAreaTop && $stepCount > 30) {
            self::logDebug("Nav stuck: char {$charId} at {$fullRoom}, target={$targetRoom}, steps={$stepCount}, clearing nav");
            self::recordAction($charId, 'nav_stuck', 'failed',
                "跨区域导航卡住: {$currentAreaTop}→{$targetAreaTop}, 走了{$stepCount}步");
            Database::execute(
                "DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'ai_nav_target'",
                [$charId]
            );
            // AI玩家不发送移动消息
            // require_once __DIR__ . '/../daemons/MessageDaemon.php';
            // MessageDaemon::queueMessageToSelf($charId,
            //     HTML_HIYEL . '你迷失了方向，不知该往何处去...' . HTML_NOR, 'self_event');
            return null; // 让正常决策接管
        }
        
        // ========== fly_away 特殊动作：直接执行，不走 stepTowards ==========
        // 注意：目标可能是 'fly_away'（孤立房间触发）或真实房间路径
        if ($targetRoom === 'fly_away') {
            // 孤立房间场景：直接飞走，不经过 stepTowards（stepTowards 找不到名为 fly_away 的房间）
            if (self::canFly($char)) {
                // 清除导航，返回 'fly_away' 让 tick 调用 doFlyAway
                Database::execute(
                    "DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'ai_nav_target'",
                    [$charId]
                );
                return 'fly_away';
            } else {
                // 不能飞，尝试步行
                return self::doWalkToCity($charId, $char);
            }
        }

        // stepTowards 返回 null：没有普通出口通往目标
        // 检查孤立死胡同房间 → fly_away，或 fallback 随机走

        // 没有普通路可走 → 检查是否卡住
        // 跨区域导航 max 12 步，同区域 max 20 步（跨区域必须快速离开，同区域多给机会）
        $currentArea = $char['current_area'] ?? '';
        $targetAreaTop = explode('/', $targetRoom)[0] ?? '';
        $isCrossArea = ($currentArea !== $targetAreaTop);
        $maxSteps = $isCrossArea ? 12 : 20;
        if ($stepCount > $maxSteps) {
            self::logDebug("Nav exhausted: char {$charId} BFS no path to {$targetRoom}, steps={$stepCount}, cross={$isCrossArea}");
            self::recordAction($charId, 'nav_exhausted', 'no_path',
                "BFS无路径到{$targetRoom}, 走了{$stepCount}步");
            Database::execute(
                "DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'ai_nav_target'",
                [$charId]
            );
            // AI玩家不发送移动消息
            // require_once __DIR__ . '/../daemons/MessageDaemon.php';
            // MessageDaemon::queueMessageToSelf($charId,
            //     HTML_HIYEL . '你找来找去也找不到通往目的地的路...' . HTML_NOR, 'self_event');
            // 清除导航目标，让正常决策接管（decideAction 会重新评估并设定新目标）
            return null;
        }

        // stepTowards 返回 null 且不是木筏房间：检查是否是孤立死胡同房间
        // 孤立房间指：本身没有通向外部的出口（如 dntg/hgs/entrance 仙石）
        // 这类房间只能通过 fly_away 离开
        $isolatedRooms = ['dntg/hgs/entrance' => '傲来国']; // room_id => fly目标名
        if (isset($isolatedRooms[$normalizedFullRoom]) && self::canFly($char)) {
            self::recordAction($charId, 'fly_away', 'isolated_room',
                "孤立死胡同房间{$normalizedFullRoom}无法导航,飞往{$isolatedRooms[$normalizedFullRoom]}");
            // 清除当前导航，改用 fly_away
            Database::execute(
                "DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'ai_nav_target'",
                [$charId]
            );
            return 'fly_away'; // 字符串让 tick 调用 doFlyAway
        }

        // 尝试随机走一步（死胡同中碰运气）
        return self::stepRandom($charId, $char, $stepCount, $purpose, $targetRoom);
    }

    /**
     * 随机走一步（导航目标存在但无法智能导航时的 fallback）
     * doMove 已内部调用 moveCharacter 移动，返回方向字符串供 tick 记录日志
     */
    private static function stepRandom(int $charId, array $char, int $stepCount, string $purpose, string $targetRoom): string {
        // 随机走（doMove 内部已调用 moveCharacter）
        $moveResult = self::doMove($charId, $char);
        // 累计步数
        $navRow = Database::queryOne(
            "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = 'ai_nav_target'",
            [$charId]
        );
        if ($navRow) {
            $nav = json_decode($navRow['temp_value'] ?? '', true);
            if (is_array($nav)) {
                self::updateNavState($charId, $nav, $stepCount, $nav['last_rooms'] ?? [], 'random');
            }
        }
        // 从 ai_detail 中提取实际移动的方向（如 "向north移动 → city/zhuo")
        $aiDetail = $moveResult['ai_detail'] ?? '';
        $dirNames = ['north', 'south', 'east', 'west', 'northup', 'northdown', 'southup', 'southdown',
            'eastup', 'eastdown', 'westup', 'westdown', 'up', 'down', 'out', 'in', 'enter',
            'northwest', 'northeast', 'southwest', 'southeast'];
        foreach ($dirNames as $d) {
            if (strpos($aiDetail, $d) !== false) {
                return $d;
            }
        }
        return $moveResult['action'] ?? 'random';
    }

    /**
     * AI 木筏导航处理：海岸 ↔ 木筏 ↔ 对岸
     * 
     * 木筏状态机：at_shore →(15s)→ sailing_away →(35s)→ at_dest →(55s)→ sailing_back →(65s)→ at_shore
     * - eastseashore 岸：at_shore 时可登船，sailing_back 结束回到这里
     * - aolaiws 岸：at_dest 时可登船，sailing_away 结束到达这里
     * - mufa 船上：at_dest 时可下到 aolaiws，at_shore 时可下到 eastseashore
     * 
     * @return array|string|null 导航结果，null 表示需要等待
     */
    private static function handleMufaNavigation(
        int $charId, array $char, string $currentRoom, string $targetRoom,
        array $nav, int $stepCount, array $lastRooms
    ): mixed {
        $stateFile = __DIR__ . '/../data/mufa_state.json';
        $mufaState = ['status' => 'at_shore', 'trigger_time' => null];
        if (file_exists($stateFile)) {
            $raw = json_decode(file_get_contents($stateFile), true);
            if ($raw) {
                // 根据时间自动计算真实状态
                $now = time();
                $elapsed = $raw['trigger_time'] ? ($now - $raw['trigger_time']) : 0;
                $status = $raw['status'] ?? 'at_shore';
                if ($elapsed >= 0 && $elapsed < 15) $status = 'at_shore';
                elseif ($elapsed >= 15 && $elapsed < 35) $status = 'sailing_away';
                elseif ($elapsed >= 35 && $elapsed < 55) $status = 'at_dest';
                elseif ($elapsed >= 55 && $elapsed < 65) $status = 'sailing_back';
                else $status = 'at_shore';
                $mufaState = ['status' => $status, 'trigger_time' => $raw['trigger_time'], 'elapsed' => $elapsed];
            }
        }
        
        $status = $mufaState['status'];
        $elapsed = $mufaState['elapsed'] ?? 0;
        
        // 判断目标在哪个方向
        // 东侧（eastseashore/beach/etc）：大部分 changan 房间
        // 西侧（aolaiws/longgong/dntg/etc）：需要通过木筏跨海才能到达
        $targetIsWest = self::isTargetOnWestSide($targetRoom);
        
        // 在东海之滨：想往西走 → 需要木筏在 at_shore
        if ($currentRoom === 'changan/eastseashore') {
            if ($status === 'at_shore') {
                // 木筏在岸边，登船
                self::logDebug("Mufa: char {$charId} boarding from eastseashore, status={$status}");
                self::recordAction($charId, 'mufa_board_east', 'boarding', "从东海之滨登木筏");
                return self::executeMufaBoard($charId, $char, 'eastseashore');
            }
            // 木筏不在，记录等待
            $waitSecs = self::mufaWaitSeconds($status, $elapsed, 'eastseashore');
            self::recordAction($charId, 'mufa_wait_coast', $status, 
                "东海之滨等木筏({$status}), {$waitSecs}秒后到岸");
            return null; // 让调用者返回 rest
        }
        
        // 在傲来国西海岸：想往东走 → 需要木筏在 at_dest
        if ($currentRoom === 'changan/aolaiws') {
            if ($status === 'at_dest') {
                self::logDebug("Mufa: char {$charId} boarding from aolaiws, status={$status}");
                self::recordAction($charId, 'mufa_board_west', 'boarding', "从傲来国西海岸登木筏");
                return self::executeMufaBoard($charId, $char, 'aolaiws');
            }
            $waitSecs = self::mufaWaitSeconds($status, $elapsed, 'aolaiws');
            self::recordAction($charId, 'mufa_wait_coast', $status,
                "傲来国西海岸等木筏({$status}), {$waitSecs}秒后到岸");
            return null;
        }
        
        // 在木筏上(changan/mufa)：看靠哪个岸
        if ($currentRoom === 'changan/mufa') {
            if ($status === 'at_shore' && !$targetIsWest) {
                // 到了东岸，目标在东侧 → 下船
                self::logDebug("Mufa: char {$charId} disembarking to eastseashore");
                self::recordAction($charId, 'mufa_disembark_east', 'disembarking', "木筏靠东岸，下船");
                return self::executeMufaDisembark($charId, $char, 'eastseashore');
            }
            if ($status === 'at_dest' && $targetIsWest) {
                // 到了西岸，目标在西侧 → 下船
                self::logDebug("Mufa: char {$charId} disembarking to aolaiws");
                self::recordAction($charId, 'mufa_disembark_west', 'disembarking', "木筏靠西岸，下船");
                return self::executeMufaDisembark($charId, $char, 'aolaiws');
            }
            if ($status === 'at_shore' || $status === 'at_dest') {
                // 靠岸了但方向不对（比如东岸但目标在西侧）→ 等下一轮
                // 或者已经到了但需要确认
                $wrong = ($status === 'at_shore' && $targetIsWest) || ($status === 'at_dest' && !$targetIsWest);
                if ($wrong) {
                    // 方向不对，下船到当前岸再说（让导航重新规划）
                    $destRoom = ($status === 'at_shore') ? 'eastseashore' : 'aolaiws';
                    self::recordAction($charId, 'mufa_disembark_wrong_dir', 'disembarking', "木筏方向不对，先下船");
                    return self::executeMufaDisembark($charId, $char, $destRoom);
                }
            }
            // 航行中，等待
            $waitSecs = self::mufaWaitSeconds($status, $elapsed, 'mufa');
            self::recordAction($charId, 'mufa_sailing', $status, "木筏航行中({$status}), {$waitSecs}秒后到岸");
            return ['action' => 'rest', 'target' => null, 'message' => "木筏航行中，等待靠岸..."];
        }
        
        return null;
    }
    
    /**
     * 判断目标房间是否在西侧（需要通过木筏跨海才能到达）
     * 西侧包括：aolaiws、longgong、dntg 等只在傲来国方向的区域
     */
    private static function isTargetOnWestSide(string $targetRoom): bool {
        // 以 aolaiws 开头或以特定西侧区域开头
        $westPrefixes = ['changan/aolaiws', 'longgong/', 'dntg/', 'nanhai/'];
        foreach ($westPrefixes as $prefix) {
            if (strpos($targetRoom, $prefix) === 0) {
                return true;
            }
        }
        // 东侧（默认）：eastseashore, beach, changan 其他房间, city 等
        return false;
    }
    
    /**
     * 计算木筏到岸还需等待的秒数
     */
    private static function mufaWaitSeconds(string $status, int $elapsed, string $fromRoom): int {
        if ($fromRoom === 'eastseashore') {
            // 等 at_shore：sailing_back → at_shore(65s), at_dest → sailing_back(55s)→at_shore
            if ($status === 'sailing_back') return max(0, 65 - $elapsed);
            if ($status === 'at_dest') return max(0, 55 - $elapsed) + 10; // 到sailing_back + 10
            if ($status === 'sailing_away') return max(0, 35 - $elapsed) + 30; // 到at_dest→sailing_back→at_shore
            return 0; // at_shore
        }
        if ($fromRoom === 'aolaiws') {
            // 等 at_dest：at_shore → sailing_away(15s)→at_dest(35s)
            if ($status === 'at_shore') return max(0, 15 - $elapsed) + 20;
            if ($status === 'sailing_away') return max(0, 35 - $elapsed);
            if ($status === 'sailing_back') return max(0, 65 - $elapsed) + 15;
            return 0; // at_dest
        }
        // 在木筏上，等到最近的岸
        if ($status === 'at_shore' || $status === 'at_dest') return 0;
        if ($status === 'sailing_away') return max(0, 35 - $elapsed); // 到at_dest
        if ($status === 'sailing_back') return max(0, 65 - $elapsed); // 到at_shore
        return 0;
    }
    
    /**
     * AI 登木筏：调用玩家级别的 MufaHandler
     */
    private static function executeMufaBoard(int $charId, array $char, string $shoreType): string {
        require_once __DIR__ . '/../daemons/MufaHandler.php';
        $handler = new MufaHandler();
        $actionName = '上木筏';
        $action = [
            'action_name' => $actionName,
            'action_cmd' => 'go mufa',
        ];
        $result = $handler->execute($charId, $action, []);
        if (!empty($result['success'])) {
            self::logDebug("Mufa: char {$charId} boarded from {$shoreType}, success");
            return 'mufa_ok';
        }
        self::logDebug("Mufa: char {$charId} board from {$shoreType} failed: " . ($result['message'] ?? 'unknown'));
        return 'board_failed';
    }
    
    /**
     * AI 下木筏：调用玩家级别的 MufaHandler
     */
    private static function executeMufaDisembark(int $charId, array $char, string $destRoom): string {
        require_once __DIR__ . '/../daemons/MufaHandler.php';
        $handler = new MufaHandler();
        $action = [
            'action_name' => '下木筏',
            'action_cmd' => 'go mufa',
        ];
        $result = $handler->execute($charId, $action, []);
        if (!empty($result['success'])) {
            self::logDebug("Mufa: char {$charId} disembarked to {$destRoom}, success");
            return 'mufa_ok';
        }
        self::logDebug("Mufa: char {$charId} disembark to {$destRoom} failed: " . ($result['message'] ?? 'unknown'));
        return 'disembark_failed';
    }

    /**
     * 更新导航状态：在 stepTowards 或 BFS 成功移动一步后调用
     * 更新 step_count、last_step_room、last_rooms（最近5个房间用于振荡检测）
     */
    private static function updateNavState(int $charId, array &$nav, int $stepCount, array $lastRooms, string $stepDir): void {
        $newChar = \CharacterModel::find($charId);
        if (!$newChar) return;

        $newArea = $newChar['current_area'] ?? '';
        $newRoom = $newChar['current_room'] ?? '';
        $newFullRoom = (strpos($newRoom, '/') !== false) ? $newRoom : $newArea . '/' . $newRoom;

        $nav['step_count'] = $stepCount + 1;
        $nav['last_step_room'] = self::normalizeRoomPath($newFullRoom);
        $nav['last_rooms'] = array_slice(array_merge($lastRooms, [self::normalizeRoomPath($newFullRoom)]), -7);

        Database::execute(
            "UPDATE character_temp SET temp_value = ? WHERE char_id = ? AND temp_key = 'ai_nav_target'",
            [json_encode($nav), $charId]
        );
    }

    /**
     * 设置 AI 导航目标（替代直接 moveCharacter 跳转）
     * AI 后续 tick 会逐步朝目标走，模拟真实玩家行为
     *
     * @param int $charId 角色ID
     * @param string $targetArea 目标区域 (如 'kaifeng')
     * @param string $targetRoom 目标房间 (如 'kaifeng/tianpeng'，空=目标区域入口)
     * @param string $purpose 导航目的（用于日志）
     */
    public static function setNavigationTarget(int $charId, string $targetArea, string $targetRoom, string $purpose = ''): void {
        // 归一化 targetRoom
        $targetRoom = preg_replace('#^/d/#', '', $targetRoom);
        if (strpos($targetRoom, '/') === false && !empty($targetArea)) {
            $targetRoom = $targetArea . '/' . $targetRoom;
        }

        // 如果没指定目标房间，使用区域入口
        if (empty($targetRoom) || $targetRoom === $targetArea) {
            $entryMap = [
                'kaifeng' => 'kaifeng/chengmen',
                'city'    => 'city/kezhan',
                'moon'    => 'moon/center',
                'dntg'    => 'dntg/hgs',
                '33tian'  => '33tian/tian33',
                'lingtai' => 'lingtai/uphill',
                'wuzhuang'=> 'wuzhuang/guangchang',
                'fangcun' => 'fangcun/shimen',
            ];
            $targetRoom = $entryMap[$targetArea] ?? ($targetArea . '/center');
        }

        // 黑名单检查：如果该目标近期因振荡失败过，不再设置导航
        if (self::isNavBlacklisted($charId, $targetRoom)) {
            self::logDebug("Nav blacklist: char {$charId} target {$targetRoom} blacklisted, skip navigation");
            return;
        }

        // 记录起始房间，用于手动移动检测
        $char = CharacterModel::find($charId);
        $startArea = $char['current_area'] ?? '';
        $startRoom = $char['current_room'] ?? '';
        $startFullRoom = (strpos($startRoom, '/') !== false) ? $startRoom : $startArea . '/' . $startRoom;

        $nav = [
            'room'       => $targetRoom,
            'area'       => $targetArea,
            'set_at'     => time(),
            'step_count' => 0,
            'purpose'    => $purpose,
            'last_rooms' => [],
            'last_step_room' => self::normalizeRoomPath($startFullRoom),
        ];

        Database::execute(
            "INSERT INTO character_temp (char_id, temp_key, temp_value) VALUES (?, 'ai_nav_target', ?)
             ON DUPLICATE KEY UPDATE temp_value = ?",
            [$charId, json_encode($nav), json_encode($nav)]
        );
    }

    /**
     * 清除 AI 导航目标
     */
    public static function clearNavigationTarget(int $charId): void {
        Database::execute(
            "DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'ai_nav_target'",
            [$charId]
        );
    }

    /**
     * 将导航失败的目标房间加入黑名单（防止振荡时无限重试同一目标）
     * 黑名单有效期 10 分钟
     */
    private static function addToNavBlacklist(int $charId, string $targetRoom): void {
        $row = Database::queryOne(
            "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = 'ai_nav_blacklist'",
            [$charId]
        );
        $blacklist = [];
        if ($row) {
            $blacklist = json_decode($row['temp_value'] ?? '{}', true) ?: [];
        }
        // 清理超过 10 分钟的旧条目
        $now = time();
        foreach ($blacklist as $room => $ts) {
            if (($now - $ts) > 600) {
                unset($blacklist[$room]);
            }
        }
        $blacklist[$targetRoom] = $now;
        Database::execute(
            "INSERT INTO character_temp (char_id, temp_key, temp_value) VALUES (?, 'ai_nav_blacklist', ?)
             ON DUPLICATE KEY UPDATE temp_value = ?",
            [$charId, json_encode($blacklist, JSON_UNESCAPED_UNICODE), json_encode($blacklist, JSON_UNESCAPED_UNICODE)]
        );
    }

    /**
     * 检查目标房间是否在导航黑名单中（10 分钟内振荡失败过）
     */
    private static function isNavBlacklisted(int $charId, string $targetRoom): bool {
        $row = Database::queryOne(
            "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = 'ai_nav_blacklist'",
            [$charId]
        );
        if (!$row) return false;
        $blacklist = json_decode($row['temp_value'] ?? '{}', true) ?: [];
        if (!isset($blacklist[$targetRoom])) return false;
        // 超过 10 分钟自动解禁
        if ((time() - $blacklist[$targetRoom]) > 600) {
            unset($blacklist[$targetRoom]);
            Database::execute(
                "UPDATE character_temp SET temp_value = ? WHERE char_id = ? AND temp_key = 'ai_nav_blacklist'",
                [json_encode($blacklist, JSON_UNESCAPED_UNICODE), $charId]
            );
            return false;
        }
        return true;
    }

    /**
     * 判断是否为后期角色（需要极力避免死亡）
     * 
     * 前期（combat_exp < 10000）：资源少、技能少，死亡损失不大，
     *   走鬼魂→还魂流程即可，不用紧急传送回避死亡。
     * 后期（combat_exp >= 10000）：技能、经验积累较多，死亡损失大，
     *   需要激进保命。
     */
    private static function isLateGame(array $char): bool {
        return intval($char['combat_exp'] ?? 0) >= self::LATE_GAME_COMBAT_EXP;
    }

    /**
     * AI 决策：根据当前状态和位置选择行为
     */
    private static function decideAction(array $char): string {
        $keePct = ($char['max_kee'] > 0) ? ($char['kee'] / $char['max_kee']) : 1;
        $ginPct = ($char['max_gin'] > 0) ? ($char['gin'] / $char['max_gin']) : 1;
        $area = $char['current_area'] ?? '';
        $room = $char['current_room'] ?? '';
        $fullRoom = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
        
        // 1. 低血量恢复（游戏真实机制）
        //    tryAutoRecover 已在 tick 中尝试 exert recover（需内功+内力），
        //    能走到这里说明内功恢复失败（无内功 / 内力耗尽）→ 走药铺路线
        //    前期不买药，直接跳过恢复逻辑，死了走鬼魂还魂流程
        if (self::isLateGame($char) && ($keePct < self::REST_KEE_THRESHOLD || $ginPct < self::REST_GIN_THRESHOLD)) {
            // 后期（combat_exp>=10000）：极力避免死亡，气血<25%紧急传送客栈
            if ($keePct < self::LATE_CRITICAL_KEE_THRESHOLD) {
                return 'move_to_safe';
            }

            $silver = intval($char['silver'] ?? 0);

            // 已在药铺 → 买药（有钱）或去灭妖赚钱（没钱）
            if (self::isMedicineShop($area, $room)) {
                if ($silver >= 10) {
                    return 'buy_med';
                }
                // 没钱买药 → 去灭妖赚钱
                return 'goto_mieyao';
            }

            // 在长安城内（非药铺）→ 导航去药铺
            if (self::isInCity($area, $room)) {
                return 'navigate_to_pharmacy';
            }

            // 在野外 → 导航回长安药铺
            return 'navigate_to_pharmacy';
        }
        
        // ★ 1.5 ourhome 区域出口检查（最高优先级，仅低于危险检查）
        //    ourhome 没有通往其他区域的 room_exits，唯一离开方式是通过
        //    room_action "跨出栏杆(out)" 从 xiaoting → dntg/hgs/entrance
        //    必须放在灭妖/开封任务之前，否则死亡前的残留任务会拦截，
        //    导致 AI 永久被困在 ourhome 无法离开
        $ourhomeExit = self::checkOurhomeExit($char);
        if ($ourhomeExit !== null) return $ourhomeExit;

        // 2. 在开封府衙时 → 优先处理开封任务，灭妖任务暂时搁置
        //    原因：如果 AI 在开封府衙有开封任务却去灭妖，会导致开封任务永远无法完成
        //    灭妖任务有独立的接取机制（city/tianjiantai），不会因短暂搁置而丢失
        $area = $char['current_area'] ?? '';
        $room = $char['current_room'] ?? '';
        $fullRoom = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
        $normalizedRoom = self::normalizeRoomPath($fullRoom);
        if (self::isKaifengQuestRoom($fullRoom) || $normalizedRoom === 'kaifeng/chengmen') {
            $kaifengNav = self::checkKaifengNavigation($char);
            if ($kaifengNav !== null) return $kaifengNav;
        }
        
        // 3. 检查是否有进行中的灭妖任务，导航到妖怪位置
        //    注意：只有在 AI 不在开封任务区时才优先处理灭妖
        $mieyaoNav = self::checkMieyaoNavigation($char);
        if ($mieyaoNav !== null) return $mieyaoNav;
        
        // 4. 检查是否有进行中的开封任务，导航执行（非开封府衙区域时）
        $kaifengNav = self::checkKaifengNavigation($char);
        if ($kaifengNav !== null) return $kaifengNav;
        
        // 5. 没有进行中的任务时，主动导航去接任务
        $noQuestNav = self::checkQuestNavigation($char);
        if ($noQuestNav !== null) return $noQuestNav;
        
        // 6. 根据位置进行特殊行为判定（接任务、学习技能等）
        $specialAction = self::decideLocationAction($char);
        if ($specialAction !== null) {
            return $specialAction;
        }
        
        // 7. 正常权重决策（前期不买药，去掉 heal_buy 权重）
        $healWeight = self::isLateGame($char) ? self::HEAL_BUY_WEIGHT : 0;
        $total = self::MOVE_WEIGHT + self::REST_WEIGHT + self::TRAIN_WEIGHT + 
                 self::TASK_WEIGHT + $healWeight + self::LEARN_WEIGHT + 
                 self::CHAT_WEIGHT + self::STAY_WEIGHT;
        
        $rand = mt_rand(1, $total);
        
        if ($rand <= self::MOVE_WEIGHT) return 'move';
        $rand -= self::MOVE_WEIGHT;
        if ($rand <= self::REST_WEIGHT) return 'rest';
        $rand -= self::REST_WEIGHT;
        if ($rand <= self::TRAIN_WEIGHT) return 'train';
        $rand -= self::TRAIN_WEIGHT;
        if ($rand <= self::TASK_WEIGHT) return 'task';
        $rand -= self::TASK_WEIGHT;
        if ($rand <= $healWeight) return 'heal_buy';
        $rand -= $healWeight;
        if ($rand <= self::LEARN_WEIGHT) return 'learn';
        $rand -= self::LEARN_WEIGHT;
        if ($rand <= self::CHAT_WEIGHT) return 'chat';
        
        return 'stay';
    }
    
    /**
     * 检查 ourhome 区域出口：ourhome 没有通往其他区域的 room_exits，
     * 唯一离开方式是通过 room_action "跨出栏杆(out)" 从 xiaoting → dntg/hgs/entrance
     * 必须在其他导航决策之前处理，否则 AI 会被困在 ourhome 永久循环
     */
    private static function checkOurhomeExit(array $char): ?string {
        $area = $char['current_area'] ?? '';
        $room = $char['current_room'] ?? '';
        $fullRoom = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
        $normalizedFullRoom = self::normalizeRoomPath($fullRoom);

        // 不在 ourhome → 不需要处理
        if ($area !== 'ourhome' && strpos($normalizedFullRoom, 'ourhome/') !== 0
            && $normalizedFullRoom !== 'ourhome/xiaoting' && $fullRoom !== 'ourhome/xiaoting') {
            return null;
        }

        $charId = intval($char['id'] ?? 0);
        
        // 检查是否在鬼魂流程中（exit_nav/exit_out 阶段让鬼魂流程处理）
        $ghostFlow = Database::queryOne(
            "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = 'ai_ghost_flow'",
            [$charId]
        );
        $ghostState = $ghostFlow ? json_decode($ghostFlow['temp_value'] ?? '', true) : null;
        $ghostPhase = $ghostState['phase'] ?? '';
        
        if (in_array($ghostPhase, ['exit_nav', 'exit_out'])) {
            return null; // 让鬼魂流程处理
        }
        
        // 已经在聚见亭 → 直接跨出栏杆
        if ($normalizedFullRoom === 'ourhome/xiaoting' || $fullRoom === 'ourhome/xiaoting') {
            return 'out';
        }
        
        // 在 ourhome 其他房间 → 导航去聚见亭
        self::setNavigationTarget($charId, 'ourhome', 'ourhome/xiaoting', '前往聚见亭离开');
        // 返回 'rest'（而非 null），阻止后续的 checkMieyaoNavigation/checkKaifengNavigation
        // 覆盖导航目标。下个 tick 的 processNavigationTarget 会接管逐步走向 xiaoting。
        return 'rest';
    }

    /**
     * 检查灭妖任务导航：如果有进行中的灭妖任务，导航到妖怪所在区域
     */
    private static function checkMieyaoNavigation(array $char): ?string {
        $charId = intval($char['id'] ?? 0);
        $task = Database::queryOne(
            "SELECT area, expires_at FROM mieyao_yaoguai WHERE owner_id = ? AND is_killed = 0 ORDER BY id DESC LIMIT 1",
            [$charId]
        );
        if ($task) {
            $expiresAt = $task['expires_at'] ?? null;
            $targetArea = $task['area'] ?? '';
            $currentArea = $char['current_area'] ?? '';

            if ($expiresAt !== null && strtotime($expiresAt) <= time()) {
                return 'mieyao_retake';
            }

            // 如果在妖怪所在区域，有概率执行灭妖战斗
            if ($targetArea === $currentArea) {
                return (mt_rand(1, 100) <= 50) ? 'mieyao_fight' : 'move';
            }

            // 不在妖怪区域：AI 有一定概率尝试使用自动寻怪（如水晶球/AI 特权）
            $isAi = intval($char['is_ai_player'] ?? 0) === 1;
            if ($isAi) {
                try {
                    $hasCrystal = Database::queryOne(
                        "SELECT 1 FROM character_inventory WHERE char_id = ? AND item_id = 'crystalball' AND quantity > 0 LIMIT 1",
                        [$charId]
                    );
                    if (!empty($hasCrystal)) {
                        return 'mieyao_auto_find';
                    }
                } catch (\Exception $e) {
                    // 查询失败则回退为常规导航
                }
            }

            // 否则导航过去
            return 'mieyao_navigate';
        }

        $expiredTask = Database::queryValue(
            "SELECT 1 FROM character_temp_states WHERE char_id = ? AND state_key = 'mieyao_task' AND state_value <> '' LIMIT 1",
            [$charId]
        );
        if ($expiredTask) {
            return 'mieyao_retake';
        }

        return null;
    }
    
    /**
     * 检查开封任务导航（完整版：支持所有任务类型的完整生命周期）
     * 
     * 优先级：
     * 1. done状态任务 → 回NPC领奖 (kaifeng_claim)
     * 2. pending状态任务 → 执行任务 (kaifeng_do)
     * 3. 在开封府NPC房间 → 接新任务 (kaifeng_task)
     * 4. 不在开封但有任务 → 导航到开封 (kaifeng_navigate)
     */
    private static function checkKaifengNavigation(array $char): ?string {
        $charId = intval($char['id'] ?? 0);
        $currentArea = $char['current_area'] ?? '';
        
        // 1. 优先检查是否有 done 状态的任务（完成目标但未领奖）
        $doneQuests = Database::queryAll(
            "SELECT * FROM character_quests WHERE char_id = ? AND status = 'done' ORDER BY id ASC LIMIT 3",
            [$charId]
        );
        if (!empty($doneQuests)) {
            // 有未领奖的任务，直接去领奖（doKaifengClaim 会自动导航到NPC房间）
            return 'kaifeng_claim';
        }
        
        // 2. 检查 pending 状态的任务
        $pendingQuests = Database::queryAll(
            "SELECT * FROM character_quests WHERE char_id = ? AND status = 'pending' ORDER BY id ASC LIMIT 3",
            [$charId]
        );
        
        if (!empty($pendingQuests)) {
            // 有进行中的任务，直接执行（doKaifengDo 会根据任务类型导航到正确位置）
            return 'kaifeng_do';
        }
        
        // 3. 没有任务，在开封区域时尝试接任务
        //    开封任务是后期高级内容，按"能不能飞"分类：
        //    能飞的玩家可以自由离开开封，可以接任务
        //    不能飞的玩家（道行/法力不够）会被困在开封，直接引导离开
        if ($currentArea === 'kaifeng') {
            if (!self::canFly($char)) {
                // 不能飞的新手，不要困在开封，引导离开
                return 'fly_away';
            }
            
            $room = $char['current_room'] ?? '';
            $fullRoom = (strpos($room, '/') !== false) ? $room : $currentArea . '/' . $room;
            // 检查是否在开封NPC房间
            if (self::isKaifengQuestRoom($fullRoom)) {
                // 在NPC房间，高概率接任务
                return (mt_rand(1, 100) <= 75) ? 'kaifeng_task' : 'move';
            }
            // 不在NPC房间 → 直接导航去接任务（不要返回null让AI随机走）
            return 'kaifeng_navigate_to_quest';
        }
        
        // 4. 有品德值可赴京请赏（根据祥云颜色数量调整触发概率）
        $questReward = intval($char['quest_reward'] ?? 0);
        if ($questReward >= 30) {
            // 获取祥云颜色数量用于决策
            $colorCount = 1;
            if (file_exists(__DIR__ . '/../helpers/QuestHelper.php')) {
                require_once __DIR__ . '/../helpers/QuestHelper.php';
                $colorData = QuestHelper::getColorCounter($charId);
                $colorCount = $colorData['count'] ?? 1;
            }
            
            if ($currentArea === 'beijing') {
                // 在北京皇宫，颜色越多越倾向于请赏
                $beijingChance = min(80, 30 + $colorCount * 5);
                return (mt_rand(1, 100) <= $beijingChance) ? 'kaifeng_beijing' : 'move';
            }
            // 有足够品德值+多色祥云，更高概率导航去北京
            $navChance = min(40, 10 + $colorCount * 3);
            if (mt_rand(1, 100) <= $navChance) {
                return 'kaifeng_beijing_nav';
            }
        }
        
        // 5. 在开封但没有活跃任务时，有一定概率离开开封去其他地方
        //    防止 AI 一直困在开封任务闭环中
        $pendingCount = intval(Database::queryValue(
            "SELECT COUNT(*) FROM character_quests WHERE char_id = ? AND status = 'pending'",
            [$charId]
        ) ?? 0);
        $doneCount = intval(Database::queryValue(
            "SELECT COUNT(*) FROM character_quests WHERE char_id = ? AND status = 'done'",
            [$charId]
        ) ?? 0);
        
        if ($pendingCount === 0 && $doneCount === 0 && $currentArea === 'kaifeng') {
            // 没有进行中/待领奖任务，且在开封，有较高概率飞去其他区域
            // 提高离开概率，防止AI长期困在开封
            $rand = mt_rand(1, 100);
            if ($rand <= 40) {
                return 'fly_away';  // 40% 概率飞行离开
            }
        }
        
        return null;
    }
    
    /**
     * 没有进行中的任务时，主动导航去接任务
     * 如果不在长安或开封，有较高概率导航过去
     */
    private static function checkQuestNavigation(array $char): ?string {
        $area = $char['current_area'] ?? '';
        $room = $char['current_room'] ?? '';
        $fullRoom = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
        $normalizedFullRoom = self::normalizeRoomPath($fullRoom);
        
        // ★ ourhome 区域：唯一出口是 xiaoting→out→dntg/hgs/entrance
        //   不要导航去长安/开封，那些区域的 BFS 路径无法穿越 ourhome 的 room_action 出口
        //   导航由 checkOurhomeExit 负责，此处直接跳过
        if ($area === 'ourhome' || strpos($normalizedFullRoom, 'ourhome/') === 0) {
            return null;
        }
        
        // 已经在天监台（袁天罡）或云楼台（李靖），交给 decideLocationAction 处理
        if (preg_match('#tianjiantai|tiantai|yunluotai#i', $normalizedFullRoom)) {
            return null;
        }
        
        // 已经在开封任务 NPC 房间，交给 decideLocationAction 处理
        if ($area === 'kaifeng' && self::isKaifengQuestRoom($fullRoom)) {
            return null;
        }
        
        // 在开封但没有在任务NPC房间 → 不能飞的离开，能飞的接任务
        if ($area === 'kaifeng') {
            if (!self::canFly($char)) {
                return 'fly_away';  // 不能飞，离开开封
            }
            // 能飞 → 导航去NPC房间接任务
            $rand = mt_rand(1, 100);
            if ($rand <= 85) {
                return 'kaifeng_navigate_to_quest';  // 85% 去 NPC 房间接任务
            }
            // 15% 概率飞行离开开封（防止无限循环）
            return 'fly_away';
        }
        
        // 在长安城 → 导航去天监台接灭妖任务
        if ($area === 'city') {
            return (mt_rand(1, 100) <= 50) ? 'task' : null;
        }
        
        // 在其他区域 → 有较高概率导航回长安接灭妖任务
        // 不能飞的玩家优先回长安练级，能飞的玩家小概率去开封
        $rand = mt_rand(1, 100);
        if ($rand <= 35) {
            return 'task';  // 导航到长安天监台
        }
        // 只有能飞的玩家且小概率才导航去开封（开封有独立导航逻辑，不需此处高频触发）
        if ($rand <= 40 && self::canFly($char)) {
            return 'kaifeng_navigate';  // 导航到开封接任务
        }
        
        return null;
    }
    
    /**
     * 基于位置的特殊行为判定
     */
    private static function decideLocationAction(array $char): ?string {
        $area = $char['current_area'] ?? '';
        $room = $char['current_room'] ?? '';
        $fullRoom = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
        $normalizedFullRoom = self::normalizeRoomPath($fullRoom);

        // 在袁天罡房间（天监台）- 接灭妖任务（使用归一化路径匹配）
        if (preg_match('#city/tianjiantai|city/tiantai#i', $normalizedFullRoom)) {
            if (mt_rand(1, 100) <= 60) return 'mieyao_task';
        }
        
        // 在李靖房间（云楼台）- 接高级灭妖任务（使用归一化路径匹配）
        if (preg_match('#heaven/yunluotai|yunluotai#i', $normalizedFullRoom)) {
            if (mt_rand(1, 100) <= 60) return 'mieyao_task_heaven';
        }
        
        // 在书店 - 购买技能书学习
        $normalizedFullRoom = self::normalizeRoomPath($fullRoom);
        if ($normalizedFullRoom === 'city/bookstore') {
            $hasBook = self::hasStudyBook($char['id'] ?? 0, $char);
            if ($hasBook) {
                // 已有可学书籍，不用再买
                return (mt_rand(1, 100) <= 50) ? 'learn' : 'move';
            }
            // 还没买书，优先购买
            $totalMoney = (intval($char['silver'] ?? 0) * 100) + intval($char['copper'] ?? 0);
            if ($totalMoney >= 100) {
                return 'learn';  // learn 内部会路由到 doBuyBook
            }
        }
        
        // 在开封府NPC房间 - 接开封任务（checkKaifengNavigation 已处理主要流程，这里是兜底）
        // 使用归一化后的房间路径进行匹配，避免 /d/ 前缀导致不一致
        if ($area === 'kaifeng' || strpos($normalizedFullRoom, 'kaifeng') !== false) {
            // 检查是否有活跃的开封任务
            $hasQuest = self::hasActiveKaifengQuest($char['id'] ?? 0);
            
            if (!$hasQuest && self::isKaifengQuestRoom($fullRoom)) {
                // 在NPC房间
                if (!self::canFly($char)) {
                    return 'fly_away';  // 不能飞，直接离开
                }
                // 能飞：高概率接任务
                $rand = mt_rand(1, 100);
                if ($rand <= 75) {
                    return 'kaifeng_task';
                } elseif ($rand <= 90) {
                    return 'stay';
                } else {
                    return 'kaifeng_navigate_to_quest';  // 换个NPC房间试试
                }
            }
            // 在开封但没有任务也不在NPC房间 → 导航去接任务
            if (!$hasQuest && !self::isKaifengQuestRoom($fullRoom)) {
                if (!self::canFly($char)) {
                    return 'fly_away';  // 不能飞，离开开封
                }
                return 'kaifeng_navigate_to_quest';
            }
        }
        


        // 在门派师父房间 - 学习技能
        if (self::isMasterRoom($area, $room)) {
            $combatExp = intval($char['combat_exp'] ?? 0);
            $potential = intval($char['potential'] ?? 0);
            if ($potential > 50 && mt_rand(1, 100) <= 40) {
                return 'learn_skill';
            }
        }
        
        // 在药铺 - 后期才买药，前期不买
        if (self::isMedicineShop($area, $room) && self::isLateGame($char)) {
            $silver = intval($char['silver'] ?? 0);
            if ($silver >= 10 && mt_rand(1, 100) <= 50) {
                return 'buy_med';
            }
        }
        
        // 在练功房 - 练习技能
        if (self::isTrainingRoom($area, $room)) {
            if (mt_rand(1, 100) <= 50) return 'train';
        }
        
        return null;
    }
    
    /**
     * 执行具体行为
     */
    private static function executeAction(int $charId, array $char, string $action): array {
        switch ($action) {
            case 'move':
                return self::doMove($charId, $char);
            case 'move_to_safe':
                return self::doMoveToSafe($charId, $char);
            case 'rest':
                // 已废弃：游戏必须通过内功运功(exert)或药铺买药来恢复，
                // 不能直接写 HP。实际不会走到这里，仅为兼容保留。
                // 有内功的 AI 通过 tryAutoRecover(exert recover) 恢复；
                // 无内功的 AI 通过 navigate_to_pharmacy → buy_med 恢复。
                return self::doRecover($charId, $char);
            case 'train':
                return self::doTrain($charId, $char);
            case 'task':
                return self::doTask($charId, $char);
            case 'heal_buy':
                return self::doHealOrBuy($charId, $char);
            case 'learn':
                return self::doLearnSkill($charId, $char);
            case 'chat':
                return self::doChat($charId, $char);
            case 'mieyao_task':
                return self::doMieyaoTask($charId, $char);
            case 'mieyao_task_heaven':
                return self::doMieyaoTaskHeaven($charId, $char);
            case 'mieyao_retake':
                return self::doMieyaoRetake($charId, $char);
            case 'mieyao_auto_find':
                return self::doMieyaoAutoFind($charId, $char);
            case 'mieyao_navigate':
                return self::doMieyaoNavigate($charId, $char);
            case 'mieyao_fight':
                return self::doMieyaoFight($charId, $char);
            case 'kaifeng_task':
                return self::doKaifengTask($charId, $char);
            case 'kaifeng_do':
                return self::doKaifengDo($charId, $char);
            case 'kaifeng_claim':
                return self::doKaifengClaim($charId, $char);
            case 'kaifeng_navigate':
                return self::doKaifengNavigate($charId, $char);
            case 'kaifeng_navigate_to_quest':
                return self::doKaifengNavigateToQuest($charId, $char);
            case 'kaifeng_beijing':
                return self::doKaifengBeijing($charId, $char);
            case 'kaifeng_beijing_nav':
                return self::doKaifengBeijing($charId, $char);
            case 'fly_away':
                return self::doFlyAway($charId, $char);
            case 'mufa_ok':
                // tryExecuteMufaAction 已执行登船/下船，角色已移动，此处不再执行任何动作
                return ['success' => true, 'message' => '木筏动作已执行', 'action' => 'mufa_ok'];
            case 'buy_med':
                return self::doBuyMedicine($charId, $char);
            case 'learn_skill':
                return self::doLearnSkill($charId, $char);
            case 'navigate_to_pharmacy':
                // 无内功或内力耗尽 → 导航去长安药铺买药
                return self::doNavigateToPharmacy($charId, $char);
            case 'goto_mieyao':
                // 药铺没钱 → 去灭妖赚钱
                return self::doGotoMieyao($charId, $char);
            case 'stay':
            default:
                // 尝试作为方向命令处理（如 north, west, east, south 等）
                if (in_array($action, ['north','south','east','west','up','down',
                    'northeast','northwest','southeast','southwest',
                    'n','s','e','w','u','d','ne','nw','se','sw',
                    'out','in','enter','northup','northdown','southup','southdown',
                    'eastup','eastdown','westup','westdown'])) {
                    return self::doMoveDirection($charId, $char, $action);
                }
                return ['success' => true, 'message' => 'AI停留在原地', 'action' => 'stay'];
        }
    }
    
    // ==================== 移动行为 ====================
    
    /**
     * AI 玩家统一移动方法：更新位置并处理跟随者
     */
    public static function moveCharacter(int $charId, string $targetArea, string $targetRoomId, string $charName = '', string $oldRoomId = ''): void {
        // 归一化 roomId：去除可能的 /d/ 前缀，保留 area/room 格式
        // /d/kaifeng/tianpeng → kaifeng/tianpeng
        $cleanRoomId = preg_replace('#^/d/#', '', $targetRoomId);
        // 防止双重区域前缀：如 "city/city/tianjiantai" → "city/tianjiantai"
        if (!empty($targetArea) && strpos($cleanRoomId, $targetArea . '/' . $targetArea . '/') === 0) {
            $cleanRoomId = substr($cleanRoomId, strlen($targetArea) + 1);
        }
        // 如果 roomId 不以 targetArea/ 开头，则补全前缀
        if (strpos($cleanRoomId, $targetArea . '/') !== 0 && !empty($targetArea)) {
            $cleanRoomId = $targetArea . '/' . $cleanRoomId;
        }
        
        // 校验目标房间是否存在，不存在则传送到长安客栈
        require_once __DIR__ . '/../models/Room.php';
        $targetRoom = RoomModel::load($targetArea, $cleanRoomId);
        if (!$targetRoom) {
            $targetArea = 'city';
            $cleanRoomId = 'city/kezhan';
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            MessageDaemon::queueMessageToSelf($charId, HTML_HIYEL . "前方道路不通，你被传送回了长安客栈。" . HTML_NOR, 'self_event');
        }
        
        CharacterModel::updatePosition($charId, $targetArea, $cleanRoomId);
        
        // 处理跟随该 AI 玩家的其他玩家
        require_once __DIR__ . '/../daemons/MessageDaemon.php';
        $followers = Database::queryAll(
            "SELECT id, name FROM characters WHERE following_id = ? AND online = 1",
            [$charId]
        );
        
        foreach ($followers as $follower) {
            $followerChar = CharacterModel::find($follower['id']);
            if (!$followerChar) continue;
            // 只有同房间的跟随者才跟随移动
            if (!empty($oldRoomId) && $followerChar['current_room'] !== $oldRoomId) continue;
            
            // 更新跟随者位置
            CharacterModel::updatePosition($follower['id'], $targetArea, $cleanRoomId);
            
            // 给跟随者发消息
            MessageDaemon::queueMessageToSelf($follower['id'], HTML_HIYEL . "你跟着{$charName}移动了。" . HTML_NOR, 'self_event');
            
            // 记录日志
            log_game('MOVE', "{$follower['name']} 跟随 AI {$charName} 移动到 {$targetRoomId}");
        }
    }

    /**
     * 朝指定方向移动（用于导航系统指定方向的移动）
     * @param string $direction 方向（north, west, east, south, up, down, out 等）
     */
    private static function doMoveDirection(int $charId, array $char, string $direction): array {
        // 加载 go 命令（包含 cmd_go 函数）
        require_once __DIR__ . '/../commands/go.php';

        $charName = $char['name'] ?? 'Unknown';
        $area = $char['current_area'] ?? 'city';
        $currentRoom = $char['current_room'] ?? 'city/kezhan';
        $fullRoom = (strpos($currentRoom, '/') !== false) ? $currentRoom : $area . '/' . $currentRoom;

        // 调用 go 命令处理（包括特殊房间动作如木筏登船）
        $goResult = cmd_go($charId, $direction);
        if (!($goResult['success'] ?? false)) {
            return [
                'success' => false,
                'message' => $goResult['message'] ?? "无法向{$direction}走",
                'action' => 'move',
            ];
        }

        // 移动成功：更新跟随者位置
        $newRoom = $goResult['room'] ?? '';
        if (!empty($newRoom)) {
            self::updateFollowersPosition($charId, $charName, $fullRoom, $newRoom);
        }

        return [
            'success' => true,
            'message' => $goResult['message'] ?? "向{$direction}走了一步",
            'action' => 'move',
            'ai_detail' => "方向{$direction}",
        ];
    }
    
    /**
     * AI 移动行为：随机选择一个出口方向移动
     */
    private static function doMove(int $charId, array $char): array {
        $area = $char['current_area'] ?? 'city';
        $roomId = $char['current_room'] ?? 'city/kezhan';
        
        if (strpos($roomId, '/') === false) {
            $roomId = $area . '/' . $roomId;
        }
        
        $room = RoomModel::load($area, $roomId);
        if (!$room) {
            $shortRoomId = basename(str_replace('\\', '/', $roomId));
            $room = RoomModel::load($area, $shortRoomId);
        }
        if (!$room) {
            return ['success' => false, 'message' => 'AI无法获取房间信息', 'action' => 'move'];
        }
        
        $exits = RoomModel::getExits($room['id'] ?? 0);
        if (empty($exits)) {
            return self::doEmergencyTp($charId, $char);
        }
        
        $validExits = [];
        foreach ($exits as $exit) {
            $dir = $exit['direction'] ?? '';
            $targetRoom = $exit['target_room'] ?? '';
            if (!empty($dir) && !empty($targetRoom)) {
                $validExits[$dir] = $exit;
            }
        }
        
        if (empty($validExits)) {
            return ['success' => false, 'message' => 'AI无有效出口', 'action' => 'move'];
        }
        
        $directions = array_keys($validExits);
        $chosenDir = $directions[array_rand($directions)];
        $exitData = $validExits[$chosenDir];
        $targetArea = $exitData['target_area'] ?? $area;
        $targetRoomId = $exitData['target_room'] ?? '';
        
        // 确保 target_room 是完整的 area/sub/room 格式
        if (strpos($targetRoomId, $targetArea . '/') !== 0) {
            $targetRoomId = $targetArea . '/' . $targetRoomId;
        }
        
        $targetRoomCheck = Database::queryOne(
            "SELECT 1 FROM rooms WHERE room_id = ? LIMIT 1",
            [$targetRoomId]
        );
        if (!$targetRoomCheck) {
            return ['success' => false, 'message' => 'AI目标房间不存在', 'action' => 'move'];
        }
        
        $oldRoom = $char['current_room'] ?? '';
        self::moveCharacter($charId, $targetArea, $targetRoomId, $char['name'] ?? '', $oldRoom);
        
        // AI玩家不发送移动消息，避免刷屏
        // require_once __DIR__ . '/../daemons/MessageDaemon.php';
        // $dirNames = [...];
        // MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
        
        return [
            'success' => true,
            'message' => "向{$chosenDir}移动",
            'action' => 'move',
            'ai_detail' => "向{$chosenDir}移动 → {$targetRoomId}"
        ];
    }
    
    /**
     * 移动到安全区域（客栈）- 后期保命，步行回去不传送
     */
    private static function doMoveToSafe(int $charId, array $char): array {
        $area = $char['current_area'] ?? '';
        $room = $char['current_room'] ?? '';
        $fullRoom = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
        
        // 已在客栈 → 停留恢复（下一步 decideAction 会走药铺路线）
        if ($fullRoom === 'city/kezhan' || ($area === 'city' && $room === 'kezhan')) {
            return ['success' => true, 'message' => '已在客栈', 'action' => 'stay', 'ai_detail' => '已在客栈，等待自然恢复'];
        }
        
        // 设置导航目标，BFS 步行回长安客栈（不传送！走回去，尊重游戏世界）
        self::setNavigationTarget($charId, 'city', 'city/kezhan', '后期保命回客栈');
        
        // 立刻走一步
        $navResult = self::processNavigationTarget($charId, $char);
        if ($navResult !== null) {
            return [
                'success' => true,
                'message' => '赶回客栈途中',
                'action' => 'move_to_safe',
                'ai_detail' => '后期保命→BFS步行回city/kezhan'
            ];
        }
        
        // BFS 完全不可达（如被困死胡同）→ 兜底传送（极其罕见）
        require_once __DIR__ . '/../daemons/MessageDaemon.php';
        MessageDaemon::queueMessageToSelf($charId, HTML_HIYEL . "四周无路可走，你被传送回了长安客栈。" . HTML_NOR, 'self_event');
        $oldRoom = $char['current_room'] ?? '';
        self::moveCharacter($charId, 'city', 'city/kezhan', $char['name'] ?? '', $oldRoom);
        return ['success' => true, 'message' => '传送回客栈（无路可走）', 'action' => 'emergency_tp', 'ai_detail' => 'BFS不可达→兜底传送回客栈'];
    }
    
    // ==================== 休息恢复 ====================
    
    /**
     * AI 在残血时自动运功恢复（使用游戏真实的 exert recover 机制）
     * 
     * 必须满足：已启用内功心法(enable force) + 内力 >= 20 + 8秒冷却
     * 不满足则返回 null，由 decideAction 走药铺购买路线
     */
    private static function tryAutoRecover(int $charId, array $char): ?array {
        $kee = intval($char['kee'] ?? 0);
        $maxKee = intval($char['max_kee'] ?? 100);
        $gin = intval($char['gin'] ?? 0);
        $maxGin = intval($char['max_gin'] ?? 100);
        if ($maxKee <= 0 || $kee <= 0) {
            return null;
        }

        $keePct = $kee / $maxKee;
        $ginPct = $maxGin > 0 ? ($gin / $maxGin) : 1;
        if ($keePct >= self::AUTO_RECOVER_KEE_THRESHOLD && $ginPct >= self::AUTO_RECOVER_GIN_THRESHOLD) {
            return null;
        }

        if (is_player_busy($charId)) {
            return null;
        }

        // 冷却：两次运功之间至少间隔 8 秒
        $lastRecoverAt = intval($char['last_recover'] ?? 0);
        if ($lastRecoverAt > 0 && (time() - $lastRecoverAt) < 8) {
            return null;
        }

        // 必须有启用的内功心法
        require_once __DIR__ . '/../helpers/SkillManager.php';
        $mappedForce = SkillManager::querySkillMapped($charId, 'force');
        if (empty($mappedForce)) {
            return null; // 无内功 → 后续走药铺路线
        }

        $forceSkillLevel = SkillManager::getSkillLevel($charId, $mappedForce);
        if ($forceSkillLevel < 1) {
            return null;
        }

        // 内力不足最低消耗（exert recover 需要 20 内力）
        $force = intval($char['force'] ?? 0);
        if ($force < 20) {
            return null; // 内力耗尽 → 走药铺路线
        }

        // 调用游戏真实的 exert recover 机制
        // 注意：不能用 ActionRouter::handleCustomAction('exert recover')，因为 handleCustomAction
        // 内部走 findRoomAction（查 room_actions 表），exert 是命令级动作不在 room_actions 中，
        // 会导致匹配失败 → 返回 false
        require_once __DIR__ . '/../commands/exert.php';
        if (!function_exists('cmd_exert')) {
            return null;
        }
        $result = cmd_exert($charId, 'recover');

        self::recordAction($charId, 'exert_recover', ($result['success'] ?? false) ? 'success' : 'failed',
            "运功恢复: " . ($result['message'] ?? 'failed'));

        if ($result['success'] ?? false) {
            // 更新冷却时间戳（注意：rest 命令也用 last_recover 做冷却，但 exert 不走 rest 冷却，
            // 这里不更新 last_recover，让 AI 在 exert 成功后仍可立即尝试 rest）
            // Database::execute("UPDATE characters SET last_recover = ? WHERE id = ?", [time(), $charId]);
            return [
                'success' => true,
                'message' => '运功恢复',
                'action' => 'recover',
                'ai_detail' => "运功恢复 HP+" . ($result['recover_amount'] ?? 0) . " FORCE-" . ($result['force_cost'] ?? 0)
            ];
        }

        // exert recover 失败（内力不足/无内功/战斗中）→ 尝试普通休息
        // 普通休息消耗食物+饮水缓慢回血，和正常玩家体验一致
        if (!CombatDaemon::isInCombat($charId)) {
            $area = $char['current_area'] ?? '';
            // 在安全区域时优先用休息而非直接冲药铺
            if (self::isInCity($area, $char['current_room'] ?? '') || $area === 'ourhome') {
                require_once __DIR__ . '/../helpers/AutoRecoverHelper.php';
                $restResult = AutoRecoverHelper::checkAndRecover($charId);
                if ($restResult['success'] ?? false) {
                    self::recordAction($charId, 'ai_rest', 'success',
                        "普通休息: " . ($restResult['message'] ?? ''));
                    return [
                        'success' => true,
                        'message' => '普通休息',
                        'action' => 'rest',
                        'ai_detail' => $restResult['message'] ?? '休息了一会儿'
                    ];
                }
                // rest 失败（冷却/无食物）→ 走药铺路线
            }
        }

        return null;
    }

    /**
     * AI 鬼魂还魂多阶段流程（替代 tryAutoReincarnate）
     *
     * 阶段：
     *   wall_nav   — 鬼魂导航到 death/new-out6 穿墙
     *   wall_wait  — 在阴阳界等待 60 秒
     *   wall_pass  — 执行穿墙（→ death/gateway）
     *   city_nav   — 导航到 death/zhengtang 找崔判官
     *   reincarnate — 在崔判官处还魂（→ ourhome/kedian）
     *   exit_nav   — 导航到 ourhome/xiaoting
     *   exit_out   — 跨出栏杆(out) → dntg/hgs/entrance
     */
    private static function handleGhostFlow(int $charId, array $char): ?array {
        $currentArea = $char['current_area'] ?? '';
        $currentRoom = $char['current_room'] ?? '';
        $fullRoom = (strpos($currentRoom, '/') !== false) ? $currentRoom : $currentArea . '/' . $currentRoom;
        $isGhost = intval($char['is_ghost'] ?? 0) === 1;
        $hellTime = intval($char['hell_enter_time'] ?? 0);
        $kee = intval($char['kee'] ?? 0);

        // CLI fallback: HTML 常量可能未加载
        if (!defined('HTML_HIYEL')) {
            define('HTML_HIYEL', '<span style="color:#FFFF00;font-weight:bold">');
            define('HTML_HIGRN', '<span style="color:#00FF00;font-weight:bold">');
            define('HTML_HICYN', '<span style="color:#00FFFF;font-weight:bold">');
            define('HTML_HIBLU', '<span style="color:#0000FF;font-weight:bold">');
            define('HTML_HIRED', '<span style="color:#FF0000;font-weight:bold">');
            define('HTML_NOR', '</span>');
        }

        // 读取进行中的鬼魂流程状态
        $stateRow = Database::queryOne(
            "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = 'ai_ghost_flow'",
            [$charId]
        );
        $state = $stateRow ? json_decode($stateRow['temp_value'] ?? '', true) : null;

        // === 过期状态清理：角色已复活但鬼魂流程状态未清除 ===
        // 可能发生在导航卡住/跨区域失败后状态不一致的情况
        // 注意：不清理 exit_nav/exit_out 阶段（还魂后还需完成跨出栏杆）
        $phaseCheck = $state['phase'] ?? '';
        if ($state && !$isGhost && $hellTime <= 0 && $kee > 1
            && !in_array($phaseCheck, ['exit_nav', 'exit_out'])) {
            self::logDebug("Ghost state stale for char {$charId}: not ghost, no hellTime, kee>1, phase={$phaseCheck} → clearing");
            self::clearGhostFlowState($charId);
            self::clearNavigationTarget($charId);
            return null; // 正常决策
        }

        // === 鬼魂流程已完成还魂但状态残留（在 ourhome/kedian 但未进入 exit_nav）===
        if ($state && !$isGhost && $hellTime > 0
            && ($currentArea === 'ourhome' || strpos($currentRoom, 'ourhome/') === 0)
            && $fullRoom !== 'ourhome/xiaoting') {
            // 已还魂到 ourhome，跳到 exit_nav 阶段
            $phase = $state['phase'] ?? '';
            if ($phase !== 'exit_nav' && $phase !== 'exit_out') {
                self::logDebug("Ghost in ourhome but phase={$phase} → fast-forward to exit_nav");
                $state['phase'] = 'exit_nav';
                self::saveGhostFlowState($charId, $state);
                self::clearNavigationTarget($charId);
                self::setNavigationTarget($charId, 'ourhome', 'ourhome/xiaoting', '前往聚见亭');
            }
        }

        // === 状态过期清理：鬼魂状态超过20分钟的旧流程 ===
        if ($state && $isGhost) {
            $startedAt = intval($state['started_at'] ?? 0);
            if ($startedAt > 0 && (time() - $startedAt) > 1200) {
                self::logDebug("Ghost state expired for char {$charId}: started " . (time() - $startedAt) . "s ago → resetting");
                self::clearGhostFlowState($charId);
                self::clearNavigationTarget($charId);
                $state = null; // 重新初始化
            }
        }

        // === 通用修复：鬼魂不在 death 区域 → 直接传送（无论状态是否已初始化）===
        // 原因：阳间没有通往地府的出口，鬼魂无法步行抵达，会无限随机乱走
        if ($isGhost && $kee <= 1 && $hellTime > 0
            && $currentArea !== 'death' && strpos($currentRoom, 'death/') !== 0) {
            Database::execute(
                "UPDATE characters SET current_area = 'death', current_room = 'death/gate' WHERE id = ?",
                [$charId]
            );
            $char['current_area'] = 'death';
            $char['current_room'] = 'death/gate';
            $fullRoom = 'death/gate';
            $currentArea = 'death';
            $currentRoom = 'death/gate';
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            MessageDaemon::queueMessageToSelf($charId, HTML_HIYEL . '阴风骤起，你的魂魄被卷入鬼门关中...' . HTML_NOR, 'self_event');
            self::logDebug("Ghost {$charId} teleported from non-death area to death/gate (state={" . ($state ? $state['phase'] : 'none') . "})");
            self::clearNavigationTarget($charId);
            // 重置状态到 wall_nav（放弃旧状态，重新开始）
            $state = null;
        }

        // === 状态初始化 ===
        if (!$state) {
            if ($isGhost && $kee <= 1 && $hellTime > 0) {
                // 鬼魂不在 death 区域 → 直接传送到鬼门关（阳间没有通往地府的出口，鬼魂无法步行抵达）
                if ($currentArea !== 'death' && strpos($currentRoom, 'death/') !== 0) {
                    Database::execute(
                        "UPDATE characters SET current_area = 'death', current_room = 'death/gate' WHERE id = ?",
                        [$charId]
                    );
                    $char['current_area'] = 'death';
                    $char['current_room'] = 'death/gate';
                    $fullRoom = 'death/gate';
                    require_once __DIR__ . '/../daemons/MessageDaemon.php';
                    MessageDaemon::queueMessageToSelf($charId, HTML_HIYEL . '阴风骤起，你的魂魄被卷入鬼门关中...' . HTML_NOR, 'self_event');
                    self::logDebug("Ghost {$charId} teleported from {$currentRoom} to death/gate (no cross-area exit)");
                }
                // ★ 崔珏在 death/gate，直接问他还魂（与真人点击"还魂"一致）
                // 跳过漫长的穿墙→酆都城→阎罗宝殿路线
                if ($fullRoom === 'death/gate') {
                    require_once __DIR__ . '/../daemons/ReincarnateHandler.php';
                    $rcResult = ReincarnateHandler::executeReincarnate($charId);
                    self::logDebug(
                        "Ghost {$charId} at death/gate, direct executeReincarnate: " .
                        ($rcResult['success'] ? 'SUCCESS' : 'FAILED: ' . ($rcResult['message'] ?? ''))
                    );
                    if ($rcResult['success']) {
                        // 还魂成功 → 直接进入 exit 阶段（已传送到 ourhome/kedian）
                        $state = ['phase' => 'exit_nav', 'started_at' => time()];
                        self::saveGhostFlowState($charId, $state);
                        self::clearNavigationTarget($charId);
                        self::setNavigationTarget($charId, 'ourhome', 'ourhome/xiaoting', '前往聚见亭');
                        require_once __DIR__ . '/../daemons/MessageDaemon.php';
                        MessageDaemon::queueMessageToSelf($charId,
                            HTML_HIYEL . '你向崔判官道了谢，转身向南走去...' . HTML_NOR, 'self_event');
                        return [
                            'success' => true, 'action' => 'ghost_reincarnate',
                            'message' => 'AI在崔判官处还魂成功', 'ai_detail' => 'death/gate直接还魂(npc_id=167 topic=life)→ourhome/kedian'
                        ];
                    }
                    // 还魂失败（如条件不满足）→ 降级到穿墙路线
                    self::logDebug("Ghost {$charId} reincarnate at death/gate failed, fallback to wall_nav");
                }
                // 鬼魂：开始穿墙流程（降级路线），导航到阴阳界
                $state = ['phase' => 'wall_nav', 'started_at' => time()];
                self::saveGhostFlowState($charId, $state);
                self::setNavigationTarget($charId, 'death', 'death/new-out6', '鬼魂前往阴阳界穿墙');
                require_once __DIR__ . '/../daemons/MessageDaemon.php';
                MessageDaemon::queueMessageToSelf($charId, HTML_HIYEL . '你感到一阵阴风吹过，向着阴阳界飘去...' . HTML_NOR, 'self_event');
                return [
                    'success' => true, 'action' => 'ghost_wall_nav',
                    'message' => 'AI鬼魂前往阴阳界', 'ai_detail' => '鬼魂导航至death/new-out6'
                ];
            }
            if (!$isGhost && $hellTime > 0) {
                // 已不是鬼魂但还有 hell_enter_time：刚穿墙或从旧流程遗留
                if ($currentArea === 'death' || strpos($currentRoom, 'death/') === 0) {
                    // 刚穿墙，在酆都城内，继续去找崔判官
                    $state = ['phase' => 'city_nav', 'started_at' => time()];
                    self::saveGhostFlowState($charId, $state);
                    self::setNavigationTarget($charId, 'death', 'death/zhengtang', '前往阎罗宝殿找崔判官');
                    require_once __DIR__ . '/../daemons/MessageDaemon.php';
                    MessageDaemon::queueMessageToSelf($charId, HTML_HIYEL . '你穿过城墙，向酆都城内走去...' . HTML_NOR, 'self_event');
                    return [
                        'success' => true, 'action' => 'ghost_city_nav',
                        'message' => 'AI前往阎罗宝殿', 'ai_detail' => '穿墙后导航至death/zhengtang'
                    ];
                }
                if ($currentArea === 'ourhome' || strpos($currentRoom, 'ourhome/') === 0) {
                    // 旧流程遗留：已在 ourhome，直接跳到 exit_nav
                    $state = ['phase' => 'exit_nav', 'started_at' => time()];
                    self::saveGhostFlowState($charId, $state);
                    self::setNavigationTarget($charId, 'ourhome', 'ourhome/xiaoting', '前往聚见亭');
                    require_once __DIR__ . '/../daemons/MessageDaemon.php';
                    MessageDaemon::queueMessageToSelf($charId, HTML_HIYEL . '你整理了一下衣衫，向南边的聚见亭走去...' . HTML_NOR, 'self_event');
                    return [
                        'success' => true, 'action' => 'ghost_exit_nav',
                        'message' => 'AI前往聚见亭', 'ai_detail' => 'ourhome遗留→直接去xiaoting'
                    ];
                }
                // 在其他区域：清除过期标记
                self::clearHellEnterTimeIfStale($charId);
                return null;
            }
            return null; // 无需还魂流程
        }

        $phase = $state['phase'] ?? '';

        switch ($phase) {

            // === 阶段1: 导航到阴阳界 ===
            case 'wall_nav':
                if ($fullRoom === 'death/new-out6') {
                    // 已到达阴阳界，检查 60 秒等待
                    $waited = time() - $hellTime;
                    if ($waited >= 60) {
                        // 时间已到，直接穿墙
                        $state['phase'] = 'wall_pass';
                        self::saveGhostFlowState($charId, $state);
                        // fall through to wall_pass
                    } else {
                        // 需等待
                        $remaining = 60 - $waited;
                        $state['phase'] = 'wall_wait';
                        self::saveGhostFlowState($charId, $state);
                        require_once __DIR__ . '/../daemons/MessageDaemon.php';
                        MessageDaemon::queueMessageToSelf($charId,
                            HTML_HIYEL . "鬼门关阴气太重，你还需要等待{$remaining}秒才能穿墙..." . HTML_NOR,
                            'self_event');
                        return [
                            'success' => true, 'action' => 'ghost_wall_wait',
                            'message' => "等待穿墙剩余{$remaining}秒", 'ai_detail' => "wall_wait:{$remaining}s"
                        ];
                    }
                }
                if ($phase === 'wall_pass') break; // 跳转到 wall_pass
                // 确保导航目标还在（上次导航可能被清除了）
                if (!self::hasNavigationTarget($charId)) {
                    self::setNavigationTarget($charId, 'death', 'death/new-out6', '鬼魂前往阴阳界穿墙');
                }
                return [
                    'success' => true, 'action' => 'ghost_nav',
                    'message' => 'AI鬼魂前往阴阳界', 'ai_detail' => '导航中'
                ];

            // === 阶段1b: 等待 60 秒 ===
            case 'wall_wait':
                if ($fullRoom !== 'death/new-out6') {
                    // 被移动了？重新导航
                    $state['phase'] = 'wall_nav';
                    self::saveGhostFlowState($charId, $state);
                    self::setNavigationTarget($charId, 'death', 'death/new-out6', '鬼魂返回阴阳界');
                    return [
                        'success' => true, 'action' => 'ghost_nav',
                        'message' => 'AI鬼魂返回阴阳界', 'ai_detail' => '被移动后重新导航'
                    ];
                }
                $waited = time() - $hellTime;
                $remaining = 60 - $waited;
                if ($remaining <= 0) {
                    $state['phase'] = 'wall_pass';
                    self::saveGhostFlowState($charId, $state);
                    // fall through to wall_pass
                    break;
                }
                return [
                    'success' => true, 'action' => 'ghost_wall_wait',
                    'message' => "等待穿墙剩余{$remaining}秒", 'ai_detail' => "wall_wait:{$remaining}s"
                ];

            default:
                break;
        }

        // === 阶段2: 执行穿墙 ===
        if ($phase === 'wall_pass') {
            // 参考 handleHellWallPass 逻辑
            Database::execute(
                "UPDATE characters SET kee = max_kee, gin = max_gin, sen = max_sen, is_ghost = 0, current_area = 'death', current_room = 'death/gateway' WHERE id = ?",
                [$charId]
            );
            self::clearNavigationTarget($charId);

            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            MessageDaemon::queueMessageToSelf($charId,
                "\n你直直地向北边的城门走去，忽然穿过黑色的城墙进了城去。", 'self_event');

            // 进入下一阶段
            $state['phase'] = 'city_nav';
            self::saveGhostFlowState($charId, $state);
            // 设置导航到阎罗宝殿
            self::setNavigationTarget($charId, 'death', 'death/zhengtang', '前往阎罗宝殿找崔判官');

            return [
                'success' => true, 'action' => 'ghost_wall_pass',
                'message' => 'AI穿过城墙进入酆都城', 'ai_detail' => 'wall_pass→death/gateway'
            ];
        }

        // === 阶段3: 导航到阎罗宝殿 ===
        if ($phase === 'city_nav') {
            // 安全检查：如果不在 death 区域，说明被旧流程传送到别处
            if ($currentArea !== 'death' && strpos($currentRoom, 'death/') !== 0) {
                // 在 ourhome → 直接跳到 exit_nav；否则清除还魂标记
                if ($currentArea === 'ourhome' || strpos($currentRoom, 'ourhome/') === 0) {
                    $state['phase'] = 'exit_nav';
                    self::saveGhostFlowState($charId, $state);
                    self::clearNavigationTarget($charId);
                    self::setNavigationTarget($charId, 'ourhome', 'ourhome/xiaoting', '前往聚见亭');
                    require_once __DIR__ . '/../daemons/MessageDaemon.php';
                    MessageDaemon::queueMessageToSelf($charId, HTML_HIYEL . '你发现已身在阳间，于是向南边的聚见亭走去...' . HTML_NOR, 'self_event');
                    return [
                        'success' => true, 'action' => 'ghost_exit_nav',
                        'message' => 'AI前往聚见亭', 'ai_detail' => '已在ourhome→跳至exit_nav'
                    ];
                }
                self::clearHellEnterTimeIfStale($charId);
                self::clearGhostFlowState($charId);
                return null;
            }
            if ($fullRoom === 'death/zhengtang') {
                // 到了！触发还魂
                $state['phase'] = 'reincarnate';
                self::saveGhostFlowState($charId, $state);
                // fall through
            } else {
                if (!self::hasNavigationTarget($charId)) {
                    self::setNavigationTarget($charId, 'death', 'death/zhengtang', '前往阎罗宝殿找崔判官');
                }
                return [
                    'success' => true, 'action' => 'ghost_city_nav',
                    'message' => 'AI前往阎罗宝殿', 'ai_detail' => '导航至death/zhengtang'
                ];
            }
        }

        // === 阶段4: 崔判官处还魂（统一走 executeReincarnate，与真人 http://127.0.0.1/functions/action.php?action=ask&npc_id=167&topic=life 一致）===
        if ($phase === 'reincarnate') {
            require_once __DIR__ . '/../daemons/ReincarnateHandler.php';
            // 使用统一的 executeReincarnate，条件与真人 ask life 完全一致：
            // kee <= 1（鬼魂） 或 hell_enter_time > 0（刚穿墙）
            $result = ReincarnateHandler::executeReincarnate($charId);
            self::clearNavigationTarget($charId);

            if (!$result['success']) {
                // 还魂失败，清除流程状态由下次tick重试
                self::clearGhostFlowState($charId);
                return ['success' => false, 'action' => 'ghost_error', 'message' => $result['message'], 'ai_detail' => '还魂失败:' . ($result['message'] ?? '未知错误')];
            }

            // 还魂成功，清除可能存在的任务目标（防止还魂后立即执行 mieyao_retake 等任务动作）
            Database::execute(
                "DELETE FROM character_temp WHERE char_id=? AND temp_key='ai_goal'",
                [$charId]
            );
            self::logDebug("Cleared ai_goal for char {$charId} after reincarnate");

            // 还魂成功，进入 exit 阶段
            $state['phase'] = 'exit_nav';
            self::saveGhostFlowState($charId, $state);
            self::setNavigationTarget($charId, 'ourhome', 'ourhome/xiaoting', '前往聚见亭');

            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            MessageDaemon::queueMessageToSelf($charId,
                HTML_HIYEL . '你向崔判官道了谢，转身向南走去...' . HTML_NOR, 'self_event');

            return [
                'success' => true, 'action' => 'ghost_reincarnate',
                'message' => 'AI在崔判官处还魂成功', 'ai_detail' => 'executeReincarnate→ourhome/kedian,开始去xiaoting'
            ];
        }

        // === 阶段5: 导航到聚见亭 ===
        if ($phase === 'exit_nav') {
            // 如果不在 ourhome 区域（可能被其他任务传走），直接传送回来
            if ($currentArea !== 'ourhome' && strpos($currentRoom, 'ourhome/') !== 0) {
                Database::execute(
                    "UPDATE characters SET current_area='ourhome', current_room='ourhome/kedian' WHERE id=?",
                    [$charId]
                );
                $char['current_area'] = 'ourhome';
                $char['current_room'] = 'ourhome/kedian';
                $fullRoom = 'ourhome/kedian';
                $currentArea = 'ourhome';
                $currentRoom = 'ourhome/kedian';
                self::logDebug("exit_nav: char {$charId} not in ourhome, teleported back to ourhome/kedian");
                self::clearNavigationTarget($charId);
                self::setNavigationTarget($charId, 'ourhome', 'ourhome/xiaoting', '前往聚见亭');
            }

            if ($fullRoom === 'ourhome/xiaoting') {
                // 到达聚见亭，直接执行 exit_out 逻辑（不依赖 switch fall through）
                require_once __DIR__ . '/../commands/go.php';
                $currentRoomObj = RoomModel::load($currentArea, $fullRoom)
                    ?: ['room_id' => $fullRoom, 'area' => $currentArea, 'name' => '聚见亭'];
                $result = handleXiaotingOut($charId, $currentRoomObj);

                self::clearNavigationTarget($charId);
                self::clearGhostFlowState($charId);

                // 复活完成 → 设置后续目标
                self::setGoal($charId, 'quest', 'mieyao', 7, '复活后接灭妖任务');
                self::logDebug("Ghost flow complete for {$charId} (exit_nav arrived), set follow-up goal: quest→mieyao");

                // 广播消息
                if (!empty($result['leave_message'])) {
                    MessageDaemon::broadcastToRoom($fullRoom, $result['leave_message'], intval($charId));
                }
                if (!empty($result['arrive_message']) && !empty($result['new_room'])) {
                    MessageDaemon::broadcastToRoom($result['new_room']['room_id'], $result['arrive_message'], intval($charId));
                }

                $targetName = $result['new_room']['name'] ?? '仙石';
                MessageDaemon::queueMessageToSelf($charId,
                    HTML_HIYEL . '你跨出栏杆，经过一阵天旋地转，来到了' . $targetName . '。' . HTML_NOR,
                    'self_event');

                return [
                    'success' => true, 'action' => 'ghost_exit_out',
                    'message' => 'AI跨出聚见亭栏杆(handleXiaotingOut)', 'ai_detail' => 'out→dntg/hgs/entrance,还魂流程完成,已设后续目标'
                ];
            } else {
                if (!self::hasNavigationTarget($charId)) {
                    self::setNavigationTarget($charId, 'ourhome', 'ourhome/xiaoting', '前往聚见亭');
                }
                return [
                    'success' => true, 'action' => 'ghost_exit_nav',
                    'message' => 'AI前往聚见亭', 'ai_detail' => '导航至ourhome/xiaoting'
                ];
            }
        }

        // === 阶段6: 跨出栏杆 out ===
        // 调用真实游戏逻辑 handleXiaotingOut()（go.php），与真人玩家 go out 完全一致
        if ($phase === 'exit_out') {
            require_once __DIR__ . '/../commands/go.php';
            
            // 构建 room 数组（handleXiaotingOut 需要 currentRoom）
            $currentRoom = RoomModel::load($currentArea, $fullRoom) ?: ['room_id' => $fullRoom, 'area' => $currentArea, 'name' => '聚见亭'];
            $result = handleXiaotingOut($charId, $currentRoom);
            
            self::clearNavigationTarget($charId);
            self::clearGhostFlowState($charId);

            // 复活完成 → 设置后续目标（去接灭妖任务，避免复活后发呆）
            self::setGoal($charId, 'quest', 'mieyao', 7, '复活后接灭妖任务');
            self::logDebug("Ghost flow complete for {$charId} (via handleXiaotingOut), set follow-up goal: quest→mieyao");

            // 广播消息（handleXiaotingOut 返回 leave/arrive message）
            if (!empty($result['leave_message'])) {
                MessageDaemon::broadcastToRoom($fullRoom, $result['leave_message'], intval($charId));
            }
            if (!empty($result['arrive_message']) && !empty($result['new_room'])) {
                MessageDaemon::broadcastToRoom($result['new_room']['room_id'], $result['arrive_message'], intval($charId));
            }

            // 给自己发消息
            $targetName = $result['new_room']['name'] ?? '仙石';
            MessageDaemon::queueMessageToSelf($charId,
                HTML_HIYEL . '你跨出栏杆，经过一阵天旋地转，来到了' . $targetName . '。' . HTML_NOR,
                'self_event');

            return [
                'success' => true, 'action' => 'ghost_exit_out',
                'message' => 'AI跨出聚见亭栏杆(handleXiaotingOut)', 'ai_detail' => 'out→dntg/hgs/entrance,还魂流程完成,已设后续目标'
            ];
        }

        return null;
    }

    /**
     * 保存鬼魂流程状态到 character_temp
     */
    private static function saveGhostFlowState(int $charId, array $state): void {
        Database::execute(
            "INSERT INTO character_temp (char_id, temp_key, temp_value) VALUES (?, 'ai_ghost_flow', ?)
             ON DUPLICATE KEY UPDATE temp_value = ?",
            [$charId, json_encode($state), json_encode($state)]
        );
    }

    /**
     * 清除鬼魂流程状态
     */
    private static function clearGhostFlowState(int $charId): void {
        Database::execute(
            "DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'ai_ghost_flow'",
            [$charId]
        );
    }

    // ==================== AI 目标与记忆系统 ====================

    /**
     * 获取 AI 当前目标
     * @return array|null ['type','target','priority','desc','created_at','attempts','last_action_at']
     */
    private static function getGoal(int $charId): ?array {
        $row = Database::queryOne(
            "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = 'ai_goal'",
            [$charId]
        );
        if (!$row) return null;
        $goal = json_decode($row['temp_value'] ?? '', true);
        if (!is_array($goal) || empty($goal['type'])) {
            Database::execute("DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'ai_goal'", [$charId]);
            return null;
        }
        // 超时清理（超过30分钟的目标废弃）
        if ((time() - intval($goal['created_at'] ?? 0)) > 1800) {
            self::logDebug("Goal expired for char {$charId}: {$goal['type']} → {$goal['target']}");
            Database::execute("DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'ai_goal'", [$charId]);
            return null;
        }
        return $goal;
    }

    /**
     * 设置 AI 目标（持久化到 character_temp）
     */
    private static function setGoal(int $charId, string $type, string $target, int $priority, string $desc): void {
        $goal = [
            'type' => $type,
            'target' => $target,
            'priority' => $priority,
            'desc' => $desc,
            'created_at' => time(),
            'attempts' => 0,
            'last_action_at' => time(),
        ];
        Database::execute(
            "INSERT INTO character_temp (char_id, temp_key, temp_value) VALUES (?, 'ai_goal', ?)
             ON DUPLICATE KEY UPDATE temp_value = ?",
            [$charId, json_encode($goal, JSON_UNESCAPED_UNICODE), json_encode($goal, JSON_UNESCAPED_UNICODE)]
        );
        self::logDebug("Goal set for char {$charId}: {$type} → {$target} (priority={$priority})");
    }

    /**
     * 完成目标：记录到历史后清除
     */
    private static function completeGoal(int $charId): void {
        $goal = self::getGoal($charId);
        if ($goal) {
            self::recordAction($charId, 'goal_complete', 'success', "完成目标: {$goal['type']}={$goal['target']}");
        }
        Database::execute("DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'ai_goal'", [$charId]);
    }

    /**
     * 清除指定类型的 goal（用于 executeGoalAction 设置导航后防止重复触发）
     */
    private static function clearGoal(int $charId, string $type, string $target): void {
        $goal = self::getGoal($charId);
        if ($goal && ($goal['type'] ?? '') === $type && ($goal['target'] ?? '') === $target) {
            Database::execute("DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'ai_goal'", [$charId]);
        }
    }

    /**
     * 标记目标失败（增加尝试次数，超过阈值则废弃）
     */
    private static function failGoalAttempt(int $charId): void {
        $goal = self::getGoal($charId);
        if (!$goal) return;
        $goal['attempts'] = intval($goal['attempts'] ?? 0) + 1;
        $goal['last_action_at'] = time();
        if ($goal['attempts'] >= 10) {
            self::recordAction($charId, 'goal_abandon', 'failed', "放弃目标({$goal['attempts']}次失败): {$goal['type']}={$goal['target']}");
            Database::execute("DELETE FROM character_temp WHERE char_id = ? AND temp_key = 'ai_goal'", [$charId]);
            return;
        }
        Database::execute(
            "UPDATE character_temp SET temp_value = ? WHERE char_id = ? AND temp_key = 'ai_goal'",
            [json_encode($goal, JSON_UNESCAPED_UNICODE), $charId]
        );
    }

    /**
     * 记录 AI 行为（最近 50 条，用于学习分析）
     */
    private static function recordAction(int $charId, string $action, string $result, string $detail = ''): void {
        $row = Database::queryOne(
            "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = 'ai_action_history'",
            [$charId]
        );
        $history = $row ? json_decode($row['temp_value'] ?? '', true) : [];
        if (!is_array($history)) $history = [];

        $entry = [
            'action' => $action,
            'result' => $result,
            'detail' => $detail,
            'at' => time(),
        ];
        $history[] = $entry;

        // 保留最近 50 条
        if (count($history) > 50) {
            $history = array_slice($history, -50);
        }

        Database::execute(
            "INSERT INTO character_temp (char_id, temp_key, temp_value) VALUES (?, 'ai_action_history', ?)
             ON DUPLICATE KEY UPDATE temp_value = ?",
            [$charId, json_encode($history, JSON_UNESCAPED_UNICODE), json_encode($history, JSON_UNESCAPED_UNICODE)]
        );
    }

    /**
     * 检测 AI 是否"卡住"（最近连续多次同类型动作但无实质进展）
     * @return bool 是否卡住
     */
    private static function isStuck(int $charId, array $char): bool {
        $row = Database::queryOne(
            "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = 'ai_action_history'",
            [$charId]
        );
        if (!$row) return false;
        $history = json_decode($row['temp_value'] ?? '', true);
        if (!is_array($history) || count($history) < 5) return false;

        // 取最近 5 条
        $recent = array_slice($history, -5);
        $moveCount = 0;
        $sameActionCount = 0;
        $lastAction = '';
        foreach ($recent as $entry) {
            $act = $entry['action'] ?? '';
            if ($act === 'move' || $act === 'nav_step') $moveCount++;
            if ($act === $lastAction) $sameActionCount++;
            $lastAction = $act;
        }

        // 最近 5 次中有 4 次以上是移动 → 可能在乱走
        if ($moveCount >= 4) return true;

        // 连续 3 次相同动作都失败 → 卡住了
        $recentFailures = 0;
        foreach (array_reverse($recent) as $entry) {
            $result = $entry['result'] ?? '';
            if ($result === 'failed' || $result === 'no_path') {
                $recentFailures++;
            } else {
                break; // 遇到成功的就停止计数
            }
        }
        if ($recentFailures >= 3) return true;

        return false;
    }

    /**
     * 获取 AI 行为摘要（用于日志分析）
     */
    private static function getActionSummary(int $charId): string {
        $row = Database::queryOne(
            "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = 'ai_action_history'",
            [$charId]
        );
        if (!$row) return '无历史';
        $history = json_decode($row['temp_value'] ?? '', true);
        if (!is_array($history) || empty($history)) return '无历史';

        $recent = array_slice($history, -5);
        $actions = array_map(function($e) {
            $ago = time() - intval($e['at'] ?? 0);
            return ($e['action'] ?? '?') . '(' . ($e['result'] ?? '?') . ',' . $ago . 's前)';
        }, $recent);
        return implode(' → ', $actions);
    }

    /**
     * 执行持久化目标动作（将目标类型映射为具体动作）
     * @return array|null 动作结果；null 表示目标无效/已完成
     */
    private static function executeGoalAction(int $charId, array $char, array $goal): ?array {
        $type = $goal['type'] ?? '';
        $target = $goal['target'] ?? '';
        $currentArea = $char['current_area'] ?? '';
        $currentRoom = $char['current_room'] ?? '';
        $fullRoom = (strpos($currentRoom, '/') !== false) ? $currentRoom : $currentArea . '/' . $currentRoom;

        switch ($type) {

            // 复活类目标：导航到安全区域
            case 'revive':
                // 如果已经是鬼魂 → 交给 ghost flow 处理（不要重复初始化）
                if (intval($char['is_ghost'] ?? 0) === 1) {
                    return null; // ghost flow 已在 tick 里处理
                }
                // 如果不是鬼魂但记录了这个目标 → 已完成
                return null;

            // 导航类目标：设置导航标记
            case 'navigate':
                $parts = explode('/', $target, 2);
                $targetArea = $parts[0] ?? '';
                $targetRoom = $target;
                if (count($parts) < 2) $targetRoom = $targetArea . '/' . $target;

                // 已到达目标
                if ($fullRoom === $targetRoom) {
                    self::recordAction($charId, 'goal_arrived', 'success', "已到达{$target}");
                    return null; // 目标达成，tick 下一轮会清除
                }

                self::setNavigationTarget($charId, $targetArea, $targetRoom, "目标导航: {$goal['desc']}");
                return [
                    'success' => true, 'action' => 'goal_navigate',
                    'message' => "AI按目标导航至{$target}",
                    'ai_detail' => "goal_navigate: {$goal['desc']}"
                ];

            // 探索类目标：在指定区域随机探索
            case 'explore':
                $targetArea = explode('/', $target)[0];
                if ($currentArea !== $targetArea) {
                    self::setNavigationTarget($charId, $targetArea, $target, "探索: {$goal['desc']}");
                    return [
                        'success' => true, 'action' => 'goal_explore_nav',
                        'message' => "AI导航至探索区域{$targetArea}",
                        'ai_detail' => "goal_explore: 前往{$targetArea}"
                    ];
                }
                // 已在目标区域，随机移动探索
                return self::doMove($charId, $char);

            // 任务类目标：根据具体任务类型处理
            case 'quest':
                // 简单的任务目标：检查是否有对应任务
                if ($target === 'kaifeng') {
                    $kaifengNav = self::checkKaifengNavigation($char);
                    if ($kaifengNav !== null) {
                        $actionResult = self::executeAction($charId, $char, $kaifengNav);
                        // 【关键修复】设置导航动作后，清除 goal 防止下个 tick 重复设置导航造成死循环
                        // executeAction 返回非 null → completeGoal 不清除 goal → 这里主动清除
                        if ($actionResult !== null) {
                            self::clearGoal($charId, 'quest', $target);
                        }
                        return $actionResult;
                    }
                }
                if ($target === 'mieyao') {
                    $mieyaoNav = self::checkMieyaoNavigation($char);
                    if ($mieyaoNav !== null) return self::executeAction($charId, $char, $mieyaoNav);
                    // 没有活跃灭妖任务：导航去天监台接新任务（不清除 goal）
                    $currentArea = $char['current_area'] ?? '';
                    if ($currentArea !== 'city') {
                        // 不在长安，设导航去天监台
                        self::setNavigationTarget($charId, 'city', 'city/tianjiantai', '前往天监台接灭妖任务');
                        self::logDebug("Goal quest→mieyao: no active task, set nav to city/tianjiantai for {$charId}");
                    }
                    // 已在长安或已设导航：返回 stay 让 tick 继续处理导航
                    return [
                        'success' => true,
                        'message' => '灭妖任务：无活跃任务，前往天监台接新任务',
                        'action' => 'mieyao_navigate',
                    ];
                }
                // 没有对应任务 → 目标失效
                return null;

            default:
                self::logDebug("Unknown goal type: {$type} for char {$charId}");
                return null;
        }
    }

    /**
     * 检查是否有导航目标
     */
    private static function hasNavigationTarget(int $charId): bool {
        $row = Database::queryOne(
            "SELECT 1 FROM character_temp WHERE char_id = ? AND temp_key = 'ai_nav_target' LIMIT 1",
            [$charId]
        );
        return !empty($row);
    }

    /**
     * 清除过期的 hell_enter_time（角色已在非地府/非ourhome区域）
     */
    private static function clearHellEnterTimeIfStale(int $charId): void {
        Database::execute(
            "UPDATE characters SET hell_enter_time = 0 WHERE id = ? AND hell_enter_time > 0",
            [$charId]
        );
    }

    /**
     * AI 恢复（安全兜底 — 实际流程中不会走到这里）
     * 正常的恢复路径：
     *   有内功 → tryAutoRecover（exert recover）→ 内功运功恢复
     *   无内功 → navigate_to_pharmacy → buy_med → 买药恢复
     *   药铺没钱 → goto_mieyao → 灭妖赚钱
     * 此函数仅为兼容旧代码路径，尝试 exert recover；失败则导航去药铺
     */
    private static function doRecover(int $charId, array $char): array {
        require_once __DIR__ . '/../helpers/SkillManager.php';
        $mappedForce = SkillManager::querySkillMapped($charId, 'force');
        $force = intval($char['force'] ?? 0);

        if (!empty($mappedForce) && $force >= 20) {
            require_once __DIR__ . '/../daemons/ActionRouter.php';
            $result = ActionRouter::handleCustomAction($charId, 'exert recover', '');
            if ($result['success'] ?? false) {
                return [
                    'success' => true, 'message' => '运功恢复',
                    'action' => 'recover',
                    'ai_detail' => "运功恢复 HP+" . ($result['recover_amount'] ?? 0)
                ];
            }
        }

        // 无法运功恢复；已满血则不需要去药铺
        $kee = intval($char['kee'] ?? 0);
        $maxKee = intval($char['max_kee'] ?? 100);
        if ($maxKee > 0 && ($kee / $maxKee) >= 0.9) {
            return ['success' => true, 'message' => '气血已满，无需恢复', 'action' => 'stay'];
        }

        // 需要恢复但无内功 → 导航去药铺
        return self::doNavigateToPharmacy($charId, $char);
    }

    /**
     * 导航到长安药铺 (city/yaopu) 买药恢复
     * 适用于无内功、内力耗尽的 AI
     */
    private static function doNavigateToPharmacy(int $charId, array $char): array {
        $area = $char['current_area'] ?? '';
        $room = $char['current_room'] ?? '';
        $fullRoom = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
        $normalizedFullRoom = self::normalizeRoomPath($fullRoom);

        // 已在药铺 → 直接买药
        if (self::isMedicineShop($area, $room)) {
            return self::doBuyMedicine($charId, $char);
        }

        // BFS 导航到 city/yaopu（步行，不传送）
        self::setNavigationTarget($charId, 'city', 'city/yaopu', '去药铺买药恢复');
        $navResult = self::processNavigationTarget($charId, $char);
        if ($navResult !== null) {
            return [
                'success' => true,
                'message' => '前往长安药铺买药',
                'action' => 'navigate_to_pharmacy',
                'ai_detail' => '导航去 city/yaopu 买药恢复气血'
            ];
        }

        // BFS 失败（如在不可达区域），使用安全传送
        $oldRoom = $char['current_room'] ?? '';
        self::moveCharacter($charId, 'city', 'city/yaopu', $char['name'] ?? '', $oldRoom);
        return [
            'success' => true,
            'message' => '传送至长安药铺',
            'action' => 'navigate_to_pharmacy',
            'ai_detail' => 'BFS失败，传送至 city/yaopu 买药'
        ];
    }

    /**
     * 药铺没钱 → 去灭妖赚钱
     */
    private static function doGotoMieyao(int $charId, array $char): array {
        $area = $char['current_area'] ?? '';
        $room = $char['current_room'] ?? '';

        // 先看看有没有已接的灭妖任务可以直接做
        $mieyaoNav = self::checkMieyaoNavigation($char);
        if ($mieyaoNav !== null) {
            return self::executeAction($charId, $char, $mieyaoNav);
        }

        // BFS 导航到天监台接灭妖任务（步行，不传送）
        self::setNavigationTarget($charId, 'city', 'city/tianjiantai', '去天监台接灭妖赚钱');
        $navResult = self::processNavigationTarget($charId, $char);
        if ($navResult !== null) {
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            MessageDaemon::queueMessageToSelf($charId,
                HTML_HIYEL . "囊中羞涩买不起药，先去天监台接灭妖任务赚银两..." . HTML_NOR,
                'self_event');
            return [
                'success' => true,
                'message' => '前往天监台接灭妖任务',
                'action' => 'goto_mieyao',
                'ai_detail' => '没钱买药，去天监台接灭妖'
            ];
        }

        // BFS 失败 → 传送
        $oldRoom = $char['current_room'] ?? '';
        self::moveCharacter($charId, 'city', 'city/tianjiantai', $char['name'] ?? '', $oldRoom);
        return [
            'success' => true,
            'message' => '传送至天监台',
            'action' => 'goto_mieyao',
            'ai_detail' => 'BFS失败，传送至 city/tianjiantai 接灭妖'
        ];
    }
    
    // ==================== 修炼 ====================
    
    /**
     * AI 修炼：练习技能或打坐恢复内力
     */
    private static function doTrain(int $charId, array $char): array {
        $skills = CharacterModel::getSkills($charId);
        
        if (!empty($skills)) {
            $skill = $skills[array_rand($skills)];
            $skillId = $skill['skill_id'] ?? '';
            $skillName = $skill['skill_name'] ?? $skillId;
            
            $expGain = mt_rand(10, 50);
            Database::execute(
                "UPDATE character_skills SET exp = exp + ? WHERE char_id = ? AND skill_id = ?",
                [$expGain, $charId, $skillId]
            );
            
            // 尝试升级
            $levelUp = self::tryLevelUpSkill($charId, $skillId);
            
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            $msg = HTML_HICYN . "你专心练习{$skillName}，感觉略有精进。" . HTML_NOR;
            if ($levelUp) {
                $msg .= HTML_HIGRN . "\n你的{$skillName}升级了！" . HTML_NOR;
            }
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
            
            return [
                'success' => true,
                'message' => "修炼{$skillName}",
                'action' => 'train',
                'ai_detail' => "修炼{$skillName} +{$expGain}经验" . ($levelUp ? ' 升级!' : '')
            ];
        }
        
        // 没有技能则打坐增加内力
        $force = intval($char['force'] ?? 0);
        $maxForce = intval($char['max_force'] ?? 100);
        $forceGain = mt_rand(1, 5);
        $newForce = min($maxForce, $force + $forceGain);
        
        Database::execute("UPDATE characters SET `force` = ? WHERE id = ?", [$newForce, $charId]);
        
        require_once __DIR__ . '/../daemons/MessageDaemon.php';
        $msg = HTML_HICYN . "你盘膝打坐，内力缓缓流转。" . HTML_NOR;
        MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
        
        return [
            'success' => true,
            'message' => '打坐修炼内力',
            'action' => 'train',
            'ai_detail' => "打坐 +{$forceGain}内力"
        ];
    }
    
    // ==================== 灭妖任务 ====================
    
    /**
     * 接取灭妖任务（袁天罡 - 新手入口）
     */
    private static function doMieyaoTask(int $charId, array $char): array {
        // 检查是否已有活跃的灭妖任务
        $existingTask = Database::queryOne(
            "SELECT id FROM mieyao_yaoguai WHERE owner_id = ? AND is_killed = 0 AND expires_at > NOW()",
            [$charId]
        );
        if ($existingTask) {
            // 已有任务，尝试移动到妖怪所在区域
            return self::doMove($charId, $char);
        }
        
        // 检查经验门槛
        $combatExp = intval($char['combat_exp'] ?? 0);
        $daoxing = intval($char['daoxing'] ?? 0);
        $total = ($combatExp + $daoxing) / 2;
        
        if ($total > 50000) {
            // 经验超限，尝试去李靖处
            return self::doMove($charId, $char);
        }
        
        // 执行灭妖任务
        try {
            require_once __DIR__ . '/../daemons/MieyaoHandler.php';
            require_once __DIR__ . '/../daemons/MieyaoYaoguai.php';
            
            $handler = new MieyaoHandler();
            $actionConfig = [
                'npc_id' => 'yuantiangang',
                'action' => 'ask',
            ];
            $result = $handler->execute($charId, $char, $actionConfig);
            
            if ($result['success']) {
                require_once __DIR__ . '/../daemons/MessageDaemon.php';
                MessageDaemon::queueMessageToSelf($charId, HTML_HIYEL . $result['message'] . HTML_NOR, 'self_event');
            }
            
            return [
                'success' => $result['success'],
                'message' => '接取灭妖任务',
                'action' => 'mieyao_task',
                'ai_detail' => '在袁天罡处接灭妖任务'
            ];
        } catch (\Exception $e) {
            error_log("[AI_MIEYAO] Error: " . $e->getMessage());
            return ['success' => false, 'message' => '灭妖任务失败', 'action' => 'mieyao_task'];
        }
    }
    
    /**
     * 接取灭妖任务（李靖 - 高级入口）
     */
    private static function doMieyaoTaskHeaven(int $charId, array $char): array {
        $existingTask = Database::queryOne(
            "SELECT id FROM mieyao_yaoguai WHERE owner_id = ? AND is_killed = 0 AND expires_at > NOW()",
            [$charId]
        );
        if ($existingTask) {
            return self::doMove($charId, $char);
        }
        
        try {
            require_once __DIR__ . '/../daemons/MieyaoHandler.php';
            require_once __DIR__ . '/../daemons/MieyaoYaoguai.php';
            
            $handler = new MieyaoHandler();
            $actionConfig = [
                'npc_id' => 'lijing',
                'action' => 'ask',
            ];
            $result = $handler->execute($charId, $char, $actionConfig);
            
            if ($result['success']) {
                require_once __DIR__ . '/../daemons/MessageDaemon.php';
                MessageDaemon::queueMessageToSelf($charId, HTML_HIYEL . $result['message'] . HTML_NOR, 'self_event');
            }
            
            return [
                'success' => $result['success'],
                'message' => '接取高级灭妖任务',
                'action' => 'mieyao_task_heaven',
                'ai_detail' => '在李靖处接高级灭妖任务'
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => '高级灭妖任务失败', 'action' => 'mieyao_task_heaven'];
        }
    }
    
    /**
     * 重新领取过期的灭妖任务
     */
    private static function doMieyaoRetake(int $charId, array $char): array {
        $area = $char['current_area'] ?? '';
        $room = $char['current_room'] ?? '';
        $fullRoom = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
        $normalizedFullRoom = self::normalizeRoomPath($fullRoom);

        if ($normalizedFullRoom !== 'city/tianjiantai') {
            self::setNavigationTarget($charId, 'city', 'city/tianjiantai', '前往天监台重新领取灭妖任务');
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            MessageDaemon::queueMessageToSelf($charId, HTML_HIYEL . "你前往天监台找袁天罡重新领取灭妖任务..." . HTML_NOR, 'self_event');
            return [
                'success' => true,
                'message' => '前往天监台重新领取灭妖任务',
                'action' => 'mieyao_retake',
                'ai_detail' => '导航到天监台重新领取灭妖任务'
            ];
        }

        return self::doMieyaoTask($charId, $char);
    }

    /**
     * AI 使用自动寻怪（调用 AutoFindYaoguaiHandler）
     */
    private static function doMieyaoAutoFind(int $charId, array $char): array {
        try {
            require_once __DIR__ . '/../daemons/AutoFindYaoguaiHandler.php';
            $handler = new AutoFindYaoguaiHandler();
            $res = $handler->execute($charId, [], []);
            if (!empty($res['success'])) {
                require_once __DIR__ . '/../daemons/MessageDaemon.php';
                MessageDaemon::queueMessageToSelf($charId, $res['message'] ?? '自动寻怪成功', 'self_event');
                return [
                    'success' => true,
                    'message' => $res['message'] ?? '自动寻怪已触发',
                    'action' => 'mieyao_auto_find',
                    'ai_detail' => 'AI 调用自动寻怪并移动到目标'
                ];
            }
            return [
                'success' => false,
                'message' => $res['message'] ?? '自动寻怪失败',
                'action' => 'mieyao_auto_find'
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => '自动寻怪异常: ' . $e->getMessage(), 'action' => 'mieyao_auto_find'];
        }
    }
    
    // ==================== 开封任务 ====================
    
    /**
     * 检查是否有活跃的开封任务（pending 或 done）
     */
    private static function hasActiveKaifengQuest(int $charId): bool {
        if (file_exists(__DIR__ . '/../helpers/QuestHelper.php')) {
            require_once __DIR__ . '/../helpers/QuestHelper.php';
            $pending = QuestHelper::getPendingQuests($charId);
            if (!empty($pending)) return true;
            $done = QuestHelper::getDoneQuests($charId);
            return !empty($done);
        }
        // 回退：直接查表
        $quest = Database::queryOne(
            "SELECT id FROM character_quests WHERE char_id = ? AND status IN ('pending', 'done') LIMIT 1",
            [$charId]
        );
        return !empty($quest);
    }
    
    /**
     * 判断当前房间是否是开封任务NPC所在房间
     * 支持多种路径格式: kaifeng/bingqi, /d/kaifeng/bingqi, /d/kaifeng/bingqi/xxx
     */
    private static function isKaifengQuestRoom(string $fullRoom): bool {
        $normalizedFullRoom = self::normalizeRoomPath($fullRoom);
        $kaifengNpcRooms = [
            'kaifeng/tianpeng',        // 猪八戒 - food
            'kaifeng/shuaifu',         // 猪八戒备用 (数据库实际 spawn_room)
            'kaifeng/bingqi',          // 相公 - weapon
            'kaifeng/kuijia',          // 相婆 - armor
            'kaifeng/xianglan',        // 香兰 - cloth
            'kaifeng/yulan',           // 玉兰 - wearing
            'kaifeng/cuilan',          // 翠兰 - misc
            'kaifeng/pudu',            // 殷夫人 - ask
            'kaifeng/jixian',          // 陈光蕊 - ask
            'kaifeng/ee',              // 胡敬德 - kill
        ];
        foreach ($kaifengNpcRooms as $qr) {
            if (strpos($normalizedFullRoom, $qr) !== false) return true;
        }
        return false;
    }
    
    /**
     * 归一化房间路径：去除 /d/ 前缀并标准化格式
     */
    private static function normalizeRoomPath(string $room): string {
        $room = trim(str_replace('\\', '/', $room));
        $room = preg_replace('#^/d/#', '', $room);
        $room = trim($room, '/');
        return $room;
    }

    /**
     * 根据房间匹配开封NPC配置
     * 注意：数据库 npcs.spawn_room 格式为 kaifeng/roomid (无 /d/ 前缀)
     */
    private static function matchKaifengNpc(string $fullRoom): ?array {
        require_once __DIR__ . '/QuestHelper.php';
        $npcMap = QuestHelper::getNpcMap();
        if (empty($npcMap)) return null;
        
        // 归一化房间路径：去除可能的 /d/ 前缀，保留 kaifeng/roomid 格式
        $normalizedRoom = self::normalizeRoomPath($fullRoom);
        $shortRoom = basename($normalizedRoom);
        
        // 多种匹配方式：精确匹配、短名匹配、以及 /d/ 前缀匹配
        $npcs = Database::queryAll(
            "SELECT * FROM npcs WHERE spawn_room = ? OR spawn_room = ? OR spawn_room = ? OR spawn_room LIKE ?",
            [$normalizedRoom, $shortRoom, '/d/' . $normalizedRoom, "%{$normalizedRoom}%"]
        );
        
        foreach ($npcs as $npc) {
            $npcKey = $npc['npc_id'] ?? '';
            if (isset($npcMap[$npcKey])) {
                return [
                    'npc' => $npc,
                    'config' => $npcMap[$npcKey],
                    'npc_id' => $npcKey,
                ];
            }
        }
        
        // 如果还是没匹配到，尝试通过 spawn_area = 'kaifeng' 来查找 NPC
        // 然后检查其 spawn_room 是否与当前房间有关联
        $allKaifengNpcs = Database::queryAll(
            "SELECT * FROM npcs WHERE spawn_area = 'kaifeng' AND spawn_room IS NOT NULL AND spawn_room != ''"
        );
        foreach ($allKaifengNpcs as $npc) {
            $npcKey = $npc['npc_id'] ?? '';
            if (!isset($npcMap[$npcKey])) continue;
            
            $npcRoom = self::normalizeRoomPath($npc['spawn_room'] ?? '');
            // 检查当前房间是否与 NPC 房间匹配
            if (strpos($normalizedRoom, $npcRoom) !== false || strpos($npcRoom, $shortRoom) !== false) {
                return [
                    'npc' => $npc,
                    'config' => $npcMap[$npcKey],
                    'npc_id' => $npcKey,
                ];
            }
        }
        
        return null;
    }
    
    /**
     * 接取开封任务（使用 kaifeng_quests.php 配置池分配任务）
     * 
     * 根据当前房间精确匹配NPC，从该NPC对应的任务池中分配任务。
     * 任务记录写入 character_quests 表，包含 npc_id 用于后续领奖定位。
     */
    /**
     * 接取开封任务 - 模拟玩家向开封NPC询问任务
     * 
     * 核心：调用 handleKaifengQuest() 向NPC询问（而非直接 assign），
     * 这样与玩家通过网页询问任务的逻辑完全一致。
     */
    private static function doKaifengTask(int $charId, array $char): array {
        $area = $char['current_area'] ?? '';
        $room = $char['current_room'] ?? '';
        $fullRoom = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
        
        require_once __DIR__ . '/../helpers/QuestHelper.php';
        
        // 先检查是否已有 pending 任务
        $existingPending = QuestHelper::getPendingQuests($charId);
        if (!empty($existingPending)) {
            return self::doKaifengDo($charId, $char);
        }
        
        // 匹配当前房间的 NPC
        $matched = self::matchKaifengNpc($fullRoom);
        if (!$matched) {
            // 当前房间没有开封任务NPC → 不要乱走，直接导航到随机任务NPC房间
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            MessageDaemon::queueMessageToSelf($charId,
                HTML_HIMAG . '这里没有开封任务NPC，前往府衙接任务...' . HTML_NOR, 'self_event');
            return self::doKaifengNavigateToQuest($charId, $char);
        }
        
        $npcId = $matched['npc_id'] ?? '';
        $npcConfig = $matched['config'];
        $questType = $npcConfig['quest_type'] ?? '';
        $npcName = $npcConfig['name'] ?? $npcId;
        $npcTopic = $npcConfig['topic'] ?? '任务';  // NPC 主话题，如"祭祖"、"食物"、"灭妖"
        
        // 从数据库加载该NPC的完整记录（handleKaifengQuest 需要 inquiry 等字段）
        require_once __DIR__ . '/../models/Npc.php';
        $dbNpc = NpcModel::findByNpcId($npcId);
        if (!$dbNpc) {
            // 数据库找不到该NPC，使用配置的简化信息
            $dbNpc = [
                'id' => 0,
                'npc_id' => $npcId,
                'name' => $npcName,
                'inquiry' => null,
                'area' => $area,
                'room' => $fullRoom,
            ];
        }
        
        // ★ 核心修复：直接用 assignKaifengQuestFromPool() 分配任务
        // handleKaifengQuest() 内部调用 QuestHelper::assignQuest()，后者读的是
        // config/quest_definitions.php（不是开封任务定义），导致永远返回null！
        // 正确做法：直接使用 kaifeng_quests.php 的 quest_pools 分配任务
        $quest = self::assignKaifengQuestFromPool($charId, $npcId, $questType, $char);
        
        require_once __DIR__ . '/../daemons/MessageDaemon.php';
        if ($quest !== null) {
            // 任务分配成功
            $targetName = $quest['name'] ?? '';
            $questName = $quest['quest_name'] ?? '';
            $objectName = $quest['object'] ?? '';
            $typeNames = [
                'food' => '寻食物', 'weapon' => '寻兵器', 'armor' => '寻盔甲',
                'cloth' => '寻衣物', 'wearing' => '寻首饰', 'misc' => '寻杂物',
                'ask' => '打听消息', 'kill' => '除妖',
            ];
            $typeText = $typeNames[$questType] ?? '任务';
            $msg = HTML_HIYEL . "{$npcName}委托你{$typeText}：去「{$targetName}」那里打听「{$objectName}」的消息。" . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
            
            // 返回 stay：让 AI 在当前位置停留，不要立即被 decideAction → checkKaifengNavigation → doKaifengDo 抢走
            // 这样下个 tick AI 才开始执行任务（doKaifengDo）
            return [
                'success' => true,
                'message' => "接取开封{$typeText}任务",
                'action' => 'stay',
                'ai_detail' => "向{$npcName}接取{$typeText}任务: {$targetName} - {$objectName}"
            ];
        } else {
            // 任务分配失败（可能已满或缓存问题）→ 尝试其他NPC
            self::logDebug("AI kaifeng assign failed for {$npcName}, trying next NPC");
        }
        
        // 分配失败 → 尝试下一个NPC
        return self::doKaifengNavigateToQuest($charId, $char);
    }
    
    /**
     * 从 kaifeng_quests.php 配置池中分配任务给AI玩家
     * 
     * 模拟 QuestHandler::assignKaifengQuest() 的逻辑，
     * 使用 kaifeng_quests.php 的任务池而非 quest_definitions.php
     */
    private static function assignKaifengQuestFromPool(int $charId, string $npcId, string $questType, array $char): ?array {
        require_once __DIR__ . '/QuestHelper.php';
        
        $npcMap = QuestHelper::getNpcMap();
        $npcInfo = $npcMap[$npcId] ?? null;
        if (!$npcInfo) return null;
        
        $questPool = QuestHelper::getQuestPool($questType);
        if (empty($questPool)) return null;
        
        $charDaoxing = intval($char['daoxing'] ?? 0);
        $cacheSize = QuestHelper::getConfigParam('cache_size', 30);
        $indexDelta = QuestHelper::getConfigParam('index_delta', 20);
        
        // 根据任务类型获取对应NPC的颜色代码
        $colorCode = $npcInfo['color_code'] ?? 'white';
        
        // 根据道行计算奖励（参考 QuestHandler 逻辑）
        $daoxingFactor = max(1, intval($charDaoxing / 1000));
        $baseDaoxing = 100;
        $rewards = [
            'daoxing' => $baseDaoxing * $daoxingFactor,
            'potential' => intval($baseDaoxing * $daoxingFactor * 0.6),
            'silver' => intval($baseDaoxing * $daoxingFactor * 0.3),
        ];
        
        // 将任务池按道行值排序
        $daoxingKeys = array_keys($questPool);
        sort($daoxingKeys);
        $totalKeys = count($daoxingKeys);
        
        // 二分查找：找到道行值 <= 角色道行值的最大索引
        $suitableIndex = 0;
        $low = 0;
        $high = $totalKeys - 1;
        while ($low <= $high) {
            $mid = intval(($low + $high) / 2);
            if ($daoxingKeys[$mid] <= $charDaoxing + 5000) {
                $suitableIndex = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }
        
        // 加入随机偏移
        $lower = max(0, $suitableIndex - $indexDelta);
        $upper = min($totalKeys - 1, $suitableIndex + $indexDelta);
        $lower = intval($upper / 4);
        if ($upper - $lower < $indexDelta) {
            $lower = 0;
        }
        
        // 随机选一个任务，跳过缓存中已有的
        $maxAttempts = 10;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $selectedIndex = $lower + rand(0, max(0, $upper - $lower));
            $selectedDaxingKey = $daoxingKeys[$selectedIndex];
            $selectedQuest = $questPool[$selectedDaxingKey];
            
            // 缓存检查
            $cached = Database::queryOne(
                "SELECT COUNT(*) as cnt FROM quest_cache WHERE char_id = ? AND quest_type = ? AND quest_index = ?",
                [$charId, $questType, $selectedDaxingKey]
            );
            if ($cached && intval($cached['cnt']) > 0 && $attempt < $maxAttempts - 1) {
                continue;
            }
            
            // 根据任务类型选择合适的字段填充
            $hasAskNpc = isset($selectedQuest['ask_npc']);
            if ($questType === 'ask' && $hasAskNpc) {
                // ask 任务结构：ask_npc(发布者), target_npc(目标), target_room(目标房间), topic(话题)
                $questgiverNpc = $selectedQuest['ask_npc'];
                $targetNpcKey = $selectedQuest['target_npc'];
                $targetNpcName = $selectedQuest['target_npc_name'] ?? '';
                $targetRoom = $selectedQuest['target_room'] ?? '';
                $topic = $selectedQuest['topic'] ?? '';
                $targetName = null;  // ask 任务无 target_name
                
                // INSERT 完整列顺序：char_id, quest_type, quest_name, target_id, target_name, object_name, 
                // daoxing_require, reward_daoxing, reward_potential, reward_silver, color_code, npc_id, 
                // questgiver_npc, target_room, status, created_at
                $sql = "INSERT INTO character_quests 
                        (char_id, quest_type, quest_name, target_id, target_name, object_name, 
                         daoxing_require, reward_daoxing, reward_potential, reward_silver, color_code, 
                         npc_id, questgiver_npc, target_room, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
                $params = [
                    $charId, $questType,
                    $targetNpcName,        // quest_name = 目标NPC名字
                    $targetNpcKey,         // target_id = 目标NPC key  
                    $targetName,            // target_name = null
                    $topic,                // object_name = 话题
                    $selectedDaxingKey,
                    $rewards['daoxing'], $rewards['potential'], $rewards['silver'],
                    $colorCode,
                    $targetNpcKey,        // npc_id = 目标NPC key（兼容旧逻辑）
                    $questgiverNpc,        // questgiver_npc = 发布任务NPC
                    $targetRoom,           // target_room = 目标房间
                ];
            } else {
                // find 任务结构：weapon/armor/cloth/food/wearing/misc/kill
                $targetId = $selectedQuest['id'] ?? '';
                $questName = $selectedQuest['name'] ?? '';
                $objectName = $selectedQuest['topic'] ?? '';
                $targetName = $objectName;  // target_name = object_name
                
                $sql = "INSERT INTO character_quests 
                        (char_id, quest_type, quest_name, target_id, target_name, object_name, 
                         daoxing_require, reward_daoxing, reward_potential, reward_silver, color_code, 
                         npc_id, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
                $params = [
                    $charId, $questType,
                    $questName, $targetId, $targetName, $objectName,
                    $selectedDaxingKey,
                    $rewards['daoxing'], $rewards['potential'], $rewards['silver'],
                    $colorCode, $npcId
                ];
                $questgiverNpc = $npcId;
                $targetRoom = null;
            }
            
            $result = Database::execute($sql, $params);
            if ($result) {
                $questId = Database::lastInsertId();
                
                // 更新缓存
                Database::execute(
                    "INSERT INTO quest_cache (char_id, quest_type, quest_index, cached_at) VALUES (?, ?, ?, NOW())",
                    [$charId, $questType, $selectedDaxingKey]
                );
                // 清理超出大小的旧缓存
                Database::execute(
                    "DELETE FROM quest_cache WHERE char_id = ? AND quest_type = ? 
                     AND id NOT IN (SELECT id FROM (SELECT id FROM quest_cache WHERE char_id = ? AND quest_type = ? ORDER BY cached_at DESC LIMIT ?) as tmp)",
                    [$charId, $questType, $charId, $questType, $cacheSize]
                );
                
                return [
                    'id' => $questId,
                    'quest_name' => $questType === 'ask' ? ($selectedQuest['target_npc_name'] ?? '') : ($selectedQuest['name'] ?? ''),
                    'name' => $questType === 'ask' ? ($selectedQuest['target_npc_name'] ?? '') : ($selectedQuest['name'] ?? ''),
                    'target' => $questType === 'ask' ? ($selectedQuest['target_npc'] ?? '') : ($selectedQuest['id'] ?? ''),
                    'object' => $questType === 'ask' ? ($selectedQuest['topic'] ?? '') : ($selectedQuest['topic'] ?? ''),
                    'type' => $questType,
                    'color' => $colorCode,
                    'reward_dx' => $rewards['daoxing'],
                    'reward_pot' => $rewards['potential'],
                    'reward_silver' => $rewards['silver'],
                    'questgiver_npc' => $questgiverNpc,
                    'target_room' => $targetRoom,
                ];
            }
        }
        
        return null;
    }
    
    /**
     * 执行开封任务（根据任务类型智能处理 find/ask/kill）
     * 
     * 任务类型详解：
     * - find (food/weapon/armor/cloth/wearing/misc): 需要获取指定物品
     *   → 优先从背包检查 → 自动购买 → 打怪掉落
     * - ask: 需要去指定NPC处打听消息
     *   → 殷夫人(kaifeng/pudu) / 陈光蕊(kaifeng/jixian)
     * - kill: 需要击杀指定怪物
     *   → 在当前区域搜索匹配怪物 → 发起战斗
     */
    private static function doKaifengDo(int $charId, array $char): array {
        require_once __DIR__ . '/../helpers/QuestHelper.php';
        
        $pendingQuests = QuestHelper::getPendingQuests($charId);
        
        if (empty($pendingQuests)) {
            // 没有 pending 任务，检查 done 状态
            $doneQuests = QuestHelper::getDoneQuests($charId);
            if (!empty($doneQuests)) {
                return self::doKaifengClaim($charId, $char);
            }
            // 既没有 pending 也没有 done 任务 → 尝试在当前位置接新任务
            return self::doKaifengTask($charId, $char);
        }
        
        // 优先处理已超时的任务（过期任务标记为expired）
        $expiredQuests = QuestHelper::checkExpiredQuests($charId);
        if (!empty($expiredQuests)) {
            // 有过期任务，刷新 pending 列表（在DB更新后重新查询）
            $pendingQuests = QuestHelper::getPendingQuests($charId);
            if (empty($pendingQuests)) {
                // 所有 pending 任务都过期了 → 在当前位置直接尝试接新任务
                return self::doKaifengTask($charId, $char);
            }
            // 还有其他 pending 任务，继续处理
        }
        
        // 随机选一个 pending 任务处理
        $quest = $pendingQuests[array_rand($pendingQuests)];
        $questType = $quest['quest_type'] ?? 'find';
        $targetId = $quest['target_id'] ?? '';
        $objectName = $quest['object_name'] ?? '';
        $questName = $quest['quest_name'] ?? '';
        
        // ★ 修复：处理旧任务（npc_id/target_id 为 NULL 的无法完成的任务）
        // 旧代码 bug 导致创建的任务数据不完整，这些任务无法被正确处理
        // 主动检测并放弃，而不是依赖 expires_at（很多旧任务没有设置过期时间）
        $savedNpcId = $quest['npc_id'] ?? '';
        $questId = intval($quest['id'] ?? 0);
        $isIncompleteTask = empty($targetId) || empty($savedNpcId);
        
        // 额外检查：ask 类型任务没有 target_room 也无法完成
        $hasTargetRoom = !empty($quest['target_room'] ?? '');
        if ($questType === 'ask' && $isIncompleteTask) {
            // ask 任务但 target_id/npc_id 为空 → 放弃
        } elseif ($questType === 'ask' && !$hasTargetRoom) {
            // ask 任务没有 target_room → 无法导航到正确位置 → 放弃
            $isIncompleteTask = true;
        }
        
        if ($isIncompleteTask) {
            // 无法确定任务目标，标记为过期并重新尝试接新任务
            if ($questId > 0) {
                Database::execute(
                    "UPDATE character_quests SET status = 'expired' WHERE id = ? AND char_id = ?",
                    [$questId, $charId]
                );
            }
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            MessageDaemon::queueMessageToSelf($charId, HTML_HIMAG . "旧任务数据不完整，已自动放弃。" . HTML_NOR, 'self_event');
            // 不要设置导航目标（在当前位置直接尝试接新任务）
            return self::doKaifengTask($charId, $char);
        }
        
        $currentArea = $char['current_area'] ?? '';
        $currentRoom = $char['current_room'] ?? '';
        $fullRoom = (strpos($currentRoom, '/') !== false) ? $currentRoom : $currentArea . '/' . $currentRoom;
        
        // ===== find 类型：food/weapon/armor/cloth/wearing/misc =====
        $findTypes = ['food', 'weapon', 'armor', 'cloth', 'wearing', 'misc', 'find'];
        if (in_array($questType, $findTypes)) {
            // 1. 检查背包中是否已有目标物品
            $inventory = Database::queryOne(
                "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = ? LIMIT 1",
                [$charId, $targetId]
            );
            
            if ($inventory) {
                // 已获得物品，标记任务完成
                $result = QuestHelper::markQuestDone($charId, $questType, $targetId);
                if ($result) {
                    require_once __DIR__ . '/../daemons/MessageDaemon.php';
                    $msg = HTML_HIGRN . "你找到了{$questName}！快回去领赏吧。" . HTML_NOR;
                    MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
                    
                    return [
                        'success' => true,
                        'message' => "找到{$questName}，标记完成",
                        'action' => 'kaifeng_do',
                        'ai_detail' => "find任务完成: {$questName}，待回访领奖"
                    ];
                }
            }
            
            // 2. 尝试自动购买目标物品（搜索所有区域商店）
            $buyResult = self::doAutoBuyItem($charId, $char, $targetId, $questName);
            if ($buyResult && $buyResult['success']) {
                // 购买成功或正在导航去商店，下次 tick 继续
                return $buyResult;
            }
            
            // 3. 找不到商店出售该物品，尝试搜索物品可能存在的区域去打怪获取
            // 查找该物品可能在哪个区域的NPC身上（通过 npc 的携带物品或掉落）
            $itemArea = Database::queryValue(
                "SELECT n.spawn_area FROM npcs n 
                 INNER JOIN shop_items si ON si.shop_id = n.id 
                 WHERE si.item_id = ? AND n.spawn_area IS NOT NULL AND n.spawn_area != ''
                 LIMIT 1",
                [$targetId]
            );
            
            if (!empty($itemArea) && $currentArea !== $itemArea) {
                // 物品在其他区域，逐步导航过去
                $targetEntryRoom = ($itemArea === 'city') ? 'city/kezhan' : "{$itemArea}/center";
                self::setNavigationTarget($charId, $itemArea, $targetEntryRoom, "去{$itemArea}寻找{$questName}");
                require_once __DIR__ . '/../daemons/MessageDaemon.php';
                $msg = HTML_HIYEL . "你启程去{$itemArea}寻找「{$questName}」..." . HTML_NOR;
                MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
                return [
                    'success' => true,
                    'message' => "导航到{$itemArea}寻找{$questName}",
                    'action' => 'kaifeng_do',
                    'ai_detail' => "find导航: 逐步前往{$itemArea}获取{$questName}"
                ];
            }
            
            // 4. 确实找不到商店或无法购买时，继续前往开封任务NPC房间寻找线索
            return self::doKaifengNavigateToQuest($charId, $char);
        }
        
        // ===== ask 类型：去目标NPC处询问打听 =====
        if ($questType === 'ask') {
            // 从任务记录中获取目标信息（由 assignKaifengQuestFromPool 填充）
            $targetRoom = $quest['target_room'] ?? '';
            $targetNpcKey = $quest['target_id'] ?? '';  // 目标NPC的key，如 'chen', 'yin'
            $askTopic = $quest['object_name'] ?? '';     // 要询问的话题，如 '祭祖', '求签'
            
            // ★ 修复：旧任务 target_room 为空时，通过 target_id 查找对应NPC房间
            if (empty($targetRoom) && !empty($targetNpcKey)) {
                // 在开封NPC表中查找该NPC的 spawn_room
                $npcRoom = Database::queryValue(
                    "SELECT spawn_room FROM npcs WHERE npc_id = ? AND spawn_area = 'kaifeng' LIMIT 1",
                    [$targetNpcKey]
                );
                if (!empty($npcRoom)) {
                    $targetRoom = preg_replace('#^/d/#', '', $npcRoom);
                }
            }
            
            // ★ 修复：仍然无法确定目标房间时，放弃旧任务继续
            if (empty($targetRoom)) {
                $questId = intval($quest['id'] ?? 0);
                if ($questId > 0) {
                    Database::execute(
                        "UPDATE character_quests SET status = 'expired' WHERE id = ? AND char_id = ?",
                        [$questId, $charId]
                    );
                }
                require_once __DIR__ . '/../daemons/MessageDaemon.php';
                MessageDaemon::queueMessageToSelf($charId, HTML_HIMAG . "旧ask任务「{$questName}」无法完成，已自动放弃。" . HTML_NOR, 'self_event');
                return self::doKaifengNavigateToQuest($charId, $char);
            }
            
            $normalizedFullRoom = self::normalizeRoomPath($fullRoom);
            $normalizedTargetRoom = self::normalizeRoomPath($targetRoom);
            
            if ($normalizedFullRoom === $normalizedTargetRoom) {
                // 已在目标NPC房间 → 执行打听
                $result = QuestHelper::markQuestDone($charId, 'ask', $targetNpcKey);
                if ($result) {
                    require_once __DIR__ . '/../daemons/MessageDaemon.php';
                    $msg = HTML_HIGRN . "你打听到了「{$askTopic}」的消息！快回去复命吧。" . HTML_NOR;
                    MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
                    return [
                        'success' => true,
                        'message' => "完成ask任务打听",
                        'action' => 'kaifeng_do',
                        'ai_detail' => "ask任务完成: {$questName}/{$askTopic}，待回访领奖"
                    ];
                }
                // markQuestDone 失败（可能已经完成），继续处理
            }
            
            // 不在目标房间，导航过去
            $oldRoom = $char['current_room'] ?? '';
            self::moveCharacter($charId, 'kaifeng', $targetRoom, $char['name'] ?? '', $oldRoom);
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            $msg = HTML_HIYEL . "你前往{$questName}处打听「{$askTopic}」的消息..." . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
            return [
                'success' => true,
                'message' => "导航到ask目标NPC: {$questName}",
                'action' => 'kaifeng_do',
                'ai_detail' => "ask导航: 前往{$targetRoom}找{$questName}打听{$askTopic}"
            ];
        }
        
        // ===== kill 类型：需要击杀目标怪物 =====
        if ($questType === 'kill') {
            // 归一化当前房间格式，支持 /d/ 前缀与标准 room_id 格式
            $normalizedFullRoom = self::normalizeRoomPath($fullRoom);
            $normalizedCurrentRoom = self::normalizeRoomPath($currentRoom);
            
            // 检查当前房间是否有匹配的怪物NPC（精确匹配 spawn_room 或含义匹配）
            $monsters = Database::queryAll(
                "SELECT * FROM npcs WHERE spawn_room IN (?,?,?,?,?) LIMIT 50",
                [$normalizedFullRoom, $normalizedCurrentRoom, '/d/' . $normalizedFullRoom, '/d/' . $normalizedCurrentRoom, "%{$normalizedFullRoom}%"]
            );
            
            if (empty($monsters)) {
                // 当前房间搜不到时，扩展到当前区域内潜在目标
                $monsters = Database::queryAll(
                    "SELECT * FROM npcs WHERE spawn_area = ? AND (name LIKE ? OR npc_id LIKE ? OR spawn_room LIKE ?) LIMIT 50",
                    [$currentArea, "%{$questName}%", "%{$targetId}%", "%{$normalizedCurrentRoom}%"]
                );
            }
            
            foreach ($monsters as $monster) {
                $monsterName = $monster['name'] ?? '';
                $monsterNpcId = $monster['npc_id'] ?? '';
                $monsterDbId = intval($monster['id'] ?? 0);

                $lowerMonsterName = mb_strtolower($monsterName, 'UTF-8');
                $lowerQuestName = mb_strtolower($questName, 'UTF-8');
                $lowerMonsterNpcId = mb_strtolower($monsterNpcId, 'UTF-8');
                $lowerTargetId = mb_strtolower((string)$targetId, 'UTF-8');

                $matchByName = !empty($monsterName) && !empty($questName) && (
                    stripos($lowerMonsterName, $lowerQuestName) !== false ||
                    stripos($lowerQuestName, $lowerMonsterName) !== false
                );
                $matchByNpcId = !empty($monsterNpcId) && !empty($targetId) && (
                    $lowerMonsterNpcId === $lowerTargetId ||
                    stripos($lowerMonsterNpcId, $lowerTargetId) !== false ||
                    stripos($lowerTargetId, $lowerMonsterNpcId) !== false
                );
                $matchByDbId = $monsterDbId > 0 && intval($targetId) > 0 && $monsterDbId === intval($targetId);

                if ($matchByName || $matchByNpcId || $matchByDbId) {
                    require_once __DIR__ . '/../daemons/CombatDaemon.php';
                    if (!CombatDaemon::isInCombat($charId)) {
                        $combatResult = CombatDaemon::startKill($charId, $monsterDbId, 'npc');
                        if (!$combatResult['success']) {
                            return [
                                'success' => false,
                                'message' => $combatResult['message'] ?? '发起击杀失败',
                                'action' => 'kaifeng_do',
                                'ai_detail' => 'kill任务发起战斗失败'
                            ];
                        }
                    }

                    return [
                        'success' => true,
                        'message' => "AI开始击杀{$monsterName}",
                        'action' => 'kaifeng_do',
                        'ai_detail' => "kill任务发起战斗: {$monsterName}"
                    ];
                }
            }

            // 没找到目标怪物，搜索该怪物可能在哪个区域
            $monsterLocation = Database::queryOne(
                "SELECT spawn_area, spawn_room FROM npcs 
                 WHERE (name LIKE ? OR npc_id LIKE ?) AND spawn_area IS NOT NULL AND spawn_area != ''
                 LIMIT 1",
                ['%' . $questName . '%', '%' . $targetId . '%']
            );
            
            if ($monsterLocation) {
                $monsterArea = $monsterLocation['spawn_area'] ?? '';
                $monsterRoom = $monsterLocation['spawn_room'] ?? '';
                $monsterRoom = preg_replace('#^/d/#', '', $monsterRoom);
                if (strpos($monsterRoom, '/') === false && !empty($monsterArea)) {
                    $monsterRoom = $monsterArea . '/' . $monsterRoom;
                }
                $normalizedFullRoom = self::normalizeRoomPath($fullRoom);
                if ($currentArea !== $monsterArea || strpos($normalizedFullRoom, $monsterRoom) === false) {
                    $oldRoom = $char['current_room'] ?? '';
                    self::moveCharacter($charId, $monsterArea, $monsterRoom, $char['name'] ?? '', $oldRoom);
                    require_once __DIR__ . '/../daemons/MessageDaemon.php';
                    $msg = HTML_HIYEL . "你去{$monsterArea}猎杀「{$questName}」..." . HTML_NOR;
                    MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
                    return [
                        'success' => true,
                        'message' => "导航猎杀{$questName}",
                        'action' => 'kaifeng_do',
                        'ai_detail' => "kill导航: 前往{$monsterRoom}猎杀{$questName}"
                    ];
                }
            }
            
            // 找不到怪物位置，继续前往开封任务NPC房间寻找线索
            return self::doKaifengNavigateToQuest($charId, $char);
        }
        
        // ===== give 类型 =====
        if ($questType === 'give') {
            // 检查背包中是否有目标物品
            $inventory = Database::queryOne(
                "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = ? LIMIT 1",
                [$charId, $targetId]
            );
            
            if ($inventory) {
                $result = QuestHelper::markQuestDone($charId, 'give', $targetId);
                if ($result) {
                    require_once __DIR__ . '/../daemons/MessageDaemon.php';
                    $msg = HTML_HIGRN . "你准备好了{$questName}，快送去给委托人吧！" . HTML_NOR;
                    MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
                    
                    return [
                        'success' => true,
                        'message' => "give任务完成: {$questName}",
                        'action' => 'kaifeng_do',
                        'ai_detail' => "give任务完成: {$questName}，待回访领奖"
                    ];
                }
            }
            
            // 尝试自动购买
            $buyResult = self::doAutoBuyItem($charId, $char, $targetId, $questName);
            if ($buyResult && $buyResult['success']) {
                return $buyResult;
            }
            
            return self::doKaifengNavigateToQuest($charId, $char);
        }
        
        // 未知类型，移动探索
        return self::doMove($charId, $char);
    }
    
    /**
     * AI自动购买任务所需物品
     * 
     * 使用项目已有的 ShopModel 和 shop_items 表进行购买。
     * 在开封区域的商店NPC处搜索并购买指定物品。
     * 支持所有 find 类型任务（food/weapon/armor/cloth/wearing/misc）的物品。
     */
    private static function doAutoBuyItem(int $charId, array $char, string $itemId, string $questName): ?array {
        if (empty($itemId)) return null;
        
        $currentArea = $char['current_area'] ?? '';
        $currentRoom = $char['current_room'] ?? '';
        $fullRoom = (strpos($currentRoom, '/') !== false) ? $currentRoom : $currentArea . '/' . $currentRoom;
        $normalizedFullRoom = self::normalizeRoomPath($fullRoom);
        
        require_once __DIR__ . '/../models/Shop.php';
        $allShops = ShopModel::findShopItemLocations($itemId);
        if (empty($allShops)) {
            // 没有任何商店出售该物品，尝试通过打怪获取
            return null;
        }

        // 优先选当前区域的商店，其次是任意区域
        usort($allShops, function($a, $b) use ($currentArea) {
            $aPriority = (isset($a['spawn_area']) && $a['spawn_area'] === $currentArea) ? 0 : 1;
            $bPriority = (isset($b['spawn_area']) && $b['spawn_area'] === $currentArea) ? 0 : 1;
            if ($aPriority !== $bPriority) return $aPriority - $bPriority;
            return intval($a['price'] ?? 0) - intval($b['price'] ?? 0);
        });

        $shopItem = $allShops[0];
        
        $shopRoom = $shopItem['spawn_room'] ?? '';
        $shopArea = $shopItem['spawn_area'] ?? '';
        $shopNpcId = intval($shopItem['shop_id'] ?? 0);
        $shopNpcName = $shopItem['npc_name'] ?? '商人';
        $price = intval($shopItem['price'] ?? 0);
        
        // 归一化房间路径
        $shopRoom = preg_replace('#^/d/#', '', $shopRoom);
        if (strpos($shopRoom, '/') === false && !empty($shopArea)) {
            $shopRoom = $shopArea . '/' . $shopRoom;
        }
        $shopRoom = self::normalizeRoomPath($shopRoom);
        
        // 如果不在商店房间，导航过去
        if (!empty($shopRoom) && $normalizedFullRoom !== $shopRoom) {
            $oldRoom = $char['current_room'] ?? '';
            self::moveCharacter($charId, $shopArea, $shopRoom, $char['name'] ?? '', $oldRoom);
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            $msg = HTML_HIYEL . "你去{$shopNpcName}处购买「{$questName}」..." . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
            
            return [
                'success' => true,
                'message' => "导航到商店购买{$questName}",
                'action' => 'kaifeng_do',
                'ai_detail' => "自动购买: 前往{$shopRoom}({$shopNpcName})买{$questName}"
            ];
        }
        
        // 在商店房间，使用 ShopModel 执行购买
        require_once __DIR__ . '/../models/Shop.php';
        
        // 检查价格是否可接受（价格大于0才尝试购买）
        if ($price <= 0) {
            // 价格异常，尝试下一个商店
            if (count($allShops) > 1) {
                array_shift($allShops);
                $nextShop = $allShops[0];
                $nextRoom = preg_replace('#^/d/#', '', $nextShop['spawn_room'] ?? '');
                $nextArea = $nextShop['spawn_area'] ?? '';
                if (strpos($nextRoom, '/') === false && !empty($nextArea)) {
                    $nextRoom = $nextArea . '/' . $nextRoom;
                }
                $nextRoom = self::normalizeRoomPath($nextRoom);
                if (!empty($nextRoom)) {
                    $oldRoom = $char['current_room'] ?? '';
                    self::moveCharacter($charId, $nextArea, $nextRoom, $char['name'] ?? '', $oldRoom);
                    return [
                        'success' => true,
                        'message' => "换商店购买{$questName}",
                        'action' => 'kaifeng_do',
                        'ai_detail' => "换商店: 前往{$nextRoom}买{$questName}"
                    ];
                }
            }
            return null;
        }
        
        // 检查资金
        require_once __DIR__ . '/../helpers/MoneyHelper.php';
        if (!MoneyHelper::hasEnoughMoney($charId, $price)) {
            // 钱不够，尝试找更便宜的商店
            if (count($allShops) > 1) {
                $cheapest = $allShops[0]; // 最便宜的（已按price ASC排序）
                $cheapestPrice = intval($cheapest['price'] ?? 0);
                if ($cheapestPrice > 0 && $cheapestPrice < $price && MoneyHelper::hasEnoughMoney($charId, $cheapestPrice)) {
                    $cheapRoom = preg_replace('#^/d/#', '', $cheapest['spawn_room'] ?? '');
                    $cheapArea = $cheapest['spawn_area'] ?? '';
                    if (strpos($cheapRoom, '/') === false && !empty($cheapArea)) {
                        $cheapRoom = $cheapArea . '/' . $cheapRoom;
                    }
                    $cheapRoom = self::normalizeRoomPath($cheapRoom);
                    if (!empty($cheapRoom)) {
                        $oldRoom = $char['current_room'] ?? '';
                        self::moveCharacter($charId, $cheapArea, $cheapRoom, $char['name'] ?? '', $oldRoom);
                        return [
                            'success' => true,
                            'message' => "去更便宜的商店买{$questName}",
                            'action' => 'kaifeng_do',
                            'ai_detail' => "比价: 前往{$cheapRoom}买{$questName}({$cheapestPrice}文)"
                        ];
                    }
                }
            }
            
            // 真的钱不够，去打怪赚钱
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            $msg = HTML_HIYEL . "钱不够买{$questName}（需{$price}文），去赚点钱..." . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
            return self::doMove($charId, $char);
        }
        
        // 执行购买（使用 ShopModel::buyItem）
        $category = $shopItem['category'] ?? '';
        $buyResult = ShopModel::buyItem($charId, $shopNpcId, $itemId, 1, $category);
        
        if ($buyResult['success']) {
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            $msg = HTML_HIGRN . "你花费{$price}文购买了「{$questName}」！" . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
            
            return [
                'success' => true,
                'message' => "购买{$questName}成功",
                'action' => 'kaifeng_do',
                'ai_detail' => "自动购买: {$questName} 花费{$price}文"
            ];
        }
        
        // 购买失败，尝试下一个商店
        if (count($allShops) > 1) {
            array_shift($allShops);
            $nextShop = $allShops[0];
            $nextRoom = preg_replace('#^/d/#', '', $nextShop['spawn_room'] ?? '');
            $nextArea = $nextShop['spawn_area'] ?? '';
            if (strpos($nextRoom, '/') === false && !empty($nextArea)) {
                $nextRoom = $nextArea . '/' . $nextRoom;
            }
            if (!empty($nextRoom)) {
                $oldRoom = $char['current_room'] ?? '';
                self::moveCharacter($charId, $nextArea, $nextRoom, $char['name'] ?? '', $oldRoom);
                return [
                    'success' => true,
                    'message' => "换商店购买{$questName}",
                    'action' => 'kaifeng_do',
                    'ai_detail' => "购买失败，换商店: 前往{$nextRoom}"
                ];
            }
        }
        
        return null;
    }
    
    /**
     * 获取ask任务的目标NPC房间
     * 
     * 殷夫人(yin furen) → kaifeng/pudu
     * 陈光蕊(chen guangrui) → kaifeng/jixian
     */
    private static function getAskTargetRoom(string $targetId, string $questName): ?string {
        // 通过 target_id 匹配
        if (stripos($targetId, 'yin') !== false || stripos($questName, '殷夫人') !== false) {
            return 'kaifeng/pudu';
        }
        if (stripos($targetId, 'chen') !== false || stripos($questName, '陈光蕊') !== false) {
            return 'kaifeng/jixian';
        }
        
        require_once __DIR__ . '/QuestHelper.php';
        $askPool = QuestHelper::getQuestPool('ask');
        foreach ($askPool as $dxKey => $quest) {
            $npcId = $quest['id'] ?? '';
            if ($npcId === $targetId || $quest['name'] === $targetId || 
                stripos($targetId, $npcId) !== false) {
                if (stripos($npcId, 'yin') !== false) return 'kaifeng/pudu';
                if (stripos($npcId, 'chen') !== false) return 'kaifeng/jixian';
            }
        }
        
        return null;
    }
    
    /**
     * 回访NPC领奖（处理 done 状态的任务）
     * 
     * 优先使用 character_quests.npc_id 精确导航到发布任务的NPC房间。
     * 对于ask类型任务（殷夫人/陈光蕊共用ask类型），通过npc_id区分。
     */
    private static function doKaifengClaim(int $charId, array $char): array {
        require_once __DIR__ . '/../helpers/QuestHelper.php';
        
        $doneQuests = QuestHelper::getDoneQuests($charId);
        if (empty($doneQuests)) {
            return ['success' => false, 'message' => '没有待领奖的任务', 'action' => 'kaifeng_claim'];
        }
        
        $area = $char['current_area'] ?? '';
        $room = $char['current_room'] ?? '';
        $fullRoom = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
        
        // 必须在开封区域
        if ($area !== 'kaifeng') {
            return self::doKaifengNavigate($charId, $char);
        }
        
        require_once __DIR__ . '/QuestHelper.php';
        $npcMap = QuestHelper::getNpcMap();
        
        // 找第一个done任务
        $quest = $doneQuests[0];
        $questType = $quest['quest_type'] ?? '';
        $questId = intval($quest['id'] ?? 0);
        $savedNpcId = $quest['npc_id'] ?? '';
        $questgiverNpc = $quest['questgiver_npc'] ?? '';  // ask任务专用：发布任务的NPC
        
        // 精确定位NPC房间：优先使用 questgiver_npc（ask任务发布者）
        $targetNpcId = '';
        $targetNpcRoom = '';
        $targetNpcName = '';
        
        // 方法1：ask任务使用 questgiver_npc（发布任务的NPC房间 → 领奖处）
        if (!empty($questgiverNpc) && isset($npcMap[$questgiverNpc])) {
            $targetNpcId = $questgiverNpc;
            $npcInfo = $npcMap[$questgiverNpc];
            $targetNpcName = $npcInfo['name'] ?? '';
            $npcData = Database::queryOne(
                "SELECT spawn_room FROM npcs WHERE npc_id = ? AND spawn_area = 'kaifeng' LIMIT 1",
                [$questgiverNpc]
            );
            $targetNpcRoom = $npcData['spawn_room'] ?? $npcInfo['room'] ?? '';
        }
        // 方法2：fallback 使用 npc_id（兼容旧任务记录）
        elseif (!empty($savedNpcId) && isset($npcMap[$savedNpcId])) {
            $targetNpcId = $savedNpcId;
            $npcInfo = $npcMap[$savedNpcId];
            $targetNpcName = $npcInfo['name'] ?? '';
            $npcData = Database::queryOne(
                "SELECT spawn_room FROM npcs WHERE npc_id = ? AND spawn_area = 'kaifeng' LIMIT 1",
                [$savedNpcId]
            );
            $targetNpcRoom = $npcData['spawn_room'] ?? $npcInfo['room'] ?? '';
        }
        // 方法3：如果npc_id为空，按quest_type匹配
        else {
            foreach ($npcMap as $npcId => $npcInfo) {
                if (($npcInfo['quest_type'] ?? '') === $questType) {
                    $targetNpcId = $npcId;
                    $targetNpcName = $npcInfo['name'] ?? '';
                    $npcData = Database::queryOne(
                        "SELECT spawn_room FROM npcs WHERE npc_id = ? AND spawn_area = 'kaifeng' LIMIT 1",
                        [$npcId]
                    );
                    $targetNpcRoom = $npcData['spawn_room'] ?? $npcInfo['room'] ?? '';
                    break;
                }
            }
        }
        
        // 归一化房间路径：去除 /d/ 前缀
        $targetNpcRoom = preg_replace('#^/d/#', '', $targetNpcRoom);
        $normalizedFullRoom = self::normalizeRoomPath($fullRoom);
        $normalizedTargetNpcRoom = self::normalizeRoomPath($targetNpcRoom);
        
        // 如果不在对应NPC房间，导航过去
        if (!empty($normalizedTargetNpcRoom) && $normalizedFullRoom !== $normalizedTargetNpcRoom) {
            $oldRoom = $char['current_room'] ?? '';
            self::moveCharacter($charId, 'kaifeng', $targetNpcRoom, $char['name'] ?? '', $oldRoom);
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            $msg = HTML_HIYEL . "你赶回{$targetNpcName}处领赏..." . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
            
            return [
                'success' => true,
                'message' => "导航到领奖NPC",
                'action' => 'kaifeng_claim',
                'ai_detail' => "前往{$targetNpcRoom}({$targetNpcName})领奖"
            ];
        }
        
        // 在NPC房间，执行领奖
        $claimResult = QuestHelper::claimQuestReward($charId, $questId, $targetNpcId);
        
        if ($claimResult['success']) {
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            $questName = $claimResult['quest_name'] ?? '';
            $msg = HTML_HIGRN . "领赏成功！完成「{$questName}」" . HTML_NOR;
            if ($claimResult['daoxing'] > 0) $msg .= HTML_HIWHT . " 道行+{$claimResult['daoxing']}" . HTML_NOR;
            if ($claimResult['potential'] > 0) $msg .= HTML_HIWHT . " 潜能+{$claimResult['potential']}" . HTML_NOR;
            if ($claimResult['silver'] > 0) $msg .= HTML_HIWHT . " 白银+{$claimResult['silver']}" . HTML_NOR;
            if ($claimResult['moral'] > 0) $msg .= HTML_HIWHT . " 品德+{$claimResult['moral']}" . HTML_NOR;
            if (!empty($claimResult['cloud_message'])) {
                $msg .= "\n" . $claimResult['cloud_message'];
            }
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
            
            // 更新角色缓存中的 quest_reward
            $char['quest_reward'] = ($char['quest_reward'] ?? 0) + ($claimResult['moral'] ?? 0);
            
            return [
                'success' => true,
                'message' => "领奖成功",
                'action' => 'kaifeng_claim',
                'ai_detail' => "领奖: {$questName} 道行+{$claimResult['daoxing']} 潜能+{$claimResult['potential']} 品德+{$claimResult['moral']}"
            ];
        }
        
        // 领奖失败，可能是状态不对（任务已过期等），尝试导航接新任务
        return [
            'success' => false,
            'message' => '领奖失败',
            'action' => 'kaifeng_claim',
            'ai_detail' => '领奖失败: ' . ($claimResult['message'] ?? '未知错误')
        ];
    }
    
    /**
     * 赴京请赏（在皇宫消耗累计品德值）
     */
    private static function doKaifengBeijing(int $charId, array $char): array {
        require_once __DIR__ . '/../helpers/QuestHelper.php';
        
        $questReward = intval($char['quest_reward'] ?? 0);
        if ($questReward < 1) {
            return ['success' => false, 'message' => '没有可领取的赏赐', 'action' => 'kaifeng_beijing'];
        }
        
        $area = $char['current_area'] ?? '';
        $room = $char['current_room'] ?? '';
        $fullRoom = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
        $normalizedFullRoom = self::normalizeRoomPath($fullRoom);

        // 必须在皇宫区域
        if ($area !== 'beijing' && strpos($normalizedFullRoom, 'beijing') === false) {
            // 导航到北京
            $oldRoom = $char['current_room'] ?? '';
            self::moveCharacter($charId, 'beijing', 'beijing/center', $char['name'] ?? '', $oldRoom);
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            $msg = HTML_HIYEL . "你启程赴京请赏..." . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
            
            return [
                'success' => true,
                'message' => '导航到北京',
                'action' => 'kaifeng_beijing_nav',
                'ai_detail' => '赴京请赏: 前往北京皇宫'
            ];
        }
        
        // 在皇宫，执行请赏
        $result = QuestHelper::calculateBeijingReward($charId);
        
        if ($result['success']) {
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            $msg = HTML_HIGRN . "赴京请赏成功！" . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
            
            $rewards = $result['rewards'];
            $rewardType = $rewards['type'] ?? '';
            $rewardAmount = $rewards['amount'] ?? 0;
            $minister = $rewards['minister'] ?? '';
            
            return [
                'success' => true,
                'message' => '赴京请赏',
                'action' => 'kaifeng_beijing',
                'ai_detail' => "赴京请赏: {$minister}赐{$rewardType}+{$rewardAmount}"
            ];
        }
        
        return [
            'success' => false,
            'message' => '请赏失败',
            'action' => 'kaifeng_beijing',
            'ai_detail' => '赴京请赏失败: ' . ($result['message'] ?? '')
        ];
    }
    
    /**
     * 判断角色是否能飞行
     * 条件：道行>=1000，最大法力>=500，当前法力>=200
     * 不能飞行的新手不应该去开封——他们会被困在那里
     */
    private static function canFly(array $char): bool {
        $daoxing = intval($char['daoxing'] ?? 0);
        $mana = intval($char['mana'] ?? 0);
        $maxMana = intval($char['max_mana'] ?? 0);
        // 提高飞行门槛：需要一定道行基础 + 足够法力 + 有实际法力值才能飞行
        // 之前的门槛 (道行1000/法力500) 太低，导致新手AI误入开封
        return $daoxing >= 5000 && $maxMana >= 1000 && $mana >= 300;
    }
    
    /**
     * 尝试执行木筏动作（傲来国西海岸 ↔ 东海之滨）
     * 木筏循环：at_shore(东海) → sailing_away → at_dest(傲来) → sailing_back → at_shore(东海)
     * 周期：65秒
     * 
     * @return array|null 执行结果，或 null 表示木筏状态不对、不需要处理
     */
    private static function tryExecuteMufaAction(int $charId, array $char, string $fullRoom) /*: string|array|null */ {
        // 注意：本方法内部已通过 ActionRouter::handleCustomAction 执行了登船/下船动作
        // 成功时返回字符串 'mufa_ok' 让 tick 跳过 executeAction（动作已执行，角色已移动）
        // 失败时返回 ['action' => 'rest', ...] 让 tick 继续正常决策（不动，不重试）
        require_once __DIR__ . '/../daemons/MufaHandler.php';
        require_once __DIR__ . '/../daemons/ActionRouter.php';

        $mufaHandler = new MufaHandler();
        $mufaState = $mufaHandler->getMufaState();
        $status = $mufaState['status'] ?? 'at_shore';

        if ($fullRoom === 'changan/aolaiws') {
            // 傲来国西海岸：木筏在 at_dest 状态时可以上船
            if ($status === 'at_dest') {
                $result = ActionRouter::handleCustomAction($charId, 'zuo', '');
                self::recordAction($charId, 'mufa_board', $result['success'] ? 'success' : 'failed',
                    "傲来国西海岸上木筏: status={$status}, result=" . ($result['message'] ?? ''));
                if ($result['success'] ?? false) {
                    return 'mufa_ok'; // 角色已移动到 changan/mufa，跳过 executeAction
                }
                // 上船失败（可能已有人在船上），等待
                return ['action' => 'rest', 'target' => null, 'message' => $result['message'] ?? '无法上木筏'];
            }
            // 木筏不在岸边，无法上船，等待
            return ['action' => 'rest', 'target' => null, 'message' => "木筏还在海上..."];
        }

        if ($fullRoom === 'changan/eastseashore') {
            // 东海之滨：木筏在 at_shore 状态时可以上船
            if ($status === 'at_shore') {
                $result = ActionRouter::handleCustomAction($charId, 'zuo', '');
                self::recordAction($charId, 'mufa_board', $result['success'] ? 'success' : 'failed',
                    "东海之滨上木筏: status={$status}, result=" . ($result['message'] ?? ''));
                if ($result['success'] ?? false) {
                    return 'mufa_ok'; // 角色已移动到 changan/mufa，跳过 executeAction
                }
                return ['action' => 'rest', 'target' => null, 'message' => $result['message'] ?? '无法上木筏'];
            }
            return ['action' => 'rest', 'target' => null, 'message' => "木筏不在岸边..."];
        }

        if ($fullRoom === 'changan/mufa') {
            // 在木筏上：等待靠岸（下木筏）
            if ($status === 'at_dest' || $status === 'at_shore') {
                $result = ActionRouter::handleCustomAction($charId, 'xia', '');
                self::recordAction($charId, 'mufa_disembark', $result['success'] ? 'success' : 'failed',
                    "木筏上下木筏: status={$status}, result=" . ($result['message'] ?? ''));
                if ($result['success'] ?? false) {
                    return 'mufa_ok'; // 角色已移动到岸边，跳过 executeAction
                }
                return ['action' => 'rest', 'target' => null, 'message' => $result['message'] ?? '无法下木筏'];
            }
            // 航行中，等待
            return ['action' => 'rest', 'target' => null, 'message' => "木筏航行中，等待靠岸..."];
        }

        return null;
    }

    /**
     * 南海木筏自动处理：南海之滨 ↔ 南海小岛
     * 
     * 简化版木筏（无需状态机，即时传送）：
     * - changan/southseashore（南海之滨）：坐木筏 → nanhai/island（小岛）
     * - nanhai/island（小岛）：坐木筏 → changan/southseashore（南海之滨）
     * 
     * 方向判断：根据导航目标的区域决定是否上木筏，避免在 nanhai 区域内探索时误回对岸
     * 
     * @return string|null 'mufa_ok'（已移动），rest 数组（失败等待），或 null（非相关房间）
     */
    private static function tryExecuteNanhaiMufaAction(int $charId, array $char, string $fullRoom) {
        require_once __DIR__ . '/../daemons/ActionRouter.php';

        // 读取导航目标区域，用于方向判断
        $navRow = Database::queryOne(
            "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = 'ai_nav_target'",
            [$charId]
        );
        $nav = null;
        if ($navRow) {
            $nav = json_decode($navRow['temp_value'] ?? '', true);
        }
        $targetRoom = $nav['room'] ?? '';
        $targetArea = explode('/', $targetRoom)[0] ?? '';

        if ($fullRoom === 'changan/southseashore') {
            // 南海之滨 → 小岛：只有导航目标是 nanhai 区域时才坐木筏
            if ($targetArea === 'nanhai') {
                $result = ActionRouter::handleCustomAction($charId, 'zuo mufa', '');
                self::recordAction($charId, 'nanhai_mufa_board', $result['success'] ? 'success' : 'failed',
                    "南海之滨坐木筏→小岛: result=" . ($result['message'] ?? ''));
                if ($result['success'] ?? false) {
                    return 'mufa_ok'; // 角色已移动到 nanhai/island
                }
                return ['action' => 'rest', 'target' => null, 'message' => $result['message'] ?? '无法坐木筏'];
            }
            // 目标不在 nanhai 区域，不需要坐木筏
            return null;
        }

        if ($fullRoom === 'nanhai/island') {
            // 小岛 → 南海之滨：只有导航目标不在 nanhai 区域时才坐木筏回去
            if ($targetArea !== 'nanhai' && $targetArea !== '') {
                $result = ActionRouter::handleCustomAction($charId, 'zuo mufa', '');
                self::recordAction($charId, 'nanhai_mufa_return', $result['success'] ? 'success' : 'failed',
                    "小岛坐木筏→南海之滨: result=" . ($result['message'] ?? ''));
                if ($result['success'] ?? false) {
                    return 'mufa_ok'; // 角色已移动到 changan/southseashore
                }
                return ['action' => 'rest', 'target' => null, 'message' => $result['message'] ?? '无法坐木筏'];
            }
            // 目标仍在 nanhai 区域，继续在岛内移动
            return null;
        }

        return null;
    }

    /**
     * AI 飞行离开当前区域，前往其他地图
     * 用于打破开封任务闭环，让 AI 能够探索世界
     */
    private static function doFlyAway(int $charId, array $char): array {
        $charName = $char['name'] ?? 'Unknown';
        $currentArea = $char['current_area'] ?? '';
        
        // 检查飞行条件：不能飞 → 步行去长安天监台接灭妖任务
        if (!self::canFly($char)) {
            return self::doWalkToCity($charId, $char);
        }
        
        // 根据当前区域选择飞行目的地，优先飞往长安或月宫
        $destinations = [
            'changan' => 'city_center',
            'moon'    => 'moon_ontop2',
            'stone'   => 'dntg_hgs_entrance',
            'lingtai' => 'lingtai_gate',
            'putuo'   => 'nanhai_gate',
        ];
        
        // 如果当前已经在某个目的地，排除它（area → fly目标名 的映射）
        $areaToDest = [
            'city'   => 'changan',
            'moon'   => 'moon',
            'heaven' => 'sky',
            'dntg'   => 'stone',  // 花果山区域（可能是仙石等孤立房间）→ 排除 stone 目标
        ];
        
        foreach ($areaToDest as $areaKey => $destKey) {
            if ($currentArea === $areaKey) {
                unset($destinations[$destKey]);
            }
        }
        
        // 从剩余目的地中随机选择
        if (empty($destinations)) {
            $destinations = ['changan' => 'city_center'];
        }
        $destName = array_rand($destinations);
        $roomId = $destinations[$destName];
        
        // 查找目标房间
        require_once __DIR__ . '/../models/Room.php';
        $targetRoom = RoomModel::findByAlias($roomId);
        if (!$targetRoom) {
            // 找不到目标房间，步行去长安
            return self::doWalkToCity($charId, $char);
        }
        
        // 计算法力消耗（与 fly.php 相同逻辑）
        require_once __DIR__ . '/../helpers/SkillManager.php';
        $spellsSkill = SkillManager::querySkill($charId, 'spells');
        $manaCost = -(100 - $spellsSkill) / 4 - 40;
        if ($manaCost > 0) $manaCost = 0;
        $manaCost = intval($manaCost);
        
        // 扣除法力
        Database::execute(
            "UPDATE characters SET mana = mana + ? WHERE id = ?",
            [$manaCost, $charId]
        );
        
        // 移动到目的地
        $oldRoom = $char['current_room'] ?? '';
        $destArea = $targetRoom['area'] ?? 'city';
        $destRoomId = $targetRoom['room_id'] ?? 'city/kezhan';
        self::moveCharacter($charId, $destArea, $destRoomId, $charName, $oldRoom);
        
        $destNames = [
            'changan' => '长安城', 'moon' => '昆仑山月宫', 'stone' => '花果山',
            'lingtai' => '灵台方寸山', 'putuo' => '南海普陀山',
        ];
        $destLabel = $destNames[$destName] ?? '远方';
        
        require_once __DIR__ . '/../daemons/MessageDaemon.php';
        $msg = HTML_HIYEL . "你腾云驾雾，飞往{$destLabel}..." . HTML_NOR;
        MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
        
        return [
            'success' => true,
            'message' => "飞行前往{$destLabel}",
            'action' => 'fly_away',
            'ai_detail' => "腾云驾雾飞往{$destLabel} ({$destRoomId})"
        ];
    }
    
    /**
     * 不满足飞行条件时，自动传送到长安天监台接灭妖任务
     */
    private static function doWalkToCity(int $charId, array $char): array {
        $charName = $char['name'] ?? 'Unknown';
        $oldRoom = $char['current_room'] ?? '';

        require_once __DIR__ . '/../daemons/MessageDaemon.php';

        // 直接传送到天监台（不能飞时跳转更可靠）
        self::moveCharacter($charId, 'city', 'city/tianjiantai', $charName, $oldRoom);

        $msg = HTML_HIYEL . "你前往天监台找袁天罡打听妖怪的消息..." . HTML_NOR;
        MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');

        return [
            'success' => true,
            'message' => '传送至长安天监台',
            'action' => 'fly_away',
            'ai_detail' => '传送至长安天监台找袁天罡接灭妖任务'
        ];
    }
    
    // ==================== 买药/回复 ====================
    
    /**
     * 买药或回复
     */
    private static function doHealOrBuy(int $charId, array $char): array {
        $silver = intval($char['silver'] ?? 0);
        $area = $char['current_area'] ?? '';
        $room = $char['current_room'] ?? '';
        
        if (self::isMedicineShop($area, $room)) {
            return self::doBuyMedicine($charId, $char);
        }
        
        if (self::isHealRoom($area, $room)) {
            return self::doRecover($charId, $char);
        }
        
        // 不在药铺/客栈，导航去药铺
        return self::doNavigateToPharmacy($charId, $char);
    }
    
    /**
     * 在药铺买药
     */
    private static function doBuyMedicine(int $charId, array $char): array {
        $area = $char['current_area'] ?? '';
        $room = $char['current_room'] ?? '';
        $fullRoom = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
        $normalizedFullRoom = self::normalizeRoomPath($fullRoom);
        $prefixedRoom = '/d/' . $normalizedFullRoom;
        
        // 获取房间中的商人 NPC
        $npcs = Database::queryAll(
            "SELECT * FROM npcs WHERE (spawn_room = ? OR spawn_room = ? OR spawn_room = ? OR spawn_room LIKE ?) AND merchant = 1 LIMIT 1",
            [$normalizedFullRoom, $fullRoom, $prefixedRoom, "%{$normalizedFullRoom}%"]
        );
        
        if (empty($npcs)) {
            return ['success' => false, 'message' => '药铺没有商人', 'action' => 'buy_med'];
        }
        
        $npc = $npcs[0];
        
        // 获取商店物品
        require_once __DIR__ . '/../models/Shop.php';
        $shopItems = ShopModel::getShopItems($npc['id']);
        
        // 筛选药品类物品
        $medItems = [];
        foreach ($shopItems as $item) {
            $itemId = $item['item_id'] ?? '';
            $itemName = $item['item_name'] ?? '';
            if (preg_match('/药|丹|丸|膏|散|汤|baiyao|dan|wan|gao|san|tang/i', $itemId . $itemName)) {
                $medItems[] = $item;
            }
        }
        
        if (empty($medItems)) {
            return ['success' => false, 'message' => '药铺没有药品', 'action' => 'buy_med'];
        }
        
        // 随机选一种药
        $targetItem = $medItems[array_rand($medItems)];
        $itemName = $targetItem['item_name'] ?? $targetItem['item_id'];
        $itemId = $targetItem['item_id'] ?? '';
        
        // 执行购买（使用 ShopModel::buyItem 替代 cmd_buy，仅影响 AI 玩家）
        try {
            $result = ShopModel::buyItem($charId, intval($npc['id']), $targetItem['item_id'], 1, $targetItem['category'] ?? '');
            
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            if ($result['success']) {
                MessageDaemon::queueMessageToSelf($charId, HTML_HIGRN . "你从{$npc['name']}处购买了{$itemName}。" . HTML_NOR, 'self_event');

                // 自动服用购买的药品以恢复气血
                require_once __DIR__ . '/../daemons/ActionRouter.php';
                $eatResult = ActionRouter::handleCustomAction($charId, 'eat ' . $itemId, '');
                $eatMsg = ($eatResult['success'] ?? false)
                    ? "服下{$itemName}，恢复气血。"
                    : "服用{$itemName}失败。";
                MessageDaemon::queueMessageToSelf($charId, HTML_HICYN . $eatMsg . HTML_NOR, 'self_event');
                self::recordAction($charId, 'eat_med', ($eatResult['success'] ?? false) ? 'success' : 'failed',
                    "服用{$itemName}: " . ($eatResult['message'] ?? ''));
            }
            
            return [
                'success' => $result['success'],
                'message' => '买药并服用',
                'action' => 'buy_med',
                'ai_detail' => "购买并服用{$itemName}"
            ];
        } catch (\Exception $e) {
            error_log("[AI_BUY_MED] Error: " . $e->getMessage());
            return ['success' => false, 'message' => '购买失败', 'action' => 'buy_med'];
        }
    }
    
    // ==================== 学习技能 ====================
    
    /**
     * 在师父处学习技能
     */
    private static function doLearnSkill(int $charId, array $char): array {
        $area = $char['current_area'] ?? '';
        $room = $char['current_room'] ?? '';
        $fullRoom = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
        $normalizedFullRoom = self::normalizeRoomPath($fullRoom);
        
        // ========== 策略1：优先购书自学 ==========
        // 检查是否已有可学习的书籍
        $hasBook = self::hasStudyBook($charId, $char);
        if ($hasBook) {
            return self::doStudyBook($charId, $char);
        }
        
        // 如果不在书店且有一定金钱，尝试去买书
        $silver = intval($char['silver'] ?? 0);
        $copper = intval($char['copper'] ?? 0);
        $totalMoney = $silver * 100 + $copper;
        
        if ($normalizedFullRoom !== 'city/bookstore' && $totalMoney >= 500) {
            // 有足够金钱，导航去书店买书
            return self::doBuyBook($charId, $char);
        }
        
        // 如果钱不够买书，去灭妖赚钱
        if ($normalizedFullRoom !== 'city/bookstore' && $totalMoney < 500) {
            $mieyaoNav = self::checkMieyaoNavigation($char);
            if ($mieyaoNav !== null) {
                return self::executeAction($charId, $char, $mieyaoNav);
            }
            // 没有灭妖任务，去天监台接任务
            if ($normalizedFullRoom !== 'city/tianjiantai') {
                $oldRoom = $char['current_room'] ?? '';
                self::moveCharacter($charId, 'city', 'city/tianjiantai', $char['name'] ?? '', $oldRoom);
                require_once __DIR__ . '/../daemons/MessageDaemon.php';
                $msg = HTML_HIYEL . "囊中羞涩，先去天监台接灭妖任务赚些银两..." . HTML_NOR;
                MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
                return [
                    'success' => true, 'message' => '前往天监台接灭妖任务',
                    'action' => 'learn_skill', 'ai_detail' => '钱不够买书，去天监台接灭妖任务赚钱'
                ];
            }
            // 已经在天监台，接灭妖任务
            return self::doMieyaoTask($charId, $char);
        }
        
        // ========== 策略2：书店买书 ==========
        if ($normalizedFullRoom === 'city/bookstore') {
            return self::doBuyBook($charId, $char);
        }
        
        // ========== 策略3：向门派师父学习（fallback） ==========
        $npcs = Database::queryAll(
            "SELECT * FROM npcs WHERE spawn_room = ? OR spawn_room = ?",
            [$fullRoom, $room]
        );
        
        require_once __DIR__ . '/../helpers/SectHelper.php';
        $masterNpc = null;
        $sectInfo = null;
        
        foreach ($npcs as $npc) {
            $sect = SectHelper::getSectByNpcId($npc['id']);
            if ($sect) {
                $masterNpc = $npc;
                $sectInfo = $sect;
                break;
            }
        }
        
        if (!$masterNpc) {
            return self::doMove($charId, $char);
        }
        
        $playerFamily = $char['family'] ?? '';
        if (empty($playerFamily)) {
            return self::doMove($charId, $char);
        }
        
        if ($playerFamily !== ($sectInfo['key'] ?? '')) {
            return self::doMove($charId, $char);
        }
        
        $sectSkills = SectHelper::getSectSkills($playerFamily);
        $allSkills = array_merge(
            $sectSkills['exclusive'] ?? [],
            $sectSkills['important'] ?? []
        );
        
        if (file_exists(__DIR__ . '/../config/sects.php')) {
            $sectsConfig = require __DIR__ . '/../config/sects.php';
            $commonSkills = $sectsConfig['common_skills'] ?? [];
            foreach ($commonSkills as $skillId => $skillInfo) {
                $skillName = is_array($skillInfo) ? ($skillInfo['name'] ?? $skillId) : $skillInfo;
                $allSkills[$skillId] = $skillName;
            }
        }
        
        if (empty($allSkills)) {
            return ['success' => false, 'message' => '没有可学习的技能', 'action' => 'learn_skill'];
        }
        
        $skillIds = array_keys($allSkills);
        $chosenSkillId = $skillIds[array_rand($skillIds)];
        $chosenSkillName = $allSkills[$chosenSkillId];
        
        require_once __DIR__ . '/../helpers/SkillManager.php';
        $currentLevel = SkillManager::querySkill($charId, $chosenSkillId, true);
        
        $potential = intval($char['potential'] ?? 0);
        if ($potential < 10) {
            return ['success' => false, 'message' => '潜能不足', 'action' => 'learn_skill'];
        }
        
        $cost = max(1, intval(($currentLevel + 1) / 2) + 1);
        if ($potential < $cost) {
            return ['success' => false, 'message' => '潜能不足', 'action' => 'learn_skill'];
        }
        
        Database::execute(
            "UPDATE characters SET potential = potential - ? WHERE id = ?",
            [$cost, $charId]
        );
        
        $gain = max(1, intval($currentLevel / 5) + intval(($char['int'] ?? 10) / 4));
        
        $existing = Database::queryOne(
            "SELECT level, exp FROM character_skills WHERE char_id = ? AND skill_id = ? LIMIT 1",
            [$charId, $chosenSkillId]
        );
        if (!$existing) {
            Database::execute(
                "INSERT INTO character_skills (char_id, skill_id, level, exp) VALUES (?, ?, 1, 0)",
                [$charId, $chosenSkillId]
            );
        }
        
        Database::execute(
            "UPDATE character_skills SET exp = exp + ? WHERE char_id = ? AND skill_id = ?",
            [$gain, $charId, $chosenSkillId]
        );
        
        $levelUp = self::tryLevelUpSkill($charId, $chosenSkillId);
        
        require_once __DIR__ . '/../daemons/MessageDaemon.php';
        $msg = HTML_HICYN . "你向{$masterNpc['name']}请教{$chosenSkillName}。" . HTML_NOR;
        if ($levelUp) {
            $msg .= HTML_HIGRN . "\n你的{$chosenSkillName}升级了！" . HTML_NOR;
        }
        MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
        
        return [
            'success' => true,
            'message' => "学习{$chosenSkillName}",
            'action' => 'learn_skill',
            'ai_detail' => "向{$masterNpc['name']}学习{$chosenSkillName} 潜能-{$cost} 经验+{$gain}" . ($levelUp ? ' 升级!' : '')
        ];
    }
    
    /**
     * 检查背包中是否有可学习的书籍
     */
    private static function hasStudyBook(int $charId, array $char): bool {
        $inventory = CharacterModel::getInventory($charId);
        foreach ($inventory as $item) {
            $itemId = $item['item_id'] ?? '';
            if (empty($itemId)) continue;
            $bookSkill = Database::queryOne(
                "SELECT * FROM book_skills WHERE item_id = ?", [$itemId]
            );
            if (!$bookSkill) continue;
            
            $skillId = $bookSkill['skill_id'];
            $maxSkill = intval($bookSkill['max_skill'] ?? 100);
            $minSkill = intval($bookSkill['min_skill'] ?? 0);
            
            require_once __DIR__ . '/../helpers/SkillManager.php';
            $currentLevel = SkillManager::querySkill($charId, $skillId, true);
            
            // 技能等级在范围内，可以学习
            if ($currentLevel >= $minSkill && $currentLevel < $maxSkill) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * AI 用书籍自学技能
     */
    private static function doStudyBook(int $charId, array $char): array {
        require_once __DIR__ . '/../helpers/SkillManager.php';
        
        $sen = intval($char['sen'] ?? 0);
        if ($sen < 20) {
            // 精力不够，休息一下
            return self::doRest($charId, $char);
        }
        
        $inventory = CharacterModel::getInventory($charId);
        $studyable = [];
        
        foreach ($inventory as $item) {
            $itemId = $item['item_id'] ?? '';
            if (empty($itemId)) continue;
            $bookSkill = Database::queryOne(
                "SELECT * FROM book_skills WHERE item_id = ?", [$itemId]
            );
            if (!$bookSkill) continue;
            
            $skillId = $bookSkill['skill_id'];
            $maxSkill = intval($bookSkill['max_skill'] ?? 100);
            $minSkill = intval($bookSkill['min_skill'] ?? 0);
            
            $currentLevel = SkillManager::querySkill($charId, $skillId, true);
            if ($currentLevel >= $minSkill && $currentLevel < $maxSkill) {
                $studyable[] = ['item' => $item, 'book_skill' => $bookSkill];
            }
        }
        
        if (empty($studyable)) {
            // 没有可学的书，尝试买新书
            return self::doBuyBook($charId, $char);
        }
        
        // 随机选一本书学习
        $chosen = $studyable[array_rand($studyable)];
        $item = $chosen['item'];
        $bookSkill = $chosen['book_skill'];
        $itemName = $item['name'] ?? $item['item_id'];
        $skillId = $bookSkill['skill_id'];
        $skillName = SkillManager::getSkillChineseName($skillId);
        
        // 计算精力消耗
        $senCost = intval($bookSkill['sen_cost'] ?? 25) + max(0, 35 - intval($char['int'] ?? 10));
        if ($senCost < 10) $senCost = 10;
        
        if ($sen < $senCost) {
            return self::doRest($charId, $char);
        }
        
        // 扣除精力
        Database::execute("UPDATE characters SET sen = sen - ? WHERE id = ?", [$senCost, $charId]);
        
        // 增加技能经验
        $currentLevel = SkillManager::querySkill($charId, $skillId, true);
        $gain = max(1, intval($currentLevel / 5) + intval(($char['int'] ?? 10) / 4));
        
        $existing = Database::queryOne(
            "SELECT level, exp FROM character_skills WHERE char_id = ? AND skill_id = ? LIMIT 1",
            [$charId, $skillId]
        );
        if (!$existing) {
            Database::execute(
                "INSERT INTO character_skills (char_id, skill_id, level, exp) VALUES (?, ?, 1, 0)",
                [$charId, $skillId]
            );
        }
        
        Database::execute(
            "UPDATE character_skills SET exp = exp + ? WHERE char_id = ? AND skill_id = ?",
            [$gain, $charId, $skillId]
        );
        
        $levelUp = self::tryLevelUpSkill($charId, $skillId);
        
        require_once __DIR__ . '/../daemons/MessageDaemon.php';
        $msg = HTML_HICYN . "你仔细研读{$itemName}，领悟{$skillName}。" . HTML_NOR;
        if ($levelUp) {
            $msg .= HTML_HIGRN . "\n你的{$skillName}升级了！" . HTML_NOR;
        }
        MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
        
        return [
            'success' => true,
            'message' => "研读{$itemName}学习{$skillName}",
            'action' => 'learn_skill',
            'ai_detail' => "研读{$itemName}学习{$skillName} 精力-{$senCost} 经验+{$gain}" . ($levelUp ? ' 升级!' : '')
        ];
    }
    
    /**
     * AI 前往书店购买技能书
     * 钱不够时先去灭妖赚钱
     */
    private static function doBuyBook(int $charId, array $char): array {
        $area = $char['current_area'] ?? '';
        $room = $char['current_room'] ?? '';
        $fullRoom = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
        $normalizedFullRoom = self::normalizeRoomPath($fullRoom);
        
        // 不在书店，导航过去
        if ($normalizedFullRoom !== 'city/bookstore') {
            $silver = intval($char['silver'] ?? 0);
            $copper = intval($char['copper'] ?? 0);
            $totalMoney = $silver * 100 + $copper;
            
            // 钱不够，先去灭妖赚钱
            if ($totalMoney < 500) {
                // 检查是否有灭妖任务
                $mieyaoNav = self::checkMieyaoNavigation($char);
                if ($mieyaoNav !== null) {
                    return self::executeAction($charId, $char, $mieyaoNav);
                }
                // 去天监台接灭妖任务
                if ($normalizedFullRoom !== 'city/tianjiantai') {
                    $oldRoom = $char['current_room'] ?? '';
                    self::moveCharacter($charId, 'city', 'city/tianjiantai', $char['name'] ?? '', $oldRoom);
                    require_once __DIR__ . '/../daemons/MessageDaemon.php';
                    $msg = HTML_HIYEL . "囊中羞涩，先去天监台接灭妖任务赚些银两买书..." . HTML_NOR;
                    MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
                    return [
                        'success' => true, 'message' => '前往天监台接灭妖任务',
                        'action' => 'buy_book', 'ai_detail' => '钱不够，先去天监台接灭妖任务'
                    ];
                }
                return self::doMieyaoTask($charId, $char);
            }
            
            // 有钱，导航到书店
            $oldRoom = $char['current_room'] ?? '';
            self::moveCharacter($charId, 'city', 'city/bookstore', $char['name'] ?? '', $oldRoom);
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            $msg = HTML_HIYEL . "你前往三联书店，打算买几本武学秘籍..." . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
            return [
                'success' => true, 'message' => '前往书店',
                'action' => 'buy_book', 'ai_detail' => '前往长安三联书店购买技能书'
            ];
        }
        
        // 已在书店，执行购买
        require_once __DIR__ . '/../models/Shop.php';
        require_once __DIR__ . '/../helpers/SkillManager.php';

        $bookStores = ShopModel::findShopNpcsByType('bookstore', 'city');
        if (empty($bookStores)) {
            // 当前房间不是标准书店，或未找到书店NPC，离开
            return self::doMove($charId, $char);
        }

        $storeNpc = $bookStores[0];
        $storeRoom = preg_replace('#^/d/#', '', $storeNpc['spawn_room'] ?? '');
        if (strpos($storeRoom, '/') === false && !empty($storeNpc['spawn_area'])) {
            $storeRoom = $storeNpc['spawn_area'] . '/' . $storeRoom;
        }

        if (!empty($storeRoom) && $normalizedFullRoom !== self::normalizeRoomPath($storeRoom)) {
            $oldRoom = $char['current_room'] ?? '';
            self::moveCharacter($charId, $storeNpc['spawn_area'] ?? 'city', $storeRoom, $char['name'] ?? '', $oldRoom);
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            $msg = HTML_HIYEL . "你前往{$storeNpc['name']}的书店，打算买几本秘籍..." . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
            return [
                'success' => true,
                'message' => '前往书店',
                'action' => 'buy_book',
                'ai_detail' => '前往书店购买技能书'
            ];
        }

        $shopItems = ShopModel::getShopItems(intval($storeNpc['id']));
        if (empty($shopItems)) {
            // 书店没货，离开
            return self::doMove($charId, $char);
        }

        // 筛选出有 book_skills 记录的技能书
        $bookItems = [];
        foreach ($shopItems as $shopItem) {
            $itemId = $shopItem['item_id'] ?? '';
            if (empty($itemId)) continue;
            
            $bookSkill = Database::queryOne(
                "SELECT * FROM book_skills WHERE item_id = ?", [$itemId]
            );
            if (!$bookSkill) continue;
            
            $skillId = $bookSkill['skill_id'];
            $maxSkill = intval($bookSkill['max_skill'] ?? 100);
            $currentLevel = SkillManager::querySkill($charId, $skillId, true);
            
            // 技能还没学到上限，值得买
            if ($currentLevel < $maxSkill) {
                $price = intval($shopItem['price'] ?? 1000);
                $bookItems[] = array_merge($shopItem, ['book_skill' => $bookSkill, 'price' => $price]);
            }
        }
        
        if (empty($bookItems)) {
            // 没有需要的书，离开
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            $msg = HTML_HIYEL . "书店里没有你需要的秘籍，你转身离开了。" . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
            return self::doMove($charId, $char);
        }
        
        // 按价格排序，优先买便宜的（性价比高）
        usort($bookItems, function($a, $b) { return $a['price'] - $b['price']; });
        
        // 选一本买得起的
        $silver = intval($char['silver'] ?? 0);
        $copper = intval($char['copper'] ?? 0);
        $totalMoney = $silver * 100 + $copper;
        
        $chosen = null;
        foreach ($bookItems as $book) {
            if ($book['price'] <= $totalMoney) {
                $chosen = $book;
                break;
            }
        }
        
        if (!$chosen) {
            // 钱不够买任何一本，去灭妖
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            $msg = HTML_HIYEL . "银两不够买书，先去灭妖赚些盘缠..." . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
            return self::doWalkToCity($charId, $char);
        }
        
        // 执行购买
        $itemId = $chosen['item_id'];
        $itemName = $chosen['item_name'] ?? $itemId;
        $price = $chosen['price'];
        $skillId = $chosen['book_skill']['skill_id'];
        $skillName = SkillManager::getSkillChineseName($skillId);
        
        $result = ShopModel::buyItem($charId, intval($storeNpc['id']), $itemId, 1, $chosen['category'] ?? 'obj');
        
        require_once __DIR__ . '/../daemons/MessageDaemon.php';
        if ($result['success']) {
            $msg = HTML_HIGRN . "你花了{$price}文铜钱买下了《{$itemName}》，可以用来学习{$skillName}。" . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
            return [
                'success' => true,
                'message' => "购买{$itemName}",
                'action' => 'buy_book',
                'ai_detail' => "在书店购买《{$itemName}》学习{$skillName} 花费{$price}铜钱"
            ];
        } else {
            $msg = HTML_HIYEL . "购买失败：{$result['message']}" . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
            return self::doMove($charId, $char);
        }
    }
    
    // ==================== 任务探索 ====================
    
    /**
     * 通用任务行为（移动到任务相关区域）
     */
    private static function doTask(int $charId, array $char): array {
        $area = $char['current_area'] ?? '';
        
        // 如果在长安城，随机去袁天罡处或移动到开封
        if ($area === 'city') {
            $oldRoom = $char['current_room'] ?? '';
            // 不能飞的玩家不去开封（去了会被赶回来），直接去天监台
            if (!self::canFly($char)) {
                self::moveCharacter($charId, 'city', 'city/tianjiantai', $char['name'] ?? '', $oldRoom);
                return ['success' => true, 'message' => '前往天监台', 'action' => 'task', 'ai_detail' => '前往天监台找袁天罡'];
            }
            // 能飞的玩家：90% 去天监台接灭妖任务，10% 去开封
            // 开封任务有独立导航逻辑，不需要在 doTask 里高概率触发
            if (mt_rand(1, 100) <= 90) {
                self::moveCharacter($charId, 'city', 'city/tianjiantai', $char['name'] ?? '', $oldRoom);
                return ['success' => true, 'message' => '前往天监台', 'action' => 'task', 'ai_detail' => '前往天监台找袁天罡'];
            } else {
                self::moveCharacter($charId, 'kaifeng', 'kaifeng/chengmen', $char['name'] ?? '', $oldRoom);
                return ['success' => true, 'message' => '前往开封府', 'action' => 'task', 'ai_detail' => '前往开封府'];
            }
        }
        
        // 在其他区域：设置导航目标到天监台，逐步走回去（模拟真实玩家行为）
        // ★ 不用 doMove 随机乱走，否则 AI 会成为无头苍蝇
        $targetArea = 'city';
        $targetRoom = 'city/tianjiantai';
        self::setNavigationTarget($charId, $targetArea, $targetRoom, '任务导航: 前往天监台接灭妖');
        
        return [
            'success' => true,
            'message' => '导航前往天监台',
            'action' => 'task',
            'ai_detail' => "设置导航目标: {$targetRoom}"
        ];
    }
    
    // ==================== 聊天 ====================
    
    /**
     * AI 聊天：在房间发送随机消息
     */
    private static function doChat(int $charId, array $char): array {
        $name = $char['name'];
        $messages = [
            "{$name}环顾四周，若有所思。",
            "{$name}轻轻叹了口气。",
            "{$name}微微一笑。",
            "{$name}打了个哈欠。",
            "{$name}四处张望，似乎在寻找什么。",
            "{$name}自言自语道：这江湖真是热闹啊。",
            "{$name}哼着小曲，看起来心情不错。",
            "{$name}站在原地发呆。",
            "{$name}整理了一下行囊。",
            "{$name}活动了一下筋骨。",
        ];
        
        $msg = $messages[array_rand($messages)];
        
        require_once __DIR__ . '/../daemons/MessageDaemon.php';
        MessageDaemon::queueMessageToSelf($charId, HTML_HICYN . $msg . HTML_NOR, 'self_event');
        
        return [
            'success' => true,
            'message' => $msg,
            'action' => 'chat',
            'ai_detail' => '聊天'
        ];
    }
    
    // ==================== 战斗 AI ====================
    
    /**
     * AI 战斗行为
     */
    private static function doCombatAction(int $charId, array $char): array {
        require_once __DIR__ . '/../daemons/CombatDaemon.php';
        $result = CombatDaemon::performAiCombatRound($charId);
        if (!is_array($result)) {
            return ['success' => false, 'message' => 'AI战斗回合失败', 'action' => 'combat'];
        }
        if (empty($result['action'])) {
            $result['action'] = 'combat';
        }
        return $result;
    }
    
    // ==================== 紧急传送 ====================
    
    private static function doEmergencyTp(int $charId, array $char): array {
        $oldRoom = $char['current_room'] ?? '';
        self::moveCharacter($charId, 'city', 'city/kezhan', $char['name'] ?? '', $oldRoom);
        
        require_once __DIR__ . '/../daemons/MessageDaemon.php';
        $msg = HTML_HIYEL . "你被传送回了客栈。" . HTML_NOR;
        MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
        
        return [
            'success' => true,
            'message' => 'AI紧急传送到客栈',
            'action' => 'emergency_tp',
            'ai_detail' => '紧急传送到客栈'
        ];
    }
    
    // ==================== NPC 检测与自动战斗触发 ====================
    
    /**
     * 检查当前房间是否有可攻击的 NPC，并尝试触发战斗
     */
    private static function tryInitiateCombat(int $charId, array $char): ?array {
        $area = $char['current_area'] ?? '';
        $room = $char['current_room'] ?? '';
        $fullRoom = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
        $normalizedFullRoom = self::normalizeRoomPath($fullRoom);
        $prefixedRoom = '/d/' . $normalizedFullRoom;
        
        // 获取当前房间的 NPC
        $npcs = Database::queryAll(
            "SELECT * FROM npcs WHERE (spawn_room = ? OR spawn_room = ? OR spawn_room = ? OR spawn_room LIKE ?) AND (attitude = 'aggressive' OR class = 'yaomo' OR aggressive = 1) LIMIT 5",
            [$normalizedFullRoom, $fullRoom, $prefixedRoom, "%{$normalizedFullRoom}%"]
        );
        
        if (empty($npcs)) return null;
        
        // 30% 概率主动攻击 NPC
        if (mt_rand(1, 100) > 30) return null;
        
        $npc = $npcs[array_rand($npcs)];
        $npcId = intval($npc['id'] ?? 0);
        $npcName = $npc['name'] ?? '未知NPC';
        
        if ($npcId <= 0) return null;
        
        // 检查是否已经在战斗中
        require_once __DIR__ . '/../daemons/CombatDaemon.php';
        if (CombatDaemon::isInCombat($charId)) return null;

        $res = CombatDaemon::startKill($charId, $npcId, 'npc');
        if (!$res['success']) {
            return null;
        }

        return [
            'success' => true,
            'message' => $res['message'] ?? "AI向{$npcName}发起攻击",
            'action' => 'combat_start',
            'ai_detail' => "主动攻击NPC: {$npcName}"
        ];
    }

    /**
     * 检查并尝试由 AI 发起灭妖任务的击杀（如果妖怪在当前房间且属于该角色）
     */
    private static function tryAutoKillMieyao(int $charId, array $char): ?array {
        $area = $char['current_area'] ?? '';
        $room = $char['current_room'] ?? '';
        $fullRoom = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;

        $normalizedFullRoom = self::normalizeRoomPath($fullRoom);
        $prefixedRoom = '/d/' . $normalizedFullRoom;

        // 查询是否有活跃的 mieyao_yaoguai 在此房间且属于该角色
        $yaoguai = Database::queryOne(
            "SELECT * FROM mieyao_yaoguai WHERE owner_id = ? AND is_killed = 0 AND expires_at > NOW() "
             . "AND (room_id = ? OR room_id = ? OR room_id LIKE ?) LIMIT 1",
            [$charId, $normalizedFullRoom, $prefixedRoom, "%{$normalizedFullRoom}%"]
        );
        if (!$yaoguai) return null;

        // 已在房间，检查自身能否战斗
        $currentKee = intval($char['kee'] ?? 0);
        if ($currentKee <= 0) {
            return null;
        }
        if (!empty($char['sleep_state']) && $char['sleep_state'] == 1) {
            return null;
        }
        if (!empty($char['unconscious_state']) && $char['unconscious_state'] == 1) {
            return null;
        }
        if (!empty($char['daze_state']) && $char['daze_state'] == 1) {
            return null;
        }

        require_once __DIR__ . '/../daemons/CombatDaemon.php';
        if (CombatDaemon::isInCombat($charId)) return null;

        $res = CombatDaemon::startKill($charId, intval($yaoguai['id'] ?? 0), 'yaoguai');
        if (!$res['success']) return null;

        require_once __DIR__ . '/../daemons/MessageDaemon.php';
        MessageDaemon::queueMessageToSelf($charId, HTML_HICYN . ($res['message'] ?? '你开始攻击妖怪！') . HTML_NOR, 'combat');

        return [
            'success' => true,
            'message' => $res['message'] ?? '开始击杀妖怪',
            'action' => 'combat_start',
            'ai_detail' => 'AI 发起灭妖击杀'
        ];
    }
    
    // ==================== 灭妖任务导航 ====================
    
    /**
     * 导航到灭妖任务目标区域
     */
    private static function doMieyaoNavigate(int $charId, array $char): array {
        $task = Database::queryOne(
            "SELECT area FROM mieyao_yaoguai WHERE owner_id = ? AND is_killed = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1",
            [$charId]
        );
        if (!$task) {
            return self::doMove($charId, $char);
        }
        
        $targetArea = $task['area'] ?? '';
        $currentArea = $char['current_area'] ?? '';
        
        // 如果不在目标区域，设置逐步导航目标
        if ($targetArea !== $currentArea && !empty($targetArea)) {
            $entryRooms = [
                'city' => 'city/kezhan',
                'kaifeng' => 'kaifeng/chengmen',
                'moon' => 'moon/center',
                'heaven' => 'heaven/yunluotai',
                'death' => 'death/center',
                'dragon' => 'dragon/center',
            ];
            $targetRoom = $entryRooms[$targetArea] ?? $targetArea . '/center';
            self::setNavigationTarget($charId, $targetArea, $targetRoom, '前往灭妖地点');
            
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            $msg = HTML_HIYEL . "你赶往灭妖地点..." . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
            
            return [
                'success' => true,
                'message' => "导航到灭妖区域{$targetArea}",
                'action' => 'mieyao_navigate',
                'ai_detail' => "逐步前往灭妖任务区域: {$targetArea}"
            ];
        }
        
        // 已在目标区域，随机移动探索
        return self::doMove($charId, $char);
    }
    
    /**
     * 在灭妖区域执行战斗搜索
     */
    private static function doMieyaoFight(int $charId, array $char): array {
        $area = $char['current_area'] ?? '';
        $room = $char['current_room'] ?? '';
        $fullRoom = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
        $normalizedFullRoom = self::normalizeRoomPath($fullRoom);
        $prefixedRoom = '/d/' . $normalizedFullRoom;

        // 检查当前房间是否有该角色的灭妖任务妖怪
        $yaoguai = Database::queryOne(
            "SELECT * FROM mieyao_yaoguai WHERE owner_id = ? AND is_killed = 0 AND expires_at > NOW() AND (room_id = ? OR room_id = ? OR room_id LIKE ?) LIMIT 1",
            [$charId, $normalizedFullRoom, $prefixedRoom, "%{$normalizedFullRoom}%"]
        );
        if (!$yaoguai) {
            return self::doMove($charId, $char);
        }

        $yaoguaiId = intval($yaoguai['id'] ?? 0);
        $yaoguaiName = $yaoguai['npc_name'] ?? '妖怪';

        require_once __DIR__ . '/../daemons/CombatDaemon.php';
        if (CombatDaemon::isInCombat($charId)) {
            return ['success' => false, 'message' => '已经在战斗中', 'action' => 'combat', 'ai_detail' => '战斗中无法再次发起灭妖战斗'];
        }

        $res = CombatDaemon::startKill($charId, $yaoguaiId, 'yaoguai');
        if (!$res['success']) {
            return ['success' => false, 'message' => $res['message'] ?? '发起灭妖战斗失败', 'action' => 'combat_start', 'ai_detail' => '发起灭妖战斗失败'];
        }

        return [
            'success' => true,
            'message' => $res['message'],
            'action' => 'combat_start',
            'ai_detail' => "灭妖战斗: {$yaoguaiName}"
        ];
    }
    
    // ==================== 开封任务导航 ====================
    
    /**
     * 导航到开封府
     */
    private static function doKaifengNavigate(int $charId, array $char): array {
        $currentArea = $char['current_area'] ?? '';
        
        if ($currentArea !== 'kaifeng') {
            // 逐步走向开封（不直接传送）
            self::setNavigationTarget($charId, 'kaifeng', 'kaifeng/chengmen', '前往开封府办事');
            $oldRoom = $char['current_room'] ?? '';
            $currentRoom = $char['current_room'] ?? '';

            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            $msg = HTML_HIYEL . "你启程前往开封府..." . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
            
            return [
                'success' => true,
                'message' => '导航到开封府',
                'action' => 'kaifeng_navigate',
                'ai_detail' => '传送到开封府做任务'
            ];
        }
        
        return self::doMove($charId, $char);
    }
    
    /**
     * 在开封但不在任务NPC房间时，导航到具体任务NPC房间
     * 使用数据库实际的 spawn_room 格式 (kaifeng/xxx)
     */
    private static function doKaifengNavigateToQuest(int $charId, array $char): array {
        require_once __DIR__ . '/QuestHelper.php';
        $npcMap = QuestHelper::getNpcMap();
        
        $rooms = [];
        foreach ($npcMap as $npcId => $npcInfo) {
            $room = $npcInfo['room'] ?? '';
            if (empty($room)) continue;
            // room 格式为 /d/kaifeng/xxx，转成 kaifeng/xxx
            $room = preg_replace('#^/d/#', '', $room);
            
            // 验证该NPC确实在数据库中存在
            $exists = Database::queryOne(
                "SELECT id FROM npcs WHERE npc_id = ? AND spawn_area = 'kaifeng' LIMIT 1",
                [$npcId]
            );
            if ($exists) {
                $rooms[] = $room;
            }
        }
        
        // 回退：如果所有NPC都不在数据库中，使用硬编码列表
        if (empty($rooms)) {
            $rooms = ['kaifeng/tianpeng', 'kaifeng/bingqi', 'kaifeng/kuijia',
                      'kaifeng/xianglan', 'kaifeng/yulan', 'kaifeng/cuilan',
                      'kaifeng/pudu', 'kaifeng/jixian', 'kaifeng/ee'];
        }
        
        $targetRoom = $rooms[array_rand($rooms)];
        $oldRoom = $char['current_room'] ?? '';
        // 设置导航目标，让 processNavigationTarget 每 tick 走一步直到到达
        self::setNavigationTarget($charId, 'kaifeng', $targetRoom, '前往开封府衙接任务');

        require_once __DIR__ . '/../daemons/MessageDaemon.php';
        $msg = HTML_HIYEL . "你前往开封府衙看看有什么任务..." . HTML_NOR;
        MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
        
        // 立即走第一步，避免被 decideAction 抢占导航控制权
        // 后续 tick 的 processNavigationTarget 会继续逐步走向目标
        require_once __DIR__ . '/RoomNavHelper.php';
        require_once __DIR__ . '/../commands/go.php';
        $path = RoomNavHelper::findPath($oldRoom, $targetRoom);
        if (!empty($path)) {
            $firstDir = $path[0];
            $goResult = cmd_go($charId, $char, $firstDir);
            $dirNames = ['north' => '北', 'south' => '南', 'east' => '东', 'west' => '西',
                'northwest' => '西北', 'northeast' => '东北', 'southwest' => '西南', 'southeast' => '东南',
                'up' => '上', 'down' => '下', 'out' => '出去', 'in' => '进入'];
            $dirName = $dirNames[$firstDir] ?? $firstDir;
            return ['success' => true, 'message' => "向{$dirName}走去。", 'action' => 'move', 'ai_detail' => "导航第一步: {$firstDir}"];
        }
        // 找不到路，直接停留在原地
        return ['success' => true, 'message' => '无法前往开封府衙', 'action' => 'stay', 'ai_detail' => '找不到路径'];
    }
    
    // ==================== 辅助方法 ====================
    
    /**
     * 尝试提升技能等级
     */
    private static function tryLevelUpSkill(int $charId, string $skillId): bool {
        $skill = Database::queryOne(
            "SELECT level, exp FROM character_skills WHERE char_id = ? AND skill_id = ?",
            [$charId, $skillId]
        );
        if (!$skill) return false;
        
        $level = intval($skill['level']);
        $exp = intval($skill['exp']);
        
        // 升级所需经验：level^2 * 10
        $requiredExp = $level * $level * 10;
        
        if ($exp >= $requiredExp) {
            Database::execute(
                "UPDATE character_skills SET level = level + 1, exp = 0 WHERE char_id = ? AND skill_id = ?",
                [$charId, $skillId]
            );
            return true;
        }
        
        return false;
    }
    
    /**
     * 检查是否在药铺
     */
    private static function isMedicineShop(string $area, string $room): bool {
        $fullRoom = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
        $normalized = self::normalizeRoomPath($fullRoom);
        return preg_match('#yaopu|药店|药铺|药房|yaodian|medicine#i', $normalized) > 0;
    }
    
    /**
     * 检查是否是恢复房间（客栈等）
     */
    private static function isHealRoom(string $area, string $room): bool {
        $fullRoom = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
        $normalized = self::normalizeRoomPath($fullRoom);
        return preg_match('#kezhan|客栈|sleep|rest|休息#i', $normalized) > 0;
    }
    
    /**
     * 检查是否在长安城
     */
    private static function isInCity(string $area, string $room): bool {
        $fullRoom = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
        $normalized = self::normalizeRoomPath($fullRoom);
        return $area === 'city' || strpos($normalized, 'city/') !== false || $normalized === 'city';
    }
    
    /**
     * 检查是否是门派师父房间
     */
    private static function isMasterRoom(string $area, string $room): bool {
        $fullRoom = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
        
        // 门派师父常驻房间列表
        $masterRooms = [
            'city/center', 'city/tiantai', 'city/kezhan',
            'kaifeng/chengmen', 'kaifeng/tianpeng',
            'moon/center', 'moon/guanghan',
            'heaven/yunluotai', 'heaven/lingxiao',
            'death/center', 'death/yanluo',
        ];
        
        $normalized = self::normalizeRoomPath($fullRoom);
        foreach ($masterRooms as $mr) {
            if (strpos($normalized, $mr) !== false) return true;
        }
        
        return false;
    }
    
    /**
     * 检查是否是练功房
     */
    private static function isTrainingRoom(string $area, string $room): bool {
        $fullRoom = (strpos($room, '/') !== false) ? $room : $area . '/' . $room;
        $normalized = self::normalizeRoomPath($fullRoom);
        return preg_match('#lian|train|practice|练功|修炼|武场#i', $normalized) > 0;
    }
    
    // ==================== 状态管理 ====================
    
    private static function updateLastAction(int $charId): void {
        Database::execute(
            "UPDATE characters SET ai_last_action = ? WHERE id = ?",
            [time(), $charId]
        );
    }
    
    private static function logAiAction(int $charId, string $charName, string $action, array $result): void {
        $detail = $result['ai_detail'] ?? $result['message'] ?? '';
        $success = $result['success'] ? 1 : 0;
        $successStr = $success ? 'SUCCESS' : 'FAIL';
        error_log("[AI_PLAYER] {$successStr} | {$charName}(ID:{$charId}) | 行为:{$action} | {$detail}");
        if (self::$debug) {
            error_log('[AI_PLAYER DEBUG] ' . json_encode($result, JSON_UNESCAPED_UNICODE));
        }

        // 写入 ai_player_logs 表（用 try/catch 避免日志写入失败影响 AI tick）
        try {
            Database::execute(
                "INSERT INTO ai_player_logs (char_id, char_name, action_type, action_detail, success) VALUES (?, ?, ?, ?, ?)",
                [$charId, $charName, $action, mb_substr($detail, 0, 255), $success]
            );
        } catch (\Exception $e) {
            error_log("[AI_PLAYER] 日志写入DB失败: " . $e->getMessage());
        }
    }
    
    // ==================== 调度方法 ====================
    
    /**
     * 随机选择一个在线 AI 玩家执行一次 tick
     */
    public static function tickRandomOne(): array {
        $aiPlayerIds = self::getAiPlayerIds();
        if (empty($aiPlayerIds)) {
            return ['success' => false, 'message' => '无在线AI玩家'];
        }
        
        $charId = $aiPlayerIds[array_rand($aiPlayerIds)];
        return self::tick(intval($charId));
    }
    
    /**
     * 批量执行所有 AI 玩家的 tick
     */
    public static function tickAll(): array {
        $aiPlayers = self::getAiPlayers();
        $results = [];
        
        foreach ($aiPlayers as $aiPlayer) {
            $charId = intval($aiPlayer['id']);
            try {
                $result = self::tick($charId);
                $results[] = [
                    'char_id' => $charId,
                    'char_name' => $aiPlayer['name'],
                    'success' => $result['success'],
                    'action' => $result['action'] ?? 'unknown',
                    'message' => $result['ai_detail'] ?? $result['message'] ?? '',
                ];
            } catch (\Exception $e) {
                error_log("[AI_PLAYER] ERROR | {$aiPlayer['name']}(ID:{$charId}) | {$e->getMessage()}");
                $results[] = [
                    'char_id' => $charId,
                    'char_name' => $aiPlayer['name'],
                    'success' => false,
                    'action' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }
        
        return [
            'total' => count($aiPlayers),
            'processed' => count($results),
            'results' => $results,
        ];
    }
    
    // ==================== 登录/登出 ====================
    
    public static function loginAiPlayer(int $charId): bool {
        $char = CharacterModel::find($charId);
        if (!$char) return false;
        
        Database::execute(
            "UPDATE characters SET online = 1, is_ai_player = 1, ai_last_action = ? WHERE id = ?",
            [time(), $charId]
        );
        
        $area = $char['current_area'] ?? 'city';
        $room = $char['current_room'] ?? 'city/kezhan';
        if (empty($area) || empty($room)) {
            CharacterModel::updatePosition($charId, 'city', 'city/kezhan');
        }
        
        error_log("[AI_PLAYER] LOGIN | {$char['name']}(ID:{$charId})");
        return true;
    }
    
    public static function logoutAiPlayer(int $charId): bool {
        Database::execute(
            "UPDATE characters SET online = 0 WHERE id = ?",
            [$charId]
        );
        
        $char = CharacterModel::find($charId);
        error_log("[AI_PLAYER] LOGOUT | " . ($char['name'] ?? "ID:{$charId}"));
        return true;
    }
    
    /**
     * 创建一个 AI 玩家角色
     */
    public static function createAiCharacter(string $name, string $gender = 'male', string $race = 'human'): int|false {
        $exists = CharacterModel::findByName($name);
        if ($exists) {
            return false;
        }
        
        // 生成自增的 aiplayer 账号名：aiplayer1, aiplayer2, ...
        $maxUser = Database::queryOne("SELECT MAX(CAST(SUBSTRING(username, 9) AS UNSIGNED)) AS max_num FROM users WHERE username REGEXP '^aiplayer[0-9]+$'");
        $nextNum = intval($maxUser['max_num'] ?? 0) + 1;
        $username = 'aiplayer' . $nextNum;
        
        // 创建新用户，密码统一为 123456
        Database::execute(
            "INSERT INTO users (username, password, status, vip_level, wizard_level) VALUES (?, ?, 1, 0, 0)",
            [$username, password_hash('123456', PASSWORD_DEFAULT)]
        );
        $userId = intval(Database::lastInsertId());
        
        $charId = CharacterModel::create([
            'user_id' => $userId,
            'name' => $name,
            'gender' => $gender,
            'race' => $race,
        ]);
        
        if ($charId > 0) {
            Database::execute(
                "UPDATE characters SET is_ai_player = 1, ai_last_action = ? WHERE id = ?",
                [time(), $charId]
            );
        }
        
        return $charId;
    }
}
