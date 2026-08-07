<?php
/**
 * 战斗守护进程
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 负责：
 * - 战斗状态管理
 * - 伤害计算
 * - 战斗消息生成
 */
require_once HELPER_PATH . 'AttributeHelper.php';
require_once HELPER_PATH . 'ExpHelper.php';
require_once HELPER_PATH . 'QuestHelper.php';
require_once HELPER_PATH . 'CombatMessages.php';
require_once HELPER_PATH . 'CombatSystemHelper.php';
require_once HELPER_PATH . 'SpellHelper.php';
require_once HELPER_PATH . 'WeaponHelper.php';
require_once HELPER_PATH . 'ArmorHelper.php';
require_once HELPER_PATH . 'FabaoHelper.php';
require_once HELPER_PATH . 'SkillManager.php';
require_once HELPER_PATH . 'NpcAiHelper.php';
require_once MODEL_PATH . 'Npc.php';
require_once MODEL_PATH . 'Corpse.php';
require_once MODEL_PATH . 'NpcRespawn.php';

class CombatDaemon {

    /** @var array|null 战斗配置缓存 */
    private static ?array $combatConfig = null;

    /**
     * 加载战斗配置
     */
    private static function loadConfig(): array {
        if (self::$combatConfig !== null) {
            return self::$combatConfig;
        }
        self::$combatConfig = require __DIR__ . '/../config/combat.php';
        return self::$combatConfig;
    }

    /**
     * 肢体列表
     */
    private static array $limbs = ['头部', '颈部', '胸口', '腹部', '腰部', '手臂', '腿部', '肩膀'];
    
    /**
     * 开始战斗（kill模式）
     * 参考: feature/Attack.php::killOb()
     */
    public static function startKill(int $attackerId, mixed $targetId, string $targetType = 'npc', ?string $initiatorName = null, array $multiTargets = [], ?string $combatSystem = null, ?string $rankLevel = null): array {
        $attacker = CharacterModel::find($attackerId);
        if (!$attacker) {
            return ['success' => false, 'message' => '攻击者不存在'];
        }
        
        // 检查是否在战斗中
        if (self::isInCombat($attackerId)) {
            return ['success' => false, 'message' => '你已经在战斗中！'];
        }
        
        // 检查当前房间是否禁止战斗
        // 注意：current_room 已经是完整路径（如 city/center），不需要再拼接
        $roomId = $attacker['current_room'];
        $room = RoomModel::load($attacker['current_area'], $attacker['current_room']);
        if ($room && isset($room['no_fight']) && $room['no_fight']) {
            return ['success' => false, 'message' => HTML_HIRED . '这里禁止打斗！' . HTML_NOR];
        }

        // 检查攻击者自身状态
        if (!empty($attacker['sleep_state']) && $attacker['sleep_state'] == 1) {
            return ['success' => false, 'message' => HTML_HIRED . '你正在睡梦中，无法战斗！' . HTML_NOR];
        }
        if (!empty($attacker['unconscious_state']) && $attacker['unconscious_state'] == 1) {
            return ['success' => false, 'message' => HTML_HIRED . '你已经昏迷，无法战斗！' . HTML_NOR];
        }
        if (!empty($attacker['daze_state']) && $attacker['daze_state'] == 1) {
            return ['success' => false, 'message' => HTML_HIRED . '你正在发呆，无法战斗！' . HTML_NOR];
        }
        $currentKee = intval($attacker['kee'] ?? 0);
        if ($currentKee <= 0) {
            return ['success' => false, 'message' => HTML_HIRED . '你已经重伤，无法战斗！' . HTML_NOR];
        }
        
        // 获取目标信息
        $targetName = '';
        $targetCombatExp = 0;
        $targetId = intval($targetId);  // 确保是整数
        if ($targetType === 'npc') {
            // 使用主键id查询，而不是npc_id字段
            $sql = "SELECT id, npc_id, name, combat_exp, max_kee FROM npcs WHERE id = ? LIMIT 1";
            $npc = Database::queryOne($sql, [$targetId]);
            if (!$npc) {
                return ['success' => false, 'message' => '目标不存在'];
            }
            $targetName = $npc['name'];
            $targetCombatExp = $npc['combat_exp'] ?? 0;
            
            // ★ 检查NPC是否已被法宝困住（如幌金绳束缚），被困的NPC无法战斗
            if (FabaoHelper::isTrapped($targetId)) {
                return ['success' => false, 'message' => $targetName . '已经被法宝困住，无法战斗。'];
            }

            // === 特殊处理：蒸笼老人无敌 ===
            // 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
            if ($npc['npc_id'] === 'zhenglonglaoren') {
                require_once DAEMON_PATH . 'MessageDaemon.php';

                // 设置玩家busy（从配置读取时长）
                $cfg = self::loadConfig();
                $busyTime = $cfg['npc_ai']['zheng_long_busy'];
                set_player_busy($attackerId, $busyTime);

                // 广播老人的反应
                $msg1 = HTML_HICYN . '蒸笼老人说道：在我面前......' . HTML_NOR;
                $msg2 = HTML_HIRED . '蒸笼老人哼了一声！' . HTML_NOR;
                MessageDaemon::broadcastToRoom($roomId, $msg1, $attackerId);
                MessageDaemon::broadcastToRoom($roomId, $msg2, $attackerId);

                // 给玩家的消息
                $playerMsg = $msg1 . "\n" . $msg2 . "\n" . HTML_HIRED . "你被定住了，{$busyTime}秒内无法行动！" . HTML_NOR;

                log_game('TIANMO_INVINCIBLE', "{$attacker['name']} 试图攻击蒸笼老人，被 busy {$busyTime}秒");

                return [
                    'success' => false,
                    'message' => $playerMsg
                ];
            }

            // === 特殊处理：泥娃娃 accept_fight（还原原始 LPC niwawa.c）===
            // 玩家对克隆泥娃娃（npc_id 以 niwawa_ 开头，由 PushStatueHandler 召唤）
            // 发起战斗时，若玩家 combat_exp < 15000，则泥娃娃临时变强为 玩家经验+300，
            // 并说一句「嘻，好啊！」。玩家经验足够则保持基础值 100。
            if (strpos($npc['npc_id'] ?? '', 'niwawa_') === 0) {
                if (intval($attacker['combat_exp'] ?? 0) < 15000) {
                    $boosted = intval($attacker['combat_exp'] ?? 0) + 300;
                    Database::execute('UPDATE npcs SET combat_exp = ? WHERE id = ?', [$boosted, $npc['id']]);
                    $npc['combat_exp'] = $boosted;
                    $targetCombatExp = $boosted;
                }
                require_once DAEMON_PATH . 'MessageDaemon.php';
                MessageDaemon::broadcastToRoom($roomId, HTML_HICYN . $npc['name'] . '说道：嘻，好啊！' . HTML_NOR, $attackerId);
            }

            // NPC最大血量使用 max_kee（还原原始LPC逻辑）
            $maxHp = max(100, intval($npc['max_kee'] ?? 100));
        } elseif ($targetType === 'yaoguai') {
            // 查询灭妖任务妖怪
            $sql = "SELECT id, npc_name, combat_exp, max_kee FROM mieyao_yaoguai WHERE id = ? LIMIT 1";
            $yaoguai = Database::queryOne($sql, [$targetId]);
            if (!$yaoguai) {
                return ['success' => false, 'message' => '妖怪不存在'];
            }
            $targetName = $yaoguai['npc_name'];
            $targetCombatExp = $yaoguai['combat_exp'] ?? 0;
            
            // ★ 检查妖怪是否已被法宝困住
            if (FabaoHelper::isTrapped($targetId)) {
                return ['success' => false, 'message' => $targetName . '已经被法宝困住，无法战斗。'];
            }
            
            $maxHp = $yaoguai['max_kee'] ?? 100;
        } else {
            $target = CharacterModel::find($targetId);
            if (!$target) {
                return ['success' => false, 'message' => '目标不存在'];
            }
            $targetName = $target['name'];
            $maxHp = $target['max_kee'] ?? 100;
            
            $stateCheck = self::checkTargetPlayerState($target);
            if (!$stateCheck['can_fight']) {
                return ['success' => false, 'message' => $stateCheck['message']];
            }
        }
        
        // 写入 active_combats 表（多人共享HP）
        self::insertActiveCombat($attackerId, $targetId, $targetType, $maxHp, false, $combatSystem, $rankLevel);
        
        // 初始化战斗状态
        $combatState = [
            'target_id' => $targetId,
            'target_type' => $targetType,
            'target_name' => $targetName,
            'start_time' => time(),
            'round' => 0,
            'last_npc_attack_time' => time(),  // 服务端驱动：记录上次NPC攻击时间
            'combat_system' => $combatSystem,
            'rank_level' => $rankLevel
        ];
        // 存储多目标（同时攻击的NPC列表）
        if (!empty($multiTargets)) {
            $combatState['multi_targets'] = $multiTargets;
        }
        $_SESSION["combat_{$attackerId}"] = $combatState;

        log_game('COMBAT_START', ($initiatorName ? "{$initiatorName} 开始攻击 {$attacker['name']}" : "{$attacker['name']} 开始攻击 {$targetName}"));
        
        // 广播战斗开始消息到房间（参考原始项目 message_vision）
        // 如果是 NPC 主动发起攻击，显示 NPC 对玩家喝道
        if ($initiatorName) {
            $roomBroadcast = HTML_HIRED . '【战斗】' . HTML_NOR . ' ' . $initiatorName . ' 对着 ' . $attacker['name'] . ' 喝道：「今日不是你死就是我活！」';
        } else {
            $roomBroadcast = HTML_HIRED . '【战斗】' . HTML_NOR . ' ' . $attacker['name'] . ' 对着 ' . $targetName . ' 喝道：「今日不是你死就是我活！」';
        }
        require_once DAEMON_PATH . 'MessageDaemon.php';
        $receivers = MessageDaemon::broadcastToRoom($roomId, $roomBroadcast, $attackerId);
        
        // 同时将广播消息存储到 session，让发起战斗的玩家也能看到（模拟房间视角）
        $_SESSION['fight_start_broadcast'] = [
            'message' => $roomBroadcast,
            'timestamp' => time()
        ];
        
        if ($initiatorName) {
            $playerMessage = HTML_HIRED . '【战斗】' . HTML_NOR . ' ' . HTML_HICYN . $initiatorName . HTML_NOR . ' 对着你喝道：「今日不是你死就是我活！」';
        } else {
            $playerMessage = HTML_HIRED . '【战斗】' . HTML_NOR . ' 你对着 ' . HTML_HICYN . $targetName . HTML_NOR . ' 喝道：「今日不是你死就是我活！」';
        }
        
        return [
            'success' => true,
            'message' => $playerMessage
        ];
    }

    /**
     * AI 执行战斗回合
     */
    public static function performAiCombatRound(int $charId): array {
        $attacker = CharacterModel::find($charId);
        if (!$attacker) {
            return ['success' => false, 'message' => 'AI角色不存在'];
        }

        if (!self::isInCombat($charId)) {
            return ['success' => false, 'message' => '当前不在战斗中'];
        }

        $combat = self::getCombatStatus($charId);
        if (!$combat) {
            return ['success' => false, 'message' => '战斗状态异常'];
        }

        $targetId = intval($combat['target_id'] ?? 0);
        $targetType = $combat['target_type'] ?? 'npc';
        $targetName = $combat['target_name'] ?? '';

        if (empty($targetName)) {
            if ($targetType === 'npc') {
                $npc = Database::queryOne("SELECT name FROM npcs WHERE id = ? LIMIT 1", [$targetId]);
                $targetName = $npc['name'] ?? '未知NPC';
            } elseif ($targetType === 'yaoguai') {
                $yaoguai = Database::queryOne("SELECT npc_name FROM mieyao_yaoguai WHERE id = ? LIMIT 1", [$targetId]);
                $targetName = $yaoguai['npc_name'] ?? '妖怪';
            } else {
                $target = CharacterModel::find($targetId);
                $targetName = $target['name'] ?? '玩家';
            }
        }

        $keePct = ($attacker['max_kee'] > 0) ? ($attacker['kee'] / $attacker['max_kee']) : 1;
        if ($keePct < 0.2 && mt_rand(1, 100) <= 30) {
            self::endCombat($charId);
            require_once DAEMON_PATH . 'MessageDaemon.php';
            $msg = HTML_HIYEL . "你虚晃一招，转身逃走了。" . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
            return [
                'success' => true,
                'message' => 'AI从战斗中逃脱',
                'action' => 'combat_flee',
                'ai_detail' => '战斗中逃跑'
            ];
        }

        $currentHp = self::getTargetCurrentHp($targetId, $targetType);
        $dmg = mt_rand(10, 30);
        $newHp = max(0, $currentHp - $dmg);
        Database::execute(
            "UPDATE active_combats SET target_current_hp = ? WHERE target_id = ? AND target_type = ?",
            [$newHp, $targetId, $targetType]
        );

        require_once DAEMON_PATH . 'MessageDaemon.php';

        if ($newHp <= 0) {
            self::clearAllCombatForTarget($targetId, $targetType);
            self::onTargetDeath($targetId, $targetType, $charId);
            $msg = HTML_HIRED . "你击败了{$targetName}！" . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
            return [
                'success' => true,
                'message' => 'AI击败了对手',
                'action' => 'combat_win',
                'ai_detail' => "击败目标: {$targetName}"
            ];
        }

        $playerDmg = mt_rand(5, 15);
        $newKee = intval($attacker['kee'] ?? 100) - $playerDmg;
        if ($newKee <= 0) {
            $newKee = 0;
            Database::execute("UPDATE characters SET kee = ? WHERE id = ?", [$newKee, $charId]);
            // AI 被打到0血，触发死亡流程（惩罚+鬼魂+地府）
            self::handlePlayerDeath($charId, $combat ?: ['target_id' => $targetId, 'target_type' => $targetType, 'friendly' => false]);
            $msg = HTML_HIRED . "你被{$targetName}杀死了！" . HTML_NOR;
            MessageDaemon::queueMessageToSelf($charId, $msg, 'self_event');
            return [
                'success' => true,
                'message' => 'AI被击杀，进入死亡流程',
                'action' => 'death',
                'ai_detail' => '战斗中被击杀'
            ];
        }

        Database::execute("UPDATE characters SET kee = ? WHERE id = ?", [$newKee, $charId]);

        $msg = HTML_HIRED . "你向{$targetName}发起攻击，造成了{$dmg}点伤害！" . HTML_NOR;
        MessageDaemon::queueMessageToSelf($charId, $msg, 'combat');

        return [
            'success' => true,
            'message' => "AI攻击造成{$dmg}伤害，受到{$playerDmg}反击",
            'action' => 'combat',
            'ai_detail' => "攻击目标: {$targetName} 伤害:{$dmg}"
        ];
    }

    /**
     * AI 战斗目标死亡后处理（仅供 AI 路径使用，不干扰普通玩家路径）
     * @param int $targetId   active_combats.target_id（npc 的 npcs.id，yaoguai 的 mieyao_yaoguai.id）
     * @param string $targetType  'npc' | 'yaoguai' | 'player'
     * @param int $killerId   击杀者 charId，为 0 时跳过 QuestHelper 调用
     */
    private static function onTargetDeath(int $targetId, string $targetType, int $killerId = 0): void {
        // AI 击杀妖怪：调用 MieyaoHandler 统一处理（尸体生成、掉落、奖励）
        // 与普通玩家路径（行1581）保持一致，确保 AI 也能获得完整灭妖收益
        if ($targetType === 'yaoguai') {
            require_once __DIR__ . '/MieyaoHandler.php';
            MieyaoHandler::handleKillYaoguai($targetId, $killerId);
        }

        // AI 击杀 NPC 时，检查并完成开封 kill 任务
        // 注意：此路径与普通玩家的 onNpcDeath 完全隔离，互不影响
        if ($targetType === 'npc' && $killerId > 0) {
            $npc = Database::queryOne(
                "SELECT npc_id, name FROM npcs WHERE id = ? LIMIT 1",
                [$targetId]
            );
            if ($npc && !empty($npc['npc_id'])) {
                require_once HELPER_PATH . 'QuestHelper.php';
                QuestHelper::markQuestDone($killerId, QuestHelper::TYPE_KILL, $npc['npc_id']);
            }
        }
    }
    
    /**
     * 开始友好比试（fight模式）
     * 参考: feature/Attack.php::fight_ob()
     * 
     * 与kill的区别：
     * - 不会获得道行和实战经验
     * - 不会造成真实伤害（只消耗体力）
     * - 不会死亡
     */
    public static function startFight(int $attackerId, mixed $targetId, string $targetType = 'npc', ?string $combatSystem = null, ?string $rankLevel = null): array {

        
        $attacker = CharacterModel::find($attackerId);
        if (!$attacker) {
            return ['success' => false, 'message' => '攻击者不存在'];
        }
        
        // 检查是否在战斗中
        if (self::isInCombat($attackerId)) {
            return ['success' => false, 'message' => '你已经在战斗中！'];
        }
        
        // 检查当前房间是否禁止战斗
        // 注意：current_room 已经是完整路径（如 city/center），不需要再拼接
        $roomId = $attacker['current_room'];
        $room = RoomModel::load($attacker['current_area'], $attacker['current_room']);
        if ($room && isset($room['no_fight']) && $room['no_fight']) {
            return ['success' => false, 'message' => HTML_HIRED . '这里禁止切磋！' . HTML_NOR];
        }
        
        // 清除旧的战斗日志（开始新战斗时）
        unset($_SESSION["combat_log_{$attackerId}"]);
        
        // 获取目标信息
        $targetName = '';
        $maxHp = 0;
        if ($targetType === 'npc') {
            // 确保targetId是整数（这是主键id）
            $targetId = intval($targetId);
            
            // 使用主键id查询，而不是npc_id字段
            $sql = "SELECT id, npc_id, name, combat_exp, max_kee FROM npcs WHERE id = ? LIMIT 1";
            $npc = Database::queryOne($sql, [$targetId]);
            if (!$npc) {
                return ['success' => false, 'message' => '目标不存在'];
            }
            $targetName = $npc['name'];
            
            // ★ 检查NPC是否已被法宝困住（如幌金绳束缚），被困的NPC无法应战
            if (FabaoHelper::isTrapped($targetId)) {
                return ['success' => false, 'message' => $targetName . '已经被法宝困住，无法战斗。'];
            }

            // === 特殊处理：蒸笼老人无敌 ===
            // 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
            if ($npc['npc_id'] === 'zhenglonglaoren') {
                require_once DAEMON_PATH . 'MessageDaemon.php';

                // 设置玩家busy（从配置读取时长）
                $cfg = self::loadConfig();
                $busyTime = $cfg['npc_ai']['zheng_long_busy'];
                set_player_busy($attackerId, $busyTime);

                // 广播老人的反应
                $msg1 = HTML_HICYN . '蒸笼老人说道：在我面前......' . HTML_NOR;
                $msg2 = HTML_HIRED . '蒸笼老人哼了一声！' . HTML_NOR;
                MessageDaemon::broadcastToRoom($roomId, $msg1, $attackerId);
                MessageDaemon::broadcastToRoom($roomId, $msg2, $attackerId);

                // 给玩家的消息
                $playerMsg = $msg1 . "\n" . $msg2 . "\n" . HTML_HIRED . "你被定住了，{$busyTime}秒内无法行动！" . HTML_NOR;

                log_game('TIANMO_INVINCIBLE', "{$attacker['name']} 试图与蒸笼老人切磋，被 busy {$busyTime}秒");

                return [
                    'success' => false,
                    'message' => $playerMsg
                ];
            }

            // === 特殊处理：砍柴道士陪练机制 ===
            // 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
            if ($npc['npc_id'] === 'kancai') {
                $playerExp = intval($attacker['combat_exp'] ?? 0);
                $originalExp = intval($npc['combat_exp'] ?? 0);

                // 如果玩家战斗经验 < 30000，调整NPC的战斗经验为玩家经验 + 500
                if ($playerExp < 30000) {
                    $adjustedExp = $playerExp + 500;

                    // 保存原始经验到session（战斗结束后恢复）
                    $_SESSION["kancai_original_exp_{$targetId}"] = $originalExp;

                    // 修改NPC的战斗经验
                    Database::execute(
                        "UPDATE npcs SET combat_exp = ? WHERE id = ?",
                        [$adjustedExp, $targetId]
                    );

                    log_game('KANCAI_SPARRING', "{$attacker['name']} 与砍柴道士开始陪练，NPC经验从 {$originalExp} 调整为 {$adjustedExp}");
                }
            }

            // NPC最大血量使用 max_kee（还原原始LPC逻辑）
            $maxHp = max(100, intval($npc['max_kee'] ?? 100));
        } else {
            $target = CharacterModel::find($targetId);
            if (!$target) {
                return ['success' => false, 'message' => '目标不存在'];
            }
            $targetName = $target['name'];
            $maxHp = $target['max_kee'] ?? 100;
            
            $stateCheck = self::checkTargetPlayerState($target);
            if (!$stateCheck['can_fight']) {
                return ['success' => false, 'message' => $stateCheck['message']];
            }
        }
        
        // 写入 active_combats 表（切磋模式，多人共享HP）
        self::insertActiveCombat($attackerId, intval($targetId), $targetType, $maxHp, true, $combatSystem, $rankLevel);
        
        // 初始化战斗状态（标记为友好比试）
        $_SESSION["combat_{$attackerId}"] = [
            'target_id' => intval($targetId),
            'target_type' => $targetType,
            'target_name' => $targetName,
            'start_time' => time(),
            'round' => 0,
            'friendly' => true,
            'last_npc_attack_time' => time(),  // 服务端驱动：记录上次NPC攻击时间
            'combat_system' => $combatSystem,
            'rank_level' => $rankLevel
        ];

        log_game('FIGHT_START', "{$attacker['name']} 与 {$targetName} 开始切磋武艺");
                
        // 如果是与NPC切磋，生成NPC的回应消息
        $npcResponse = '';
        if ($targetType === 'npc') {
            require_once __DIR__ . '/../helpers/RankHelper.php';
            $sql = "SELECT * FROM npcs WHERE id = ? LIMIT 1";
            $npcData = Database::queryOne($sql, [$targetId]);
            $npcData = $npcData ? NpcModel::initializeAttributes($npcData) : null;
            if ($npcData) {
                $npcSelfTitle = RankHelper::querySelf($npcData);
                $playerRespect = RankHelper::queryRespect($attacker);
                $npcResponse = ' ' . HTML_HICYN . $targetName . HTML_NOR . '说道：「既然' . $playerRespect . '赐教，' . $npcSelfTitle . '只好奉陪。」';
            }
        }
                
        // 广播战斗开始消息到房间（参考原始项目 message_vision）
        $roomBroadcast = HTML_HIYEL . '【切磋】' . HTML_NOR . ' ' . $attacker['name'] . ' 对着 ' . $targetName . ' 喝道：「领教高招！」';
        if (!empty($npcResponse)) {
            $roomBroadcast .= $npcResponse;
        }
        require_once DAEMON_PATH . 'MessageDaemon.php';
        MessageDaemon::broadcastToRoom($roomId, $roomBroadcast, $attackerId);
        
        // 同时将广播消息存储到 session，让发起战斗的玩家也能看到（模拟房间视角）
        $_SESSION['fight_start_broadcast'] = [
            'message' => $roomBroadcast,
            'timestamp' => time()
        ];
                
        return [
            'success' => true,
            'message' => HTML_HIYEL . '【切磋】' . HTML_NOR . ' 你对着 ' . HTML_HICYN . $targetName . HTML_NOR . ' 喝道：「领教高招！」' . $npcResponse
        ];
    }
    
