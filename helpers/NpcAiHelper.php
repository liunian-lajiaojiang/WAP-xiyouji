<?php
/**
 * NPC AI辅助类
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 负责：
 * - NPC主动攻击判定（玩家进入房间时）
 * - 玩家杀气狂暴判定（玩家进入房间时，还原LPC attack.c:265 berserk逻辑）
 * - NPC是否接受战斗（fight命令）
 * - NPC战斗回合AI行为（施法/招式/台词）
 * - NPC脱战判定（血量低时逃跑）
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/GaoNpcHelper.php';
require_once __DIR__ . '/MurenHelper.php';
require_once __DIR__ . '/../models/NpcRespawn.php';

class NpcAiHelper {
    
    // attitude常量
    const ATTITUDE_FRIENDLY = 'friendly';
    const ATTITUDE_AGGRESSIVE = 'aggressive';
    const ATTITUDE_KILLER = 'killer';
    const ATTITUDE_HEROISM = 'heroism';
    const ATTITUDE_PEACEFUL = 'peaceful';
    
    /**
     * 玩家进入房间后，检查NPC是否主动攻击
     * 在go.php移动成功后调用
     * 
     * @param int $charId 玩家ID
     * @param string $roomArea 房间区域
     * @param string $roomId 房间ID
     * @return array|null 如果有NPC主动攻击，返回战斗信息; null表示无攻击
     */
    public static function checkAutoAttack(int $charId, string $roomArea, string $roomId): ?array
    {
        // 获取房间内所有aggressive/killer/高bellicosity的NPC
        $npcs = Database::queryAll(
            "SELECT id, npc_id, name, attitude, bellicosity, cps, combat_exp, daoxing
             FROM npcs 
             WHERE spawn_area = ? AND spawn_room = ? AND is_active = 1
             AND (attitude IN ('aggressive', 'killer') OR bellicosity > 0)",
            [$roomArea, $roomId]
        );
        
        if (empty($npcs)) return null;
        
        // 检查玩家是否已经在战斗中
        if (isset($_SESSION["combat_{$charId}"])) return null;
        
        // 收集所有要攻击的NPC
        $attackNpcs = [];
        foreach ($npcs as $npc) {
            $deathKey = "npc_dead_" . $npc['id'];
            $isDeadBySession = isset($_SESSION[$deathKey]) && $_SESSION[$deathKey] > time();
            $isDeadByDb = NpcRespawn::isInRespawnCooldown($npc['id']);
            if ($isDeadBySession || $isDeadByDb) {
                continue; // NPC已死亡，跳过
            }
            
            if (self::shouldAutoAttack($npc)) {
                $attackNpcs[] = $npc;
            }
        }
        
        if (empty($attackNpcs)) return null;
        
        // 第一个NPC为主目标，其余为同时攻击的多目标
        $firstNpc = array_shift($attackNpcs);
        $result = self::initiateAutoFight($charId, $firstNpc, $attackNpcs);
        
        return $result;
    }
    
    /**
     * 玩家杀气狂暴检查（还原 LPC feature/attack.c:265 + combatd.c:657-695 start_berserk）
     * 
     * LPC原始逻辑：
     * 1. init() 中 random(bellicosity/40) > cps 触发狂暴
     * 2. start_berserk() 中 force > (random(bellicosity)+bellicosity)/2 可用内力压制
     * 3. bellicosity > score → kill（杀人）；否则 → fight（切磋）
     * 
     * PHP适配：在玩家进入房间时检查自身杀气是否触发狂暴，自动攻击房间内NPC。
     * （LPC中是别人进入玩家房间时触发init()，PHP为请求驱动，适配为玩家进入房间时检查）
     * 
     * @param int $charId 玩家ID
     * @param string $roomArea 房间区域
     * @param string $roomId 房间ID
     * @return array|null 狂暴结果 ['message'=>string, 'room_broadcast'=>string, 'mode'=>'kill'|'fight'] 或 null
     */
    public static function checkPlayerBerserk(int $charId, string $roomArea, string $roomId): ?array
    {
        // 获取玩家数据
        require_once __DIR__ . '/../models/Character.php';
        $char = CharacterModel::find($charId);
        if (!$char) return null;
        
        $bellicosity = intval($char['bellicosity'] ?? 0);
        if ($bellicosity <= 0) return null;
        
        // 检查是否已在战斗中
        require_once __DIR__ . '/../daemons/CombatDaemon.php';
        if (CombatDaemon::isInCombat($charId)) return null;
        
        // 检查房间是否禁止战斗
        require_once __DIR__ . '/../models/Room.php';
        $room = RoomModel::load($roomArea, $roomId);
        if ($room && isset($room['no_fight']) && $room['no_fight']) return null;
        
        // 检查玩家状态（睡眠/昏迷/发呆）
        if (!empty($char['sleep_state']) && $char['sleep_state'] == 1) return null;
        if (!empty($char['unconscious_state']) && $char['unconscious_state'] == 1) return null;
        if (!empty($char['daze_state']) && $char['daze_state'] == 1) return null;
        
        $cps = intval($char['cps'] ?? 20);
        $force = intval($char['force'] ?? 0);
        $combatExp = intval($char['combat_exp'] ?? 0);
        
        // ★ 第1步：杀气狂暴触发判定 — random(bellicosity/40) > cps
        // 对应 LPC attack.c:265
        $berserkThreshold = intval($bellicosity / 40);
        if ($berserkThreshold <= 0) return null;
        if (mt_rand(0, $berserkThreshold) <= $cps) return null;
        
        // ★ 第2步：内力压制判定 — force > (random(bellicosity) + bellicosity) / 2
        // 对应 LPC combatd.c:676
        // 内力深厚者可以用内力压制杀气，避免失控
        $suppressThreshold = intval((mt_rand(0, $bellicosity) + $bellicosity) / 2);
        if ($force > $suppressThreshold) {
            // 内力成功压制杀气，不触发狂暴
            return [
                'message' => '你感到一股杀意涌上心头，但被深厚的内力压制了下去。',
                'room_broadcast' => $char['name'] . '身上闪过一丝戾气，但很快平息了下来。',
                'mode' => 'suppressed'
            ];
        }
        
        // ★ 第3步：寻找目标 — 房间内存活的NPC
        $npcs = Database::queryAll(
            "SELECT id, npc_id, name, attitude, combat_exp FROM npcs 
             WHERE spawn_area = ? AND spawn_room = ? AND is_active = 1",
            [$roomArea, $roomId]
        );
        
        if (empty($npcs)) return null;
        
        // 过滤掉已死亡/冷却中的NPC，以及不可攻击的特殊NPC
        $validTargets = [];
        foreach ($npcs as $npc) {
            $deathKey = "npc_dead_" . $npc['id'];
            $isDeadBySession = isset($_SESSION[$deathKey]) && $_SESSION[$deathKey] > time();
            $isDeadByDb = NpcRespawn::isInRespawnCooldown($npc['id']);
            if ($isDeadBySession || $isDeadByDb) continue;
            
            // 跳过不可战斗的特殊NPC
            $npcId = $npc['npc_id'] ?? '';
            if (in_array($npcId, ['yuantiangang', 'xuerengui', 'zhenglonglaoren'])) continue;
            
            // 跳过友好NPC（attitude为friendly/peaceful的NPC不作为狂暴目标）
            $attitude = $npc['attitude'] ?? '';
            if (in_array($attitude, ['friendly', 'peaceful'])) continue;
            
            $validTargets[] = $npc;
        }
        
        if (empty($validTargets)) return null;
        
        // 随机选择一个目标
        $target = $validTargets[array_rand($validTargets)];
        $targetName = $target['name'];
        $playerName = $char['name'];
        
        // ★ 第4步：判定 kill（杀人）还是 fight（切磋）
        // 对应 LPC combatd.c:678: bellicosity > score → kill；否则 → fight
        // PHP中没有score字段，使用combat_exp作为近似值
        $isKillMode = $bellicosity > $combatExp;
        
        $scanMsg = "{$playerName}用一种异样的眼神扫视着在场的每一个人。";
        
        if ($isKillMode) {
            // 杀人模式：杀气极高，超过自身实战经验，完全失控
            $berserkMsg = "{$playerName}对着{$targetName}喝道：看你实在很不顺眼，去死吧！";
            $selfMsg = "你杀气冲天，完全失去了理智，不由自主地向{$targetName}发起了攻击！";
            
            $result = CombatDaemon::startKill($charId, $target['id'], 'npc', $targetName);
        } else {
            // 切磋模式：杀气较高但未完全失控
            $berserkMsg = "{$playerName}对着{$targetName}喝道：喂！正想找人打架，陪我玩两手吧！";
            $selfMsg = "你杀气上涌，控制不住想要找人切磋，不由自主地向{$targetName}发起了挑战！";
            
            $result = CombatDaemon::startFight($charId, $target['id'], 'npc');
        }
        
        // 如果战斗启动失败，返回null（不显示狂暴消息）
        if (empty($result['success'])) return null;
        
        return [
            'message' => $selfMsg,
            'room_broadcast' => $scanMsg . "\n" . $berserkMsg,
            'mode' => $isKillMode ? 'kill' : 'fight',
            'target_name' => $targetName,
            'combat' => $result
        ];
    }
    
    /**
     * 判断NPC是否应该主动攻击
     */
    private static function shouldAutoAttack(array $npc): bool
    {
        $attitude = $npc['attitude'] ?? '';
        
        switch ($attitude) {
            case self::ATTITUDE_KILLER:
                return true; // 必定攻击
            case self::ATTITUDE_AGGRESSIVE:
                return true; // 主动攻击
            default:
                // bellicosity随机攻击: random(bellicosity/40) > cps
                $bellicosity = intval($npc['bellicosity'] ?? 0);
                $cps = intval($npc['cps'] ?? 0);
                if ($bellicosity > 0) {
                    $threshold = intval($bellicosity / 40);
                    return $threshold > 0 && mt_rand(0, $threshold) > $cps;
                }
                // 检查aggressive字段
                $aggressive = intval($npc['aggressive'] ?? 0);
                return $aggressive > 0;
        }
    }
    
    /**
     * NPC发起自动战斗
     */
    private static function initiateAutoFight(int $charId, array $npc, array $multiTargets = []): array
    {
        // 通过CombatDaemon发起战斗
        require_once __DIR__ . '/../daemons/CombatDaemon.php';
        
        $result = CombatDaemon::startKill($charId, $npc['id'], 'npc', $npc['name'], $multiTargets);
        
        // 生成攻击消息（不带时间戳，时间由前端显示）
        $attackMsg = '';
        
        // 高老庄特殊NPC问候语
        if (GaoNpcHelper::isGaoSpecialNpc($npc['id'])) {
            $char = CharacterModel::find($charId);
            if ($npc['id'] === 210) { // 夏鹏展
                $greeting = GaoNpcHelper::getHeadGreeting($npc, $char ?: []);
                if (!empty($greeting)) {
                    $attackMsg = $greeting . "\n";
                }
            }
        }
        
        $attackMsg .= self::getAutoAttackMessage($npc);
        
        // 如果有多个NPC同时攻击，添加额外消息
        if (!empty($multiTargets)) {
            foreach ($multiTargets as $otherNpc) {
                $otherMsg = self::getAutoAttackMessage($otherNpc);
                $attackMsg .= "\n" . $otherMsg;
            }
            $otherNames = array_map(fn($n) => $n['name'], $multiTargets);
            $attackMsg .= "\n" . implode('、', $otherNames) . '也同时向你发起了攻击！';
        }
        
        // 构建房间广播消息（第三人称视角）
        $playerName = '';
        $player = CharacterModel::find($charId);
        if ($player) {
            $playerName = $player['name'];
        }
        $roomMsg = $npc['name'] . '双目圆睁，朝' . $playerName . '攻来！';
        if (!empty($multiTargets)) {
            foreach ($multiTargets as $otherNpc) {
                $roomMsg .= $otherNpc['name'] . '双目圆睁，朝' . $playerName . '攻来！';
            }
            $otherNames = array_map(fn($n) => $n['name'], $multiTargets);
            $roomMsg .= implode('、', $otherNames) . '也同时向' . $playerName . '发起了攻击！';
        }
        
        return [
            'auto_attack' => true,
            'npc_id' => $npc['id'],
            'npc_name' => $npc['name'],
            'message' => $attackMsg,
            'room_broadcast' => $roomMsg,
            'combat' => $result
        ];
    }
    
    /**
     * 获取NPC主动攻击时的消息
     */
    private static function getAutoAttackMessage(array $npc): string
    {
        $attitude = $npc['attitude'] ?? '';
        $name = $npc['name'];
        
        $messages = [
            self::ATTITUDE_AGGRESSIVE => [
                "{$name}恶狠狠地看着你，二话不说挥拳打来！",
                "{$name}大喝一声，向你扑来！",
                "{$name}双目圆睁，朝你攻来！",
            ],
            self::ATTITUDE_KILLER => [
                "{$name}杀气腾腾地向你扑来！",
                "{$name}目露凶光，出手如风向你袭来！",
            ],
            'berserk' => [
                "{$name}突然发狂，向你猛扑过来！",
                "{$name}怒吼一声，不由分说向你攻来！",
            ],
        ];
        
        $pool = $messages[$attitude] ?? $messages['berserk'];
        return $pool[array_rand($pool)];
    }
    
    /**
     * NPC是否接受战斗（玩家主动发起fight时）
     * 基于原始项目的accept_fight逻辑
     * 
     * @param array $npc NPC数据（需包含attitude, name, gin, max_gin, kee, max_kee, sen, max_sen）
     * @return array ['accept' => bool, 'message' => string]
     */
    public static function acceptFight(array $npc): array
    {
        // ★ 木人特殊处理 - 玩家镜像对手（还原原始 muren.c 设计）
        if (MurenHelper::isMuren($npc)) {
            $charId = intval($_SESSION['char_id'] ?? 0);
            if ($charId > 0) {
                return MurenHelper::acceptFight($charId, $npc);
            }
        }
        
        // 高老庄特殊NPC处理
        if (GaoNpcHelper::isGaoSpecialNpc($npc['id'])) {
            if ($npc['id'] === 210) { // 夏鹏展
                return GaoNpcHelper::handleHeadFight($npc, $_SESSION['current_char'] ?? []);
            }
        }
        
        // 袁天罡特殊处理 - 拒绝战斗
        if ($npc['npc_id'] === 'yuantiangang' || $npc['id'] === 136) {
            return [
                'accept' => false, 
                'message' => "袁天罡摇摇头，学艺尚浅者为强人所难之事也...\n"
            ];
        }
        
        // 薛仁贵特殊处理 - 拒绝战斗（身为朝廷命官，岂能随便与人动手）
        if ($npc['npc_id'] === 'xuerengui' || $npc['id'] === 254) {
            return [
                'accept' => false,
                'message' => "薛仁贵正色道：我身为朝廷命官，岂能随便与人动手？\n"
            ];
        }
        
        // 砍柴道士特殊处理 - 陪练机制（接受战斗）
        if ($npc['npc_id'] === 'kancai' || $npc['id'] === 339) {
            $name = $npc['name'];
            return [
                'accept' => true,
                'message' => "{$name}放下斧头，笑道：好，我陪你练练手！"
            ];
        }
        
        $attitude = $npc['attitude'] ?? '';
        $name = $npc['name'];
        
        // 计算NPC当前状态百分比
        $ginPct = ($npc['max_gin'] > 0) ? ($npc['gin'] * 100 / $npc['max_gin']) : 100;
        $keePct = ($npc['max_kee'] > 0) ? ($npc['kee'] * 100 / $npc['max_kee']) : 100;
        $senPct = ($npc['max_sen'] > 0) ? ($npc['sen'] * 100 / $npc['max_sen']) : 100;
        $isFullHealth = $ginPct >= 90 && $keePct >= 90 && $senPct >= 90;
        
        switch ($attitude) {
            case self::ATTITUDE_PEACEFUL:
                return ['accept' => false, 'message' => "{$name}摆了摆手，说道：我不想打架。"];
                
            case self::ATTITUDE_FRIENDLY:
                if ($isFullHealth) {
                    return ['accept' => false, 'message' => "{$name}笑了笑，说道：何必动手呢？"];
                }
                return ['accept' => false, 'message' => "{$name}说道：我现在身体不适，改日再战。"];
                
            case self::ATTITUDE_AGGRESSIVE:
            case self::ATTITUDE_KILLER:
                return ['accept' => true, 'message' => "{$name}冷哼一声：哼！出招吧！"];
                
            case self::ATTITUDE_HEROISM:
                return ['accept' => true, 'message' => "{$name}大笑道：好！来战！"];
                
            default:
                if ($isFullHealth) {
                    return ['accept' => true, 'message' => "{$name}说道：既然阁下赐教，那便接招了。"];
                }
                return ['accept' => false, 'message' => "{$name}摇了摇头：现在不方便，改日再来。"];
        }
    }
    
    /**
     * NPC战斗回合AI行为
     * 在CombatDaemon的战斗回合中调用
     * 
     * @param array $npc NPC数据（需包含chat_chance_combat, chat_msg_combat, combat_exp, name等）
     * @return array|null 额外效果 ['damage_bonus' => int, 'message' => string] 或 null
     */
    public static function combatRoundAi(array $npc): ?array
    {
        $chatChance = intval($npc['chat_chance_combat'] ?? 0);
        if ($chatChance <= 0) return null;
        
        // 概率判定
        if (mt_rand(1, 100) > $chatChance) return null;
        
        // 解析战斗聊天消息
        $combatMsgs = json_decode($npc['chat_msg_combat'] ?? '[]', true);
        if (empty($combatMsgs)) return null;
        
        // 随机选择一个行为
        $action = $combatMsgs[array_rand($combatMsgs)];
        
        return self::processCombatAction($action, $npc);
    }
    
    /**
     * 处理战斗中的NPC行为
     */
    private static function processCombatAction($action, array $npc): ?array
    {
        $name = $npc['name'];
        
        if (is_string($action)) {
            // 纯台词，无额外效果
            return ['damage_bonus' => 0, 'message' => $action];
        }
        
        if (!is_array($action) || count($action) < 2) return null;
        
        $type = $action[0];
        $skill = $action[1];
        $desc = $action[2] ?? null;
        
        switch ($type) {
            case 'spell':
                // 施法：额外伤害
                $spellLevel = intval($npc['combat_exp'] ?? 0) / 1000;
                $bonus = mt_rand(intval($spellLevel / 2), intval($spellLevel));
                $message = $desc ?? "{$name}口中念念有词，施展出{$skill}！";
                return ['damage_bonus' => $bonus, 'message' => $message, 'type' => 'spell'];
                
            case 'exert':
                // 内功运用：恢复效果
                $recovery = mt_rand(20, 50);
                $message = $desc ?? "{$name}运起内功，气势一振！";
                return ['damage_bonus' => 0, 'message' => $message, 'type' => 'exert', 'recovery' => $recovery];
                
            case 'perform':
                // 招式：大额伤害
                $skillLevel = intval($npc['combat_exp'] ?? 0) / 500;
                $bonus = mt_rand(intval($skillLevel), intval($skillLevel * 1.5));
                $message = $desc ?? "{$name}使出绝招{$skill}！";
                return ['damage_bonus' => $bonus, 'message' => $message, 'type' => 'perform'];
                
            default:
                return null;
        }
    }
    
    /**
     * 检查NPC是否应该脱战（返回家园简化版）
     * 血量低于30%时有概率脱战
     * 
     * 注意：击杀模式下NPC实际血量在 active_combats.target_current_hp 中，
     * 而 npcs.kee 字段不会在战斗中变化。必须同时检查两个数据源。
     * 
     * @param array $npc NPC数据（需包含id, kee, max_kee, name）
     * @return string|null 脱战消息，null表示不脱战
     */
    public static function shouldFlee(array $npc): ?string
    {
        $maxKee = intval($npc['max_kee'] ?? 100);
        if ($maxKee <= 0) return null;
        
        // 优先从 active_combats 表读取实际战斗血量（击杀模式共享HP）
        $npcId = intval($npc['id'] ?? 0);
        $combatHp = null;
        if ($npcId > 0) {
            $row = Database::queryOne(
                "SELECT target_current_hp FROM active_combats WHERE target_id = ? AND target_type = 'npc' LIMIT 1",
                [$npcId]
            );
            if ($row) {
                $combatHp = intval($row['target_current_hp']);
            }
        }
        
        // 如果有战斗血量则使用，否则回退到 npcs.kee
        $kee = ($combatHp !== null) ? $combatHp : intval($npc['kee'] ?? 0);
        
        $healthPct = $kee * 100 / $maxKee;
        
        // 血量低于30%且概率20%
        if ($healthPct < 30 && mt_rand(1, 100) <= 20) {
            $name = $npc['name'];
            $messages = [
                "{$name}纵身向后一跃，拱手道：阁下武艺不凡，佩服！咱们后会有期！",
                "{$name}虚晃一招，转身逃走了。",
                "{$name}说道：好汉不吃眼前亏！便急匆匆地离开了。",
            ];
            return $messages[array_rand($messages)];
        }
        
        return null;
    }
}