    /**
     * 执行一次攻击
     * 参考: adm/daemons/Combatd.php::do_attack()
     */
    public static function doAttack(int $attackerId): array {
        // 检查是否在战斗中
        $combat = self::getCombatStatus($attackerId);
        if (!$combat) {
            return ['success' => false, 'message' => '你没有在战斗中！'];
        }
        
        // 提取战斗目标信息，使用默认值防止未定义
        $targetId = intval($combat['target_id'] ?? 0);
        $targetType = $combat['target_type'] ?? 'npc';
        $targetName = $combat['target_name'] ?? '目标';
        
        // === 法宝系统：检查战斗者是否被法宝困住 ===
        if (FabaoHelper::isTrapped($attackerId) || FabaoHelper::isTrapped($targetId)) {
            // 结束这场战斗
            self::endCombat($attackerId);
            if ($targetType === 'player') {
                self::endCombat($targetId);
            }
            return ['success' => false, 'message' => '战斗因法宝介入而中断。'];
        }
        
        $attacker = CharacterModel::find($attackerId);
        if (!$attacker) {
            self::endCombat($attackerId);
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        // === 昏迷检查（参考原始LPC: living() 函数）===
        if (isset($_SESSION["unconscious_{$attackerId}"])) {
            $unconscious = $_SESSION["unconscious_{$attackerId}"];
            $elapsed = time() - $unconscious['timestamp'];
            $duration = $unconscious['duration'] ?? 30;
            
        if ($elapsed < $duration) {
            self::endCombat($attackerId);
            return [
                'success' => false,
                'message' => HTML_HIRED . '你昏迷中，无法战斗！(剩余' . ($duration - $elapsed) . '秒)' . HTML_NOR,
                'skip_queue' => true,
            ];
        } else {
            // 昏迷时间已过，清除状态（SESSION + DB 同步清除，防止其他玩家检查时读到 stale 数据）
            unset($_SESSION["unconscious_{$attackerId}"]);
            Database::execute(
                "UPDATE characters SET unconscious_state = 0, unconscious_end_time = NULL WHERE id = ? AND unconscious_state = 1",
                [$attackerId]
            );
        }
        }
        
        // === 中毒效果处理（参考原始LPC: heart_beat 每回合处理）===
        require_once HELPER_PATH . 'StatusEffectHelper.php';
        $statusMessages = StatusEffectHelper::processRoundEffects($attackerId);
        
        // 如果中毒导致昏迷，结束战斗
        if (isset($_SESSION["unconscious_{$attackerId}"])) {
            self::endCombat($attackerId);
            $poisonMsg = implode("\n", $statusMessages) . "\n" . HTML_HIRED . '你毒发攻心，昏了过去！' . HTML_NOR;
            return [
                'success' => false,
                'message' => $poisonMsg,
                'skip_queue' => true,
            ];
        }
        
        // 杀气自然衰减
        if (($attacker['kee_mark'] ?? 0) > 0) {
            Database::execute("UPDATE characters SET kee_mark = GREATEST(0, kee_mark - 1) WHERE id = ?", [$attacker['id']]);
        }
        
        // 检查气血（中毒可能导致气血为0）
        $currentKee = intval($attacker['kee'] ?? 0);
        if ($currentKee <= 0) {
            self::endCombat($attackerId);
            $poisonMsg = !empty($statusMessages) ? implode("\n", $statusMessages) . "\n" : '';
            return [
                'success' => false, 
                'message' => $poisonMsg . HTML_HIRED . '你已经重伤，无法战斗！' . HTML_NOR,
                'skip_queue' => true,
            ];
        }
        
        // 增加回合数
        $_SESSION["combat_{$attackerId}"]['round']++;
        
        // 初始化玩家受到的伤害累计（用于飘血显示）
        $playerDamage = 0;
        
        // === 勇气检查（还原原始项目 combatd.c 的 guarding 机制）===
        // 如果攻击者被防守方气势所慑，本回合跳过攻击
        $victimCps = 0;
        $victimTable = 'npcs'; // 默认表名，后续代码会用到
        if ($combat['target_type'] === 'npc') {
            $victimRow = Database::queryOne("SELECT cps FROM npcs WHERE id = ? LIMIT 1", [$combat['target_id']]);
            $victimCps = $victimRow ? intval($victimRow['cps'] ?? 10) : 10;
        } elseif ($combat['target_type'] === 'yaoguai') {
            $victimTable = 'mieyao_yaoguai';
            // mieyao_yaoguai 表没有 cps 字段，使用默认值
            $victimCps = 10;
        } else {
            $victimRow = CharacterModel::find($combat['target_id']);
            $victimCps = $victimRow ? AttributeHelper::queryCps($victimRow) : 10;
        }
        
        $attackerCor = AttributeHelper::queryCor($attacker);
        if ($victimCps > 0 && mt_rand(0, max(1, $victimCps * 3) - 1) >= $attackerCor) {
            // 攻击者被气势所慑，跳过本回合攻击
            // 但NPC仍然独立攻击（还原LPC heart_beat机制：NPC有自己的heart_beat）
            $guardMsg = HTML_HIRED . '【战斗】' . HTML_NOR . ' 你被' . HTML_HICYN . (!empty($combat['target_name']) ? $combat['target_name'] : '目标') . HTML_NOR . '的气势所慑，不敢轻举妄动。';
            $npcAttackResult = self::performNpcAttack($combat, $attackerId, $playerDamage);
            if ($npcAttackResult['killed']) {
                $isFriendly = isset($combat['friendly']) && $combat['friendly'];
                if (!$isFriendly) {
                    self::handlePlayerDefeated($attackerId, $combat);
                }
                $defeatMsg = $isFriendly ? '你被打败了，昏了过去！' : '你被打败了，被彻底杀死了！';
                return [
                    'success' => true,
                    'damage' => 0,
                    'player_damage' => $playerDamage,
                    'player_hp' => 0,
                    'killed' => true,
                    'output' => $guardMsg . $npcAttackResult['msg'] . HTML_HIRED . "\n" . $defeatMsg . HTML_NOR
                ];
            }
            return [
                'success' => true,
                'damage' => 0,
                'player_damage' => $playerDamage,
                'player_hp' => $attacker['kee'],
                'message' => $guardMsg . $npcAttackResult['msg'],
                'guarded' => true
            ];
        }
        
        // 判定防守方 guarding 状态（用于后续 riposte 判定）
        $attackerCps = AttributeHelper::queryCps($attacker);
        $defenderCor = 10;
        if ($combat['target_type'] === 'npc') {
            $defRow = Database::queryOne("SELECT cor, bellicosity FROM npcs WHERE id = ? LIMIT 1", [$combat['target_id']]);
            if ($defRow) {
                $defenderCor = intval($defRow['cor'] ?? 10) + intval(($defRow['bellicosity'] ?? 0) / 50);
            }
        } elseif ($combat['target_type'] === 'yaoguai') {
            // mieyao_yaoguai 表没有 cor 和 bellicosity 字段，使用默认值
            $defenderCor = 10;
        } else {
            $defRow = CharacterModel::find($combat['target_id']);
            if ($defRow) {
                $defenderCor = AttributeHelper::queryCor($defRow);
            }
        }
        $defenderIsGuarding = ($attackerCps > 0 && mt_rand(0, max(1, $attackerCps * 3) - 1) >= $defenderCor);
        
        // === 每回合概率触发特殊招式（替代静态预选机制）===
        // 武器变更检测：若当前武器与存储的不同，更新记录
        $specialAction = null;
        $currentWeapon = self::getEquippedWeapon($attackerId);
        $currentWeaponId = $currentWeapon ? ($currentWeapon['item_id'] ?? $currentWeapon['id'] ?? '') : 'unarmed';
        $storedWeaponId = $_SESSION["combat_weapon_{$attackerId}"] ?? null;

        if ($storedWeaponId !== $currentWeaponId) {
            // 武器变更，更新记录
            $_SESSION["combat_weapon_{$attackerId}"] = $currentWeaponId;
        }

        // 每回合概率判定是否触发特殊招式
        if (self::shouldPerformSpecialAction($attackerId)) {
            $specialAction = self::selectRandomAction($attackerId);
        }

        // 从特殊招式中提取 dodge_mod 和 parry_mod
        $dodgeMod = 0;
        $parryMod = 0;
        if ($specialAction) {
            $dodgeMod = intval($specialAction['dodge_mod'] ?? 0);
            $parryMod = intval($specialAction['parry_mod'] ?? 0);
        }

        // === 技能属性集成：闪避判定（应用 dodge_mod） ===
        $dodgeResult = self::checkDodge($attackerId, $combat, $dodgeMod, $attacker);
        if ($dodgeResult['dodged']) {
            // 闪避成功，防守方dodge技能增长（仅对玩家角色生效）
            if ($combat['target_type'] === 'player') {
                $defenderId = $combat['target_id'];
                SkillManager::combatImproveSkill($defenderId, 'dodge');
            }
            
            // 连击中断（攻击被闪避）
            Database::execute("UPDATE active_combats SET combo_count = 0 WHERE char_id = ?", [$attackerId]);
            
            $dodgeMsg = HTML_HIRED . '【战斗】' . HTML_NOR . ' 你攻击 ' . HTML_HICYN . (!empty($combat['target_name']) ? $combat['target_name'] : '目标') . HTML_NOR . '，但对方灵巧地闪开了！';
            
            // NPC仍然独立攻击（还原LPC heart_beat机制）
            $npcAttackResult = self::performNpcAttack($combat, $attackerId, $playerDamage);
            if ($npcAttackResult['killed']) {
                $isFriendly = isset($combat['friendly']) && $combat['friendly'];
                if (!$isFriendly) {
                    self::handlePlayerDefeated($attackerId, $combat);
                }
                $defeatMsg = $isFriendly ? '你被打败了，昏了过去！' : '你被打败了，被彻底杀死了！';
                return [
                    'success' => true,
                    'damage' => 0,
                    'player_damage' => $playerDamage,
                    'player_hp' => 0,
                    'killed' => true,
                    'output' => $dodgeMsg . $npcAttackResult['msg'] . HTML_HIRED . "\n" . $defeatMsg . HTML_NOR
                ];
            }
            
            return [
                'success' => true,
                'damage' => 0,
                'player_damage' => $playerDamage,
                'player_hp' => $attacker['kee'],
                'message' => $dodgeMsg . $npcAttackResult['msg']
            ];
        }
        
        // === 招架判定（还原原始项目 AP/(AP+PP) 概率比值制）===
        // 招架成功时减伤30%-50%，不再完全格挡
        $parryReduction = 0.0;
        $parrySuccess = false;
        $parryResult = self::checkParry($attackerId, $combat, $parryMod, $attacker);
        if ($parryResult['reduction'] > 0) {
            $parrySuccess = true;
            $parryReduction = $parryResult['reduction'];
            
            // 招架成功，防守方parry技能增长（仅对玩家角色生效）
            if ($combat['target_type'] === 'player') {
                $defenderId = $combat['target_id'];
                SkillManager::combatImproveSkill($defenderId, 'parry');
            }

            // 连击中断（攻击被招架）
            Database::execute("UPDATE active_combats SET combo_count = 0 WHERE char_id = ?", [$attackerId]);
        }

        // 计算伤害（简化版，参考原始Combatd::do_attack逻辑）
        $damage = self::calculateDamage($attacker, $combat);

        // === hit_ob: 技能等级伤害加成（每次攻击都生效，不仅特殊招式） ===
        $baseDamageForHitOb = $damage;
        $mappedWeaponSkill = self::getMappedWeaponSkill($attackerId, false);
        if ($mappedWeaponSkill) {
            $hitBonus = SkillManager::calculateHitBonus($attackerId, $mappedWeaponSkill, $baseDamageForHitOb, false);
            $damage += $hitBonus;
        }
        $mappedForceSkill = self::getMappedForceSkill($attackerId, false);
        if ($mappedForceSkill) {
            // 还原原始项目 force.c::hit_ob() 含反震机制
            $equippedWeaponForForce = self::getEquippedWeapon($attackerId);
            $attackerUnarmed = ($equippedWeaponForForce === null);
            
            // 获取防守方内力数据
            $defenderForceData = self::getDefenderForceData($combat, $mappedForceSkill, $attackerId);
            
            $forceHitOb = SkillManager::calculateForceHitOb($attackerId, $attacker, $defenderForceData, $attackerUnarmed);
            
            if ($forceHitOb['reflected']) {
                // 反震：攻击者自身受到伤害
                $reflectDmg = $forceHitOb['reflect_damage'];
                $newKee = max(0, $attacker['kee'] - $reflectDmg);
                Database::execute("UPDATE characters SET kee = ? WHERE id = ?", [$newKee, $attackerId]);
                $playerDamage += $reflectDmg;
                
                // 反震消息
                $reflectMsg = HTML_HIRED . '【战斗】' . HTML_NOR . ' 你的内功被' . HTML_HICYN . (!empty($combat['target_name']) ? $combat['target_name'] : '目标') . HTML_NOR . '反震！你受到' . HTML_HIRED . $reflectDmg . HTML_NOR . '点反震伤害！';
                // 将反震消息追加到后续输出
                $autoActionMessage = ($autoActionMessage ?? '') . $reflectMsg;
            } elseif (!$forceHitOb['fizzled'] && $forceHitOb['damage'] > 0) {
                $damage += $forceHitOb['damage'];
            }
        }
        
        // 战斗中技能增长：武器技能（仅对玩家角色生效）
        $weaponSkillId = $mappedWeaponSkill;
        if ($weaponSkillId) {
            SkillManager::combatImproveSkill($attacker['id'], $weaponSkillId);
        }
        // 战斗中技能增长：内功技能（仅对玩家角色生效）
        $forceSkillId = $mappedForceSkill;
        if ($forceSkillId) {
            SkillManager::combatImproveSkill($attacker['id'], $forceSkillId);
        }
        
        // === 战斗中经验获取（还原原始项目 combatd.c 第(7)步）===
        // 原始逻辑：仅当 AP < DP（攻击者弱于防守方）时，攻击者才能获得经验
        // 如果伤害相对于目标血量较大 → 目标 combat_exp += 1
        $combatTargetType = $combat['target_type'] ?? 'npc';
        
        // 计算 AP 和 DP
        $apForExp = self::calcSkillPower($attacker, $mappedWeaponSkill ?? 'unarmed', 1, false);
        $dpForExp = 0;
        if ($combatTargetType === 'npc' || $combatTargetType === 'yaoguai') {
            $defTable = ($combatTargetType === 'yaoguai') ? 'mieyao_yaoguai' : 'npcs';
            $defCharData = Database::queryOne("SELECT * FROM {$defTable} WHERE id = ? LIMIT 1", [$combat['target_id']]);
            if ($defCharData) {
                $dpForExp = self::calcSkillPower($defCharData, 'dodge', 2, true);
            }
        } else {
            $defCharData = CharacterModel::find($combat['target_id']);
            if ($defCharData) {
                $dpForExp = self::calcSkillPower($defCharData, 'dodge', 2, false);
            }
        }
        
        if ($combatTargetType !== 'player') {
            // 仅PvE战斗中有即时经验增长（原始项目：!userp(me) || !userp(victim)）
            // AP < DP 前置条件：仅当攻击者弱于防守方时获得经验
            if ($apForExp < $dpForExp) {
                $attackerSen = intval($attacker['sen'] ?? 100);
                $attackerMaxSen = max(1, intval($attacker['max_sen'] ?? 100));
                $attackerInt = intval($attacker['int'] ?? 10);
                $attackerCpsExp = intval($attacker['cps'] ?? 10);
                
                // 攻击者经验增长判定
                $expRoll = $attackerSen * 100 / $attackerMaxSen + $attackerInt * $attackerCpsExp;
                if (mt_rand(0, (int)$expRoll) > 150) {
                    Database::execute("UPDATE characters SET combat_exp = combat_exp + 1 WHERE id = ?", [$attackerId]);
                    // 潜能增长（上限检查）
                    $attackerPotential = intval($attacker['potential'] ?? 0);
                    $attackerLearned = intval($attacker['learned_points'] ?? 0);
                    if ($attackerPotential - $attackerLearned < 100) {
                        Database::execute("UPDATE characters SET potential = potential + 1 WHERE id = ?", [$attackerId]);
                    }
                    // 攻击技能增长
                    if ($weaponSkillId) {
                        SkillManager::combatImproveSkill($attacker['id'], $weaponSkillId);
                    }
                }
            }
            
            // 防守方经验获取：当伤害较大时，防守方 combat_exp +1
            $targetMaxKee = 100;
            $targetCurrentKee = 100;
            if ($combatTargetType === 'npc') {
                $npcHpRow = Database::queryOne("SELECT max_kee FROM npcs WHERE id = ? LIMIT 1", [$combat['target_id']]);
                $targetMaxKee = $npcHpRow ? max(100, intval($npcHpRow['max_kee'] ?? 100)) : 100;
                $targetCurrentKee = $targetMaxKee; // 简化
            }
            if (mt_rand(0, max(1, $targetMaxKee + $targetCurrentKee) - 1) < $damage) {
                if ($combatTargetType !== 'npc' && $combatTargetType !== 'yaoguai') {
                    // 玩家防守方获得经验
                    Database::execute("UPDATE characters SET combat_exp = combat_exp + 1 WHERE id = ?", [$combat['target_id']]);
                }
            }
        }
        
        // === 连击系统：命中后连击计数+1 ===
        $charId = $attackerId;
        $comboCount = ($combat['combo_count'] ?? 0) + 1;
        Database::execute("UPDATE active_combats SET combo_count = ? WHERE char_id = ?", [$comboCount, $charId]);
        
        // 连击伤害加成（从配置读取参数）
        $cfg = self::loadConfig();
        $comboBonus = min($cfg['combo']['bonus_cap'], intval($comboCount / $cfg['combo']['count_div']) * $cfg['combo']['bonus_per']);
        if ($comboBonus > 0) {
            $damage = intval($damage * (100 + $comboBonus) / 100);
        }
        
        // 暴风连击（从配置读取触发参数）
        $stormMsg = '';
        if ($comboCount >= $cfg['combo']['storm_threshold'] && mt_rand(1, 100) <= $cfg['combo']['storm_chance']) {
            $extraDamage = intval($damage * $cfg['combo']['storm_damage_mult']);
            $damage += $extraDamage;
            $stormMsg = "<span style='color:#FF4500;font-weight:bold'>暴风连击！" . ($attacker['name'] ?? '你') . "发动了连续攻击！</span>";
        }
        
        // 切磋模式：使用真实血量（扣除角色真实血量）
        if (isset($combat['friendly']) && $combat['friendly']) {
            // 玩家使用真实血量
            $playerCurrentHp = $attacker['kee'];
            $playerMaxHp = $attacker['max_kee'] ?? $playerCurrentHp;
            
            // 目标血量管理
            $targetMaxHp = 0;
            $targetCurrentHp = 0;
            $npcHpKey = '';
            
            if ($combat['target_type'] === 'npc') {
                // NPC 血量使用 session 追踪
                $npcHpKey = "npc_hp_friendly_{$combat['target_id']}";
                if (!isset($_SESSION[$npcHpKey])) {
                    $sql = "SELECT max_kee FROM npcs WHERE id = ? LIMIT 1";
                    $npc = Database::queryOne($sql, [$combat['target_id']]);
                    $targetMaxHp = max(100, intval($npc['max_kee'] ?? 100));
                    $_SESSION[$npcHpKey] = $targetMaxHp;
                } else {
                    // 从 NPC max_kee 获取最大血量（用于百分比计算）
                    $sql = "SELECT max_kee FROM npcs WHERE id = ? LIMIT 1";
                    $npc = Database::queryOne($sql, [$combat['target_id']]);
                    $targetMaxHp = max(100, intval($npc['max_kee'] ?? 100));
                }
                $targetCurrentHp = $_SESSION[$npcHpKey];
            } elseif ($combat['target_type'] === 'player') {
                // 玩家血量从 characters 表获取
                $targetChar = CharacterModel::find(intval($combat['target_id']));
                if ($targetChar) {
                    $targetCurrentHp = intval($targetChar['kee'] ?? 0);
                    $targetMaxHp = intval($targetChar['max_kee'] ?? 0);
                }
            }
            
            // 检查目标血量，如果已无血则结束战斗
            if ($targetCurrentHp <= 0) {
                $targetName = $combat['target_name'] ?? '对方';
                
                // === 特殊处理：蓬莱三老挑战（HP降到0，给予对应物品） ===
                $sanxingRewardMsg = self::checkSanxingReward($combat['target_id'], $combat['target_type'], $attackerId);
                if ($sanxingRewardMsg !== null) {
                    // 清理战斗状态（玩家获胜）
                    self::endCombat($attackerId, true);
                    unset($_SESSION["combat_log_{$attackerId}"]);
                    if (!empty($npcHpKey)) {
                        unset($_SESSION[$npcHpKey]);
                    }
                    return [
                        'success' => true,
                        'damage' => 0,
                        'killed' => true,
                        'friendly' => true,
                        'sanxing_win' => true,
                        'message' => $sanxingRewardMsg
                    ];
                }
                
                $winnerMsg = CombatMessages::getWinnerMessage($attacker['name'], $targetName);
                $endMsg = HTML_HICYN . '【切磋】' . HTML_NOR . $attacker['name'] . '对' . $targetName . '点到为止，拱手抱拳。' . "\n";
                $endMsg .= HTML_HIGRN . '切磋结束！' . HTML_NOR . $winnerMsg;

                // 广播战斗结束消息给房间内其他玩家
                require_once DAEMON_PATH . 'MessageDaemon.php';
                if (!empty($attacker['current_room'])) {
                    $roomMsg = HTML_HICYN . '【切磋】' . HTML_NOR . $attacker['name'] . '对' . $targetName . '点到为止，拱手抱拳。';
                    MessageDaemon::broadcastToRoom($attacker['current_room'], $roomMsg, intval($attackerId), 'room');
                }

                $debugCombatSystem = isset($combat['combat_system']) ? $combat['combat_system'] : 'null';
                $debugRankLevel = isset($combat['rank_level']) ? $combat['rank_level'] : 'null';
                log_game('DUEL_END_DEBUG', "Duel ending: attackerId={$attackerId}, targetId={$combat['target_id']}, combatSystem={$debugCombatSystem}, rankLevel={$debugRankLevel}, playerWon=true");

                // 玩家获胜，传递胜负参数
                self::endCombat($attackerId, true);
                unset($_SESSION["combat_log_{$attackerId}"]);
                if (!empty($npcHpKey)) {
                    unset($_SESSION[$npcHpKey]);
                }
                
                return [
                    'success' => true,
                    'damage' => 0,
                    'killed' => true,
                    'friendly' => true,
                    'message' => $endMsg
                ];
            }
            
            // 计算对目标的伤害（使用已包含hit_ob加成的伤害）
            $baseDamage = $damage;
            
            // 应用随机波动（从配置读取百分比）
            $fluc = self::loadConfig()['damage']['fluctuation_pct'];
            $variation = mt_rand(-$fluc, $fluc) / 100;
            $damageToTarget = intval($baseDamage * (1 + $variation));  // 还原原始项目逻辑
            $damageToTarget = max(1, $damageToTarget);
            
            // 目标受到伤害
            if ($combat['target_type'] === 'npc') {
                // NPC：更新 session 血量
                $_SESSION[$npcHpKey] = max(0, $targetCurrentHp - $damageToTarget);
                $targetCurrentHp = $_SESSION[$npcHpKey];
            } elseif ($combat['target_type'] === 'player') {
                // 玩家：更新数据库中的 kee 字段
                $newTargetHp = max(0, $targetCurrentHp - $damageToTarget);
                Database::execute("UPDATE characters SET kee = ? WHERE id = ?", [$newTargetHp, $combat['target_id']]);
                $targetCurrentHp = $newTargetHp;
            }
            
            // 目标反击（仅NPC有独立攻击，还原LPC heart_beat机制）
            $counterMsg = '';
            if ($combat['target_type'] === 'npc') {
                $npcAttackResult = self::performNpcAttack($combat, $attackerId, $playerDamage);
                $counterMsg = $npcAttackResult['msg'];
                if ($npcAttackResult['killed']) {
                    // NPC反击导致玩家失败
                    $targetName = !empty($combat['target_name']) ? $combat['target_name'] : '目标';
                    $endMsg = HTML_HIRED . '【切磋】' . HTML_NOR . ' 你被' . HTML_HICYN . $targetName . HTML_NOR . '打败了！';

                    // 广播战斗结束消息给房间内其他玩家
                    require_once DAEMON_PATH . 'MessageDaemon.php';
                    if (!empty($attacker['current_room'])) {
                        $roomMsg = HTML_HIRED . '【切磋】' . HTML_NOR . $attacker['name'] . '被' . $targetName . '打败了！';
                        MessageDaemon::broadcastToRoom($attacker['current_room'], $roomMsg, intval($attackerId), 'room');
                    }

                    self::endCombat($attackerId);
                    unset($_SESSION[$npcHpKey]);
                    return [
                        'success' => true,
                        'damage' => $damageToTarget,
                        'player_damage' => $playerDamage,
                        'player_hp' => 0,
                        'killed' => true,
                        'friendly' => true,
                        'message' => $endMsg . $counterMsg
                    ];
                }
                // 刷新玩家血量（performNpcAttack可能已修改DB中的kee）
                $freshKeeRow = Database::queryOne("SELECT kee FROM characters WHERE id = ? LIMIT 1", [$attackerId]);
                if ($freshKeeRow) {
                    $playerCurrentHp = intval($freshKeeRow['kee'] ?? 0);
                }
            }
                    
            // 生成战斗消息（使用技能动作描述系统）
            $limb = self::getRandomLimb();
            $equippedWeapon = self::getEquippedWeapon($attacker['id']);
            $weaponType = $equippedWeapon ? ($equippedWeapon['weapon_type'] ?? 'unarmed') : 'unarmed';
            $weaponName = $equippedWeapon ? ($equippedWeapon['name'] ?? '') : '';
            $targetName = !empty($combat['target_name']) ? $combat['target_name'] : '目标';

            // === 技能触发：切磋模式也支持特殊招式（使用已判定的$specialAction） ===
            $autoActionMsgFight = '';
            if ($specialAction) {
                // 切磋模式不扣除内力（无真实消耗）
                $autoActionMsgFight = self::formatActionText(
                    $specialAction['action_text'],
                    '你',
                    $targetName,
                    $weaponName
                );
            }

            if ($autoActionMsgFight !== '') {
                $actionMsg = $autoActionMsgFight;
            } else {
                $actionText = self::getAttackActionText($attacker, $weaponType);
                if ($actionText) {
                    $actionMsg = self::replaceVars($actionText, '你', $targetName, $limb, $weaponName);
                } else {
                    $actionMsg = '你向' . $targetName . '发起攻击，瞄准' . $targetName . '的' . $limb;
                }
            }
            
            $damageType = self::getDamageTypeForAttacker($attacker, $weaponType);
            $damageMsg = CombatMessages::getDamageMessage($damageToTarget, $damageType);
            $damageMsg = self::replaceVars($damageMsg, '你', $targetName, $limb, $weaponName);
            
            $message = $actionMsg . '。' . $damageMsg;
                    
            // 检查是否应该结束战斗（任一方真实血量低于30%）
            $isFightOver = false;
            $winner = null;
            $loser = null;
            
            // 计算血量百分比（使用真实血量）
            $playerHpPercent = $playerMaxHp > 0 ? ($playerCurrentHp / $playerMaxHp) * 100 : 100;
            $targetHpPercent = $targetMaxHp > 0 ? ($targetCurrentHp / $targetMaxHp) * 100 : 100;
            
            // 检查玩家真实血量是否低于30%
            if ($playerHpPercent < 30) {
                $isFightOver = true;
                // 目标胜，玩家败
                $winner = !empty($combat['target_name']) ? $combat['target_name'] : '目标';
                $loser = $attacker['name'];
            }
                    
            // 检查目标血量是否低于30%
            if (!$isFightOver && $targetHpPercent < 30) {
                $isFightOver = true;
                // 玩家胜，目标败
                $winner = $attacker['name'];
                $loser = !empty($combat['target_name']) ? $combat['target_name'] : '目标';
            }
                    
            if ($isFightOver) {
                $playerWon = $winner === $attacker['name'];
                
                // === 特殊处理：蓬莱三老挑战（玩家胜利时给予对应物品） ===
                if ($playerWon && $combat['target_type'] === 'npc') {
                    $sanxingRewardMsg = self::checkSanxingReward($combat['target_id'], $combat['target_type'], $attackerId);
                    if ($sanxingRewardMsg !== null) {
                        // 清理战斗状态
                        self::endCombat($attackerId, $playerWon);
                        if (!empty($npcHpKey)) {
                            unset($_SESSION[$npcHpKey]);
                        }
                        return [
                            'success' => true,
                            'damage' => 0,
                            'killed' => true,
                            'friendly' => true,
                            'sanxing_win' => true,
                            'message' => $sanxingRewardMsg
                        ];
                    }
                }
                
                // 广播战斗结束消息给房间内其他玩家
                require_once DAEMON_PATH . 'MessageDaemon.php';
                if (!empty($attacker['current_room'])) {
                    $roomMsg = HTML_HICYN . '【切磋】' . HTML_NOR . $winner . '和' . $loser . '的切磋结束了。';
                    MessageDaemon::broadcastToRoom($attacker['current_room'], $roomMsg, intval($attackerId), 'room');
                }

                self::endCombat($attackerId, $playerWon);
                        
                // 清理切磋血量 session
                if (!empty($npcHpKey)) {
                    unset($_SESSION[$npcHpKey]);
                }
                        
                // 如果是NPC切磋且NPC被击败，设置NPC的拒绝状态（30秒内拒绝再次切磋）
                if ($combat['target_type'] === 'npc' && $winner === $attacker['name']) {
                    $rejectKey = "npc_reject_fight_{$combat['target_id']}";
                    $_SESSION[$rejectKey] = time() + 30;  // 30秒后过期
                }
                        
                // 根据实际胜负显示消息
                $winnerMsg = CombatMessages::getWinnerMessage($winner, $loser);
                        
                return [
                    'success' => true,
                    'damage' => 0,
                    'killed' => true,
                    'friendly' => true,
                    'message' => $winnerMsg
                ];
            }
                    
            return [
                'success' => true,
                'damage' => $damageToTarget,  // 切磋模式：对目标造成的伤害
                'player_damage' => $playerDamage,
                'player_hp' => $playerCurrentHp,
                'target_hp' => $targetCurrentHp,
                'target_hp_percent' => $targetHpPercent,
                'message' => $message . $counterMsg
            ];
        }
        
        // 击杀模式：正常造成伤害

        // === 特殊招式：内力消耗和消息（伤害加成已通过 hit_ob 统一处理） ===
        $autoActionMessage = $autoActionMessage ?? '';
        if ($specialAction) {
            // 扣除内力消耗
            $forceCost = intval($specialAction['force_cost'] ?? 0);
            if ($forceCost > 0) {
                $sql = "UPDATE characters SET `force` = GREATEST(0, `force` - ?) WHERE id = ?";
                Database::execute($sql, [$forceCost, $attackerId]);
            }

            // 格式化招式消息
            $equippedWeaponForMsg = self::getEquippedWeapon($attackerId);
            $weaponNameForMsg = $equippedWeaponForMsg ? ($equippedWeaponForMsg['name'] ?? '') : '';
            $actionText = self::formatActionText(
                $specialAction['action_text'],
                '你',
                $combat['target_name'],
                $weaponNameForMsg
            );
            $autoActionMessage .= $actionText;
        }

        // === 防御减免循环（在所有伤害加成之后、招架减伤之前执行）===
        $attackerCombatExp = intval($attacker['combat_exp'] ?? 0);
        $defenderCombatExp = 0;
        if ($combat['target_type'] === 'npc' || $combat['target_type'] === 'yaoguai') {
            $defCombatExpRow = Database::queryOne(
                "SELECT combat_exp FROM " . ($combat['target_type'] === 'yaoguai' ? 'mieyao_yaoguai' : 'npcs') . " WHERE id = ? LIMIT 1",
                [$combat['target_id']]
            );
            $defenderCombatExp = $defCombatExpRow ? intval($defCombatExpRow['combat_exp'] ?? 0) : 0;
        } else {
            $defCombatExpRow = Database::queryOne("SELECT combat_exp FROM characters WHERE id = ? LIMIT 1", [$combat['target_id']]);
            $defenderCombatExp = $defCombatExpRow ? intval($defCombatExpRow['combat_exp'] ?? 0) : 0;
        }
        $damage = CombatSystemHelper::applyDefenseReduction($damage, $attackerCombatExp, $defenderCombatExp);

        // === 招架减伤应用（在所有伤害加成之后）===
        $parryMsg = '';
        if ($parrySuccess && $parryReduction > 0) {
            $damageBeforeParry = $damage;
            $damage = max(0, intval($damage * (1.0 - $parryReduction)));
            
            // 生成招架消息
            $parryTargetName = $combat['target_name'] ?? '目标';
            $parryWeaponForMsg = self::getEquippedWeapon($attacker['id']);
            $parryWeaponName = $parryWeaponForMsg ? ($parryWeaponForMsg['name'] ?? '') : '';
            $parryReductionPct = intval($parryReduction * 100);
            if (!empty($parryWeaponName)) {
                $parryMsg = '你挥动' . $parryWeaponName . '向' . HTML_HICYN . $parryTargetName . HTML_NOR . '发起攻击，被对方招架住了，攻势大为减弱！（减伤' . $parryReductionPct . '%）';
            } else {
                $parryMsg = '你向' . HTML_HICYN . $parryTargetName . HTML_NOR . '发起攻击，被对方招架住了，攻势大为减弱！（减伤' . $parryReductionPct . '%）';
            }
        }
        
        // 应用伤害到目标
        $result = self::applyDamage($combat, $damage, $attackerId);

        // 生成战斗消息（使用技能动作描述系统）
        $limb = self::getRandomLimb();
        $equippedWeapon = self::getEquippedWeapon($attacker['id']);
        $weaponType = $equippedWeapon ? ($equippedWeapon['weapon_type'] ?? 'unarmed') : 'unarmed';
        $weaponName = $equippedWeapon ? ($equippedWeapon['name'] ?? '') : '';
        $targetName = $combat['target_name'] ?? '目标';

        // 如果本回合触发了特殊招式，优先使用招式消息；否则使用普通攻击消息
        if ($autoActionMessage !== '') {
            $actionMsg = $autoActionMessage;
        } else {
            $actionText = self::getAttackActionText($attacker, $weaponType);
            if ($actionText) {
                $actionMsg = self::replaceVars($actionText, '你', $targetName, $limb, $weaponName);
            } else {
                $actionMsg = '你向' . $targetName . '发起攻击，瞄准' . $targetName . '的' . $limb;
            }
        }

        // 伤害类型：若特殊招式有指定类型则优先使用
        if ($specialAction && !empty($specialAction['damage_type'])) {
            $damageType = $specialAction['damage_type'];
        } else {
            $damageType = self::getDamageTypeForAttacker($attacker, $weaponType);
        }
        $damageMsg = CombatMessages::getDamageMessage($damage, $damageType);
        $damageMsg = self::replaceVars($damageMsg, '你', $targetName, $limb, $weaponName);

        // 根据是否为友好比试选择颜色
        $colorPrefix = (isset($combat['friendly']) && $combat['friendly']) ? HTML_HIYEL : HTML_HIRED;
        $label = (isset($combat['friendly']) && $combat['friendly']) ? '【切磋】' : '【战斗】';

        // 连击数显示（3连击以上才显示）
        $comboPrefix = '';
        if ($comboCount >= 3) {
            $comboPrefix = "<span style='color:#FFA500'>[{$comboCount}连击]</span> ";
        }
        // 暴风连击消息
        if (!empty($stormMsg)) {
            $comboPrefix .= $stormMsg . ' ';
        }

        // 组装消息：招架成功时显示招架减伤消息，否则显示正常攻击消息
        if (!empty($parryMsg)) {
            $damageMsg = CombatMessages::getDamageMessage($damage, $damageType);
            $damageMsg = self::replaceVars($damageMsg, '你', $targetName, $limb, $weaponName);
            $message = $comboPrefix . $parryMsg . $damageMsg;
        } else {
            $message = $comboPrefix . $actionMsg . '。' . $damageMsg;
        }
        
        // === NPC AI系统 ===
        require_once __DIR__ . '/../helpers/NpcAiHelper.php';
        
        // NPC AI: 检查是否应该脱战（血量低于30%时有概率逃跑）
        if ($targetType === 'npc' && (!isset($combat['friendly']) || !$combat['friendly'])) {
            $npcForAi = Database::queryOne("SELECT * FROM npcs WHERE id = ? LIMIT 1", [$targetId]);
            if ($npcForAi) {
                $fleeMsg = NpcAiHelper::shouldFlee($npcForAi);
                if ($fleeMsg) {
                    // NPC脱战，结束战斗
                    self::clearAllCombatForTarget($targetId, $targetType);
                    $fleeMsgColored = HTML_HIYEL . $fleeMsg . HTML_NOR;
                    
                    // NPC逃跑：移动到其他房间，而不是直接消失
                    $npcId = $npcForAi['id'];
                    $currentRoom = $npcForAi['spawn_room'] ?? '';
                    
                    if (!empty($currentRoom)) {
                        // 解析当前房间的区域和房间ID
                        $roomParts = explode('/', $currentRoom);
                        $area = $roomParts[0] ?? '';
                        $roomId = (count($roomParts) > 1) ? implode('/', array_slice($roomParts, 1)) : $roomParts[0] ?? '';
                        
                        // 获取当前房间的出口
                        require_once MODEL_PATH . 'Room.php';
                        $room = RoomModel::load($area, $roomId);
                        if ($room && isset($room['id'])) {
                            $exits = RoomModel::getExits($room['id']);
                            if (!empty($exits)) {
                                // 随机选择一个出口
                                $randomExit = $exits[array_rand($exits)];
                                $targetArea = $randomExit['target_area'] ?? $area;
                                $targetRoom = $randomExit['target_room'] ?? '';
                                
                                // 构建新的 spawn_room
                                $newSpawnRoom = (!empty($targetArea) && $targetArea !== $area) 
                                    ? "{$targetArea}/{$targetRoom}" 
                                    : $targetRoom;
                                
                                if (!empty($newSpawnRoom)) {
                                    // 更新NPC的位置
                                    Database::execute(
                                        "UPDATE npcs SET spawn_room = ? WHERE id = ?",
                                        [$newSpawnRoom, $npcId]
                                    );
                                }
                            }
                        }
                    }
                    
                    // 广播脱战消息给房间内其他玩家（不包含攻击者自己）
                    require_once DAEMON_PATH . 'MessageDaemon.php';
                    if (!empty($attacker['current_room'])) {
                        MessageDaemon::broadcastToRoom($attacker['current_room'], $fleeMsgColored, intval($attackerId), 'room');
                    }
                    
                    return [
                        'success' => true,
                        'damage' => $damage,
                        'killed' => false,
                        'npc_fled' => true,
                        'message' => $message,
                        'flee_message' => $fleeMsgColored
                    ];
                }
            }
        }
        
        // NPC独立攻击（还原LPC heart_beat机制：NPC有自己的heart_beat，每回合必定攻击）
        // 注意：如果目标已经死亡，则不进行反击
        $counterMsg = '';
        if (empty($result['target_dead'])) {
            $npcAttackResult = self::performNpcAttack($combat, $attackerId, $playerDamage);
            $counterMsg = $npcAttackResult['msg'];
            if ($npcAttackResult['killed']) {
                $isFriendly = isset($combat['friendly']) && $combat['friendly'];
                if (!$isFriendly) {
                    self::handlePlayerDefeated($attackerId, $combat);
                }
                $defeatMsg = $isFriendly ? '你被打败了，昏了过去！' : '你被打败了，被彻底杀死了！';
                return [
                    'success' => true,
                    'damage' => $damage,
                    'player_damage' => $playerDamage,
                    'player_hp' => 0,
                    'killed' => true,
                    'output' => $counterMsg . HTML_HIRED . "\n" . $defeatMsg . HTML_NOR
                ];
            }
            // 刷新攻击者血量（performNpcAttack可能已修改DB中的kee）
            $attackerKeeRow = Database::queryOne("SELECT kee FROM characters WHERE id = ? LIMIT 1", [$attackerId]);
            if ($attackerKeeRow) {
                $attacker['kee'] = intval($attackerKeeRow['kee'] ?? 0);
            }
        }

        // NPC AI: 战斗回合特殊行为（施法/招式/台词）
        // 注意：如果目标已经死亡，则不执行AI行为
        $aiActionMsg = '';
        if (empty($result['target_dead']) && $targetType === 'npc') {
            if (!isset($npcForAi)) {
                $npcForAi = Database::queryOne("SELECT * FROM npcs WHERE id = ? LIMIT 1", [$targetId]);
            }
            if ($npcForAi) {
                $aiAction = NpcAiHelper::combatRoundAi($npcForAi);
                if ($aiAction) {
                    // AI行为消息
                    $aiActionMsg = ' ' . HTML_HIMAG . $aiAction['message'] . HTML_NOR;

                    // 施法或招式：额外伤害加到玩家身上
                    if (!empty($aiAction['damage_bonus']) && $aiAction['damage_bonus'] > 0) {
                        if (!isset($combat['friendly']) || !$combat['friendly']) {
                            $bonusDmg = intval($aiAction['damage_bonus']);

                            // 检查濒死状态
                            $nearDeathTime = intval($attacker['near_death_time'] ?? 0);
                            if ($nearDeathTime > 0 && $attacker['kee'] <= 0) {
                                self::triggerPlayerDeathForCriticalState($attackerId, 'near_death');
                                return [
                                    'success' => true,
                                    'damage' => $damage + $bonusDmg,
                                    'player_damaged' => true,
                                    'player_hp' => 0,
                                    'killed' => true,
                                    'output' => HTML_HIRED . '你已处于濒死状态，再次受伤导致真正死亡！' . HTML_NOR
                                ];
                            }

                            $newKee = max(0, $attacker['kee'] - $bonusDmg);
                            Database::execute("UPDATE characters SET kee = ? WHERE id = ?", [$newKee, $attackerId]);
                            
                            // 累加玩家受到的伤害（用于飘血显示）
                            $playerDamage += $bonusDmg;
                            
                        if ($newKee <= 0) {
                            self::handlePlayerDefeated($attackerId, $combat);
                            $isFriendly = isset($combat['friendly']) && $combat['friendly'];
                            $deathMsg = $isFriendly 
                                ? HTML_HIRED . '你被打败了，昏了过去！' . HTML_NOR
                                : HTML_HIRED . '你被打败了，被彻底杀死了！' . HTML_NOR;
                            return [
                                'success' => true,
                                'damage' => $damage + $bonusDmg,
                                'player_damaged' => true,
                                'player_hp' => 0,
                                'killed' => true,
                                'output' => $deathMsg
                            ];
                        }
                        }
                    }
                    
                    // 内功运用：NPC恢复血量
                    if (!empty($aiAction['recovery']) && $aiAction['recovery'] > 0) {
                        $recovery = intval($aiAction['recovery']);
                        // 恢复 active_combats 表中的共享血量
                        Database::execute(
                            "UPDATE active_combats SET target_current_hp = LEAST(target_max_hp, target_current_hp + ?) WHERE target_id = ? AND target_type = ?",
                            [$recovery, $targetId, $targetType]
                        );
                    }
                }
            }
        }
        unset($npcForAi); // 清理临时变量
        
        // === 特殊处理：蓬莱三老挑战（HP低于50%即判负，给予对应物品） ===
        // 参考原始MUD代码：
        // 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
        if ($targetType === 'npc' && !$result['target_dead']) {
            $hpPercent = $result['hp_percent'] ?? 100;
            if ($hpPercent <= 50) {
                // 检查目标是否为蓬莱三老
                $sanxingNpc = Database::queryOne("SELECT npc_id, name, max_kee FROM npcs WHERE id = ? LIMIT 1", [$targetId]);
                
                if ($sanxingNpc) {
                    $npcStringId = $sanxingNpc['npc_id'] ?? '';
                    
                    // 蓬莱三老配置
                    $sanxingConfig = [
                        'luxing' => [
                            'name' => '禄星',
                            'item_id' => 'jiaoli',
                            'item_name' => '交梨',
                            'fight_mark' => 'luxing_fight',
                            'stock_key' => 'luxing_jiaoli_stock',
                            'cooldown_key' => 'luxing_jiaoli_cooldown',
                            'last_winner_key' => 'luxing_last_winner',
                            'log_type' => 'LUXING_FIGHT',
                        ],
                        'shouxing' => [
                            'name' => '寿星',
                            'item_id' => 'biou',
                            'item_name' => '碧藕',
                            'fight_mark' => 'shouxing_fight',
                            'stock_key' => 'shouxing_biou_stock',
                            'cooldown_key' => 'shouxing_biou_cooldown',
                            'last_winner_key' => 'shouxing_last_winner',
                            'log_type' => 'SHOUXING_FIGHT',
                        ],
                        'fuxing' => [
                            'name' => '福星',
                            'item_id' => 'huozao',
                            'item_name' => '火枣',
                            'fight_mark' => 'fuxing_fight',
                            'stock_key' => 'fuxing_huozao_stock',
                            'cooldown_key' => 'fuxing_huozao_cooldown',
                            'last_winner_key' => 'fuxing_last_winner',
                            'log_type' => 'FUXING_FIGHT',
                        ],
                    ];
                    
                    $config = $sanxingConfig[$npcStringId] ?? null;
                    
                    if ($config) {
                        // NPC认输！结束战斗，给予对应物品
                        $allAttackers = self::getAllAttackers($targetId, $targetType);
                        self::clearAllCombatForTarget($targetId, $targetType);
                        
                        $npcName = $sanxingNpc['name'] ?? $config['name'];
                        $itemName = $config['item_name'];
                        $itemId = $config['item_id'];
                        $fightMark = $config['fight_mark'];
                        $stockKey = $config['stock_key'];
                        $cooldownKey = $config['cooldown_key'];
                        $lastWinnerKey = $config['last_winner_key'];
                        $logType = $config['log_type'];
                        
                        // 检查攻击者是否有对应的战斗标记
                        $hasFightMark = Database::queryOne(
                            "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = ? AND temp_value = '1'",
                            [$attackerId, $fightMark]
                        );
                        
                        // 广播认输消息
                        require_once DAEMON_PATH . 'MessageDaemon.php';
                        $surrenderMsg = HTML_HICYN . "{$npcName}叹了口气，说道：好汉，饶了我吧！" . HTML_NOR . "\n";
                        $surrenderMsg .= HTML_HIYEL . "{$npcName}从怀中取出一枚{$itemName}，递给了{$attacker['name']}。" . HTML_NOR . "\n";
                        $surrenderMsg .= HTML_HICYN . "然后{$npcName}纵身一跃，化作一道白光直冲云霄而去！" . HTML_NOR;
                        
                        if (!empty($attacker['current_room'])) {
                            MessageDaemon::broadcastToRoom($attacker['current_room'], $surrenderMsg, intval($attackerId), 'room');
                        }
                        
                        if ($hasFightMark) {
                            // 给予对应物品
                            require_once MODEL_PATH . 'Item.php';
                            ItemModel::addToInventory($attackerId, $itemId, 1, 'obj');
                            
                            // 消耗存货
                            Database::execute(
                                "UPDATE variables SET `value` = '0', updated_at = NOW() WHERE var_key = ?",
                                [$stockKey]
                            );
                            
                            // 设置冷却时间（从配置读取范围）
                            $timingCfg = self::loadConfig()['timing'];
                            $cooldown = time() + $timingCfg['scatter_item_cd_base'] + mt_rand(0, $timingCfg['scatter_item_cd_rand']);
                            Database::execute(
                                "INSERT INTO variables (var_key, `value`, updated_at) VALUES (?, ?, NOW())
                                 ON DUPLICATE KEY UPDATE `value` = ?, updated_at = NOW()",
                                [$cooldownKey, $cooldown, $cooldown]
                            );
                            
                            // 记录最后获胜者
                            Database::execute(
                                "INSERT INTO variables (var_key, `value`, updated_at) VALUES (?, ?, NOW())
                                 ON DUPLICATE KEY UPDATE `value` = ?, updated_at = NOW()",
                                [$lastWinnerKey, $attackerId, $attackerId]
                            );
                            
                            // 清理战斗标记
                            Database::execute("DELETE FROM character_temp WHERE char_id = ? AND temp_key = ?", [$attackerId, $fightMark]);
                            
                            $rewardMsg = HTML_HIGRN . "你获得了{$itemName}！" . HTML_NOR;
                        } else {
                            // 没有标记（不是通过问话题发起的战斗），不给物品
                            $rewardMsg = HTML_HIYEL . "（{$npcName}逃走，但你似乎错过了什么……）" . HTML_NOR;
                        }
                        
                        // 恢复NPC的HP
                        $maxKee = $sanxingNpc['max_kee'] ?? 1000;
                        if ($maxKee) {
                            Database::execute("UPDATE npcs SET kee = ? WHERE id = ?", [$maxKee, $targetId]);
                        }
                        
                        log_game($logType, "{$attacker['name']} 击败了{$npcName}，" . ($hasFightMark ? "获得{$itemName}" : "未获得{$itemName}(无标记)"));
                        
                        return [
                            'success' => true,
                            'damage' => $damage,
                            'killed' => true,
                            'friendly' => true,
                            'sanxing_win' => true,
                            'message' => $message . "\n" . $surrenderMsg . "\n" . $rewardMsg
                        ];
                    }
                }
            }
        }
        
        // 检查目标是否死亡（简化：NPC需要多次攻击）
        if ($result['target_dead']) {
            // 获取所有参战玩家（在清理之前）
            $allAttackers = self::getAllAttackers($targetId, $targetType);
            
            // 清理所有参战玩家的战斗状态
            self::clearAllCombatForTarget($targetId, $targetType);
            
            // 友好比试不获得道行和实战经验
            if (isset($combat['friendly']) && $combat['friendly']) {
                // === 特殊处理：蓬莱三老挑战（HP降到0也判负，给予对应物品） ===
                $sanxingRewardMsg = self::checkSanxingReward($targetId, $targetType, $attackerId);
                if ($sanxingRewardMsg !== null) {
                    $message .= "\n" . $sanxingRewardMsg;
                    return [
                        'success' => true,
                        'damage' => $damage,
                        'killed' => true,
                        'friendly' => true,
                        'sanxing_win' => true,
                        'message' => $message
                    ];
                }
                
                // 使用胜利消息
                $winnerMsg = CombatMessages::getWinnerMessage($attacker['name'], $targetName);
                $message .= ' ' . HTML_HIGRN . '你赢得了切磋！' . HTML_NOR . $winnerMsg;
                
                return [
                    'success' => true,
                    'damage' => $damage,
                    'killed' => true,
                    'friendly' => true,
                    'message' => $message
                ];
            }
            
            // 查询完整的目标数据用于奖励计算
            $dxGain = 0;
            $expGain = 0;
            
            if ($targetType === 'yaoguai') {
                // 妖怪：调用 MieyaoHandler 处理
                require_once __DIR__ . '/MieyaoHandler.php';
                $yaoguaiResult = MieyaoHandler::handleKillYaoguai($targetId, $attackerId);
                
                if ($yaoguaiResult['success']) {
                    $message .= ' ' . HTML_HIGRN . '你击败了 ' . $targetName . '！' . HTML_NOR;
                    $message .= ' ' . $yaoguaiResult['message'];
                }
                
                // 通知其他参战玩家
                foreach ($allAttackers as $otherAttackerId) {
                    if ($otherAttackerId != $attackerId) {
                        require_once DAEMON_PATH . 'MessageDaemon.php';
                        MessageDaemon::sendToPlayer($otherAttackerId, 
                            HTML_HIGRN . '【战斗】' . HTML_NOR . ' ' . $targetName . ' 被击败了！' . HTML_NOR, 
                            'combat');
                    }
                }
            } elseif ($targetType === 'player') {
                // 玩家对战：击杀玩家
                // 给所有参战玩家增加杀气
                foreach ($allAttackers as $otherAttackerId) {
                    $keeMarkGain = 50;
                    Database::execute("UPDATE characters SET kee_mark = kee_mark + ? WHERE id = ?", [$keeMarkGain, $otherAttackerId]);
                    
                    // 通知其他参战玩家（非击杀者）
                    if ($otherAttackerId != $attackerId) {
                        require_once DAEMON_PATH . 'MessageDaemon.php';
                        $notifyMsg = HTML_HIGRN . '【战斗】' . HTML_NOR . ' ' . $targetName . ' 被杀死了！' . HTML_NOR;
                        MessageDaemon::sendToPlayer($otherAttackerId, $notifyMsg, 'combat');
                    }
                }
                
                $message .= ' ' . HTML_HIGRN . '你杀死了 ' . $targetName . '！' . HTML_NOR;
                
                log_game('COMBAT_KILL_PLAYER', "{$attacker['name']} 杀死了玩家 {$targetName}（参战人数：" . count($allAttackers) . "）");
            } else {
                // 普通NPC
                $sql = "SELECT * FROM npcs WHERE id = ? LIMIT 1";
                $npc = Database::queryOne($sql, [$targetId]);
                $npc = $npc ? NpcModel::initializeAttributes($npc) : null;
                
                // 处理NPC死亡：生成尸体、安排重生
                if ($npc) {
                    self::handleNpcDeath($targetId, $npc, $attackerId, $attacker['name']);
                }
                
                // 给所有参战玩家分发奖励（各自独立计算）
                foreach ($allAttackers as $otherAttackerId) {
                    $otherAttacker = CharacterModel::find($otherAttackerId);
                    if (!$otherAttacker) continue;
                    
                    // kill模式击杀增加杀气
                    $isKillMode = !isset($combat['friendly']) || !$combat['friendly'];
                    if ($isKillMode) {
                        $keeMarkGain = ($combat['target_type'] === 'player') ? 50 : 5;
                        Database::execute("UPDATE characters SET kee_mark = kee_mark + ? WHERE id = ?", [$keeMarkGain, $otherAttackerId]);
                    }
                    
                    // 每个玩家独立计算奖励
                    $otherDxGain = ExpHelper::calculateDxGain($otherAttacker, $npc);
                    $otherExpGain = ExpHelper::calculateCombatExpGain($otherAttacker, $npc);
                    
                    if ($otherDxGain > 0 || $otherExpGain > 0) {
                        $updateFields = [];
                        $updateParams = [];
                        
                        if ($otherDxGain > 0) {
                            $updateFields[] = "daoxing = daoxing + ?";
                            $updateParams[] = $otherDxGain;
                        }
                        if ($otherExpGain > 0) {
                            $updateFields[] = "combat_exp = combat_exp + ?";
                            $updateParams[] = $otherExpGain;
                        }
                        $updateParams[] = $otherAttackerId;
                        
                        if (!empty($updateFields)) {
                            $sql = "UPDATE characters SET " . implode(', ', $updateFields) . " WHERE id = ?";
                            Database::execute($sql, $updateParams);
                        }
                    }
                    
                    // 检查并标记杀怪任务为 done（需回NPC领奖）
                    $npcIdStr = $npc['npc_id'] ?? '';
                    QuestHelper::markQuestDone($otherAttackerId, QuestHelper::TYPE_KILL, $npcIdStr);
                    
                    // 通知其他参战玩家（非击杀者）
                    if ($otherAttackerId != $attackerId) {
                        require_once DAEMON_PATH . 'MessageDaemon.php';
                        $notifyMsg = HTML_HIGRN . '【战斗】' . HTML_NOR . ' ' . (!empty($combat['target_name']) ? $combat['target_name'] : '目标') . ' 被击败了！' . HTML_NOR;
                        if ($otherDxGain > 0) {
                            $notifyMsg .= ' 获得 ' . HTML_HIYEL . $otherDxGain . HTML_NOR . '点道行！';
                        }
                        if ($otherExpGain > 0) {
                            $notifyMsg .= ' 获得 ' . HTML_HIYEL . $otherExpGain . HTML_NOR . '点实战经验！';
                        }
                        MessageDaemon::sendToPlayer($otherAttackerId, $notifyMsg, 'combat');
                    }
                }
                
                // 当前攻击者的奖励（用于返回显示）
                $dxGain = 0;
                $expGain = 0;
                if ($npc) {
                    $dxGain = ExpHelper::calculateDxGain($attacker, $npc);
                    $expGain = ExpHelper::calculateCombatExpGain($attacker, $npc);
                }
                
                if ($dxGain > 0 || $expGain > 0) {
                    $message .= ' ' . HTML_HIGRN . '你击败了 ' . $targetName . '！' . HTML_NOR;
                    
                    if ($dxGain > 0) {
                        $dxDesc = ExpHelper::describeDx($attacker['daoxing'] + $dxGain);
                        $message .= ' 获得 ' . HTML_HIYEL . $dxGain . HTML_NOR . '点道行！';
                        $message .= "\n你的道行：{$dxDesc}";
                    }
                    
                    if ($expGain > 0) {
                        if ($dxGain > 0) {
                            $message .= "\n";
                        }
                        $expDesc = ExpHelper::describeExp($attacker['combat_exp'] + $expGain);
                        $message .= ' 获得 ' . HTML_HIYEL . $expGain . HTML_NOR . ' 点实战经验！';
                        $message .= "\n你的实战经验：{$expDesc}";
                    }
                } else {
                    $message .= ' ' . HTML_HIGRN . '你击败了 ' . $targetName . '！' . HTML_NOR;
                }
                
                log_game('COMBAT_KILL', "{$attacker['name']} 击败了 {$targetName}，获得道行 {$dxGain}，实战经验 {$expGain}（参战人数：" . count($allAttackers) . "）");
                
                // 检查并标记杀怪任务为 done（需回NPC领奖）
                $npcId = $npc['npc_id'] ?? '';
                $questResult = QuestHelper::markQuestDone($attackerId, QuestHelper::TYPE_KILL, $npcId);
                if ($questResult) {
                    $questRewardMsg = "\n【任务完成】杀怪任务目标已达成！快回去复命领奖吧。";
                    $message .= $questRewardMsg;
                    
                    // 显示颜色祥云升起效果
                    $stats = QuestHelper::getQuestStats($attackerId);
                    if ($stats && !empty($stats['quest_colors'])) {
                        $colors = json_decode($stats['quest_colors'], true) ?: [];
                        $lastColor = end($colors);
                        $colorNames = [
                            'red' => '红',
                            'green' => '绿',
                            'yellow' => '黄',
                            'blue' => '蓝',
                            'purple' => '紫',
                            'cyan' => '青',
                            'white' => '白',
                        ];
                        $colorName = $colorNames[$lastColor] ?? '白';
                        $cloudMessages = [
                            "一朵{$colorName}色祥云从你脚下升起，托着你缓缓飘上天空...",
                            "只见你头顶一朵{$colorName}色祥云升起，瑞气千条，霞光万道！",
                            "{$colorName}色祥云从天而降，笼罩在你身上，缓缓升起...",
                            "一阵仙风拂过，一朵{$colorName}色祥云出现在你脚下，托着你徐徐上升。",
                            "{$colorName}色祥云环绕你周身，缓缓升起，光芒四射！",
                        ];
                        $message .= "\n" . $cloudMessages[array_rand($cloudMessages)];
                    }
                }
            }
            
            // 注意：不在这里广播消息，由 handlePlayerDeath 统一处理
            // 避免重复广播和消息混乱
            
            // === 多目标战斗：检查是否有其他NPC还在攻击 ===
            $remainingTargets = $combat['multi_targets'] ?? [];
            if (!empty($remainingTargets)) {
                // 取出下一个NPC
                $nextNpc = array_shift($remainingTargets);
                $nextNpcId = intval($nextNpc['id']);
                $nextNpcName = $nextNpc['name'];
                
                // 计算新NPC的最大血量（使用 max_kee，还原原始LPC逻辑）
                $nextMaxHp = max(100, intval($nextNpc['max_kee'] ?? 100));
                
                // 重新初始化战斗（指向下一个NPC）
                self::insertActiveCombat($attackerId, $nextNpcId, 'npc', $nextMaxHp, false);
                $_SESSION["combat_{$attackerId}"] = [
                    'target_id' => $nextNpcId,
                    'target_type' => 'npc',
                    'target_name' => $nextNpcName,
                    'start_time' => $combat['start_time'] ?? time(),
                    'round' => $combat['round'] ?? 0,
                    'multi_targets' => $remainingTargets
                ];
                
                // 广播新NPC加入战斗
                $switchBroadcast = HTML_HIRED . '【战斗】' . HTML_NOR . ' ' . HTML_HICYN . $nextNpcName . HTML_NOR . ' 继续向你攻击！';
                require_once DAEMON_PATH . 'MessageDaemon.php';
                MessageDaemon::broadcastToRoom($attacker['current_room'] ?? '', $switchBroadcast, $attackerId);
                
                $message .= ' ' . HTML_HIRED . '  ' . $nextNpcName . ' 继续向你攻击！' . HTML_NOR;
                
                return [
                    'success' => true,
                    'damage' => $damage,
                    'killed' => true,
                    'target_name' => $targetName,
                    'target_type' => $targetType,
                    'next_target' => true,
                    'next_target_name' => $nextNpcName,
                    'daoxing_gain' => $dxGain ?? 0,
                    'combat_exp_gain' => $expGain ?? 0,
                    'message' => $message
                ];
            }
            
            self::endCombat($attackerId, true);
            return [
                'success' => true,
                'damage' => $damage,
                'killed' => true,
                'target_name' => $targetName,
                'target_type' => $targetType,
                'daoxing_gain' => $dxGain,
                'combat_exp_gain' => $expGain,
                'message' => $message
            ];
        }
        
        // === 多目标战斗：其他NPC同时攻击玩家 ===
        $multiAttackMsg = '';
        $multiTargets = $combat['multi_targets'] ?? [];
        if (!empty($multiTargets) && (!isset($combat['friendly']) || !$combat['friendly'])) {
            $totalMultiDamage = 0;
            foreach ($multiTargets as $mtNpc) {
                $mtNpcId = intval($mtNpc['id']);
                $mtNpcName = $mtNpc['name'];
                $mtExp = $mtNpc['combat_exp'] ?? 0;
                
                // 所有NPC都参与攻击（移除几率限制）
                // if (mt_rand(1, 100) > 70) continue;
                
                // 计算伤害（从配置读取参数）
                $mtCfg = self::loadConfig()['multi_target'];
                $mtBaseDamage = intval($mtExp / $mtCfg['exp_to_damage_div']) + $mtCfg['base_damage'];
                $mtDamage = mt_rand($mtCfg['rand_min'], $mtCfg['rand_max']) + $mtBaseDamage;
                $fluc = self::loadConfig()['damage']['fluctuation_pct'];
                $mtVariation = mt_rand(-$fluc, $fluc) / 100;
                $mtDamage = intval($mtDamage * (1 + $mtVariation));
                $mtDamage = max(1, $mtDamage);
                $totalMultiDamage += $mtDamage;
                
                // 生成攻击消息
                $mtLimb = self::getRandomLimb();
                $mtActionInfo = self::getNpcAttackActionText($mtNpcId);
                if ($mtActionInfo) {
                    $mtActionMsg = self::replaceVars($mtActionInfo['text'], $mtNpcName, '你', $mtLimb);
                    $mtDmgType = $mtActionInfo['damage_type'] ?? 'blunt';
                } else {
                    $mtActionMsg = $mtNpcName . '向你发起了攻击，瞄准你的' . $mtLimb;
                    $mtDmgType = 'blunt';
                }
                $mtDmgMsg = CombatMessages::getDamageMessage($mtDamage, $mtDmgType);
                $mtDmgMsg = self::replaceVars($mtDmgMsg, $mtNpcName, '你', $mtLimb);
                $multiAttackMsg .= ' ' . $mtActionMsg . '。' . $mtDmgMsg;
            }
            
            // 应用多目标伤害
            if ($totalMultiDamage > 0) {
                // 检查濒死状态
                $nearDeathTime = intval($attacker['near_death_time'] ?? 0);
                if ($nearDeathTime > 0 && $attacker['kee'] <= 0) {
                    self::triggerPlayerDeathForCriticalState($attackerId, 'near_death');
                    return [
                        'success' => true,
                        'damage' => $damage,
                        'player_damaged' => true,
                        'player_hp' => 0,
                        'killed' => true,
                        'output' => HTML_HIRED . '你已处于濒死状态，再次受伤导致真正死亡！' . HTML_NOR
                    ];
                }

                $newKee = max(0, $attacker['kee'] - $totalMultiDamage);
                Database::execute("UPDATE characters SET kee = ? WHERE id = ?", [$newKee, $attackerId]);
                
                // 累加玩家受到的伤害（用于飘血显示）
                $playerDamage += $totalMultiDamage;
                
            if ($newKee <= 0) {
                self::handlePlayerDefeated($attackerId, $combat);
                $isFriendly = isset($combat['friendly']) && $combat['friendly'];
                $deathMsg = $isFriendly 
                    ? HTML_HIRED . '你被击败了，昏了过去！' . HTML_NOR
                    : HTML_HIRED . '你被击败了，被彻底杀死了！' . HTML_NOR;
                return [
                    'success' => true,
                    'damage' => $damage,
                    'player_damaged' => true,
                    'player_hp' => 0,
                    'killed' => true,
                    'output' => $deathMsg
                ];
            }
            }
        }
        
        // === Riposte 反击判定（还原原始项目 combatd.c TYPE_RIPOSTE/TYPE_QUICK）===
        // 条件：普通攻击（非特殊招式）、最终伤害 < 1、防守方处于 guarding 状态
        $riposteMsg = '';
        $isRegularAttack = ($specialAction === null);
        if ($isRegularAttack && $damage < 1 && $defenderIsGuarding) {
            // 反击概率（从配置读取参数）
            $riposteCfg = self::loadConfig()['riposte'];
            $riposteType = 'riposte'; // TYPE_RIPOSTE
            if ($attackerCps < $riposteCfg['quick_attack_cps'] && mt_rand(1, 100) <= $riposteCfg['quick_attack_chance']) {
                $riposteType = 'quick'; // TYPE_QUICK
            }
            
            // 计算反击伤害（简化版）
            $riposteDamage = 0;
            if ($combat['target_type'] === 'npc' || $combat['target_type'] === 'yaoguai') {
                $riposteDamage = self::calculateNpcCounterDamage($combat);
            } else {
                // 玩家防守方反击
                $defChar = CharacterModel::find($combat['target_id']);
                if ($defChar) {
                    $defStr = AttributeHelper::queryStr($defChar);
                    $riposteDamage = max(1, $defStr + mt_rand($riposteCfg['def_str_rand_min'], $riposteCfg['def_str_rand_max']));
                }
            }
            
            if ($riposteDamage > 0 && (!isset($combat['friendly']) || !$combat['friendly'])) {
                // 应用反击伤害
                $newKee = max(0, $attacker['kee'] - $riposteDamage);
                Database::execute("UPDATE characters SET kee = ? WHERE id = ?", [$newKee, $attackerId]);
                $playerDamage += $riposteDamage;
                
                $defenderName = $combat['target_name'] ?? '目标';
                $riposteLimb = self::getRandomLimb();
                if ($riposteType === 'quick') {
                    $riposteMsg = ' ' . HTML_HIMAG . $defenderName . HTML_NOR . '趁隙发动快速攻击，击中你的' . $riposteLimb . '！';
                } else {
                    $riposteMsg = ' ' . HTML_HIMAG . $defenderName . HTML_NOR . '抓住破绽发动反击，击中你的' . $riposteLimb . '！';
                }
                $riposteDmgMsg = CombatMessages::getDamageMessage($riposteDamage, 'blunt');
                $riposteDmgMsg = self::replaceVars($riposteDmgMsg, $defenderName, '你', $riposteLimb);
                $riposteMsg .= $riposteDmgMsg;
                
            // 检查是否被打败
            if ($newKee <= 0) {
                self::handlePlayerDefeated($attackerId, $combat);
                $isFriendly = isset($combat['friendly']) && $combat['friendly'];
                $deathMsg = $isFriendly 
                    ? HTML_HIRED . '你被反击打败了，昏了过去！' . HTML_NOR
                    : HTML_HIRED . '你被反击打败了，被彻底杀死了！' . HTML_NOR;
                return [
                    'success' => true,
                    'damage' => $damage,
                    'player_damaged' => true,
                    'player_hp' => 0,
                    'killed' => true,
                    'output' => $deathMsg
                ];
            }
            } elseif (isset($combat['friendly']) && $combat['friendly']) {
                $defenderName = $combat['target_name'] ?? '目标';
                $riposteMsg = ' ' . HTML_HIMAG . $defenderName . HTML_NOR . '抓住破绽发动反击，但因为是切磋，并未造成实际伤害！';
            }
        }
        
        return [
            'success' => true,
            'damage' => $damage,
            'player_damage' => $playerDamage,
            'target_hp_percent' => $result['hp_percent'],
            'player_hp' => $attacker['kee'],
            'message' => $message . $counterMsg . $aiActionMsg . $riposteMsg . $multiAttackMsg
        ];
    }

    /**
     * 服务端驱动：检查并处理待处理的NPC攻击
     * 不依赖前端定时器，每次页面请求时自动检查
     * 还原LPC heart_beat机制：NPC有自己的心跳，每5秒攻击一次
     *
     * @param int $playerId 玩家角色ID
     * @param int $maxAttacks 最多补多少次攻击（防止玩家长时间离线后被秒）
     * @return array ['attacks' => int, 'total_damage' => int, 'messages' => string, 'killed' => bool]
     */
    public static function processPendingNpcAttacks(int $playerId, int $maxAttacks = 3): array {
        $combat = self::getCombatStatus($playerId);
        if (!$combat) {
            return ['attacks' => 0, 'total_damage' => 0, 'messages' => '', 'killed' => false];
        }

        $targetType = $combat['target_type'] ?? 'npc';
        $targetId = intval($combat['target_id'] ?? 0);

        // 仅NPC和妖怪会主动攻击
        if ($targetType !== 'npc' && $targetType !== 'yaoguai') {
            return ['attacks' => 0, 'total_damage' => 0, 'messages' => '', 'killed' => false];
        }

        // 检查NPC/妖怪是否被法宝束缚，如果是则清除战斗状态
        if (FabaoHelper::isTrapped($targetId)) {
            self::clearAllCombatForTarget($targetId, $targetType);
            return ['attacks' => 0, 'total_damage' => 0, 'messages' => '', 'killed' => false];
        }

        // 双重检查：如果目标已经死亡，立即清除战斗状态并返回
        $currentHp = self::getTargetCurrentHp($targetId, $targetType);
        if ($currentHp <= 0) {
            self::clearAllCombatForTarget($targetId, $targetType);
            return ['attacks' => 0, 'total_damage' => 0, 'messages' => '', 'killed' => false];
        }

        $now = time();

        // 兼容旧战斗状态：如果没有last_npc_attack_time，用start_time或当前时间减5秒
        if (isset($combat['last_npc_attack_time'])) {
            $lastAttackTime = intval($combat['last_npc_attack_time']);
        } elseif (isset($combat['start_time'])) {
            $lastAttackTime = intval($combat['start_time']);
        } else {
            $lastAttackTime = $now - 5;  // 默认5秒前，确保第一次就触发
        }

        $timePassed = $now - $lastAttackTime;

        // 还没到攻击时间
        if ($timePassed < 5) {
            return ['attacks' => 0, 'total_damage' => 0, 'messages' => '', 'killed' => false];
        }

        // 计算应该攻击多少次（每5秒一次，最多$maxAttacks次）
        $attackCount = intval(floor($timePassed / 5));
        if ($attackCount > $maxAttacks) {
            $attackCount = $maxAttacks;
        }

        if ($attackCount <= 0) {
            return ['attacks' => 0, 'total_damage' => 0, 'messages' => '', 'killed' => false];
        }

        $totalDamage = 0;
        $allMessages = '';
        $killed = false;

        for ($i = 0; $i < $attackCount; $i++) {
            $playerDamage = 0;
            $result = self::performNpcAttack($combat, $playerId, $playerDamage);

            $totalDamage += $playerDamage;
            $allMessages .= $result['msg'];

            if ($result['killed']) {
                $killed = true;
                break;
            }
        }

        // 更新上次攻击时间
        $combat['last_npc_attack_time'] = $lastAttackTime + $attackCount * 5;
        $_SESSION["combat_{$playerId}"] = $combat;

        return [
            'attacks' => $attackCount,
            'total_damage' => $totalDamage,
            'messages' => $allMessages,
            'killed' => $killed
        ];
    }

    /**
     * NPC独立攻击回合（还原LPC heart_beat机制）
     * 当玩家不主动攻击时，NPC也会按照自己的heart_beat独立发起攻击
     * 由前端定时器触发，不执行玩家攻击逻辑
     *
     * @param int $playerId 玩家角色ID（被攻击方）
     * @return array 攻击结果
     */
    public static function doNpcTurn(int $playerId): array {
        $combat = self::getCombatStatus($playerId);
        if (!$combat) {
            return ['success' => false, 'message' => ''];
        }

        $targetType = $combat['target_type'] ?? 'npc';
        $targetId = intval($combat['target_id'] ?? 0);

        // 仅NPC和妖怪会主动攻击
        if ($targetType !== 'npc' && $targetType !== 'yaoguai') {
            return ['success' => false, 'message' => ''];
        }

        // 检查NPC/妖怪是否被法宝束缚，如果是则清除战斗状态
        if (FabaoHelper::isTrapped($targetId)) {
            self::clearAllCombatForTarget($targetId, $targetType);
            return ['success' => false, 'message' => ''];
        }

        // 双重检查：如果目标已经死亡，立即清除战斗状态并返回
        $currentHp = self::getTargetCurrentHp($targetId, $targetType);
        if ($currentHp <= 0) {
            self::clearAllCombatForTarget($targetId, $targetType);
            return ['success' => false, 'message' => ''];
        }

        $playerDamage = 0;
        $message = '';

        // === NPC 主动攻击 ===
        $npcAttackResult = self::performNpcAttack($combat, $playerId, $playerDamage);
        $message .= $npcAttackResult['msg'];

        // 更新上次NPC攻击时间（服务端驱动与前端驱动同步）
        $combat['last_npc_attack_time'] = time();
        $_SESSION["combat_{$playerId}"] = $combat;

        if ($npcAttackResult['killed']) {
            return [
                'success' => true,
                'damage' => 0,
                'player_damage' => $playerDamage,
                'player_hp' => 0,
                'killed' => true,
                'message' => $message
            ];
        }

        // === 玩家自动还手（还原 LPC 双向 heart_beat 机制）===
        // NPC 攻击后，玩家自动还击一次（模拟玩家的 heart_beat）
        $playerCounterMsg = '';
        $npcDamageTaken = 0;
        if (!$npcAttackResult['killed'] && $currentHp > 0) {
            $playerCounterResult = self::performPlayerAutoCounter($playerId, $combat);
            $playerCounterMsg = $playerCounterResult['msg'];
            $npcDamageTaken = $playerCounterResult['damage'];
            $playerDamage += $playerCounterResult['player_damage'];
            
            if ($playerCounterResult['npc_killed']) {
                // 玩家自动还手击杀了NPC
                self::endCombat($playerId);
                $npcName = $combat['target_name'] ?? '目标';
                $killMsg = ' ' . HTML_HIRED . $npcName . '被你的还击击败了！' . HTML_NOR;
                return [
                    'success' => true,
                    'damage' => $npcDamageTaken,
                    'player_damage' => $playerDamage,
                    'player_hp' => $playerCounterResult['player_hp'],
                    'killed' => false,
                    'target_killed' => true,
                    'message' => $message . $playerCounterMsg . $killMsg
                ];
            }
        }

        // === NPC AI: 战斗回合特殊行为（施法/招式/台词）===
        $aiActionMsg = '';
        if ($targetType === 'npc' || $targetType === 'yaoguai') {
            $aiTable = ($targetType === 'yaoguai') ? 'mieyao_yaoguai' : 'npcs';
            $npcForAi = Database::queryOne("SELECT * FROM {$aiTable} WHERE id = ? LIMIT 1", [$targetId]);
            if ($npcForAi) {
                // 如果是妖怪，动态生成 chat_msg_combat（基于技能/法术/绝招配置）
                if ($targetType === 'yaoguai') {
                    $npcForAi = self::enrichYaoguaiAi($npcForAi);
                }
                
                $aiAction = NpcAiHelper::combatRoundAi($npcForAi);
                if ($aiAction) {
                    $aiActionMsg = ' ' . HTML_HIMAG . $aiAction['message'] . HTML_NOR;

                    // AI伤害加成
                    if (!empty($aiAction['damage_bonus']) && $aiAction['damage_bonus'] > 0) {
                        $isFriendly = isset($combat['friendly']) && $combat['friendly'];
                        if (!$isFriendly) {
                            $bonusDmg = intval($aiAction['damage_bonus']);
                            $currentKeeRow = Database::queryOne("SELECT kee FROM characters WHERE id = ? LIMIT 1", [$playerId]);
                            $currentKee = intval($currentKeeRow['kee'] ?? 0);
                            $newKee = max(0, $currentKee - $bonusDmg);
                            Database::execute("UPDATE characters SET kee = ? WHERE id = ?", [$newKee, $playerId]);
                            $playerDamage += $bonusDmg;

                        if ($newKee <= 0) {
                            self::handlePlayerDefeated($playerId, $combat);
                            $isFriendly2 = isset($combat['friendly']) && $combat['friendly'];
                            $deathMsg2 = $isFriendly2 
                                ? HTML_HIRED . "\n你被打败了，昏了过去！" . HTML_NOR
                                : HTML_HIRED . "\n你被打败了，被彻底杀死了！" . HTML_NOR;
                            return [
                                'success' => true,
                                'damage' => 0,
                                'player_damage' => $playerDamage,
                                'player_hp' => 0,
                                'killed' => true,
                                'message' => $message . $aiActionMsg . $deathMsg2
                            ];
                        }
                        }
                    }

                    // NPC恢复血量
                    if (!empty($aiAction['recovery']) && $aiAction['recovery'] > 0) {
                        $recovery = intval($aiAction['recovery']);
                        Database::execute(
                            "UPDATE active_combats SET target_current_hp = LEAST(target_max_hp, target_current_hp + ?) WHERE target_id = ? AND target_type = ?",
                            [$recovery, $targetId, $targetType]
                        );
                    }
                }
            }
        }

        // 读取最新玩家血量
        $playerRow = Database::queryOne("SELECT kee FROM characters WHERE id = ? LIMIT 1", [$playerId]);
        $currentPlayerHp = intval($playerRow['kee'] ?? 0);

        return [
            'success' => true,
            'damage' => $npcDamageTaken,
            'player_damage' => $playerDamage,
            'player_hp' => $currentPlayerHp,
            'killed' => false,
            'message' => $message . $playerCounterMsg . $aiActionMsg
        ];
    }

    /**
     * 玩家自动还手（还原 LPC 双向 heart_beat 机制）
     * 当 NPC 攻击玩家后，玩家自动还击一次
     * 使用与玩家主动攻击相同的 guarding 判定逻辑
     *
     * @param int $playerId 玩家角色ID
     * @param array $combat 战斗状态
     * @return array ['damage' => int, 'msg' => string, 'player_damage' => int, 'player_hp' => int, 'npc_killed' => bool]
     */
    private static function performPlayerAutoCounter(int $playerId, array $combat): array {
        $player = CharacterModel::find($playerId);
        if (!$player) {
            return ['damage' => 0, 'msg' => '', 'player_damage' => 0, 'player_hp' => 0, 'npc_killed' => false];
        }
        
        $targetName = $combat['target_name'] ?? '目标';
        
        // === 玩家 guarding 判定（还原 LPC 原始逻辑）===
        // 玩家也可能被 NPC 气势所慑，概率跳过还手
        $playerCor = AttributeHelper::queryCor($player);
        $npcCps = 10;
        if ($combat['target_type'] === 'npc') {
            $npcRow = Database::queryOne("SELECT cps FROM npcs WHERE id = ? LIMIT 1", [$combat['target_id']]);
            if ($npcRow) {
                $npcCps = max(1, intval($npcRow['cps'] ?? 10));
            }
        }
        
        $guardCfg = self::loadConfig()['npc_guarding'] ?? ['cps_multiplier' => 3, 'min_attack_chance' => 10, 'player_counter_chance' => 70];
        
        // === 玩家还手概率判定（双层：guarding + 基础概率）===
        // 第一层：guarding 判定（玩家胆识 vs NPC气势，还原LPC双向heart_beat）
        $playerAttackThreshold = $playerCor;
        $randMax = max(1, $npcCps * $guardCfg['cps_multiplier']) - 1;
        $roll = mt_rand(0, $randMax);
        $minAttackRoll = intval(($guardCfg['min_attack_chance'] / 100) * ($randMax + 1));
        $guardingPass = ($roll < $playerAttackThreshold || $roll < $minAttackRoll);
        
        // 第二层：基础还手概率（配置中的 player_counter_chance）
        $baseChancePass = (mt_rand(1, 100) <= $guardCfg['player_counter_chance']);
        
        $shouldAttack = $guardingPass && $baseChancePass;
        
        if (!$shouldAttack) {
            // 玩家本回合不还手
            $guardMsgs = [
                '你被' . $targetName . '的气势所慑，一时间竟忘了还手。',
                '你心头一凛，紧守门户，不敢贸然出击。',
                '你见' . $targetName . '攻势凌厉，暂且退后一步，凝神以待。',
                '你正要还手，' . $targetName . '却已收回招式，让你扑了个空。',
            ];
            return ['damage' => 0, 'msg' => ' ' . HTML_HIRED . $guardMsgs[array_rand($guardMsgs)] . HTML_NOR, 'player_damage' => 0, 'player_hp' => $player['kee'], 'npc_killed' => false];
        }
        
        // === 计算玩家伤害 ===
        $damage = self::calculateDamage($player, $combat);
        if ($damage <= 0) {
            return ['damage' => 0, 'msg' => '', 'player_damage' => 0, 'player_hp' => $player['kee'], 'npc_killed' => false];
        }
        
        // === 闪避判定 ===
        $dodgeResult = self::checkDodge($playerId, $combat, 0, $player);
        if ($dodgeResult['dodged']) {
            $dodgeMsg = ' ' . HTML_HIMAG . $targetName . HTML_NOR . '灵巧地闪开了你的还击！';
            return ['damage' => 0, 'msg' => $dodgeMsg, 'player_damage' => 0, 'player_hp' => $player['kee'], 'npc_killed' => false];
        }
        
        // === 应用伤害 ===
        $result = self::applyDamage($combat, $damage, $playerId);
        $limb = self::getRandomLimb();
        $weapon = self::getEquippedWeapon($playerId);
        $weaponName = $weapon ? ($weapon['name'] ?? '') : '';
        $actionMsg = CombatMessages::getActionMessage($weaponName ? 'weapon' : 'unarmed');
        $actionMsg = self::replaceVars($actionMsg, '你', $targetName, $limb, $weaponName);
        $dmgMsg = CombatMessages::getDamageMessage($damage, 'blunt');
        $dmgMsg = self::replaceVars($dmgMsg, '你', $targetName, $limb, $weaponName);
        
        $msg = ' ' . HTML_HIYEL . '【还手】' . HTML_NOR . ' ' . $actionMsg . '。' . $dmgMsg;
        
        // 检查目标是否被击杀
        $npcKilled = !empty($result['target_dead']);
        
        // 重新获取玩家血量
        $playerRow = Database::queryOne("SELECT kee FROM characters WHERE id = ? LIMIT 1", [$playerId]);
        $playerHp = intval($playerRow['kee'] ?? 0);
        
        return [
            'damage' => $damage,
            'msg' => $msg,
            'player_damage' => 0,
            'player_hp' => $playerHp,
            'npc_killed' => $npcKilled
        ];
    }

    /**
     * 逃跑
     * 参考: cmds/std/Go.php::doFlee()
     */
    public static function flee(int $charId): array {
        $combat = self::getCombatStatus($charId);
        if (!$combat) {
            return ['success' => false, 'message' => '你没有在战斗中！'];
        }
        
        $me = CharacterModel::find($charId);
        if (!$me) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        // 获取当前房间
        $room = RoomModel::load($me['current_area'], $me['current_room']);
        if (!$room) {
            return ['success' => false, 'message' => '无法获取房间信息。'];
        }
        
        // 检查no_flee
        if (isset($room['no_flee']) && $room['no_flee']) {
            return ['success' => false, 'message' => '这里无法逃跑。'];
        }
        
        // 获取出口
        $exits = RoomModel::getExits($room['id']);
        if (empty($exits)) {
            return ['success' => false, 'message' => '这里没有出口，无法逃跑！'];
        }
        
        $message = '看来该找机会逃跑了．．．';
        
        // 计算逃跑成功率（参考原始逻辑）
        $dodgeSkill = 0; // TODO: 从技能系统获取
        $kar = $me['cps'] ?? 10;
        
        $roll = mt_rand(0, intval($dodgeSkill / 10) + $kar);
        
        if ($roll < 10) {
            // 逃跑失败
            return [
                'success' => true,
                'fled' => false,
                'message' => $message . "\n你逃跑失败。"
            ];
        }
        
        // 逃跑成功，随机选择方向
        $randomIndex = array_rand($exits);
        $selectedExit = $exits[$randomIndex];
        $dir = $selectedExit['direction'];  // 使用direction字段
        
        // 将英文方向转换为中文
        $dirMap = [
            'north' => '北',
            'south' => '南',
            'west' => '西',
            'east' => '东',
            'northeast' => '东北',
            'northwest' => '西北',
            'southeast' => '东南',
            'southwest' => '西南',
            'up' => '上',
            'down' => '下',
            'enter' => '里',
            'out' => '外',
            'northup' => '北上',
            'southup' => '南上',
            'eastup' => '东上',
            'westup' => '西上',
            'northdown' => '北下',
            'southdown' => '南下',
            'eastdown' => '东下',
            'westdown' => '西下',
            'northeastup' => '东北',
            'northwestup' => '西北',
            'southeastup' => '东南',
            'southwestup' => '西南',
        ];
        $dirChinese = $dirMap[$dir] ?? $dir;  // 如果有映射则使用中文，否则保持原样
        
        $targetExit = $selectedExit;
        
        // 更新位置
        $newArea = $targetExit['target_area'] ?: $me['current_area'];
        $newRoom = $targetExit['target_room'];
        
        $sql = "UPDATE characters SET current_area = ?, current_room = ? WHERE id = ?";
        Database::execute($sql, [$newArea, $newRoom, $charId]);
        
        // 结束战斗
        self::endCombat($charId);
        
        // 获取目标房间名称
        // 注意：rooms表中的room_id字段存储的是完整路径（如 city/zhuque-s2）
        // 所以需要拼接 newArea 和 newRoom
        $fullRoomId = $newArea . '/' . $newRoom;
        $targetRoom = RoomModel::load($newArea, $fullRoomId);
        $roomName = $targetRoom ? $targetRoom['name'] : '未知地方';
        
        // 生成逃跑消息（参考原始LPC go.c:85-87 战斗中的特殊移动消息）
        $playerName = $me['name'];
        $oldRoomId = $me['current_area'] . '/' . $me['current_room'];
        $newRoomId = "{$newArea}/{$newRoom}";
        
        // 自己看到的消息
        $output = $message . "\n你往{$dirChinese}落荒而逃，跌跌撞撞到了{$roomName}。";
        
        // 离开房间广播（原始LPC: mout = "往{方向}落荒而逃了。"）
        $leaveMessage = "{$playerName}往{$dirChinese}落荒而逃了。";
        
        // 到达新房间广播（原始LPC: min = "跌跌撞撞地跑了过来，模样有些狼狈。"）
        $arriveMessage = "{$playerName}跌跌撞撞地跑了过来，模样有些狼狈。";
        
        log_game('COMBAT_FLEE', "{$playerName} 从战斗中逃跑至 {$roomName}");
        
        return [
            'success' => true,
            'type' => 'flee_success',
            'fled' => true,
            'message' => $output,
            'new_room_id' => $newRoomId,
            'direction' => $dir,
            'old_room' => ['room_id' => $oldRoomId],
            'new_room' => ['room_id' => $newRoomId, 'name' => $roomName],
            'leave_message' => $leaveMessage,
            'arrive_message' => $arriveMessage
        ];
    }
    
    /**
     * 结束战斗
     * @param int $charId 角色ID
     * @param bool|null $playerWon 玩家是否获胜（null表示未知）
     */
    public static function endCombat(int $charId, ?bool $playerWon = null): void {
        log_game('COMBAT_END_START', "endCombat called for charId={$charId}, playerWon={$playerWon}");
        $combat = self::getCombatStatus($charId);
        
        // 清理切磋NPC血量
        if ($combat && $combat['target_type'] === 'npc') {
            $npcHpKey = "npc_hp_friendly_{$combat['target_id']}";
            unset($_SESSION[$npcHpKey]);

            // 恢复砍柴道士的原始战斗经验（陪练机制）
            $npcId = intval($combat['target_id'] ?? 0);
            $originalExpKey = "kancai_original_exp_{$npcId}";
            if (isset($_SESSION[$originalExpKey])) {
                $originalExp = intval($_SESSION[$originalExpKey]);
                Database::execute(
                    "UPDATE npcs SET combat_exp = ? WHERE id = ?",
                    [$originalExp, $npcId]
                );
                unset($_SESSION[$originalExpKey]);
                log_game('KANCAI_SPARRING_END', "砍柴道士陪练结束，经验恢复为 {$originalExp}");
            }
            
            // ★ 木人战斗结束后恢复默认属性（还原原始 muren.c 设计）
            require_once DAEMON_PATH . '/../helpers/MurenHelper.php';
            if (MurenHelper::isMurenById($npcId)) {
                MurenHelper::restoreDefaults($npcId);
                log_game('MUREN_RESTORE', "木人战斗结束，恢复默认属性 npc_id={$npcId}");
            }
        }
        
        // 清理旧格式虚拟血量（兼容旧战斗）
        unset($_SESSION["virtual_hp_{$charId}"]);
        unset($_SESSION["virtual_hp_player_{$charId}"]);
        
        // 获取战斗系统信息（在删除之前）
        $combatSystem = $combat['combat_system'] ?? null;
        $rankLevel = $combat['rank_level'] ?? null;
        $targetId = $combat['target_id'] ?? null;
        
        // 从 active_combats 表删除当前角色的战斗记录
        Database::execute("DELETE FROM active_combats WHERE char_id = ?", [$charId]);
        
        // Fallback: 如果Session中没有combat_system和rank_level，尝试从数据库查询目标NPC是否属于蟠桃会或武状元替身
        if (!$combatSystem && !$rankLevel && $targetId && $combat['target_type'] === 'npc') {
            $avatar = Database::queryOne(
                "SELECT 'pantaohui' AS `system`, rank_level FROM pantaohui_avatars WHERE npc_id = ?",
                [$targetId]
            );
            if ($avatar && !empty($avatar['rank_level'])) {
                $combatSystem = 'pantaohui';
                $rankLevel = $avatar['rank_level'];
                log_game('COMBAT_END_FALLBACK', "Fallback: Found pantaohui avatar for npc_id={$targetId}, rankLevel={$rankLevel}");
            } else {
                $wzAvatar = Database::queryOne(
                    "SELECT rank_level, rank_position FROM wuzhuangyuan_avatars WHERE npc_id = ?",
                    [$targetId]
                );
                if ($wzAvatar && !empty($wzAvatar['rank_level'])) {
                    $combatSystem = 'wuzhuangyuan';
                    $rankLevel = "{$wzAvatar['rank_level']}_{$wzAvatar['rank_position']}";
                    log_game('COMBAT_END_FALLBACK', "Fallback: Found wuzhuangyuan avatar for npc_id={$targetId}, rankLevel={$rankLevel}");
                } else {
                    $xxAvatar = Database::queryOne(
                        "SELECT rank_level FROM xingxiu_avatars WHERE npc_id = ?",
                        [$targetId]
                    );
                    if ($xxAvatar && !empty($xxAvatar['rank_level'])) {
                        $combatSystem = 'xingxiu';
                        $rankLevel = $xxAvatar['rank_level'];
                        log_game('COMBAT_END_FALLBACK', "Fallback: Found xingxiu avatar for npc_id={$targetId}, rankLevel={$rankLevel}");
                    }
                }
            }
        }
        
        // 调用战斗系统回调
        log_game('COMBAT_END_CALLBACK', "combatSystem={$combatSystem}, rankLevel={$rankLevel}, targetId={$targetId}, playerWon={$playerWon}");
        if ($combatSystem && $rankLevel && $targetId) {
            if ($playerWon == true) {
                log_game('COMBAT_END_CALLBACK_TRIGGER', "Triggering callback: winner={$charId}, loser={$targetId}, system={$combatSystem}, rank={$rankLevel}");
                self::triggerCombatCallback($charId, $targetId, $combatSystem, $rankLevel);
            } elseif ($playerWon == false) {
                log_game('COMBAT_END_CALLBACK_TRIGGER', "Triggering callback: winner={$targetId}, loser={$charId}, system={$combatSystem}, rank={$rankLevel}");
                self::triggerCombatCallback($targetId, $charId, $combatSystem, $rankLevel);
            }
        }
        
        // 清除Session战斗状态
        unset($_SESSION["combat_{$charId}"]);
        
        // 清除预选招式和武器记录
        unset($_SESSION["combat_action_{$charId}"]);
        unset($_SESSION["combat_weapon_{$charId}"]);
    }
    
    /**
     * 触发战斗系统回调
     */
    private static function triggerCombatCallback(int $winnerId, int $loserId, string $combatSystem, string $rankLevel): void {
        log_game('TRIGGER_CALLBACK_START', "triggerCombatCallback called: winnerId={$winnerId}, loserId={$loserId}, combatSystem={$combatSystem}, rankLevel={$rankLevel}");
        try {
            switch ($combatSystem) {
                case 'pantaohui':
                    require_once DAEMON_PATH . 'PantaohuiHandler.php';
                    log_game('TRIGGER_CALLBACK_PANTAOHUI', "Calling PantaohuiHandler::onCombatResult...");
                    PantaohuiHandler::onCombatResult($winnerId, $loserId, $rankLevel);
                    log_game('TRIGGER_CALLBACK_PANTAOHUI', "PantaohuiHandler::onCombatResult completed");
                    break;
                case 'wuzhuangyuan':
                    require_once DAEMON_PATH . 'WuzhuangyuanHandler.php';
                    $parts = explode('_', $rankLevel);
                    $realRankLevel = $parts[0] ?? $rankLevel;
                    $position = intval($parts[1] ?? 0);
                    WuzhuangyuanHandler::onCombatResult($winnerId, $loserId, $realRankLevel, $position);
                    break;
                case 'xingxiu':
                    require_once DAEMON_PATH . 'XingxiuHandler.php';
                    log_game('TRIGGER_CALLBACK_XINGXIU', "Calling XingxiuHandler::onCombatResult...");
                    XingxiuHandler::onCombatResult($winnerId, $loserId, $rankLevel);
                    log_game('TRIGGER_CALLBACK_XINGXIU', "XingxiuHandler::onCombatResult completed");
                    break;
                case 'longzhu':
                    require_once DAEMON_PATH . 'LongzhuHandler.php';
                    log_game('TRIGGER_CALLBACK_LONGZHU', "Calling LongzhuHandler::onDragonKill...");
                    LongzhuHandler::onDragonKill($winnerId, $loserId, $rankLevel);
                    log_game('TRIGGER_CALLBACK_LONGZHU', "LongzhuHandler::onDragonKill completed");
                    break;
            }
        } catch (Exception $e) {
            log_game('COMBAT_CALLBACK_ERROR', "战斗系统回调失败: {$combatSystem}, error: {$e->getMessage()}");
            log_game('COMBAT_CALLBACK_ERROR', "Stack trace: " . $e->getTraceAsString());
        }
    }
    
    /**
     * 检查是否在战斗中
     */
    public static function isInCombat(int $charId): bool {
        // 快速检查Session标记
        if (isset($_SESSION["combat_{$charId}"])) {
            return true;
        }
        // 兼容：检查DB是否有残留记录
        $row = Database::queryOne("SELECT id, started_at FROM active_combats WHERE char_id = ? LIMIT 1", [$charId]);
        if ($row === null) {
            return false;
        }
        
        // 检查记录是否过期（超过30分钟视为残留记录）
        $startedAt = strtotime($row['started_at'] ?? '');
        if ($startedAt && (time() - $startedAt > 1800)) {
            // 过期的残留记录，自动清理
            Database::execute("DELETE FROM active_combats WHERE id = ?", [$row['id']]);
            return false;
        }
        
        return true;
    }
    
    /**
     * 检查目标玩家状态，判断是否可以发起战斗
     * @param array $target 目标玩家数据
     * @return array ['can_fight' => bool, 'message' => string]
     */
    public static function checkTargetPlayerState(array $target): array {
        if (!empty($target['sleep_state']) && $target['sleep_state'] == 1) {
            return ['can_fight' => false, 'message' => $target['name'] . '正在睡觉，你怎么忍心打扰？', 'wake' => 'sleep'];
        }
        
        if (!empty($target['unconscious_state']) && $target['unconscious_state'] == 1) {
            return ['can_fight' => false, 'message' => $target['name'] . '已经昏迷了，无需再攻击。', 'wake' => 'unconscious'];
        }
        
        if (!empty($target['daze_state']) && $target['daze_state'] == 1) {
            return ['can_fight' => false, 'message' => $target['name'] . '正在发呆，无法战斗。', 'wake' => 'daze'];
        }
        
        return ['can_fight' => true, 'message' => '', 'wake' => ''];
    }
    
    /**
     * 惊醒处于特殊状态的玩家
     * @param int $targetId 目标ID
     * @param int $attackerId 攻击者ID
     * @param string $stateType 状态类型: sleep/unconscious/daze
     * @return array 惊醒结果
     */
    public static function wakeTargetFromState(int $targetId, int $attackerId, string $stateType): array {
        $target = CharacterModel::find($targetId);
        $attacker = CharacterModel::find($attackerId);
        
        if (!$target || !$attacker) {
            return ['success' => false, 'message' => '目标或攻击者不存在'];
        }
        
        require_once DAEMON_PATH . 'MessageDaemon.php';
        require_once __DIR__ . '/../commands/sleep.php';
        require_once __DIR__ . '/../commands/faint.php';
        require_once __DIR__ . '/../commands/daze.php';
        
        $wakeMessages = [];
        
        switch ($stateType) {
            case 'sleep':
                if (!empty($target['sleep_state']) && $target['sleep_state'] == 1) {
                    wakeup_player($targetId);
                    $wakeMessages[] = [
                        'self' => "<span style='color: #FF6347;'>你被{$attacker['name']}的举动惊醒，慌忙中站了起来！</span>",
                        'room' => "<span style='color: #FF6347;'>{$target['name']}被惊醒，慌忙从睡梦中跳了起来！</span>",
                        'attacker' => "<span style='color: #90EE90;'>你把{$target['name']}从睡梦中惊醒了！</span>"
                    ];
                }
                break;
                
            case 'unconscious':
                if (!empty($target['unconscious_state']) && $target['unconscious_state'] == 1) {
                    cmd_wake($attackerId, $target['name']);
                    $wakeMessages[] = [
                        'self' => "<span style='color: #FF6347;'>你在{$attacker['name']}的攻击下恢复了意识，勉强站起身来！</span>",
                        'room' => "<span style='color: #FF6347;'>{$target['name']}在{$attacker['name']}的攻击下慢慢恢复了意识！</span>",
                        'attacker' => "<span style='color: #90EE90;'>你的攻击让{$target['name']}从昏迷中苏醒！</span>"
                    ];
                }
                break;
                
            case 'daze':
                if (!empty($target['daze_state']) && $target['daze_state'] == 1) {
                    cmd_wake($attackerId, $target['name']);
                    $wakeMessages[] = [
                        'self' => "<span style='color: #FF6347;'>{$attacker['name']}的举动让你猛然惊醒，回过神来！</span>",
                        'room' => "<span style='color: #FF6347;'>{$target['name']}在{$attacker['name']}的干扰下猛然惊醒！</span>",
                        'attacker' => "<span style='color: #90EE90;'>你把{$target['name']}从发呆中惊醒了！</span>"
                    ];
                }
                break;
        }
        
        return [
            'success' => !empty($wakeMessages),
            'messages' => $wakeMessages
        ];
    }
    
    /**
     * 获取战斗状态（合并Session与DB数据）
     */
    public static function getCombatStatus(int $charId): ?array {
        // 先检查Session中的基础战斗数据
        $sessionCombat = $_SESSION["combat_{$charId}"] ?? null;
        if (!$sessionCombat) {
            // Session无记录，尝试从DB恢复（容错）
            $row = Database::queryOne(
                "SELECT target_id, target_type, target_current_hp, target_max_hp, is_friendly, combo_count 
                 FROM active_combats WHERE char_id = ? LIMIT 1",
                [$charId]
            );
            if (!$row) {
                return null;
            }
            // 从DB重建并写回session（关键：防止 doAttack 的 round++ 创建残缺session）
            $sessionCombat = [
                'target_id' => intval($row['target_id']),
                'target_type' => $row['target_type'],
                'target_name' => '',
                'start_time' => time(),
                'round' => 0,
                'friendly' => (bool)$row['is_friendly'],
                'target_current_hp' => intval($row['target_current_hp']),
                'target_max_hp' => intval($row['target_max_hp']),
                'combo_count' => intval($row['combo_count']),
                'combat_system' => null,
                'rank_level' => null,
                '_db_synced_at' => time(),
            ];
            $_SESSION["combat_{$charId}"] = $sessionCombat;
            return $sessionCombat;
        }
        
        // Session有记录，检查是否残缺（可能被 doAttack 的 round++ 创建了不完整记录）
        if (!isset($sessionCombat['target_id']) || !isset($sessionCombat['target_type'])) {
            $row = Database::queryOne(
                "SELECT target_id, target_type, target_current_hp, target_max_hp, is_friendly, combo_count 
                 FROM active_combats WHERE char_id = ? LIMIT 1",
                [$charId]
            );
            if ($row) {
                $sessionCombat['target_id'] = intval($row['target_id']);
                $sessionCombat['target_type'] = $row['target_type'];
                $sessionCombat['target_name'] = '';
                $sessionCombat['target_current_hp'] = intval($row['target_current_hp']);
                $sessionCombat['target_max_hp'] = intval($row['target_max_hp']);
                $sessionCombat['combo_count'] = intval($row['combo_count']);
                if (!isset($sessionCombat['friendly'])) {
                    $sessionCombat['friendly'] = (bool)$row['is_friendly'];
                }
                if (!isset($sessionCombat['round'])) {
                    $sessionCombat['round'] = 0;
                }
                if (!isset($sessionCombat['start_time'])) {
                    $sessionCombat['start_time'] = time();
                }
                $sessionCombat['_db_synced_at'] = time();
                $_SESSION["combat_{$charId}"] = $sessionCombat;
            }
        } else {
            // Session完整：节流DB同步（每2秒最多同步一次，避免每次请求都查DB）
            $now = time();
            $lastSynced = $sessionCombat['_db_synced_at'] ?? 0;
            if ($now - $lastSynced >= 2) {
                $row = Database::queryOne(
                    "SELECT target_current_hp, target_max_hp, is_friendly, combo_count 
                     FROM active_combats WHERE char_id = ? LIMIT 1",
                    [$charId]
                );
                
                if ($row) {
                    $sessionCombat['target_current_hp'] = intval($row['target_current_hp']);
                    $sessionCombat['target_max_hp'] = intval($row['target_max_hp']);
                    $sessionCombat['combo_count'] = intval($row['combo_count']);
                    
                    if (!isset($sessionCombat['friendly'])) {
                        $sessionCombat['friendly'] = (bool)$row['is_friendly'];
                    }
                    $sessionCombat['_db_synced_at'] = $now;
                    $_SESSION["combat_{$charId}"] = $sessionCombat;
                } else {
                    // DB中没有记录了，说明战斗已经结束（可能被其他玩家打死了目标）
                    unset($_SESSION["combat_{$charId}"]);
                    return null;
                }
            }
        }
        
        return $sessionCombat;
    }
    
    // ==================== active_combats 表操作方法 ====================
    
    /**
     * 插入战斗记录到 active_combats 表（多人共享HP）
     * 如果同一目标已有其他玩家在打，从现有记录获取当前HP
     */
    public static function insertActiveCombat(int $charId, int $targetId, string $targetType, int $maxHp, bool $isFriendly, ?string $combatSystem = null, ?string $rankLevel = null): void {
        // 先清理该角色的旧战斗记录（防止残留）
        Database::execute("DELETE FROM active_combats WHERE char_id = ?", [$charId]);
        
        // 检查是否有其他玩家已经在攻击同一目标
        $existingCombat = Database::queryOne(
            "SELECT target_current_hp FROM active_combats WHERE target_id = ? AND target_type = ? LIMIT 1",
            [$targetId, $targetType]
        );
        
        if ($existingCombat) {
            // 多人共享：使用现有血量
            $currentHp = intval($existingCombat['target_current_hp']);
        } else {
            // 第一个攻击者：血量=最大血量
            $currentHp = $maxHp;
        }
        
        Database::execute(
            "INSERT INTO active_combats (char_id, target_id, target_type, target_current_hp, target_max_hp, is_friendly) 
             VALUES (?, ?, ?, ?, ?, ?)",
            [$charId, $targetId, $targetType, $currentHp, $maxHp, $isFriendly ? 1 : 0]
        );
    }
    
    /**
     * 获取目标当前血量（从 active_combats 表读取共享HP）
     */
    public static function getTargetCurrentHp(int $targetId, string $targetType): int {
        $row = Database::queryOne(
            "SELECT target_current_hp FROM active_combats WHERE target_id = ? AND target_type = ? LIMIT 1",
            [$targetId, $targetType]
        );
        return $row ? intval($row['target_current_hp']) : 0;
    }
    
    /**
     * 获取所有攻击同一目标的玩家ID列表
     */
    public static function getAllAttackers(int $targetId, string $targetType): array {
        $rows = Database::queryAll(
            "SELECT char_id FROM active_combats WHERE target_id = ? AND target_type = ?",
            [$targetId, $targetType]
        );
        return array_column($rows, 'char_id');
    }
    
    /**
     * 清除目标的所有战斗记录（目标死亡时调用）
     */
    private static function clearAllCombatForTarget(int $targetId, string $targetType): void {
        // 获取所有参战玩家
        $attackers = self::getAllAttackers($targetId, $targetType);
        
        // 清除每个玩家的Session战斗状态
        foreach ($attackers as $attackerId) {
            unset($_SESSION["combat_{$attackerId}"]);
            unset($_SESSION["combat_action_{$attackerId}"]);
            unset($_SESSION["combat_weapon_{$attackerId}"]);
            unset($_SESSION["virtual_hp_{$attackerId}"]);
            unset($_SESSION["virtual_hp_player_{$attackerId}"]);
        }
        // 清理切磋NPC血量
        unset($_SESSION["npc_hp_friendly_{$targetId}"]);
        
        // 删除所有相关DB记录
        Database::execute(
            "DELETE FROM active_combats WHERE target_id = ? AND target_type = ?",
            [$targetId, $targetType]
        );
    }
    
    // ==================== 私有方法 ====================
    
    /**
     * 闪避判定
     * === 技能属性集成 ===
     * 基于防御者的 dodge 技能映射和 base_dodge 配置
     * 
     * @return array ['dodged' => bool]
     */
    /**
     * 获取NPC的技能等级（从 npc_skills 表）
     * 参考原始项目 ob->query_skill(skill)
     */
    private static function getNpcSkillLevel(int $npcDbId, string $skillId): int {
        $sql = "SELECT skill_level FROM npc_skills WHERE npc_id = ? AND skill_name = ? LIMIT 1";
        $row = Database::queryOne($sql, [$npcDbId, $skillId]);
        return $row ? intval($row['skill_level']) : 0;
    }

    /**
     * 获取NPC的技能映射（从 npc_skill_maps 表）
     * 参考原始项目 ob->query_skill_mapped(skill)
     */
    private static function getNpcSkillMapped(int $npcDbId, string $skillType): ?string {
        $sql = "SELECT mapped_skill FROM npc_skill_maps WHERE npc_id = ? AND base_skill = ? LIMIT 1";
        $row = Database::queryOne($sql, [$npcDbId, $skillType]);
        return $row ? $row['mapped_skill'] : null;
    }

    /**
     * 获取玩家的映射技能等级（还原原始项目等级计算逻辑）
     */
    private static function getMappedSkillLevelForPlayer(int $charId, string $skillType): int {
        $mappedSkill = SkillManager::querySkillMapped($charId, $skillType);
        if (!$mappedSkill) {
            return 0;
        }
        return SkillManager::querySkill($charId, $mappedSkill, true);
    }

    /**
     * 根据门派key获取门派类型（fighter/magician）
     * 还原原始项目 eff_skill_level 中的门派分类逻辑
     */
    private static function getGuildType(string $family): string {
        // 战斗门派：以物理攻击和防御技能为主
        $fighterFamilies = ['jiangjunfu', 'huoyun', 'xueshan', 'wzg'];
        // 法术门派：以法术技能为主
        $magicianFamilies = ['lingtai', 'nanhai', 'moon', 'yanluofu'];
        
        if (in_array($family, $fighterFamilies)) {
            return 'fighter';
        } elseif (in_array($family, $magicianFamilies)) {
            return 'magician';
        }
        return '';
    }

    /**
     * 计算技能威力 (skill_power)
     * 完全还原原始项目 combatd.c::skill_power()
     * 公式：(level³/3) × (sen/max_sen) + combat_exp
     * 
     * @param array $charData 角色/NPC数据（需含 id, sen, max_sen, combat_exp）
     * @param string $skill 技能ID
     * @param int $usage 技能使用类型 (1=攻击, 2=防御)
     * @param bool $isNpc 是否为NPC
     * @return int 技能威力
     */
    private static function calcSkillPower(array $charData, string $skill, int $usage = 1, bool $isNpc = false): int {
        $charId = intval($charData['id'] ?? 0);
        if ($charId <= 0) return 0;

        // 获取技能等级
        if ($isNpc) {
            $level = self::getNpcSkillLevel($charId, $skill);
            // NPC无技能时使用combat_exp的一半作为默认威力
            if ($level <= 0) {
                return max(1, intval(($charData['combat_exp'] ?? 0) / 2));
            }
        } else {
            $level = SkillManager::querySkill($charId, $skill);
        }

        // 如果无技能，返回 combat_exp / 2（与原始项目一致）
        if ($level <= 0) {
            return max(1, intval(($charData['combat_exp'] ?? 0) / 2));
        }

        // === 门派修正（eff_skill_level）===
        // 还原原始项目 std/char.c::eff_skill_level() 门派加成逻辑
        // 战斗门派（fighter）：攻击/防御技能获得加成
        // 法术门派（magician）：攻击/防御技能受到削弱
        $family = $charData['family'] ?? '';
        $guildType = self::getGuildType($family);
        if ($guildType && ($usage == 1 || $usage == 2)) {
            if ($guildType === 'fighter') {
                // 战斗门派加成
                if ($level > 300) {
                    $level += 35 + intval(($level - 300) * 2 / 5);
                } elseif ($level > 200) {
                    $level += 15 + intval(($level - 200) / 5);
                } elseif ($level > 100) {
                    $level += 5 + intval(($level - 100) / 10);
                } else {
                    $level += intval($level / 20);
                }
            } elseif ($guildType === 'magician') {
                // 法术门派削弱
                $level -= intval($level / 10);
            }
        }

        // 应用临时加成（原始项目 query_temp("apply/attack") 或 apply/defense）
        if (!$isNpc) {
            if ($usage == 1) {
                $level += intval($charData['temp_attack'] ?? 0);
            } else {
                $level += intval($charData['temp_defense'] ?? 0);
            }
        }

        // ★ 装备属性加成：dodge/parry 技能受装备的 dodge_bonus/parry_bonus 影响
        // 还原原始 LPC 项目 query_temp("apply/dodge") 和 query_temp("apply/parry")
        if (!$isNpc && $usage == 2) {
            $applyData = $_SESSION["char_apply_{$charId}"] ?? [];
            if ($skill === 'dodge' || $skill === 'parry') {
                $applyBonus = intval($applyData[$skill] ?? 0);
                if ($applyBonus > 0) {
                    // 装备加成直接加到技能等级上（与原始项目行为一致）
                    $level += $applyBonus;
                }
            }
        }

        // 基础威力：level³ / 3
        $power = intval(($level * $level * $level) / 3);

        // 精神状态修正（原始项目 sen/max_sen）
        $maxSen = max(1, intval($charData['max_sen'] ?? 100));
        $sen = intval($charData['sen'] ?? $maxSen);
        if ($power > 100000) {
            $power = intval($power / $maxSen * $sen);
        } else {
            $power = intval($power * $sen / $maxSen);
        }

        // 加上战斗经验
        $power += intval($charData['combat_exp'] ?? 0);

        return max(1, $power);
    }

    /**
     * 闪避判定 - 还原原始项目 AP/(AP+DP) 概率比值制
     * 参考: combatd.c::do_attack() 第(3)步
     * 
     * 公式: random(AP + DP) < mod_val → 闪避成功
     * mod_val = (100 + dodge_mod) × DP / 100
     * 
     * @return array ['dodged' => bool]
     */
    private static function checkDodge(int $attackerId, array $combat, int $dodgeMod = 0, ?array $attacker = null): array {
        $targetId = $combat['target_id'] ?? 0;
        $targetType = $combat['target_type'] ?? 'npc';
        $isNpc = ($targetType === 'npc' || $targetType === 'yaoguai');

        // === 计算攻击者 AP（attack power）===
        // 获取攻击者武器技能
        $attackSkill = 'unarmed';
        if ($attacker) {
            $equippedWeapon = self::getEquippedWeapon($attackerId);
            if ($equippedWeapon) {
                $attackSkill = $equippedWeapon['skill_type'] ?? 'unarmed';
            }
        }
        $ap = self::calcSkillPower($attacker ?? [], $attackSkill, 1, false);

        // === 计算防御者 DP（dodge power）===
        // 还原原始项目 combatd.c: dp = skill_power(victim, "dodge", SKILL_USAGE_DEFENSE)
        $victimDodgePower = 0; // 对应原始项目 victim_action["dodge_power"]

        if ($isNpc) {
            $npcData = Database::queryOne("SELECT * FROM npcs WHERE id = ? LIMIT 1", [$targetId]);
            if (!$npcData) {
                return ['dodged' => false];
            }
            // 尝试获取NPC的dodge技能等级
            $npcDodgeLevel = self::getNpcSkillLevel($targetId, 'dodge');
            if ($npcDodgeLevel > 0) {
                $dp = self::calcSkillPower($npcData, 'dodge', 2, true);
            } else {
                // NPC无dodge技能时，使用combat_exp/2作为DP
                $dp = max(1, intval(($npcData['combat_exp'] ?? 0) / 2));
            }

            // 获取NPC闪避技能映射的 dodge_power（还原原始项目 victim_action["dodge_power"]）
            $mappedDodge = self::getNpcSkillMapped($targetId, 'dodge');
            if ($mappedDodge) {
                $dodgeConfig = SkillManager::getSkillConfig($mappedDodge);
                if ($dodgeConfig && isset($dodgeConfig['base_dodge'])) {
                    $victimDodgePower = intval($dodgeConfig['base_dodge']);
                }
            }
        } else {
            $defender = CharacterModel::find($targetId);
            if (!$defender) {
                return ['dodged' => false];
            }
            $dp = self::calcSkillPower($defender, 'dodge', 2, false);

            // 获取玩家闪避技能映射的 dodge_power
            $mappedDodge = SkillManager::querySkillMapped($targetId, 'dodge');
            if ($mappedDodge) {
                $dodgeConfig = SkillManager::getSkillConfig($mappedDodge);
                if ($dodgeConfig && isset($dodgeConfig['base_dodge'])) {
                    $victimDodgePower = intval($dodgeConfig['base_dodge']);
                }
            }
        }

        // === busy减防：防御者忙碌时闪避威力/3（原始项目 victim->is_busy() dp /= 3）===
        if ($isNpc) {
            $npcDeathKey = "npc_busy_" . $targetId;
            if (isset($_SESSION[$npcDeathKey]) && $_SESSION[$npcDeathKey] > time()) {
                $dp = intval($dp / 3);
            }
        } else {
            if (is_player_busy($targetId)) {
                $dp = intval($dp / 3);
            }
        }
        if ($dp < 0) $dp = 0;

        // === 计算 mod_val（还原原始项目 combatd.c 第341-347行）===
        // mod_val = victim_action["dodge_power"] + action["dodge"]
        // mod_val = (100 + mod_val) * dp / 100
        $mod_val = $victimDodgePower + $dodgeMod;
        if ($dp > 1000000) {
            $mod_val = intval($dp / 100 * (100 + $mod_val));
        } else {
            $mod_val = intval((100 + $mod_val) * $dp / 100);
        }
        if ($mod_val < 0) $mod_val = 0;

        // === 闪避判定: random(AP + DP) < mod_val ===
        $total = $ap + max(0, $dp);
        if ($total <= 0) {
            return ['dodged' => false];
        }
        $dodged = (mt_rand(0, $total - 1) < $mod_val);

        // 调试日志：帮助诊断闪避概率问题
        if (function_exists('error_log')) {
            $dodgeChance = round($mod_val / $total * 100, 1);
            error_log(sprintf(
                "[Dodge] attacker=%s skill=%s AP=%d | target=%s(type=%s) DP=%d dodge_power=%d dodgeMod=%d mod_val=%d | total=%d chance=%.1f%% dodged=%s",
                $attacker['name'] ?? ('char#' . $attackerId),
                $attackSkill,
                $ap,
                $combat['target_name'] ?? ('#' . $targetId),
                $targetType,
                $dp,
                $victimDodgePower,
                $dodgeMod,
                $mod_val,
                $total,
                $dodgeChance,
                $dodged ? 'YES' : 'NO'
            ));
        }

        return ['dodged' => $dodged];
    }
    
    /**
     * 招架判定 - 还原原始项目 AP/(AP+PP) 概率比值制
     * 参考: combatd.c::do_attack() 第(4)步
     * 
     * 关键规则:
     * - 防御者有武器: PP = skill_power("parry")
     *   - 攻击者无武器时: PP *= 2
     * - 防御者无武器:
     *   - 攻击者有武器: PP = 0 (无法招架)
     *   - 攻击者无武器: PP = skill_power("unarmed")
     * - 防御者忙碌: PP /= 3
     * - 公式: random(AP + PP) < mod_val → 招架成功
     * 
     * @return array ['reduction' => float] 招架成功=1.0(完全格挡)，失败=0.0
     */
    private static function checkParry(int $attackerId, array $combat, int $parryMod = 0, ?array $attacker = null): array {
        $targetId = $combat['target_id'] ?? 0;
        $targetType = $combat['target_type'] ?? 'npc';
        $isNpc = ($targetType === 'npc' || $targetType === 'yaoguai');

        // === 获取攻击者 AP ===
        $attackSkill = 'unarmed';
        $attackerHasWeapon = false;
        if ($attacker) {
            $equippedWeapon = self::getEquippedWeapon($attackerId);
            if ($equippedWeapon) {
                $attackSkill = $equippedWeapon['skill_type'] ?? 'unarmed';
                $attackerHasWeapon = true;
            }
        }
        $ap = self::calcSkillPower($attacker ?? [], $attackSkill, 1, false);

        // === 获取防御者 PP（parry power）===
        $pp = 0;
        $parry_skill = '';

        if ($isNpc) {
            $npcData = Database::queryOne("SELECT * FROM npcs WHERE id = ? LIMIT 1", [$targetId]);
            if (!$npcData) {
                return ['reduction' => 0.0];
            }
            
            // 检查NPC是否有武器（从npc_equipment查询）
            $npcWeapon = Database::queryOne(
                "SELECT ne.item_id FROM npc_equipment ne JOIN items i ON ne.item_id = i.item_id WHERE ne.npc_id = ? AND i.type = 'weapon' LIMIT 1",
                [$targetId]
            );
            $defenderHasWeapon = ($npcWeapon !== null);
            
            if ($defenderHasWeapon) {
                // 有武器用parry技能
                $npcParryLevel = self::getNpcSkillLevel($targetId, 'parry');
                if ($npcParryLevel > 0) {
                    $pp = self::calcSkillPower($npcData, 'parry', 2, true);
                    $parry_skill = 'parry';
                } else {
                    $pp = max(1, intval(($npcData['combat_exp'] ?? 0) / 2));
                    $parry_skill = 'parry';
                }
                // 攻击者无武器时，招架威力翻倍（原始项目）
                if (!$attackerHasWeapon) {
                    $pp *= 2;
                }
            } else {
                // 无武器
                if ($attackerHasWeapon) {
                    $pp = 0; // 防御者无武器、攻击者有武器 → 无法招架
                } else {
                    // 双方无武器，用unarmed
                    $npcUnarmedLevel = self::getNpcSkillLevel($targetId, 'unarmed');
                    if ($npcUnarmedLevel > 0) {
                        $pp = self::calcSkillPower($npcData, 'unarmed', 2, true);
                    } else {
                        $pp = max(1, intval(($npcData['combat_exp'] ?? 0) / 2));
                    }
                    $parry_skill = 'unarmed';
                }
            }
        } else {
            $defender = CharacterModel::find($targetId);
            if (!$defender) {
                return ['reduction' => 0.0];
            }
            
            // 检查玩家是否装备武器
            $defenderWeapon = self::getEquippedWeapon($targetId);
            $defenderHasWeapon = ($defenderWeapon !== null);
            
            if ($defenderHasWeapon) {
                $pp = self::calcSkillPower($defender, 'parry', 2, false);
                $parry_skill = 'parry';
                if (!$attackerHasWeapon) {
                    $pp *= 2;
                }
            } else {
                if ($attackerHasWeapon) {
                    $pp = 0;
                } else {
                    $pp = self::calcSkillPower($defender, 'unarmed', 2, false);
                    $parry_skill = 'unarmed';
                }
            }

            // 检查防御者招架技能映射，获取特殊招架动作加成
            if ($parry_skill && $pp > 0) {
                $mappedParry = SkillManager::querySkillMapped($targetId, $parry_skill);
                if ($mappedParry) {
                    $parryConfig = SkillManager::getSkillConfig($mappedParry);
                    if ($parryConfig && isset($parryConfig['base_parry'])) {
                        $parryBonus = intval($parryConfig['base_parry']);
                        $mod_val_parry = $parryBonus;
                        if ($pp > 1000000) {
                            $pp = intval($pp / 100 * (100 + $mod_val_parry));
                        } else {
                            $pp = intval((100 + $mod_val_parry) * $pp / 100);
                        }
                        $pp = max(0, $pp);
                    }
                }
            }
        }

        // === busy减防：防御者忙碌时招架威力/3（原始项目 victim->is_busy() pp /= 3）===
        if ($pp > 0) {
            if ($isNpc) {
                $npcDeathKey = "npc_busy_" . $targetId;
                if (isset($_SESSION[$npcDeathKey]) && $_SESSION[$npcDeathKey] > time()) {
                    $pp = intval($pp / 3);
                }
            } else {
                if (is_player_busy($targetId)) {
                    $pp = intval($pp / 3);
                }
            }
        }

        if ($pp <= 0) {
            return ['reduction' => 0.0];
        }

        // === 应用 parry_mod（来自特殊招式）===
        $mod_val = $parryMod;
        if ($pp > 1000000) {
            $mod_val = intval($pp / 100 * (100 + $mod_val));
        } else {
            $mod_val = intval((100 + $mod_val) * $pp / 100);
        }
        if ($mod_val < 0) $mod_val = 0;

        // === 招架判定: random(AP + PP) < mod_val ===
        $total = $ap + max(0, $pp);
        if ($total <= 0) {
            return ['reduction' => 0.0];
        }

        if (mt_rand(0, $total - 1) < $mod_val) {
            // 招架成功：减伤比例从配置读取
            $parryCfg = self::loadConfig()['parry'];
            return ['reduction' => mt_rand($parryCfg['reduce_min'], $parryCfg['reduce_max']) / 100.0];
        }

        return ['reduction' => 0.0];
    }
    
    /**
     * 计算伤害值
     * 参考: Combatd::do_attack() 中的伤害计算逻辑
     * 使用 CombatSystemHelper::calculateDamage() 完整伤害链路
     */
    private static function calculateDamage(array $attacker, array $combat): int {
        $charId = $attacker['id'];
        $equippedWeapon = self::getEquippedWeapon($charId);
        $secondaryWeapon = WeaponHelper::getEquippedSecondaryWeapon($charId);
        
        // 构建攻击者数据（供CombatSystemHelper使用）
        $attackerData = [
            'id' => $charId,
            'str' => AttributeHelper::queryStr($attacker),
            'force' => $attacker['force'] ?? 0,
            'force_factor' => $attacker['force_factor'] ?? 0,
            'combat_exp' => $attacker['combat_exp'] ?? 0,
            'temp_damage' => 0,
            'temp_attack' => $attacker['temp_attack'] ?? 0,
            'temp_defense' => $attacker['temp_defense'] ?? 0,
            'sen' => $attacker['sen'] ?? 100,
            'max_sen' => $attacker['max_sen'] ?? 100,
            'guild' => $attacker['guild'] ?? '',
        ];
        
        // 设置temp_damage（武器基础伤害，支持主手+副手叠加）
        if ($equippedWeapon) {
            $weaponDamage = intval($equippedWeapon['weapon_damage'] ?? $equippedWeapon['damage'] ?? 0);
            // 防御：weapon_damage 为 NULL/0 时，根据武器类型给合理默认值
            if ($weaponDamage <= 0) {
                $weaponDamage = self::getDefaultWeaponDamage($equippedWeapon['weapon_type'] ?? 'unarmed');
            }
            $attackerData['temp_damage'] = $weaponDamage;
            
            // ★ 副手武器伤害叠加：双持时副手武器提供 50% 的 weapon_damage 加成
            if ($secondaryWeapon) {
                $secDamage = intval($secondaryWeapon['weapon_damage'] ?? $secondaryWeapon['damage'] ?? 0);
                if ($secDamage <= 0) {
                    $secDamage = self::getDefaultWeaponDamage($secondaryWeapon['weapon_type'] ?? 'unarmed');
                }
                $attackerData['temp_damage'] += intval($secDamage * 0.5);
            }
        } else {
            $attackerData['temp_damage'] = $attackerData['str'] * 2 + ($attacker['level'] ?? 1);
        }
        
        // 构建防御者数据
        $defenderExp = 0;
        $combatTargetType = $combat['target_type'] ?? 'npc';
        $combatTargetId = intval($combat['target_id'] ?? 0);
        if ($combatTargetType === 'npc' || $combatTargetType === 'yaoguai') {
            $table = ($combatTargetType === 'yaoguai') ? 'mieyao_yaoguai' : 'npcs';
            $npc = Database::queryOne("SELECT combat_exp FROM {$table} WHERE id = ?", [$combatTargetId]);
            $defenderExp = intval($npc['combat_exp'] ?? 0);
        }
        $defenderData = ['combat_exp' => $defenderExp];
        
        // 调用完整伤害公式（含动作加成、力量加成、内功加成、武器技能加成、防御经验修正）
        $damage = CombatSystemHelper::calculateDamage($attackerData, $defenderData, $equippedWeapon, null);
        
        // === 保留现有的技能配置加成（来自game_skills表） ===
        $attackerId = $attacker['id'];
        $weaponType = $equippedWeapon ? ($equippedWeapon['weapon_type'] ?? 'unarmed') : 'unarmed';
        $attackSkillName = SkillManager::querySkillMapped($attackerId, $weaponType);
        if (!$attackSkillName) {
            $attackSkillName = SkillManager::querySkillMapped($attackerId, 'force');
        }
        if ($attackSkillName) {
            $skillConfig = SkillManager::getSkillConfig($attackSkillName);
            // BUG修复: 使用映射后的具体技能名（如'yuxiao-jian'）查询等级，
            // 而非武器类型字符串（如'sword'）。character_skills表存储的是具体技能名。
            $skillLevel = SkillManager::querySkill($attackerId, $attackSkillName);
            if ($skillConfig) {
                $skillDamageBonus = ($skillConfig['base_damage'] ?? 0) + intval($skillLevel * 0.5);
                $damage += $skillDamageBonus;
            }
        }
        
        // === perform 招式战斗加成（直接使用 perform.php 存储的加成值）===
        $performKey = "perform_active_{$attackerId}";
        if (isset($_SESSION[$performKey])) {
            $performData = $_SESSION[$performKey];
            // 直接使用 perform.php 中计算好的加成值
            $performDamageBonus = intval($performData['damage'] ?? 0);
            $originalDamage = $damage;
            $damage += $performDamageBonus;
            // 调试日志：如果有加成，添加消息标记
            if ($performDamageBonus > 0 && !empty($performData['action_name'])) {
                error_log("[COMBAT_DEBUG] perform加成 applied: {$performDamageBonus} (original: {$originalDamage}, final: {$damage}, skill: {$performData['action_name']})");
            }
            unset($_SESSION[$performKey]);
        }
        
        // 杀气影响伤害
        $keeMark = $attacker['kee_mark'] ?? 0;
        if ($keeMark > 200) {
            $reduction = min(20, intval(($keeMark - 200) / 50));
            $damage = intval($damage * (100 - $reduction) / 100);
        }
        
        return max(1, $damage);
    }
    
    /**
     * 获取武器类型的默认伤害值
     * 当 items 表中 weapon_damage 为 NULL 时的防御性回退
     */
    private static function getDefaultWeaponDamage(string $weaponType): int {
        $defaults = [
            'stick'   => 18,   // 棍
            'staff'   => 20,   // 杖
            'sword'   => 20,   // 剑
            'blade'   => 20,   // 刀
            'spear'   => 25,   // 枪/矛
            'fork'    => 30,   // 叉
            'hammer'  => 25,   // 锤
            'axe'     => 30,   // 斧
            'whip'    => 15,   // 鞭
            'dagger'  => 15,   // 匕首
            'mace'    => 22,   // 锏
            'rake'    => 28,   // 耙
            'throwing'=> 20,   // 投掷
            'bow'     => 20,   // 弓
            'unarmed' => 5,    // 徒手
        ];
        return $defaults[$weaponType] ?? 15;
    }
    
    /**
     * 计算NPC或妖怪攻击伤害
     * 完全还原原始LPC do_attack() 的伤害公式，与玩家使用相同计算链路：
     *   ① 基础伤害 = 武器damage 或 徒手(str×2)
     *   ② 随机波动: (damage + random(damage)) / 2
     *   ③ 力量加成: damageBonus = str
     *   ④ 内功加成: force_factor hit_ob → damageBonus += forceBonus
     *   ⑤ 武器技能 hit_ob: skillLevel × damageBonus / 200
     *   ⑥ damageBonus 随机化: damage += (bonus + random(bonus)) / 2
     * （防御减免 defense_factor 在 performNpcAttack 中后续执行）
     *
     * @param array $npcData NPC数据（npcs 表或 mieyao_yaoguai 表查询结果）
     * @param string $targetType 'npc' 或 'yaoguai'
     * @return int 伤害值
     */
    private static function calculateNpcDamage(array $npcData, string $targetType): int {
        $npcId = intval($npcData['id'] ?? 0);
        if ($npcId <= 0) return 0;
        
        $npcStr = intval($npcData['str'] ?? 10);
        $npcExp = intval($npcData['combat_exp'] ?? 0);
        $forceFactor = intval($npcData['force_factor'] ?? 0);
        $force = intval($npcData['force'] ?? 0);
        $weaponDamage = 0;
        $weaponType = '';
        
        // === ① 基础伤害 ===
        if ($targetType === 'npc') {
            // 查询 NPC 装备的武器
            $weaponRow = Database::queryOne(
                "SELECT i.weapon_damage, i.weapon_type FROM npc_equipment ne 
                 JOIN items i ON ne.item_id = i.item_id 
                 WHERE ne.npc_id = ? AND ne.equip_slot = 'weapon' AND ne.worn = 1 AND i.type = 'weapon'
                 LIMIT 1",
                [$npcId]
            );
            if ($weaponRow) {
                $weaponDamage = intval($weaponRow['weapon_damage'] ?? 0);
                $weaponType = $weaponRow['weapon_type'] ?? '';
                if ($weaponDamage <= 0) {
                    $weaponDamage = self::getDefaultWeaponDamage($weaponType ?: 'unarmed');
                }
            }
        } else {
            // 妖怪：从 weapon_json 解析武器伤害
            $weaponJson = $npcData['weapon_json'] ?? '';
            if (!empty($weaponJson)) {
                $weaponData = json_decode($weaponJson, true);
                if ($weaponData && !empty($weaponData['id'])) {
                    $qualityDamageMap = [
                        'mu' => 3, 'tie' => 6, 'tong' => 9, 'gang' => 12,
                        'yin' => 15, 'jin' => 20, 'bao' => 25, 'shen' => 30,
                        'xian' => 40, 'sheng' => 50, 'tian' => 65, 'mo' => 80,
                    ];
                    $weaponId = $weaponData['id'] ?? '';
                    $weaponDamage = 5;
                    foreach ($qualityDamageMap as $quality => $dmg) {
                        if (strpos($weaponId, $quality) !== false) {
                            $weaponDamage = $dmg;
                            $weaponType = $weaponData['type'] ?? '';
                            break;
                        }
                    }
                }
            }
            // 妖怪技能等级加成（skills_json）
            $skillsJson = $npcData['skills_json'] ?? '';
            if (!empty($skillsJson)) {
                $skills = json_decode($skillsJson, true);
                if (!empty($skills)) {
                    $avgSkillLevel = intval(array_sum($skills) / count($skills));
                    $weaponDamage += intval($avgSkillLevel * 0.5);
                }
            }
        }
        
        // 徒手时基础伤害 = str × 2
        $hasWeapon = ($weaponDamage > 0);
        if (!$hasWeapon) {
            $weaponDamage = $npcStr * 2;
        }
        $damage = $weaponDamage;
        
        // === ② 随机波动: (damage + random(damage)) / 2 ===
        if ($damage > 0) {
            $damage = intval(($damage + mt_rand(0, $damage)) / 2);
        }
        
        // === ③ 力量加成 ===
        $damageBonus = $npcStr;
        
        // === ④ 内功加成 ===
        if ($forceFactor > 0 && $force > $forceFactor) {
            $forceBonus = intval($forceFactor / 10);
            $damageBonus += $forceBonus;
        }
        
        // === ⑤ 武器技能 hit_ob: skillLevel × damageBonus / 200 ===
        if ($hasWeapon && !empty($weaponType)) {
            // 查 NPC 技能映射表获取具体技能名
            $mappedSkillRow = Database::queryOne(
                "SELECT mapped_skill FROM npc_skill_maps WHERE npc_id = ? AND base_skill = ? LIMIT 1",
                [$npcId, $weaponType]
            );
            $mappedSkill = $mappedSkillRow ? $mappedSkillRow['mapped_skill'] : null;
            if ($mappedSkill) {
                $skillLevel = self::getNpcSkillLevel($npcId, $mappedSkill);
                if ($skillLevel > 0 && $damageBonus > 0) {
                    $hitBonus = intval(floor($skillLevel * $damageBonus / 200));
                    $damageBonus += $hitBonus;
                }
            }
        } elseif (!$hasWeapon) {
            // 徒手：查 unarmed 映射技能
            $mappedSkillRow = Database::queryOne(
                "SELECT mapped_skill FROM npc_skill_maps WHERE npc_id = ? AND base_skill = 'unarmed' LIMIT 1",
                [$npcId]
            );
            $mappedSkill = $mappedSkillRow ? $mappedSkillRow['mapped_skill'] : null;
            if ($mappedSkill) {
                $skillLevel = self::getNpcSkillLevel($npcId, $mappedSkill);
                if ($skillLevel > 0 && $damageBonus > 0) {
                    $hitBonus = intval(floor($skillLevel * $damageBonus / 200));
                    $damageBonus += intval($hitBonus * 3 / 4); // 徒手 3/4 系数
                }
            }
        }
        
        // === ⑥ damageBonus 随机化: damage += (bonus + random(bonus)) / 2 ===
        if ($damageBonus > 0) {
            $damage += intval(($damageBonus + mt_rand(0, $damageBonus)) / 2);
        }
        
        return max(1, $damage);
    }

    /**
     * NPC独立攻击（还原LPC heart_beat机制）
     * 
     * 还原原始 LPC combatd.c::fight() 的 guarding 概率判定：
     *   - NPC 攻击概率 = (npcCor + bellicosity/50) / (玩家cps * 3)
     *   - 当判定不攻击时，NPC 进入 guarding 状态（本回合只防守不攻击）
     *   - 有最低攻击概率保底，防止永远不攻击
     *
     * @param array $combat 战斗状态
     * @param int $attackerId 玩家角色ID
     * @param int $playerDamage 已累计的玩家受伤量（引用传递）
     * @return array ['damage' => int, 'msg' => string, 'killed' => bool, 'fled' => bool]
     */
    private static function performNpcAttack(array $combat, int $attackerId, int &$playerDamage): array {
        $targetType = $combat['target_type'] ?? 'npc';
        $targetId = intval($combat['target_id'] ?? 0);
        $targetName = $combat['target_name'] ?? '目标';

        // 目前仅NPC和妖怪会反击
        if ($targetType !== 'npc' && $targetType !== 'yaoguai') {
            return ['damage' => 0, 'msg' => '', 'killed' => false, 'fled' => false];
        }

        // 检查NPC/妖怪是否被法宝束缚
        if (FabaoHelper::isTrapped($targetId)) {
            return ['damage' => 0, 'msg' => '', 'killed' => false, 'fled' => false];
        }

        // 双重检查：如果目标已经死亡，不进行攻击
        $currentHp = self::getTargetCurrentHp($targetId, $targetType);
        if ($currentHp <= 0) {
            return ['damage' => 0, 'msg' => '', 'killed' => false, 'fled' => false];
        }

        // === guarding 概率判定（还原 LPC combatd.c::fight() 原始逻辑）===
        // 原始公式：random(玩家cps * 3) < (NPC cor + bellicosity/50) → NPC 攻击
        // 否则 NPC 进入 guarding 状态，本回合只防守不攻击
        $guardCfg = self::loadConfig()['npc_guarding'] ?? [
            'cps_multiplier' => 3, 'bellicosity_div' => 50,
            'default_npc_cor' => 10, 'default_npc_cps' => 10,
            'min_attack_chance' => 10,
        ];
        
        // 获取玩家气势（cps）
        $playerCps = 10;
        $playerRow = Database::queryOne("SELECT cps FROM characters WHERE id = ? LIMIT 1", [$attackerId]);
        if ($playerRow) {
            $playerCps = max(1, intval($playerRow['cps'] ?? 10));
        }
        
        // 获取 NPC 胆识（cor）和好斗度（bellicosity）
        $npcCor = $guardCfg['default_npc_cor'];
        $npcBellicosity = 0;
        if ($targetType === 'npc') {
            $npcRow = Database::queryOne("SELECT cor, bellicosity FROM npcs WHERE id = ? LIMIT 1", [$targetId]);
            if ($npcRow) {
                $npcCor = intval($npcRow['cor'] ?? $guardCfg['default_npc_cor']);
                $npcBellicosity = intval($npcRow['bellicosity'] ?? 0);
            }
        }
        // 妖怪使用默认值（mieyao_yaoguai 表无 cor/bellicosity 字段）
        
        // 计算 NPC 攻击判定值
        $npcAttackThreshold = $npcCor + intval($npcBellicosity / $guardCfg['bellicosity_div']);
        $randMax = max(1, $playerCps * $guardCfg['cps_multiplier']) - 1;
        $roll = mt_rand(0, $randMax);
        
        // 最低攻击概率保底
        $minAttackRoll = intval(($guardCfg['min_attack_chance'] / 100) * ($randMax + 1));
        
        $shouldAttack = ($roll < $npcAttackThreshold || $roll < $minAttackRoll);
        
        if (!$shouldAttack) {
            // NPC 进入 guarding 状态，本回合不攻击
            $guardMsgs = [
                $targetName . '凝神聚气，紧守门户，不露半点破绽。',
                $targetName . '身形一转，退后一步，摆出守势。',
                $targetName . '目光紧锁你的动作，全神戒备。',
            ];
            $guardMsg = ' ' . HTML_HIMAG . $guardMsgs[array_rand($guardMsgs)] . HTML_NOR;
            return ['damage' => 0, 'msg' => $guardMsg, 'killed' => false, 'fled' => false];
        }

        $isFriendly = isset($combat['friendly']) && $combat['friendly'];

        // === 玩家闪避/招架判定（还原 LPC 双向完整防御链路）===
        // 原始 LPC 中 NPC 的 fight() 也走完整的 dodge→parry→damage 流程
        // 此处对玩家（防御方）进行闪避和招架判定
        $player = CharacterModel::find($attackerId);
        $playerDodgeResult = ['dodged' => false];
        $playerParryResult = ['reduction' => 0.0];
        $dodgeCfg = self::loadConfig()['dodge'] ?? ['npc_attack_dodge_enabled' => true];

        if ($player && ($dodgeCfg['npc_attack_dodge_enabled'] ?? true)) {
            // === 计算 NPC 攻击威力 AP ===
            // NPC有武器时用武器技能（blade/sword/stick等），无武器时用unarmed
            $npcAttackSkill = 'unarmed';
            $npcHasWeapon = false;
            if ($targetType === 'npc') {
                // 用 items.type 判断武器（items表没有skill_type字段）
                $npcWeaponRow = Database::queryOne(
                    "SELECT ne.item_id FROM npc_equipment ne 
                     JOIN items i ON ne.item_id = i.item_id 
                     WHERE ne.npc_id = ? AND ne.equip_slot = 'weapon' AND ne.worn = 1 AND i.type = 'weapon'
                     LIMIT 1",
                    [$targetId]
                );
                if ($npcWeaponRow) {
                    $npcHasWeapon = true;
                    // 从 npc_skills 查该NPC会的攻击技能（排除防御技能）
                    $atkSkillRow = Database::queryOne(
                        "SELECT skill_name FROM npc_skills 
                         WHERE npc_id = ? AND skill_name NOT IN ('dodge','parry','unarmed') 
                         LIMIT 1",
                        [$targetId]
                    );
                    if ($atkSkillRow && !empty($atkSkillRow['skill_name'])) {
                        $npcAttackSkill = $atkSkillRow['skill_name'];
                    }
                }
            }
            
            // NPC 的 AP：尝试用技能等级计算，无技能则用 combat_exp/2
            $npcAp = 0;
            $npcData = null;
            if ($targetType === 'npc') {
                $npcData = Database::queryOne("SELECT * FROM npcs WHERE id = ? LIMIT 1", [$targetId]);
                if ($npcData) {
                    $npcSkillLevel = self::getNpcSkillLevel($targetId, $npcAttackSkill);
                    if ($npcSkillLevel > 0) {
                        $npcAp = self::calcSkillPower($npcData, $npcAttackSkill, 1, true);
                    } else {
                        $npcAp = max(1, intval(($npcData['combat_exp'] ?? 0) / 2));
                    }
                }
            }
            // 妖怪使用 combat_exp/2
            if ($targetType === 'yaoguai') {
                $yaoguaiData = Database::queryOne("SELECT combat_exp FROM mieyao_yaoguai WHERE id = ? LIMIT 1", [$targetId]);
                if ($yaoguaiData) {
                    $npcAp = max(1, intval(($yaoguaiData['combat_exp'] ?? 0) / 2));
                }
            }
            
            // === 计算玩家闪避威力 DP ===
            $playerDp = self::calcSkillPower($player, 'dodge', 2, false);
            
            // 特殊闪避动作加成（base_dodge）
            $mappedDodge = SkillManager::querySkillMapped($attackerId, 'dodge');
            if ($mappedDodge) {
                $dodgeConfig = SkillManager::getSkillConfig($mappedDodge);
                if ($dodgeConfig && isset($dodgeConfig['base_dodge'])) {
                    $dodgeBonus = intval($dodgeConfig['base_dodge']);
                    if ($playerDp > 1000000) {
                        $playerDp = intval($playerDp / 100 * (100 + $dodgeBonus));
                    } else {
                        $playerDp = intval((100 + $dodgeBonus) * $playerDp / 100);
                    }
                    $playerDp = max(0, $playerDp);
                }
            }
            
            // 玩家忙碌时闪避/3
            if (is_player_busy($attackerId)) {
                $playerDp = intval($playerDp / 3);
            }
            
            // === 闪避判定: random(NPC_AP + 玩家DP) < 玩家DP ===
            $dodgeTotal = $npcAp + max(0, $playerDp);
            if ($dodgeTotal > 0) {
                $dodgeModVal = $playerDp;
                $playerDodgeResult['dodged'] = (mt_rand(0, $dodgeTotal - 1) < $dodgeModVal);
                
                if ($playerDodgeResult['dodged']) {
                    // 玩家闪避成功，技能增长
                    SkillManager::combatImproveSkill($attackerId, 'dodge');
                    
                    $dodgeMsgs = [
                        '你身形一闪，灵巧地躲过了' . $targetName . '的攻击！',
                        '你脚下一滑，' . $targetName . '的攻击落了个空。',
                        '你早有防备，轻轻一侧身，' . $targetName . '便扑了个空。',
                        '你如游鱼般一闪，' . $targetName . '的招式尽数落空。',
                    ];
                    $dodgeMsg = ' ' . HTML_HIYEL . '【闪避】' . HTML_NOR . ' ' . $dodgeMsgs[array_rand($dodgeMsgs)];
                    return ['damage' => 0, 'msg' => $dodgeMsg, 'killed' => false, 'fled' => false];
                }
            }
            
            // === 闪避失败，进行招架判定 ===
            $parryCfg = self::loadConfig()['parry'] ?? ['npc_attack_parry_enabled' => true];
            if ($parryCfg['npc_attack_parry_enabled'] ?? true) {
                // 计算玩家招架威力 PP
                $pp = 0;
                $playerWeapon = self::getEquippedWeapon($attackerId);
                $playerHasWeapon = ($playerWeapon !== null);
                
                if ($playerHasWeapon) {
                    // 有武器用 parry 技能
                    $pp = self::calcSkillPower($player, 'parry', 2, false);
                    // NPC 无武器时 PP *= 2
                    if ($npcAttackSkill === 'unarmed') {
                        $pp *= 2;
                    }
                } else {
                    // 玩家无武器
                    if ($npcAttackSkill !== 'unarmed') {
                        $pp = 0; // NPC 有武器，玩家空手无法招架
                    } else {
                        $pp = self::calcSkillPower($player, 'unarmed', 2, false);
                    }
                }
                
                // 玩家忙碌时招架/3
                if (is_player_busy($attackerId)) {
                    $pp = intval($pp / 3);
                }
                
                // 招架判定: random(NPC_AP + PP) < PP
                $parryTotal = $npcAp + max(0, $pp);
                if ($parryTotal > 0 && $pp > 0) {
                    $parried = (mt_rand(0, $parryTotal - 1) < $pp);
                    if ($parried) {
                        // 招架成功，减伤 30%-50%
                        $parryReduceMin = $parryCfg['reduce_min'] ?? 30;
                        $parryReduceMax = $parryCfg['reduce_max'] ?? 50;
                        $playerParryResult['reduction'] = mt_rand($parryReduceMin, $parryReduceMax) / 100.0;
                        
                        // 技能增长
                        SkillManager::combatImproveSkill($attackerId, 'parry');
                    }
                }
            }
        }

        // 计算NPC攻击伤害（还原 LPC do_attack 完整公式）
        // 获取 NPC/妖怪完整数据
        $npcFullData = null;
        if ($targetType === 'npc') {
            $npcFullData = isset($npcData) ? $npcData : Database::queryOne("SELECT * FROM npcs WHERE id = ? LIMIT 1", [$targetId]);
        } else {
            $npcFullData = Database::queryOne("SELECT * FROM mieyao_yaoguai WHERE id = ? LIMIT 1", [$targetId]);
        }
        if (!$npcFullData) {
            return ['damage' => 0, 'msg' => '', 'killed' => false, 'fled' => false];
        }
        $counterDamage = self::calculateNpcDamage($npcFullData, $targetType);
        if ($counterDamage <= 0) {
            return ['damage' => 0, 'msg' => '', 'killed' => false, 'fled' => false];
        }

        // 应用招架减伤
        $parryMsg = '';
        if ($playerParryResult['reduction'] > 0) {
            $counterDamage = intval($counterDamage * (1 - $playerParryResult['reduction']));
            $counterDamage = max(1, $counterDamage); // 招架后至少保留1点伤害
            $parryMsgs = [
                '你举臂一格，卸去了' . $targetName . '的大半力道。',
                '你横' . (self::getEquippedWeapon($attackerId) ? '兵器' : '双臂') . '一挡，堪堪架住了' . $targetName . '的攻势。',
                '你沉着应对，将' . $targetName . '的招式化解了大半。',
            ];
            $parryMsg = ' ' . HTML_HIYEL . '【招架】' . HTML_NOR . ' ' . $parryMsgs[array_rand($parryMsgs)];
        }

        // === 玩家防御减免（还原 LPC defense_factor 循环减伤机制）===
        // 原始公式：while(random(玩家combat_exp) > NPC_combat_exp) → 伤害每次减1/3
        $defenseMsg = '';
        $defCfg = self::loadConfig()['npc_guarding'] ?? [];
        if (($defCfg['npc_attack_defense_enabled'] ?? true) && $counterDamage > 0) {
            $playerRow = Database::queryOne("SELECT combat_exp FROM characters WHERE id = ? LIMIT 1", [$attackerId]);
            $playerExp = $playerRow ? intval($playerRow['combat_exp'] ?? 0) : 0;
            
            // NPC 的 combat_exp
            $npcExp = 0;
            if ($targetType === 'npc') {
                $npcRow = Database::queryOne("SELECT combat_exp FROM npcs WHERE id = ? LIMIT 1", [$targetId]);
                $npcExp = $npcRow ? intval($npcRow['combat_exp'] ?? 0) : 0;
            } else {
                $yaoRow = Database::queryOne("SELECT combat_exp FROM mieyao_yaoguai WHERE id = ? LIMIT 1", [$targetId]);
                $npcExp = $yaoRow ? intval($yaoRow['combat_exp'] ?? 0) : 0;
            }
            
            if ($playerExp > 0 && $npcExp > 0) {
                $damageBeforeDefense = $counterDamage;
                $defenseFactor = $playerExp;
                $defenseReductionDiv = $defCfg['defense_reduction_div'] ?? 3;
                $defenseFactorDiv = $defCfg['defense_factor_div'] ?? 2;
                
                while (mt_rand(0, max(1, $defenseFactor) - 1) > $npcExp) {
                    $counterDamage -= intval($counterDamage / $defenseReductionDiv);
                    $defenseFactor = intval($defenseFactor / $defenseFactorDiv);
                    if ($counterDamage <= 0) {
                        $counterDamage = 0;
                        break;
                    }
                }
                $counterDamage = max(0, $counterDamage);
                
                if ($counterDamage < $damageBeforeDefense) {
                    $reducedBy = $damageBeforeDefense - $counterDamage;
                    if ($counterDamage <= 0) {
                        $defenseMsgs = [
                            '你凭借深厚的战斗经验，轻松化解了' . $targetName . '的攻势！',
                            '你经验老到，' . $targetName . '的攻击被你尽数卸去。',
                            '你沉着应战，以经验优势将' . $targetName . '的力道全部抵消。',
                        ];
                        $defenseMsg = ' ' . HTML_HIYEL . '【防御】' . HTML_NOR . ' ' . $defenseMsgs[array_rand($defenseMsgs)];
                    }
                    // 部分减免时不单独显示消息，伤害消息自然反映减免后数值
                }
            }
        }
        
        // 防御减免后伤害归零，视为完全防御
        if ($counterDamage <= 0) {
            $defenseMsgFinal = $defenseMsg ?: (' ' . HTML_HIYEL . '【防御】' . HTML_NOR . ' 你凭借丰富的战斗经验，完全化解了' . $targetName . '的攻击！');
            return ['damage' => 0, 'msg' => $parryMsg . $defenseMsgFinal, 'killed' => false, 'fled' => false];
        }

        // === 装备护甲 + 气防/神防减伤（防御经验减免之后、实际扣血之前）===
        // 1) 物理护甲减伤
        $armorVal = ArmorHelper::getArmorValue($attackerId);
        if ($armorVal > 0 && $counterDamage > 0) {
            $counterDamage = ArmorHelper::applyArmorReduction($counterDamage, $attackerId);
        }
        // 2) 气防减伤：每点气防减免 0.5 点伤害
        $qiDefenseVal = AttributeHelper::queryQiDefense(['id' => $attackerId]);
        $shenDefenseVal = AttributeHelper::queryShenDefense(['id' => $attackerId]);
        if ($qiDefenseVal > 0 && $counterDamage > 0) {
            $qiReduction = intval($qiDefenseVal * 0.5);
            $counterDamage = max(1, $counterDamage - $qiReduction);
        }
        // 3) 神防减伤：每点神防减免 0.3 点伤害
        if ($shenDefenseVal > 0 && $counterDamage > 0) {
            $shenReduction = intval($shenDefenseVal * 0.3);
            $counterDamage = max(1, $counterDamage - $shenReduction);
        }

        // 生成攻击消息（使用技能动作描述）
        $counterLimb = self::getRandomLimb();
        $npcActionInfo = self::getNpcAttackActionText($targetId);

        if ($isFriendly) {
            // === 切磋模式 ===
            if ($npcActionInfo) {
                $counterActionMsg = self::replaceVars($npcActionInfo['text'], $targetName, '你', $counterLimb);
            } else {
                $counterActionMsg = $targetName . '向你发起了反击，瞄准你的' . $counterLimb;
            }

            // 切磋模式造成真实伤害（参考原始切磋逻辑）
            $sql = "SELECT kee FROM characters WHERE id = ? LIMIT 1";
            $playerRow = Database::queryOne($sql, [$attackerId]);
            $currentKee = intval($playerRow['kee'] ?? 0);
            $newKee = max(0, $currentKee - $counterDamage);
            Database::execute("UPDATE characters SET kee = ? WHERE id = ?", [$newKee, $attackerId]);
            $playerDamage += $counterDamage;

            $counterDamageMsg = CombatMessages::getDamageMessage($counterDamage, ($npcActionInfo['damage_type'] ?? 'blunt'));
            $counterDamageMsg = self::replaceVars($counterDamageMsg, $targetName, '你', $counterLimb);
            $msg = $parryMsg . ' ' . $counterActionMsg . '。' . $counterDamageMsg;

            // 切磋模式：血量低于30%判定失败
            if ($newKee <= 0) {
                return ['damage' => $counterDamage, 'msg' => $msg, 'killed' => true, 'fled' => false];
            }

            return ['damage' => $counterDamage, 'msg' => $msg, 'killed' => false, 'fled' => false];
        } else {
            // === 击杀模式 ===
            if ($npcActionInfo) {
                $counterActionMsg = self::replaceVars($npcActionInfo['text'], $targetName, '你', $counterLimb);
                $npcDamageType = $npcActionInfo['damage_type'] ?? 'blunt';
            } else {
                $counterActionMsg = $targetName . '向你发起反击，瞄准你的' . $counterLimb;
                $npcDamageType = 'blunt';
            }

            // 应用伤害
            $sql = "SELECT kee FROM characters WHERE id = ? LIMIT 1";
            $playerRow = Database::queryOne($sql, [$attackerId]);
            $currentKee = intval($playerRow['kee'] ?? 0);

            // 检查濒死状态
            $sql = "SELECT near_death_time FROM characters WHERE id = ? LIMIT 1";
            $nearDeathRow = Database::queryOne($sql, [$attackerId]);
            $nearDeathTime = intval($nearDeathRow['near_death_time'] ?? 0);

            if ($nearDeathTime > 0 && $currentKee <= 0) {
                self::triggerPlayerDeathForCriticalState($attackerId, 'near_death');
                $counterDamageMsg = CombatMessages::getDamageMessage($counterDamage, $npcDamageType);
                $counterDamageMsg = self::replaceVars($counterDamageMsg, $targetName, '你', $counterLimb);
                $msg = $parryMsg . ' ' . $counterActionMsg . '。' . $counterDamageMsg;
                return ['damage' => $counterDamage, 'msg' => $msg, 'killed' => true, 'fled' => false];
            }

            $newKee = max(0, $currentKee - $counterDamage);
            Database::execute("UPDATE characters SET kee = ? WHERE id = ?", [$newKee, $attackerId]);
            $playerDamage += $counterDamage;

            $counterDamageMsg = CombatMessages::getDamageMessage($counterDamage, $npcDamageType);
            $counterDamageMsg = self::replaceVars($counterDamageMsg, $targetName, '你', $counterLimb);
            $msg = $parryMsg . ' ' . $counterActionMsg . '。' . $counterDamageMsg;

            // 检查是否被打败
            if ($newKee <= 0) {
                self::handlePlayerDefeated($attackerId, $combat);
                return ['damage' => $counterDamage, 'msg' => $msg, 'killed' => true, 'fled' => false];
            }

            return ['damage' => $counterDamage, 'msg' => $msg, 'killed' => false, 'fled' => false];
        }
    }

    /**
     * 获取角色当前装备的武器
     * 
     * @param int $charId 角色ID
     * @return array|null 武器数据，如果没有装备武器则返回null
     */
    public static function getEquippedWeapon(int $charId): ?array {
        // 从Session中获取装备信息
        if (isset($_SESSION["equipment_{$charId}"])) {
            $equipment = $_SESSION["equipment_{$charId}"];
            
            // 检查是否装备了武器（通常在weapon槽位）
            if (isset($equipment['weapon']) && !empty($equipment['weapon'])) {
                $weaponData = $equipment['weapon'];
                
                // 判断存储的是完整物品数组还是仅item_id
                if (is_array($weaponData) && isset($weaponData['item_id'])) {
                    // 直接返回完整物品数据（包含weapon_type等字段）
                    return $weaponData;
                } else {
                    // 存储的是item_id字符串，需要从数据库查询
                    $weaponId = $weaponData;
                    $weapon = Database::queryOne(
                        "SELECT * FROM items WHERE item_id = ?",
                        [$weaponId]
                    );
                    
                    if ($weapon) {
                        return $weapon;
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * 应用伤害到目标（多人共享血量使用DB）
     */
    private static function applyDamage(array $combat, int $damage, int $attackerId = 0): array {
        // 确保 target_type 和 target_id 存在
        $targetType = $combat['target_type'] ?? 'npc';
        $targetId = intval($combat['target_id'] ?? 0);
        
        // 如果目标是玩家，应用防具减伤 + 气防/神防减伤
        $armorValue = 0;
        $qiDefenseValue = 0;
        $shenDefenseValue = 0;
        if ($targetType === 'player') {
            $originalDamage = $damage;
            // 1) 物理护甲减伤
            $armorValue = ArmorHelper::getArmorValue($targetId);
            $damage = ArmorHelper::applyArmorReduction($damage, $targetId);
            
            // 2) 气防减伤：每点气防减免 0.5 点伤害（气防用于减少物理/内力伤害）
            $qiDefenseValue = AttributeHelper::queryQiDefense(['id' => $targetId]);
            if ($qiDefenseValue > 0 && $damage > 0) {
                $qiReduction = intval($qiDefenseValue * 0.5);
                $damage = max(1, $damage - $qiReduction);
            }
            
            // 3) 神防减伤：每点神防减免 0.3 点伤害（神防用于减少精神/法术伤害，对物理也有轻微减免）
            $shenDefenseValue = AttributeHelper::queryShenDefense(['id' => $targetId]);
            if ($shenDefenseValue > 0 && $damage > 0) {
                $shenReduction = intval($shenDefenseValue * 0.3);
                $damage = max(1, $damage - $shenReduction);
            }
        }

        // === 创伤系统（还原原始项目 combatd.c::receive_wound）===
        $wounded = false;
        if ($targetType === 'player' && $damage > 0 && $attackerId > 0) {
            $isKillMode = !isset($combat['friendly']) || !$combat['friendly'];
            $attackerWeapon = self::getEquippedWeapon($attackerId);
            if ($isKillMode || $attackerWeapon) {
                if (mt_rand(0, max(0, $damage - 1)) > $armorValue) {
                    $woundDamage = max(1, $damage - $armorValue);
                    $targetId = intval($combat['target_id']);
                    $targetData = Database::queryOne("SELECT eff_kee, kee FROM characters WHERE id = ?", [$targetId]);
                    if ($targetData) {
                        $currentEffKee = intval($targetData['eff_kee'] ?? $targetData['kee'] ?? 100);
                        $newEffKee = max(0, $currentEffKee - $woundDamage);
                        Database::execute("UPDATE characters SET eff_kee = ? WHERE id = ?", [$newEffKee, $targetId]);
                        $wounded = true;
                        if ($newEffKee <= 0) {
                            Database::execute(
                                "UPDATE characters SET last_fainted_from = ? WHERE id = ?",
                                [$attackerId, $targetId]
                            );
                        }
                    }
                }
            }
        }
        
        if ($targetType === 'npc' || $targetType === 'yaoguai') {
            // NPC/妖怪血量管理：使用 active_combats 表（多人共享）
            // $targetId 已在函数开头定义
            // $targetType 已在函数开头定义
            
            // 检查是否是切磋模式
            $isFriendly = isset($combat['friendly']) && $combat['friendly'];
            
            // 获取当前目标最大血量
            if ($targetType === 'yaoguai') {
                $sql = "SELECT max_kee FROM mieyao_yaoguai WHERE id = ? LIMIT 1";
                $yaoguai = Database::queryOne($sql, [$targetId]);
                $maxHp = $yaoguai['max_kee'] ?? 100;
            } else {
                $sql = "SELECT max_kee FROM npcs WHERE id = ? LIMIT 1";
                $npc = Database::queryOne($sql, [$targetId]);
                $maxHp = max(100, intval($npc['max_kee'] ?? 100));
            }
            
            // 切磋模式：使用 session 追踪血量（与 fight.php 一致）
            if ($isFriendly) {
                $npcHpKey = "npc_hp_friendly_{$targetId}";
                $currentHp = isset($_SESSION[$npcHpKey]) ? intval($_SESSION[$npcHpKey]) : $maxHp;
                $newHp = max(0, $currentHp - $damage);
                $_SESSION[$npcHpKey] = $newHp;
                
                if ($newHp <= 0) {
                    return ['target_dead' => true, 'hp_percent' => 0];
                }
                
                return [
                    'target_dead' => false,
                    'hp_percent' => intval(($newHp / $maxHp) * 100)
                ];
            }
            
            // 击杀模式：使用原子操作扣血（多人共享：更新所有攻击同一目标的记录）
            Database::execute(
                "UPDATE active_combats SET target_current_hp = GREATEST(0, target_current_hp - ?) WHERE target_id = ? AND target_type = ?",
                [$damage, $targetId, $targetType]
            );
            
            // 对于妖怪，如果攻击者不是任务主人，记录其他攻击者造成的伤害（用于奖励折扣）
            if ($targetType === 'yaoguai') {
                $yaoguai = Database::queryOne("SELECT owner_id, max_kee, other_kee FROM mieyao_yaoguai WHERE id = ?", [$targetId]);
                // 只有当 owner_id 非空且不等于攻击者ID时，才记录为他人协助
                if ($yaoguai && !empty($yaoguai['owner_id']) && $yaoguai['owner_id'] != $attackerId) {
                    $maxKee = $yaoguai['max_kee'] ?? 1;
                    $currentOtherKee = $yaoguai['other_kee'] ?? 0;
                    // 限制 other_kee 不超过 max_kee，防止折扣比例变为负数
                    $addDamage = min($damage, $maxKee - $currentOtherKee);
                    if ($addDamage > 0) {
                        Database::execute(
                            "UPDATE mieyao_yaoguai SET other_kee = other_kee + ? WHERE id = ?",
                            [$addDamage, $targetId]
                        );
                    }
                }
            }
            
            // 获取最新血量
            $currentHp = self::getTargetCurrentHp($targetId, $targetType);
            
            if ($currentHp <= 0) {
                return ['target_dead' => true, 'hp_percent' => 0];
            }
            
            return [
                'target_dead' => false,
                'hp_percent' => intval(($currentHp / $maxHp) * 100)
            ];
        } else {
            $target = CharacterModel::find($targetId);
            if ($target) {
                $currentKee = intval($target['kee']);
                $newKee = max(0, $currentKee - $damage);
                $sql = "UPDATE characters SET kee = ? WHERE id = ?";
                Database::execute($sql, [$newKee, $targetId]);
                
                if ($newKee <= 0 && $currentKee > 0) {
                    self::handlePlayerDefeated($targetId, $combat);
                }
                
                return [
                    'target_dead' => $newKee <= 0,
                    'hp_percent' => $target['max_kee'] > 0 ? intval(($newKee / $target['max_kee']) * 100) : 0
                ];
            }
        }
        
        return ['target_dead' => false, 'hp_percent' => 100];
    }

    /**
     * 处理玩家被击败
     * 切磋模式 → 昏迷（cmd_faint）
     * 击杀模式 → 真正死亡（handlePlayerDeath：地府传送+死亡惩罚）
     * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
     */
    private static function handlePlayerDefeated(int $playerId, array $combat): void {
        $isFriendly = isset($combat['friendly']) && $combat['friendly'];
        self::endCombat($playerId);
        if ($isFriendly) {
            // 切磋模式：昏迷，无惩罚
            require_once __DIR__ . '/../commands/faint.php';
            cmd_faint($playerId, '');
        } else {
            // 击杀模式：真正死亡（地府+惩罚）
            self::handlePlayerDeath($playerId, $combat);
        }
    }

    /**
     * 触发玩家真正死亡（用于非战斗场景，如昏迷后气血仍为0）
     * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
     * @param int $victimId 受害者ID
     * @param string $reason 死亡原因（natural/hp_zero/environment）
     */
    public static function triggerPlayerDeathForCriticalState(int $victimId, string $reason = 'hp_zero'): void {
        $combat = [
            'target_id' => $victimId,
            'target_type' => 'npc',
            'friendly' => false
        ];
        self::handlePlayerDeath($victimId, $combat);
    }

    /**
     * 处理玩家死亡
     * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
     */
    private static function handlePlayerDeath(int $victimId, array $combat): void {
        $victim = CharacterModel::find($victimId);
        if (!$victim) {
            return;
        }
        
        // 1. 清除所有状态
        // TODO: 清除condition等临时状态
        
        // 2. 广播死亡消息
        $killerName = '';
        
        // 关键修复：combat 是从攻击者的视角存储的
        // combat['target_id'] 是受害者ID，不是攻击者ID
        // 我们需要找到是谁在攻击这个受害者
        
        if (isset($combat['target_type']) && $combat['target_type'] === 'npc') {
            // NPC 杀死玩家
            $sql = "SELECT name FROM npcs WHERE id = ?";
            $npc = Database::queryOne($sql, [$combat['target_id']]);
            $killerName = $npc ? $npc['name'] : '未知';
        } elseif (isset($combat['target_type']) && $combat['target_type'] === 'player') {
            // 玩家对战：从active_combats表查找攻击者
            require_once MODEL_PATH . 'Character.php';
            $attackerRows = Database::queryAll(
                "SELECT ac.char_id, c.name FROM active_combats ac 
                 LEFT JOIN characters c ON ac.char_id = c.id 
                 WHERE ac.target_id = ? AND ac.target_type = 'player'",
                [$victimId]
            );
            
            if (!empty($attackerRows)) {
                $killerName = $attackerRows[0]['name'];
            }
            
            // 兼容回退：如果DB没有找到，使用 victim 自己的 combat 状态
            if (empty($killerName)) {
                $victimCombat = self::getCombatStatus($victimId);
                if ($victimCombat && isset($victimCombat['target_id'])) {
                    $potentialKiller = CharacterModel::find($victimCombat['target_id']);
                    if ($potentialKiller) {
                        $killerName = $potentialKiller['name'];
                    }
                }
            }
        }
        
        // 3. 计算并应用死亡惩罚（完全还原原始项目 victim_penalty）
        $isPlayerKill = (isset($combat['target_type']) && $combat['target_type'] === 'player');
        
        // 基础惩罚：道行和实战经验各损失 2.5% (1/40)
        $baseDxLoss = intval($victim['daoxing'] / 40);
        $baseExpLoss = intval($victim['combat_exp'] / 40);
        
        // 如果是被玩家杀死，惩罚减半
        if ($isPlayerKill) {
            $dxLoss = intval($baseDxLoss / 2);
            $expLoss = intval($baseExpLoss / 2);
        } else {
            $dxLoss = $baseDxLoss;
            $expLoss = $baseExpLoss;
        }
        
        // 确保惩罚不为负数
        $dxLoss = max(0, $dxLoss);
        $expLoss = max(0, $expLoss);
        
        // === 潜能惩罚：超出learned_points部分减半（原始项目 victim_penalty）===
        $potentialLoss = 0;
        $currentPotential = intval($victim['potential'] ?? 0);
        $learnedPoints = intval($victim['learned_points'] ?? 0);
        if ($currentPotential > $learnedPoints) {
            $excessPotential = $currentPotential - $learnedPoints;
            $potentialLoss = intval($excessPotential / 2);  // 潜能减半惩罚（固定公式，保留）
        }
        
        // === 技能死亡惩罚：福缘判定（从配置读取参数）===
        $deathCfg = self::loadConfig()['death'];
        $kar = intval($victim['kar'] ?? 10);
        $skillLoss = false;
        if (mt_rand(0, $deathCfg['kar_check_max']) > $kar) {
            $skillLoss = true;
            // 随机降低一个已学技能的等级（从配置读取范围）
            $charSkills = Database::queryAll(
                "SELECT skill_id, level FROM character_skills WHERE char_id = ? AND level > 1",
                [$victimId]
            );
            if (!empty($charSkills)) {
                $randomSkill = $charSkills[array_rand($charSkills)];
                $skillId = $randomSkill['skill_id'];
                $currentLevel = intval($randomSkill['level']);
                $lossAmount = mt_rand(1, min($deathCfg['skill_loss_max'], $currentLevel - 1));
                Database::execute(
                    "UPDATE character_skills SET level = GREATEST(1, level - ?) WHERE char_id = ? AND skill_id = ?",
                    [$lossAmount, $victimId, $skillId]
                );
                log_game('SKILL_PENALTY', "{$victim['name']} 死亡时失去 {$skillId} 技能 {$lossAmount} 级（福缘判定失败: kar={$kar}）");
            }
        }
        
        // === 杀气重置为0（原始项目 victim_penalty）===
        // 原始代码: victim->set("bellicosity", 0);
        
        // 应用惩罚到数据库
        $sql = "UPDATE characters SET 
                daoxing = GREATEST(0, daoxing - ?),
                combat_exp = GREATEST(0, combat_exp - ?),
                potential = GREATEST(0, potential - ?),
                bellicosity = 0
                WHERE id = ?";
        Database::execute($sql, [$dxLoss, $expLoss, $potentialLoss, $victimId]);
        
        log_game('DEATH_PENALTY', "{$victim['name']} 失去 {$dxLoss}点道行、{$expLoss}点实战经验、{$potentialLoss}点潜能" . 
                 ($skillLoss ? '、技能等级下降' : '') . '、杀气归零' .
                 ($isPlayerKill ? '（玩家对战减半）' : ''));
        
        // 4. 广播谣言消息（参考原始LPC的COMBAT_D->announce death_rumor）
        $deathMsg = "{$victim['name']}莫明其妙地死了。";
        if (!empty($killerName)) {
            $deathMsg = "{$victim['name']}被{$killerName}吃掉了。";
        }
        
        // 添加死亡惩罚信息（不换行，保持为一条消息）
        if ($dxLoss > 0 || $expLoss > 0) {
            $penaltyInfo = "{$victim['name']}失去了{$dxLoss}年道行和{$expLoss}点武学！";
            $deathMsg .= " " . $penaltyInfo;
        }
        
        require_once HELPER_PATH . 'SystemBroadcast.php';
        SystemBroadcast::deathRumor($deathMsg);
        
        // 6. 清除受害者的战斗状态
        if (isset($combat['target_type']) && $combat['target_type'] === 'player') {
            self::clearAllCombatForTarget($victimId, 'player');
        }
        self::endCombat($victimId);
        
        // 7. 如果是玩家对战，给击杀者奖励并清除战斗状态
        if (isset($combat['target_type']) && $combat['target_type'] === 'player' && !empty($killerName)) {
            // 查找攻击者 ID
            $onlinePlayers = Database::queryAll(
                "SELECT id, family FROM characters WHERE online = 1 AND name = ?",
                [$killerName]
            );
            
            if (!empty($onlinePlayers)) {
                $killerId = $onlinePlayers[0]['id'];
                $killerFamily = $onlinePlayers[0]['family'];
                
                // 给击杀者奖励：获得受害者损失的 80%（参考原始项目 victim_lose）
                // 原始项目：gain = lose * 8 / 10
                // 剩余 20% 被系统回收，避免通货膨胀
                if ($dxLoss > 0 || $expLoss > 0) {
                    $killerDxGain = intval($dxLoss * 8 / 10);
                    $killerExpGain = intval($expLoss * 8 / 10);
                    
                    if ($killerDxGain > 0 || $killerExpGain > 0) {
                        $sql = "UPDATE characters SET 
                                daoxing = daoxing + ?,
                                combat_exp = combat_exp + ?
                                WHERE id = ?";
                        Database::execute($sql, [$killerDxGain, $killerExpGain, $killerId]);
                        
                        log_game('PVP_REWARD', "{$killerName} 从 {$victim['name']} 处获得 {$killerDxGain} 点道行和 {$killerExpGain} 点实战经验（80%转化率）");
                    }
                }
                
                // 增加杀气（击杀玩家）
                $bellicosityGain = 20; // 基础杀气增加
                
                // 门派杀气加成（如幽冥教）
                if (!empty($killerFamily)) {
                    require_once CONFIG_PATH . 'sects.php';
                    $sectConfig = getSectConfig($killerFamily);
                    if (isset($sectConfig['bonuses']['bellicosity_growth'])) {
                        $bellicosityGain = intval($bellicosityGain * $sectConfig['bonuses']['bellicosity_growth']);
                    }
                }
                
                Database::execute(
                    "UPDATE characters SET bellicosity = bellicosity + ? WHERE id = ?",
                    [$bellicosityGain, $killerId]
                );
                
                log_game('BELLICOSITY_GAIN', "{$killerName} 击杀玩家增加 {$bellicosityGain} 点杀气");
                
                self::endCombat($killerId);
                log_game('COMBAT_END', "{$killerName} (ID: {$killerId}) 的战斗状态已清除");
            }
        }
        
        // 7.5. 在传送前创建尸体（参考原始项目 damage.c:die() → make_corpse → move(environment())）
        // 保存受害者死亡前的位置，在该位置创建尸体
        $deathArea = $victim['current_area'] ?? 'city';
        $deathRoom = $victim['current_room'] ?? 'city/kezhan';
        
        $killerId = null;
        if (isset($combat['target_type']) && $combat['target_type'] === 'player' && !empty($killerName)) {
            $onlineKillers = Database::queryAll(
                "SELECT id FROM characters WHERE online = 1 AND name = ?",
                [$killerName]
            );
            if (!empty($onlineKillers)) {
                $killerId = intval($onlineKillers[0]['id']);
            }
        }
        
        require_once __DIR__ . '/../models/Corpse.php';
        $corpseId = Corpse::createPlayerCorpse(
            $victimId, 
            $victim['name'], 
            $deathArea, 
            $deathRoom, 
            $killerId, 
            $killerName
        );
        log_game('CORPSE_CREATED', "{$victim['name']} 的尸体(ID: {$corpseId})出现在 {$deathArea}/{$deathRoom}");
        
        // 将受害者的背包物品按25%概率转移到尸体中
        $victimItems = Database::queryAll(
            "SELECT ci.id, ci.item_id, ci.category, ci.quantity, ci.enchantments, ci.liquid_remaining, ci.liquid_type, ci.liquid_name, ci.series_no,
                    COALESCE(i.name, ci.item_id) as item_name, COALESCE(i.type, 'misc') as item_type,
                    COALESCE(i.no_drop, 0) as no_drop
             FROM character_inventory ci 
             LEFT JOIN items i ON ci.item_id = i.item_id AND (ci.category = i.category OR i.category = '')
             WHERE ci.char_id = ? AND ci.equipped = 0",
            [$victimId]
        );
        if (!empty($victimItems)) {
            $fabaoSeries = [];
            $fabaoRows = Database::queryAll(
                "SELECT series_no FROM character_fabao WHERE owner_id = ?",
                [$victimId]
            );
            foreach ($fabaoRows as $fb) {
                $fabaoSeries[$fb['series_no']] = true;
            }
            
            // 每件物品独立25%概率掉落
            $droppedItems = [];
            $droppedIds = [];
            foreach ($victimItems as $item) {
                // 水晶球永不掉落
                if (($item['item_id'] ?? '') === 'crystalball') {
                    continue;
                }
                // 天王披风永不掉落
                if (($item['item_id'] ?? '') === 'tianwang_coat') {
                    continue;
                }
                // 标记为no_drop的物品永不掉落
                if (!empty($item['no_drop'])) {
                    continue;
                }
                // 玩家自制法宝永不掉落
                if (!empty($item['series_no']) && isset($fabaoSeries[$item['series_no']])) {
                    continue;
                }
                if (mt_rand(1, 100) <= 25) {
                    $droppedItems[] = $item;
                    $droppedIds[] = intval($item['id']);
                }
            }
            if (!empty($droppedItems)) {
                Corpse::addItems($corpseId, $droppedItems);
                // 仅移除掉落的物品
                $placeholders = implode(',', array_fill(0, count($droppedIds), '?'));
                Database::execute("DELETE FROM character_inventory WHERE id IN ({$placeholders})", $droppedIds);
            }
        }
        
        // 8. 设置鬼魂状态并移动到地府
        // 将玩家移动到鬼门关（death/gate）
        // 使用数据库 is_ghost 字段代替 session（跨进程可见）
        $sql = "UPDATE characters SET 
                current_area = 'death', 
                current_room = 'death/gate',
                kee = 1,
                gin = 1,
                sen = 1,
                is_ghost = 1,
                hell_enter_time = ?
                WHERE id = ?";
        Database::execute($sql, [time(), $victimId]);
        
        // 9. 记录日志
        log_game('PLAYER_DEATH', "{$victim['name']} 死亡，被传送到鬼门关，尸体留在 {$deathArea}/{$deathRoom}");
        
        // 10. 减少寿命（如果有寿命系统）
        // TODO: 实现寿命系统
    }
    
    /**
     * 处理NPC死亡
     * @param int $npcId NPC ID
     * @param array $npc NPC数据
     * @param int|null $killerId 击杀者ID
     * @param string|null $killerName 击杀者名称
     * @return void
     */
    private static function handleNpcDeath(int $npcId, array $npc, ?int $killerId = null, ?string $killerName = null): void {
        // 获取NPC位置信息
        $roomArea = $npc['spawn_area'] ?? '';
        $roomId = $npc['spawn_room'] ?? '';
        
        // 从 spawn_room 中解析 area（如果 roomId 包含完整路径）
        if (empty($roomArea) && !empty($roomId) && strpos($roomId, '/') !== false) {
            $parts = explode('/', $roomId);
            $roomArea = $parts[0];
        }
        
        // === 特殊处理：泥娃娃死亡后化作泥水消失 ===
        // 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
        $npcIdVal = $npc['npc_id'] ?? '';
        $npcName = $npc['name'] ?? '';
        
        // 调试日志
        log_game('NIWAWA_DEBUG', "NPC ID: {$npcId}, npc_id: '{$npcIdVal}', name: '{$npcName}'");
        
        // 多重条件判断：支持 npc_id 或 name 匹配
        $isNiwawa = ($npcIdVal === 'niwawa') || 
                    (strtolower($npcIdVal) === 'niwawa') ||
                    ($npcName === '泥娃娃') ||
                    (strpos($npcName, '泥娃娃') !== false);
        
        if ($isNiwawa) {
            log_game('NIWAWA_DEBUG', "触发泥娃娃特殊死亡处理");
            
            // 泥娃娃死亡后不创建尸体，直接消失并输出特殊消息
            require_once DAEMON_PATH . 'MessageDaemon.php';
            
            $deathMessage = "\n\n泥娃娃一声惨叫，倒在地上挣扎了几下，\n眼前闪过一道奇异的光芒后，泥娃娃消失了，地上只留下一滩泥水。";
            
            // 广播消息给房间内其他玩家
            MessageDaemon::broadcastToRoom($roomId, $deathMessage, $killerId);
            
            // 同时发送消息给击杀者自己
            if ($killerId > 0) {
                MessageDaemon::sendToPlayer($killerId, $deathMessage, 'combat');
            }
            
            // 删除泥娃娃NPC记录（因为是临时召唤的）
            Database::execute("DELETE FROM npcs WHERE id = ?", [$npcId]);
            
            // 确保没有残留的尸体
            Database::execute("DELETE FROM corpses WHERE owner_type = 'npc' AND owner_id = ?", [$npcId]);
            
            // 记录日志
            log_game('NPC_DEATH', "泥娃娃 (ID: {$npcId}) 被击杀，化作泥水消失");
            return;
        } else {
            log_game('NIWAWA_DEBUG', "不是泥娃娃，继续正常死亡处理");
        }

        // === 特殊处理：蒸笼老人无敌，死亡后自动复活 ===
        // 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
        $isZhenglongLaoren = ($npcIdVal === 'zhenglonglaoren') ||
                            ($npcName === '蒸笼老人') ||
                            (strpos($npcName, '蒸笼老人') !== false);

        if ($isZhenglongLaoren) {
            require_once DAEMON_PATH . 'MessageDaemon.php';

            // 播放复活消息
            $reviveMessage = "\n\n" . HTML_HIGRN . '蒸笼老人微微一笑！' . HTML_NOR . "\n\n";

            // 广播消息给房间内其他玩家
            MessageDaemon::broadcastToRoom($roomId, $reviveMessage, $killerId);

            // 同时发送消息给击杀者自己
            if ($killerId > 0) {
                MessageDaemon::sendToPlayer($killerId, $reviveMessage, 'combat');
            }

            // 恢复所有气血、精神、法力、内力到满值
            $maxKee = $npc['max_kee'] ?? 5000;
            $maxGin = $npc['max_gin'] ?? 5000;
            $maxSen = $npc['max_sen'] ?? 5000;
            $maxForce = $npc['max_force'] ?? 5000;
            $maxMana = $npc['max_mana'] ?? 5000;

            Database::execute(
                "UPDATE npcs SET kee = ?, gin = ?, sen = ?, `force` = ?, mana = ? WHERE id = ?",
                [$maxKee, $maxGin, $maxSen, $maxForce, $maxMana, $npcId]
            );

            // 确保没有残留的尸体
            Database::execute("DELETE FROM corpses WHERE owner_type = 'npc' AND owner_id = ?", [$npcId]);

            // 记录日志
            log_game('TIANMO_INVINCIBLE', "蒸笼老人 (ID: {$npcId}) 被击杀，自动复活");
            return;
        }
        
        // === 特殊处理：唐僧（取经人）死亡 → 生死轮回 → 30分钟复活 ===
        // 还原原始项目 qujingren.c die() 函数
        $isTangSeng = ($npcIdVal === 'qujing ren') || 
                      ($npcName === '陈玄奘') ||
                      (strpos($npcName, '玄奘') !== false || strpos($npcName, '唐僧') !== false);
        
        if ($isTangSeng) {
            require_once DAEMON_PATH . 'QujingHandler.php';
            QujingHandler::handleTangSengDeath($npcId);
            // 不创建尸体、不掉落，直接return
            return;
        }
        
        // === 特殊处理：金鳞怪(通天河Boss)死亡事件 ===
        // 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
        $isJinyu = ($npcIdVal === 'jinyu_guai') || 
                   ($npcIdVal === 'jinyu') ||
                   ($npcName === '金鳞怪') ||
                   (strpos($npcName, '金鳞') !== false);
        
        if ($isJinyu) {
            require_once DAEMON_PATH . 'TongtianHandler.php';
            TongtianHandler::handleJinyuBossDeath($npcId, $npc, $killerId, $killerName);
            // 注意：不return，继续执行正常死亡流程（创建尸体、掉落物品等）
            // 金鳞怪的尸体和掉落物正常生成
            log_game('TONGTIAN_BOSS', "金鳞怪 (ID: {$npcId}) 被击杀，触发通天河事件");
        }
        
        // === 特殊处理：通天河化身NPC被攻击时消失 ===
        // 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
        // 化身NPC是神仙的化身，被攻击时会揭示身份并消失
        $isHuashen = ($npcIdVal === 'huashen') || 
                     ($npcIdVal === 'xiao tong') ||
                     ($npcName === '黄发小童') ||
                     (strpos($npcName, '小童') !== false && strpos($roomId, 'tongtian') !== false);
        
        if ($isHuashen && $killerId) {
            require_once DAEMON_PATH . 'TongtianHandler.php';
            $killer = CharacterModel::find($killerId);
            if ($killer) {
                $result = TongtianHandler::handleHuashenAttack($killerId, $killer, $npc);
                // 化身NPC消失后不创建尸体，直接返回
                log_game('HUASHEN_ATTACK', "化身NPC (ID: {$npcId}) 被{$killerName}攻击后消失");
                return;
            }
        }
        
        // === 特殊处理：金兜山青牛精死亡事件 ===
        // 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
        // 青牛精是太上老君的坐骑，死亡时现原形并召唤老君
        $isDujiao = ($npcIdVal === 'dujiaosi_dawang') || 
                    ($npcIdVal === 'dujiaosi') ||
                    ($npcIdVal === 'dawang') ||
                    ($npcName === '独角兕大王') ||
                    (strpos($npcName, '青牛') !== false && strpos($roomId, 'jindou') !== false);
        
        if ($isDujiao && $killerId) {
            require_once DAEMON_PATH . 'JindouHandler.php';
            JindouHandler::handleDujiaoDeath($npcId, $npc, $killerId, $killerName);
            // 青牛精死亡后不创建尸体，直接返回
            log_game('JINDOU_BOSS', "青牛精 (ID: {$npcId}) 被{$killerName}击败");
            return;
        }
        
        // === 特殊处理：宝象国野路三关连环死亡链条 ===
        // 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
        // 女子死亡 → 生成夫人 → 夫人死亡 → 生成公公 → 公公死亡 → 白骨
        $isBaoxiangArea = (strpos($roomId, 'baoxiang') !== false) || ($roomArea === 'qujing');
        
        $isBaoxiangNuzi = $isBaoxiangArea && (($npcIdVal === 'nuzi') || ($npcName === '女子'));
        $isBaoxiangFuren = $isBaoxiangArea && ($npcIdVal === 'baoxiang_furen');
        $isBaoxiangGonggong = $isBaoxiangArea && ($npcIdVal === 'baoxiang_gonggong');
        
        if (($isBaoxiangNuzi || $isBaoxiangFuren || $isBaoxiangGonggong) && $killerId) {
            require_once DAEMON_PATH . 'BaoxiangHandler.php';
            BaoxiangHandler::handleNpcDeathChain($npcId, $npc, $killerId, $killerName, $roomId, $roomArea);
            // 继续执行普通死亡流程（创建尸体、掉落物品等）
            log_game('BAOXIANG_CHAIN', "宝象国NPC {$npcName} (ID: {$npcId}) 被击杀，触发死亡链条");
        }
        
        // === 特殊处理：平顶山四Boss死亡链条 ===
        // 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
        // 狐阿七大王 → 老奶奶 → 金角大王 → 银角大王 → 全部击杀后太上老君验证
        $isPingdingArea = (strpos($roomId, 'pingding') !== false) || ($roomArea === 'qujing');
        
        $isPingdingBoss = $isPingdingArea && (
            ($npcIdVal === 'huaqidawang') ||
            ($npcIdVal === 'laonainai') ||
            ($npcIdVal === 'jinjiaodawang') ||
            ($npcIdVal === 'yinjiaodawang')
        );
        
        if ($isPingdingBoss && $killerId) {
            require_once DAEMON_PATH . 'PingdingHandler.php';
            PingdingHandler::handleBossDeath($npcId, $npc, $killerId, $killerName, $roomId);
            // 继续执行普通死亡流程（创建尸体、掉落物品等）
            // 金角/银角大王的法宝已由 PingdingHandler 单独处理掉落
            log_game('PINGDING_BOSS', "平顶山Boss {$npcName} (ID: {$npcId}) 被{$killerName}击杀");
        }
        
        // 1. 创建尸体
        $corpseId = Corpse::createNpcCorpse(
            $npcId,
            $npc['name'] ?? 'Unknown',
            $roomArea,
            $roomId,
            $killerId,
            $killerName
        );
        
        // 2. 生成掉落物品
        Corpse::dropNpcItems($corpseId, $npc);
        
        // 2.5 龙王九子死亡掉落龙珠
        if ($killerId && preg_match('/^long(\d+)$/', $npcIdVal, $m)) {
            require_once DAEMON_PATH . 'LongzhuHandler.php';
            LongzhuHandler::onDragonKill($killerId, $npcId, $npcIdVal);
            log_game('LONGZHU_DROP', "龙王九子 {$npcName} ({$npcIdVal}) 被击杀，触发龙珠掉落");
        }
        
        // 3. 隐藏NPC（通过session临时标记死亡，10分钟后自动重生）
        $deathKey = "npc_dead_" . $npcId;
        $respawnTime = time() + 600; // 10分钟
        $_SESSION[$deathKey] = $respawnTime;
        
        // 4. 记录重生
        NpcRespawn::recordDeath(
            $npcId,
            $npc['name'] ?? 'Unknown',
            $roomArea,
            $roomId
        );
        
        // 5. 击杀NPC增加杀气（1点）
        if ($killerId) {
            Database::execute(
                "UPDATE characters SET bellicosity = bellicosity + 1 WHERE id = ?",
                [$killerId]
            );
            log_game('BELLICOSITY_GAIN', "{$killerName} 击杀NPC {$npc['name']} 增加 1 点杀气");
        }

        // 6. 清除所有玩家的战斗状态（确保NPC死亡后不再攻击）
        self::clearAllCombatForTarget($npcId, 'npc');

        // 7. 记录日志
        log_game('NPC_DEATH', "NPC {$npc['name']} (ID: {$npcId}) 被击杀，尸体ID: {$corpseId}");
    }

    // ==================== 战斗消息辅助方法 ====================

    /**
     * 获取随机肢体部位
     */
    private static function getRandomLimb(): string {
        return self::$limbs[array_rand(self::$limbs)];
    }

    /**
     * 变量替换：将 action_text 和 damage_msg 中的占位符替换为实际内容
     * $N → 攻击者名, $n → 目标名, $l → 肢体, $w → 武器名, $p → 目标名(所属格)
     *
     * @param string $text 原始文本
     * @param string $attackerName 攻击者名称（攻击者视角为"你"，目标视角为攻击者名）
     * @param string $targetName 目标名称（攻击者视角为目标名，目标视角为"你"）
     * @param string $limb 肢体部位
     * @param string $weaponName 武器名称
     * @return string 替换后的文本
     */
    private static function replaceVars(string $text, string $attackerName, string $targetName, string $limb, string $weaponName = ''): string {
        $replacements = [
            '$N' => $attackerName,
            '$n' => $targetName,
            '$p' => $targetName,  // $p 为目标所属格，与 $n 同值（消息模板中已含"的"）
            '$l' => $limb,
            '$w' => $weaponName,
        ];
        return strtr($text, $replacements);
    }

    /**
     * 获取攻击动作文本（从数据库技能系统获取）
     * 根据攻击者当前启用的攻击技能，从 skill_actions 获取 action_text
     *
     * @param array $attacker 攻击者角色数据
     * @param string|null $weaponType 武器类型（如 sword, blade, unarmed 等）
     * @return string|null 动作文本，无技能时返回 null
     */
    private static function getAttackActionText(array $attacker, ?string $weaponType = null): ?string {
        $attackerId = $attacker['id'];

        // 确定武器类型
        if ($weaponType === null) {
            $equippedWeapon = self::getEquippedWeapon($attackerId);
            $weaponType = $equippedWeapon ? ($equippedWeapon['weapon_type'] ?? 'unarmed') : 'unarmed';
        }

        // 从 skill_map 获取映射的攻击技能
        $mappedSkill = SkillManager::querySkillMapped($attackerId, $weaponType);

        // 如果武器类型没有映射，尝试 force 内功映射
        if (!$mappedSkill) {
            $mappedSkill = SkillManager::querySkillMapped($attackerId, 'force');
        }

        if (!$mappedSkill) {
            return null;
        }

        // 获取技能动作文本
        return SkillManager::getRandomActionText($mappedSkill);
    }

    /**
     * 获取攻击者的伤害类型（基于技能配置）
     *
     * @param array $attacker 攻击者角色数据
     * @param string|null $weaponType 武器类型
     * @return string 伤害类型（英文）
     */
    private static function getDamageTypeForAttacker(array $attacker, ?string $weaponType = null): string {
        $attackerId = $attacker['id'];

        if ($weaponType === null) {
            $equippedWeapon = self::getEquippedWeapon($attackerId);
            $weaponType = $equippedWeapon ? ($equippedWeapon['weapon_type'] ?? 'unarmed') : 'unarmed';
        }

        $mappedSkill = SkillManager::querySkillMapped($attackerId, $weaponType);
        if (!$mappedSkill) {
            $mappedSkill = SkillManager::querySkillMapped($attackerId, 'force');
        }

        if ($mappedSkill) {
            $skillConfig = SkillManager::getSkillConfig($mappedSkill);
            if ($skillConfig && isset($skillConfig['damage_type'])) {
                return $skillConfig['damage_type'];
            }
        }

        return 'blunt';
    }

    /**
     * 为灭妖妖怪动态生成战斗AI行为
     * 基于 mieyao_yaoguai 表中的 skills_json/cast_spells_json/exert_funcs_json/perform_actions_json
     * 
     * @param array $yaoguai 妖怪数据
     * @return array 增强后的妖怪数据（含 chat_chance_combat 和 chat_msg_combat）
     */
    private static function enrichYaoguaiAi(array $yaoguai): array {
        $castSpells = json_decode($yaoguai['cast_spells_json'] ?? '[]', true) ?: [];
        $exertFuncs = json_decode($yaoguai['exert_funcs_json'] ?? '[]', true) ?: [];
        $performActions = json_decode($yaoguai['perform_actions_json'] ?? '[]', true) ?: [];
        $castChance = intval($yaoguai['cast_chance'] ?? 10);
        
        $combatMsgs = [];
        
        // 法术
        $spellNames = [
            'freez' => '雪舞风灵', 'breathe' => '龙神吐息', 'thunder' => '五雷咒',
            'light' => '苍灵箭', 'dingshen' => '定身法', 'gouhun' => '勾魂术',
            'arrow' => '落日神箭', 'bighammer' => '大力降魔杵', 'huanying' => '幻影术',
            'suliao' => '速疗术', 'zhenhuo' => '太乙真火', 'baxian' => '八仙大阵',
            'juanbi' => '扭转乾坤', 'tuntian' => '魔兽吞天',
        ];
        foreach ($castSpells as $spell) {
            $spellName = $spellNames[$spell] ?? $spell;
            $combatMsgs[] = ['spell', $spell, "{$yaoguai['npc_name']}口中念念有词，施展出「{$spellName}」！"];
        }
        
        // 内功
        $exertNames = [
            'roar' => '碧海龙吟', 'shield' => '护体神功', 'sheqi' => '摄气诀',
            'jingxin' => '静心诀',
        ];
        foreach ($exertFuncs as $exert) {
            $exertName = $exertNames[$exert] ?? $exert;
            $combatMsgs[] = ['exert', $exert, "{$yaoguai['npc_name']}运起内功，施展「{$exertName}」！"];
        }
        
        // 绝招
        $actionNames = [
            'pili' => '霹雳三打', 'qiankun' => '乾坤一棒', 'three' => '神人鬼三式',
            'lunhui' => '六道轮回', 'sanban' => '无敌三板斧',
        ];
        foreach ($performActions as $perform) {
            $action = $perform[1];
            $actionName = $actionNames[$action] ?? $action;
            $combatMsgs[] = ['perform', $action, "{$yaoguai['npc_name']}使出绝招「{$actionName}」！"];
        }
        
        // 如果没有任何特殊行为，添加默认台词
        if (empty($combatMsgs)) {
            $combatMsgs[] = "{$yaoguai['npc_name']}面目狰狞，恶狠狠地扑了过来！";
        }
        
        // 设置战斗AI参数
        $yaoguai['chat_chance_combat'] = $castChance;
        $yaoguai['chat_msg_combat'] = json_encode($combatMsgs, JSON_UNESCAPED_UNICODE);
        
        return $yaoguai;
    }

    /**
     * 获取NPC攻击动作文本
     * 从 npc_skills 表获取NPC的技能，然后从 skill_actions 获取动作文本
     * 无技能时根据NPC种族从 race.php 配置中随机选取战斗动作
     *
     * @param int $npcId NPC ID
     * @return array|null 包含 text 和 damage_type 的数组，无动作时返回 null
     */
    private static function getNpcAttackActionText(int $npcId): ?array {
        try {
            // 直接查询 npc_skills 中的 skill_name（技能ID字段）
            $sql = "SELECT skill_name, skill_level FROM npc_skills WHERE npc_id = ? ORDER BY skill_level DESC";
            $skills = Database::queryAll($sql, [$npcId]);

            // 遍历技能，查找有 action_text 的技能
            foreach ($skills as $skill) {
                $skillId = $skill['skill_name'] ?? '';
                if (empty($skillId)) continue;

                $actionText = SkillManager::getRandomActionText($skillId);
                if ($actionText) {
                    // 有技能时，尝试获取技能的伤害类型
                    $skillConfig = SkillManager::getSkillConfig($skillId);
                    $damageType = $skillConfig['damage_type'] ?? 'blunt';
                    return ['text' => $actionText, 'damage_type' => $damageType];
                }
            }
        } catch (\Exception $e) {
            // 忽略错误，继续尝试种族动作
        }

        // 无技能时，根据NPC种族使用种族战斗动作
        try {
            $sql = "SELECT race FROM npcs WHERE id = ? LIMIT 1";
            $npc = Database::queryOne($sql, [$npcId]);
            $raceConfig = require __DIR__ . '/../config/race.php';
            
            // 获取种族，默认为人类
            $raceKey = ($npc && !empty($npc['race'])) ? $npc['race'] : 'human';

            // 种族值映射（兼容英文和中文）
            $raceMap = [
                'human'   => '人类',
                'monster' => '野兽',
                'demon'   => '妖魔',
                '人类'    => '人类',
                '野兽'    => '野兽',
                '妖魔'    => '妖魔',
            ];

            if (isset($raceMap[$raceKey])) {
                $raceKey = $raceMap[$raceKey];
            }

            if (isset($raceConfig['races'][$raceKey]['combat_actions'])) {
                $actions = $raceConfig['races'][$raceKey]['combat_actions'];
                if (!empty($actions)) {
                    $action = $actions[array_rand($actions)];
                    $name = $action['name'] ?? '攻击';
                    $damageType = $action['damage_type'] ?? 'blunt';
                    // 构造动作文本模板
                    $text = '$N使出「' . $name . '」，向$n的$l攻去';
                    return ['text' => $text, 'damage_type' => $damageType];
                }
            }
        } catch (\Exception $e) {
            // 忽略错误
        }

        return null;
    }

    // ==================== hit_ob 映射技能辅助方法 ====================

    /**
     * 获取攻击者的映射武器技能ID
     * @param int $attackerId 攻击者ID
     * @param bool $isNpc 是否为NPC
     * @return string|null 映射的武器技能ID
     */
    private static function getMappedWeaponSkill(int $attackerId, bool $isNpc = false): ?string
    {
        try {
            if ($isNpc) {
                // NPC：取 npc_skills 中等级最高的技能作为武器技能
                $sql = "SELECT skill_name FROM npc_skills WHERE npc_id = ? ORDER BY skill_level DESC LIMIT 1";
                $result = Database::queryOne($sql, [$attackerId]);
                return $result ? $result['skill_name'] : null;
            } else {
                // 玩家：根据装备武器类型查询映射
                $equippedWeapon = self::getEquippedWeapon($attackerId);
                $weaponType = $equippedWeapon ? ($equippedWeapon['weapon_type'] ?? 'unarmed') : 'unarmed';
                return SkillManager::querySkillMapped($attackerId, $weaponType);
            }
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 获取攻击者的映射内功技能ID
     * @param int $attackerId 攻击者ID
     * @param bool $isNpc 是否为NPC
     * @return string|null 映射的内功技能ID
     */
    private static function getMappedForceSkill(int $attackerId, bool $isNpc = false): ?string
    {
        try {
            if ($isNpc) {
                // NPC：从 npc_skills 中查找内功类型技能（通过 skills 表判断类型）
                $sql = "SELECT ns.skill_name FROM npc_skills ns
                        LEFT JOIN skills s ON ns.skill_name = s.skill_id
                        WHERE ns.npc_id = ? AND s.type = 'force'
                        ORDER BY ns.skill_level DESC LIMIT 1";
                $result = Database::queryOne($sql, [$attackerId]);
                return $result ? $result['skill_name'] : null;
            } else {
                // 玩家：查询 force 类型的映射
                return SkillManager::querySkillMapped($attackerId, 'force');
            }
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 获取防守方的内力相关数据（供 calculateForceHitOb 使用）
     * 
     * @param array $combat 战斗状态
     * @param string $attackerForceSkill 攻击者的内功技能ID
     * @param int $attackerId 攻击者ID
     * @return array 包含 force, force_skill_level, attacker_force_skill
     */
    private static function getDefenderForceData(array $combat, string $attackerForceSkill, int $attackerId): array {
        $targetId = $combat['target_id'] ?? 0;
        $targetType = $combat['target_type'] ?? 'npc';
        
        $data = [
            'force' => 0,
            'force_skill_level' => 0,
            'attacker_force_skill' => 0,
        ];
        
        try {
            if ($targetType === 'npc' || $targetType === 'yaoguai') {
                // NPC/妖怪
                if ($targetType === 'yaoguai') {
                    $row = Database::queryOne("SELECT `force` FROM mieyao_yaoguai WHERE id = ? LIMIT 1", [$targetId]);
                } else {
                    $row = Database::queryOne("SELECT `force` FROM npcs WHERE id = ? LIMIT 1", [$targetId]);
                }
                $data['force'] = $row ? intval($row['force'] ?? 0) : 0;
                
                // NPC 的内功技能等级
                $npcForce = Database::queryOne(
                    "SELECT ns.skill_level FROM npc_skills ns
                     LEFT JOIN skills s ON ns.skill_name = s.skill_id
                     WHERE ns.npc_id = ? AND s.type = 'force'
                     ORDER BY ns.skill_level DESC LIMIT 1",
                    [$targetId]
                );
                $data['force_skill_level'] = $npcForce ? intval($npcForce['skill_level']) : 0;
            } else {
                // 玩家防守方
                $row = Database::queryOne("SELECT `force` FROM characters WHERE id = ? LIMIT 1", [$targetId]);
                $data['force'] = $row ? intval($row['force'] ?? 0) : 0;
                
                // 玩家的内功技能等级
                $defenderForceSkill = self::getMappedForceSkill($targetId, false);
                if ($defenderForceSkill) {
                    $data['force_skill_level'] = SkillManager::getSkillLevel($targetId, $defenderForceSkill);
                }
            }
            
            // 攻击者的内功技能等级
            $data['attacker_force_skill'] = SkillManager::getSkillLevel($attackerId, $attackerForceSkill);
            
        } catch (\Exception $e) {
            // 出错时返回默认值
        }
        
        return $data;
    }

    // ==================== 动作预选方法（还原原始项目 reset_action） ====================
    
    /**
     * 预选战斗招式（还原原始项目 attack.c::reset_action）
     * 战斗开始时随机选取一个招式并持久化到 session，
     * 后续每回合使用该预选招式，而非每回合重新随机。
     * 武器变更时由 doAttack() 检测并重新调用。
     *
     * @param int $attackerId 攻击者ID
     */
    public static function resetAction(int $attackerId): void {
        $action = null;
        $weaponId = 'unarmed';
        
        try {
            // 获取当前装备武器
            $equippedWeapon = self::getEquippedWeapon($attackerId);
            $weaponId = $equippedWeapon ? ($equippedWeapon['item_id'] ?? $equippedWeapon['id'] ?? '') : 'unarmed';
            
            // 确定映射武器技能
            $weaponType = $equippedWeapon ? ($equippedWeapon['weapon_type'] ?? 'unarmed') : 'unarmed';
            $skillId = SkillManager::querySkillMapped($attackerId, $weaponType);
            if (!$skillId) {
                $skillId = SkillManager::querySkillMapped($attackerId, 'force');
            }
            
            if ($skillId) {
                // 获取该技能的所有可用招式
                $actions = SkillManager::getSkillActions($skillId);
                if (!empty($actions)) {
                    // 过滤掉 action_text 为空的招式
                    $validActions = array_values(array_filter($actions, function($a) {
                        return !empty($a['action_text']);
                    }));
                    
                    if (!empty($validActions)) {
                        // 随机选取一个招式
                        $action = $validActions[array_rand($validActions)];
                    }
                }
            }
        } catch (\Exception $e) {
            // 异常时不设置预选，使用普通攻击
        }
        
        // 存入 session
        $_SESSION["combat_action_{$attackerId}"] = [
            'action' => $action,
            'weapon_id' => $weaponId
        ];
    }
    
    // ==================== 自动技能触发方法 ====================

    /**
     * 判断攻击者本回合是否触发特殊招式
     *
     * 触发条件：
     * - 攻击者有映射的武器技能（或内功技能）
     * - 攻击者内力大于最大内力的10%
     * - 按技能等级计算的概率随机触发
     *
     * @param int $attackerId 攻击者ID
     * @param bool $isNpc 是否为NPC
     * @return bool 是否触发
     */
    private static function shouldPerformSpecialAction(int $attackerId, bool $isNpc = false): bool {
        try {
            if ($isNpc) {
                // NPC：查询 npc_skills 获取技能等级
                $sql = "SELECT skill_name, skill_level FROM npc_skills WHERE npc_id = ? ORDER BY skill_level DESC LIMIT 1";
                $npcSkill = Database::queryOne($sql, [$attackerId]);
                if (!$npcSkill) {
                    return false;
                }
                $skillLevel = intval($npcSkill['skill_level']);

                // NPC内力检查：查询 NPC 的 force / max_force
                $sql = "SELECT `force`, max_force FROM npcs WHERE id = ? LIMIT 1";
                $npc = Database::queryOne($sql, [$attackerId]);
                if ($npc) {
                    $force = intval($npc['force'] ?? 0);
                    $maxForce = intval($npc['max_force'] ?? 0);
                    // 如果 NPC 有内力系统且不足10%，则不触发
                    if ($maxForce > 0 && $force < intval($maxForce * 0.1)) {
                        return false;
                    }
                }
            } else {
                // 玩家：查询 character_skill_map 获取映射的武器技能
                // 先查装备武器类型，再查映射
                $equippedWeapon = self::getEquippedWeapon($attackerId);
                $weaponType = $equippedWeapon ? ($equippedWeapon['weapon_type'] ?? 'unarmed') : 'unarmed';

                $mappedSkill = SkillManager::querySkillMapped($attackerId, $weaponType);
                // 武器类型无映射则尝试内功映射
                if (!$mappedSkill) {
                    $mappedSkill = SkillManager::querySkillMapped($attackerId, 'force');
                }
                if (!$mappedSkill) {
                    return false;
                }

                // 获取技能等级（使用原始等级）
                $skillLevel = SkillManager::getSkillLevel($attackerId, $mappedSkill);
                if ($skillLevel < 1) {
                    return false;
                }

                // 玩家内力检查：从数据库读取 force / max_force
                $sql = "SELECT `force`, max_force FROM characters WHERE id = ? LIMIT 1";
                $charData = Database::queryOne($sql, [$attackerId]);
                if ($charData) {
                    $force = intval($charData['force'] ?? 0);
                    $maxForce = intval($charData['max_force'] ?? 0);
                    // 内力不足时不触发（从配置读取阈值）
                    $saCfg = self::loadConfig()['special_action'];
                    if ($maxForce > 0 && $force < intval($maxForce * $saCfg['force_threshold'])) {
                        return false;
                    }
                }
            }

            // 计算触发概率（从配置读取参数）
            $triggerChance = min($saCfg['max_chance'], $saCfg['base_chance'] + intval($skillLevel / $saCfg['skill_level_div']));
            $roll = mt_rand(1, 100);
            return $roll <= $triggerChance;

        } catch (\Exception $e) {
            // 出现异常时不触发，保证战斗系统稳定
            return false;
        }
    }

    /**
     * 随机选取攻击者当前映射技能的一个招式
     *
     * @param int $attackerId 攻击者ID
     * @param bool $isNpc 是否为NPC
     * @return array|null 招式数据数组，无招式时返回 null
     */
    private static function selectRandomAction(int $attackerId, bool $isNpc = false): ?array {
        try {
            $skillId = null;

            if ($isNpc) {
                // NPC：取第一个（等级最高的）技能
                $sql = "SELECT skill_name, skill_level FROM npc_skills WHERE npc_id = ? ORDER BY skill_level DESC LIMIT 1";
                $npcSkill = Database::queryOne($sql, [$attackerId]);
                if (!$npcSkill) {
                    return null;
                }
                $skillId = $npcSkill['skill_name'];
            } else {
                // 玩家：根据装备武器类型确定映射的技能ID
                $equippedWeapon = self::getEquippedWeapon($attackerId);
                $weaponType = $equippedWeapon ? ($equippedWeapon['weapon_type'] ?? 'unarmed') : 'unarmed';

                $skillId = SkillManager::querySkillMapped($attackerId, $weaponType);
                if (!$skillId) {
                    $skillId = SkillManager::querySkillMapped($attackerId, 'force');
                }
            }

            if (empty($skillId)) {
                return null;
            }

            // 获取该技能的所有招式
            $actions = SkillManager::getSkillActions($skillId);
            if (empty($actions)) {
                return null;
            }

            // 过滤掉 action_text 为空的招式
            $validActions = array_values(array_filter($actions, function($a) {
                return !empty($a['action_text']);
            }));

            if (empty($validActions)) {
                return null;
            }

            // 随机返回一个招式
            return $validActions[array_rand($validActions)];

        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 格式化招式文本，替换占位符并清除MUD颜色代码
     *
     * 占位符说明：
     * - $N  → 攻击者名称
     * - $n  → 防御者名称
     * - $w  → 武器名称
     * - $l  → 随机攻击部位
     *
     * MUD颜色代码（HTML_HIRED、HTML_NOR、HIW 等）将被去除，保留纯文字
     *
     * @param string $actionText 招式原始文本
     * @param string $attackerName 攻击者名称
     * @param string $defenderName 防御者名称
     * @param string $weaponName 武器名称（可选）
     * @return string 格式化后的字符串
     */
    public static function formatActionText(
        string $actionText,
        string $attackerName,
        string $defenderName,
        string $weaponName = ''
    ): string {
        // 随机选取攻击部位
        $limb = self::getRandomLimb();

        // 替换占位符
        $text = strtr($actionText, [
            '$N' => $attackerName,
            '$n' => $defenderName,
            '$w' => $weaponName,
            '$l' => $limb,
            '$p' => $defenderName,  // $p 所属格，与 $n 同值
        ]);

        // 清除MUD颜色宏（HTML_HIRED、HTML_HICYN、HTML_HIGRN、HIYEL、HTML_HIMAG、HIW、HTML_NOR 等）
        // 这些在PHP中是常量字符串，如 "\033[1;31m" 之类的ANSI序列
        // 先尝试去除 ANSI 转义序列
        $text = preg_replace('/\x1B\[[0-9;]*[mK]/', '', $text);

        // 去除可能残留的MUD标记关键字（不含引号的大写缩写）
        // 如 HTML_HIRED HTML_NOR HTML_HICYN HTML_HIGRN HIYEL HTML_HIMAG HIW HIBLU 等
        $text = preg_replace('/\b(HTML_HIRED|HTML_NOR|HTML_HICYN|HTML_HIGRN|HIYEL|HTML_HIMAG|HIW|HIBLU|HIC|HIPUR|HTML_HIWHT)\b/', '', $text);

        return trim($text);
    }

    /**
     * 检查蓬莱三老挑战奖励（切磋模式下HP降到0时调用）
     * 参考 NpcInquiryHelper::handleAskMe 中的配置
     * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
     * 
     * @param int $targetId 目标NPC的id（主键）
     * @param string $targetType 目标类型
     * @param int $attackerId 攻击者ID
     * @return string|null 奖励消息，如果不是三老NPC则返回null
     */
    private static function checkSanxingReward(int $targetId, string $targetType, int $attackerId): ?string {
        if ($targetType !== 'npc') {
            return null;
        }
        
        // 蓬莱三老配置（与 handleAskMe 和 applyDamage 中的配置保持一致）
        $sanxingConfig = [
            'luxing' => [
                'name' => '禄星',
                'item_id' => 'jiaoli',
                'item_name' => '交梨',
                'fight_mark' => 'luxing_fight',
                'stock_key' => 'luxing_jiaoli_stock',
                'cooldown_key' => 'luxing_jiaoli_cooldown',
                'last_winner_key' => 'luxing_last_winner',
                'log_type' => 'LUXING_FIGHT',
            ],
            'shouxing' => [
                'name' => '寿星',
                'item_id' => 'biou',
                'item_name' => '碧藕',
                'fight_mark' => 'shouxing_fight',
                'stock_key' => 'shouxing_biou_stock',
                'cooldown_key' => 'shouxing_biou_cooldown',
                'last_winner_key' => 'shouxing_last_winner',
                'log_type' => 'SHOUXING_FIGHT',
            ],
            'fuxing' => [
                'name' => '福星',
                'item_id' => 'huozao',
                'item_name' => '火枣',
                'fight_mark' => 'fuxing_fight',
                'stock_key' => 'fuxing_huozao_stock',
                'cooldown_key' => 'fuxing_huozao_cooldown',
                'last_winner_key' => 'fuxing_last_winner',
                'log_type' => 'FUXING_FIGHT',
            ],
        ];
        
        $npc = Database::queryOne("SELECT npc_id, name, max_kee FROM npcs WHERE id = ? LIMIT 1", [$targetId]);
        if (!$npc) {
            return null;
        }
        
        $npcStringId = $npc['npc_id'] ?? '';
        $config = $sanxingConfig[$npcStringId] ?? null;
        if (!$config) {
            return null;
        }
        
        $npcName = $npc['name'] ?? $config['name'];
        $itemName = $config['item_name'];
        $itemId = $config['item_id'];
        $fightMark = $config['fight_mark'];
        $stockKey = $config['stock_key'];
        $cooldownKey = $config['cooldown_key'];
        $lastWinnerKey = $config['last_winner_key'];
        $logType = $config['log_type'];
        
        // 获取攻击者信息
        $attacker = CharacterModel::find($attackerId);
        if (!$attacker) {
            return null;
        }
        
        // 检查攻击者是否有对应的战斗标记
        $hasFightMark = Database::queryOne(
            "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = ? AND temp_value = '1'",
            [$attackerId, $fightMark]
        );
        
        if (!$hasFightMark) {
            // 没有标记（不是通过问话题发起的战斗），不给物品
            return HTML_HIYEL . "{$npcName}纵身一跃，化作一道白光直冲云霄而去！（你似乎错过了什么……）" . HTML_NOR;
        }
        
        try {
            // 给予对应物品
            require_once MODEL_PATH . 'Item.php';
            ItemModel::addToInventory($attackerId, $itemId, 1, 'obj');
            
            // 消耗存货
            Database::execute(
                "UPDATE variables SET `value` = '0', updated_at = NOW() WHERE var_key = ?",
                [$stockKey]
            );
            
            // 设置冷却时间
            $timingCfg = self::loadConfig()['timing'];
            $cooldown = time() + $timingCfg['scatter_item_cd_base'] + mt_rand(0, $timingCfg['scatter_item_cd_rand']);
            Database::execute(
                "INSERT INTO variables (var_key, `value`, updated_at) VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE `value` = ?, updated_at = NOW()",
                [$cooldownKey, $cooldown, $cooldown]
            );
            
            // 记录最后获胜者
            Database::execute(
                "INSERT INTO variables (var_key, `value`, updated_at) VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE `value` = ?, updated_at = NOW()",
                [$lastWinnerKey, $attackerId, $attackerId]
            );
            
            // 清理战斗标记
            Database::execute("DELETE FROM character_temp WHERE char_id = ? AND temp_key = ?", [$attackerId, $fightMark]);
            
            // 恢复NPC的HP
            $maxKee = $npc['max_kee'] ?? 1000;
            if ($maxKee) {
                Database::execute("UPDATE npcs SET kee = ? WHERE id = ?", [$maxKee, $targetId]);
            }
            
            // 广播认输消息
            require_once DAEMON_PATH . 'MessageDaemon.php';
            $surrenderMsg = HTML_HICYN . "{$npcName}叹了口气，说道：好汉，饶了我吧！" . HTML_NOR . "\n";
            $surrenderMsg .= HTML_HIYEL . "{$npcName}从怀中取出一枚{$itemName}，递给了{$attacker['name']}。" . HTML_NOR . "\n";
            $surrenderMsg .= HTML_HICYN . "然后{$npcName}纵身一跃，化作一道白光直冲云霄而去！" . HTML_NOR;
            
            if (!empty($attacker['current_room'])) {
                MessageDaemon::broadcastToRoom($attacker['current_room'], $surrenderMsg, intval($attackerId), 'room');
            }
            
            log_game($logType, "{$attacker['name']} 击败了{$npcName}，获得{$itemName}");
            
            return HTML_HIGRN . "你获得了{$itemName}！" . HTML_NOR;
            
        } catch (\Exception $e) {
            error_log("CombatDaemon::checkSanxingReward error: " . $e->getMessage());
            return null;
        }
    }
}

