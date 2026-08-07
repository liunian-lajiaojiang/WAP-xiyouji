<?php
/**
 * 状态效果辅助类
 * 管理角色的buff/debuff状态
 */
class StatusEffectHelper {
    
    // 状态类型常量
    const TYPE_POISON = 'poison';           // 中毒：每回合损失HP
    const TYPE_STUN = 'stun';              // 眩晕：无法行动
    const TYPE_UNCONSCIOUS = 'unconscious'; // 昏迷：无法行动，气血为0时触发
    const TYPE_ATTACK_UP = 'attack_up';     // 攻击强化：伤害+value%
    const TYPE_DEFENSE_UP = 'defense_up';   // 防御强化：受伤-value%
    const TYPE_SLOW = 'slow';              // 迟缓：闪避率-value%
    const TYPE_DODGE_UP = 'dodge_up';       // 闪避强化：闪避率+value%
    const TYPE_REGEN = 'regen';            // 回复：每回合恢复value点HP
    const TYPE_WEAKEN = 'weaken';          // 虚弱：伤害-value%
    const TYPE_BANDAGED = 'bandaged';      // 绷带疗伤：每回合恢复少量HP
    const TYPE_DRUNK = 'drunk';            // 醉酒：分阶段影响
    const TYPE_KILLER = 'killer';          // 杀手标记（通缉状态）
    const TYPE_NO_PK_TIME = 'no_pk_time'; // PK冷却时间
    const TYPE_SNAKE_POISON = 'snake_poison'; // 蛇毒：每回合伤害HP和精力
    const TYPE_ICE_POISON = 'ice_poison';  // 寒毒：基于最大HP的比例伤害
    const TYPE_SLUMBER = 'slumber_drug';   // 蒙汗药/昏睡
    const TYPE_POWERUP = 'powerup';        // 运功强化：临时提升攻击和防御
    const TYPE_HEAL = 'heal';              // 疗伤中

    /**
     * 添加状态效果
     * 同类型效果会覆盖（取较强的值）
     */
    public static function addBuff(int $charId, string $type, int $value, int $duration, string $source = ''): bool {
        // 检查是否已有同类型buff
        $existing = Database::queryOne(
            "SELECT id, value, duration FROM character_buffs WHERE char_id = ? AND buff_type = ?",
            [$charId, $type]
        );
        
        if ($existing) {
            // 覆盖：取较高的值和较长的持续时间
            $newValue = max($existing['value'], $value);
            $newDuration = max($existing['duration'], $duration);
            Database::execute(
                "UPDATE character_buffs SET value = ?, duration = ?, source = ? WHERE id = ?",
                [$newValue, $newDuration, $source, $existing['id']]
            );
        } else {
            Database::execute(
                "INSERT INTO character_buffs (char_id, buff_type, value, duration, source) VALUES (?, ?, ?, ?, ?)",
                [$charId, $type, $value, $duration, $source]
            );
        }
        
        // 发送消息通知
        self::sendBuffChangeMessage($charId, $type, true);
        
        return true;
    }
    
    /**
     * 移除指定类型的状态效果
     */
    public static function removeBuff(int $charId, string $type): bool {
        // 检查是否有这个buff
        $hasBuff = self::hasBuff($charId, $type);
        
        Database::execute(
            "DELETE FROM character_buffs WHERE char_id = ? AND buff_type = ?",
            [$charId, $type]
        );
        
        // 如果有这个buff，发送消息通知
        if ($hasBuff) {
            self::sendBuffChangeMessage($charId, $type, false);
        }
        
        return true;
    }
    
    /**
     * 移除角色所有状态效果
     */
    public static function clearAllBuffs(int $charId): void {
        // 获取所有buff，用于发送消息
        $buffs = self::getActiveBuffs($charId);
        
        Database::execute("DELETE FROM character_buffs WHERE char_id = ?", [$charId]);
        
        // 如果有buff，发送消息通知
        if (!empty($buffs)) {
            require_once __DIR__ . '/../daemons/MessageDaemon.php';
            
            $char = Database::queryOne('SELECT id, name, current_room FROM characters WHERE id = ?', [$charId]);
            if ($char) {
                $charName = $char['name'];
                $roomId = $char['current_room'] ?? '';
                
                $selfMessage = '<span style="color:#00FFFF;font-weight:bold">你身上所有的状态效果都莫名其妙的消失了。</span>';
                $roomMessage = '<span style="color:#00FFFF">只见' . $charName . '身上光芒闪烁，所有异常状态都消散了。</span>';
                
                MessageDaemon::sendToPlayer($charId, $selfMessage, 'chat');
                
                if (!empty($roomId)) {
                    MessageDaemon::broadcastToRoom($roomId, $roomMessage, $charId, 'room');
                }
            }
        }
    }
    
    /**
     * 获取角色所有活跃的状态效果
     */
    public static function getActiveBuffs(int $charId): array {
        return Database::queryAll(
            "SELECT * FROM character_buffs WHERE char_id = ?",
            [$charId]
        ) ?: [];
    }
    
    /**
     * 检查是否有指定状态
     */
    public static function hasBuff(int $charId, string $type): bool {
        $result = Database::queryOne(
            "SELECT id FROM character_buffs WHERE char_id = ? AND buff_type = ?",
            [$charId, $type]
        );
        return !empty($result);
    }
    
    /**
     * 处理每回合效果（在战斗回合开始时调用）
     * 返回本回合的效果消息
     */
    public static function processRoundEffects(int $charId): array {
        $messages = [];
        $buffs = self::getActiveBuffs($charId);
        
        foreach ($buffs as $buff) {
            switch ($buff['buff_type']) {
                case self::TYPE_POISON:
                    $poisonDmg = intval($buff['value']);
                    $char = Database::queryOne("SELECT kee FROM characters WHERE id = ?", [$charId]);
                    $currentKee = $char ? intval($char['kee']) : 0;
                    $newKee = max(0, $currentKee - $poisonDmg);
                    
                    Database::execute(
                        "UPDATE characters SET kee = ? WHERE id = ?",
                        [$newKee, $charId]
                    );
                    $messages[] = '<span style="color:#00FF00;font-weight:bold">毒性发作，你受到了' . $poisonDmg . '点伤害！</span>';
                    
                    if ($newKee <= 0 && $currentKee > 0) {
                        require_once __DIR__ . '/../commands/faint.php';
                        cmd_faint($charId, '');
                        $messages[] = '<span style="color:#FF4444;font-weight:bold">你毒发攻心，昏了过去！</span>';
                    }
                    break;
                    
                case self::TYPE_REGEN:
                    // 回复：恢复HP
                    $regenAmount = intval($buff['value']);
                    // 恢复气血时清除濒死标记
                    Database::execute(
                        "UPDATE characters SET kee = LEAST(max_kee, kee + ?), near_death_time = 0 WHERE id = ?",
                        [$regenAmount, $charId]
                    );
                    $messages[] = '<span style="color:#00FFFF;font-weight:bold">内力运转，恢复了' . $regenAmount . '点气血。</span>';
                    break;
                    
                case self::TYPE_STUN:
                    $messages[] = '<span style="color:#FFFF00;font-weight:bold">你被眩晕了，无法行动！</span>';
                    break;
                    
                case self::TYPE_UNCONSCIOUS:
                    $messages[] = '<span style="color:#FF4444;font-weight:bold">你昏迷了，无法行动！</span>';
                    break;

                case self::TYPE_BANDAGED:
                    // 绷带疗伤：每回合恢复3点HP
                    $char = Database::queryOne("SELECT kee, max_kee FROM characters WHERE id = ?", [$charId]);
                    if ($char) {
                        $currentKee = intval($char['kee']);
                        $maxKee = intval($char['max_kee']);
                        if ($currentKee < $maxKee) {
                            $healAmount = min(3, $maxKee - $currentKee);
                            Database::execute(
                                "UPDATE characters SET kee = LEAST(max_kee, kee + 3) WHERE id = ?",
                                [$charId]
                            );
                            $messages[] = '<span style="color:#00FF00">绷带止住了伤口，你恢复了' . $healAmount . '点气血。</span>';
                        }
                    }
                    break;

                case self::TYPE_DRUNK:
                    // 醉酒：根据醉酒程度显示不同消息
                    $drunkValue = intval($buff['value']);
                    $char = Database::queryOne("SELECT id, name, con, max_force, current_room, unconscious_state FROM characters WHERE id = ?", [$charId]);
                    $con = $char ? intval($char['con']) : 10;
                    $maxForce = $char ? intval($char['max_force']) : 0;
                    $roomId = $char['current_room'] ?? '';
                    $charName = $char['name'] ?? '';
                    $wasUnconscious = !empty($char['unconscious_state']) && $char['unconscious_state'] == 1;
                    
                    $limit = $con * 6 + intval($maxForce / 50);
                    
                    // 引入消息发送
                    require_once __DIR__ . '/../daemons/MessageDaemon.php';
                    
                    if ($drunkValue > $limit) {
                        // 烂醉如泥，陷入昏迷
                        // 昏迷时间 = 超出酒量的部分 * 5秒（模拟原始项目回合制）
                        $excess = $drunkValue - $limit;
                        $unconsciousSeconds = max(30, $excess * 5); // 最少30秒
                        $unconsciousEndTime = time() + $unconsciousSeconds;
                        Database::execute(
                            "UPDATE characters SET unconscious_state = 1, unconscious_end_time = ? WHERE id = ?",
                            [$unconsciousEndTime, $charId]
                        );
                        $messages[] = '<span style="color:#FF4444;font-weight:bold">你喝得烂醉如泥，一头栽倒在地，不省人事！</span>';
                        
                        // 广播给房间其他人
                        if (!empty($roomId)) {
                            $roomMsg = '<span style="color:#FF4444">只见' . $charName . '喝得烂醉如泥，一头栽倒在地，不省人事！</span>';
                            MessageDaemon::broadcastToRoom($roomId, $roomMsg, $charId, 'room');
                        }
                    } else if ($drunkValue > intval($limit / 5)) {
                        // 中度酒醉
                        $messages[] = '<span style="color:#FFAA00">你脑中昏昏沉沉，身子轻飘飘地，大概是醉了。</span>';
                        // 扣除精力
                        Database::execute(
                            "UPDATE characters SET sen = GREATEST(0, sen - 10) WHERE id = ?",
                            [$charId]
                        );
                        
                        // 如果之前是昏迷状态，现在苏醒了
                        if ($wasUnconscious) {
                            Database::execute(
                                "UPDATE characters SET unconscious_state = 0, unconscious_end_time = 0 WHERE id = ?",
                                [$charId]
                            );
                            $messages[] = '<span style="color:#00FFFF">你从酒醉昏迷中苏醒了过来。</span>';
                        }
                        
                        // 广播给房间其他人
                        if (!empty($roomId)) {
                            $roomMsg = '<span style="color:#FFAA00">只见' . $charName . '摇头晃脑地站都站不稳，显然是喝醉了。</span>';
                            MessageDaemon::broadcastToRoom($roomId, $roomMsg, $charId, 'room');
                        }
                    } else if ($drunkValue > intval($limit / 10)) {
                        // 轻度酒醉
                        $messages[] = '<span style="color:#FFCC00">你感到一阵酒意上冲，眼皮有些沉重了。</span>';
                        Database::execute(
                            "UPDATE characters SET sen = GREATEST(0, sen - 3) WHERE id = ?",
                            [$charId]
                        );
                        
                        // 如果之前是昏迷状态，现在苏醒了
                        if ($wasUnconscious) {
                            Database::execute(
                                "UPDATE characters SET unconscious_state = 0, unconscious_end_time = 0 WHERE id = ?",
                                [$charId]
                            );
                            $messages[] = '<span style="color:#00FFFF">你从酒醉昏迷中苏醒了过来。</span>';
                        }
                        
                        // 广播给房间其他人
                        if (!empty($roomId)) {
                            $roomMsg = '<span style="color:#FFCC00">只见' . $charName . '脸上已经略显酒意了。</span>';
                            MessageDaemon::broadcastToRoom($roomId, $roomMsg, $charId, 'room');
                        }
                    } else {
                        // 微醺
                        $messages[] = '<span style="color:#FFFF00">你微微有些醉意。</span>';
                        
                        // 如果之前是昏迷状态，现在苏醒了
                        if ($wasUnconscious) {
                            Database::execute(
                                "UPDATE characters SET unconscious_state = 0, unconscious_end_time = 0 WHERE id = ?",
                                [$charId]
                            );
                            $messages[] = '<span style="color:#00FFFF">你从酒醉昏迷中苏醒了过来。</span>';
                        }
                    }
                    break;

                case self::TYPE_SNAKE_POISON:
                    // 蛇毒：每回合扣除10点HP和5点精力
                    $char = Database::queryOne("SELECT kee FROM characters WHERE id = ?", [$charId]);
                    $currentKee = $char ? intval($char['kee']) : 0;
                    $newKee = max(0, $currentKee - 10);
                    Database::execute(
                        "UPDATE characters SET kee = ?, sen = GREATEST(0, sen - 5) WHERE id = ?",
                        [$newKee, $charId]
                    );
                    $messages[] = '<span style="color:#00FF00;font-weight:bold">你中的蛇毒发作了！气血-10，精力-5！</span>';
                    if ($newKee <= 0 && $currentKee > 0) {
                        $messages[] = '<span style="color:#FF4444;font-weight:bold">你蛇毒攻心，昏了过去！</span>';
                    }
                    break;

                case self::TYPE_ICE_POISON:
                    // 寒毒：每回合扣除max_hp的5%（上限80）
                    $char = Database::queryOne("SELECT kee, max_kee FROM characters WHERE id = ?", [$charId]);
                    if ($char) {
                        $maxKee = intval($char['max_kee']);
                        $iceDmg = intval($maxKee * 0.05);
                        if ($iceDmg > 80) $iceDmg = 80;
                        if ($iceDmg < 10) $iceDmg = 10;
                        $currentKee = intval($char['kee']);
                        $newKee = max(0, $currentKee - $iceDmg);
                        Database::execute(
                            "UPDATE characters SET kee = ? WHERE id = ?",
                            [$newKee, $charId]
                        );
                        $messages[] = '<span style="color:#00BBFF;font-weight:bold">寒毒发作，一股彻骨寒意侵袭全身！气血-' . $iceDmg . '！</span>';
                        if ($newKee <= 0 && $currentKee > 0) {
                            $messages[] = '<span style="color:#FF4444;font-weight:bold">你寒毒攻心，昏了过去！</span>';
                        }
                    }
                    break;

                case self::TYPE_POWERUP:
                    // 运功强化：value存储攻击提升百分比，额外10%防御
                    $atkBonus = intval($buff['value']);
                    $defBonus = max(10, intval($atkBonus / 2));
                    $messages[] = '<span style="color:#FF00FF;font-weight:bold">你运功护体，攻击+' . $atkBonus . '%，防御+' . $defBonus . '%！</span>';
                    break;

                case self::TYPE_HEAL:
                    // 疗伤中：每回合恢复少量HP
                    $healAmount = intval($buff['value']) ?: 5;
                    Database::execute(
                        "UPDATE characters SET kee = LEAST(max_kee, kee + ?) WHERE id = ?",
                        [$healAmount, $charId]
                    );
                    $messages[] = '<span style="color:#00FFFF">疗伤中，你恢复了' . $healAmount . '点气血。</span>';
                    break;

                case self::TYPE_SLUMBER:
                    // 蒙汗药：无法行动
                    $messages[] = '<span style="color:#AA00FF;font-weight:bold">你被蒙汗药迷倒，昏昏沉沉无法行动！</span>';
                    break;

                case self::TYPE_KILLER:
                    // 杀手标记/通缉：无特殊回合效果，仅标记
                    $messages[] = '<span style="color:#FF0000">你正处于通缉状态。</span>';
                    break;

                case self::TYPE_NO_PK_TIME:
                    // PK冷却时间：无特殊回合效果，仅标记
                    break;
            }
            
            // 减少持续时间（duration=0表示永久）
            if ($buff['duration'] > 0) {
                $newDuration = $buff['duration'] - 1;
                if ($newDuration <= 0) {
                    // 效果过期，移除
                    Database::execute("DELETE FROM character_buffs WHERE id = ?", [$buff['id']]);
                    
                    // 特殊处理：powerup 结束时增加杀气
                    if ($buff['buff_type'] === self::TYPE_POWERUP) {
                        $atkBonus = intval($buff['value']);
                        $bellicosityIncrease = $atkBonus * 2;
                        Database::execute(
                            "UPDATE characters SET bellicosity = bellicosity + ? WHERE id = ?",
                            [$bellicosityIncrease, $charId]
                        );
                        $messages[] = '<span style="color:#FF4444;font-weight:bold">你的法力渐渐收敛，丹田内一股热气缓缓散回丹田。</span>';
                        $messages[] = '<span style="color:#FF4444">你感到杀气增加了 ' . $bellicosityIncrease . ' 点。</span>';
                    } else {
                        $messages[] = self::getExpireMessage($buff['buff_type']);
                    }
                } else {
                    // 酒醉状态：value 也要随着回合减少（和原始项目一致）
                    if ($buff['buff_type'] === self::TYPE_DRUNK) {
                        $newValue = max(0, intval($buff['value']) - 1);
                        Database::execute(
                            "UPDATE character_buffs SET duration = ?, value = ? WHERE id = ?",
                            [$newDuration, $newValue, $buff['id']]
                        );
                    } else {
                        Database::execute(
                            "UPDATE character_buffs SET duration = ? WHERE id = ?",
                            [$newDuration, $buff['id']]
                        );
                    }
                }
            }
        }
        
        return $messages;
    }
    
    /**
     * 获取攻击力修正百分比
     * 正数为增加，负数为减少
     */
    public static function getAttackModifier(int $charId): int {
        $modifier = 0;
        
        $attackUp = Database::queryOne(
            "SELECT value FROM character_buffs WHERE char_id = ? AND buff_type = ?",
            [$charId, self::TYPE_ATTACK_UP]
        );
        if ($attackUp) {
            $modifier += intval($attackUp['value']);
        }
        
        $weaken = Database::queryOne(
            "SELECT value FROM character_buffs WHERE char_id = ? AND buff_type = ?",
            [$charId, self::TYPE_WEAKEN]
        );
        if ($weaken) {
            $modifier -= intval($weaken['value']);
        }
        
        // 运功强化：提升攻击
        $powerup = Database::queryOne(
            "SELECT value FROM character_buffs WHERE char_id = ? AND buff_type = ?",
            [$charId, self::TYPE_POWERUP]
        );
        if ($powerup) {
            $modifier += intval($powerup['value']);
        }
        
        return $modifier;
    }
    
    /**
     * 获取防御力修正百分比
     */
    public static function getDefenseModifier(int $charId): int {
        $modifier = 0;
        
        $defenseUp = Database::queryOne(
            "SELECT value FROM character_buffs WHERE char_id = ? AND buff_type = ?",
            [$charId, self::TYPE_DEFENSE_UP]
        );
        if ($defenseUp) {
            $modifier += intval($defenseUp['value']);
        }
        
        // 运功强化：提升防御（攻击值的一半，最低10%）
        $powerup = Database::queryOne(
            "SELECT value FROM character_buffs WHERE char_id = ? AND buff_type = ?",
            [$charId, self::TYPE_POWERUP]
        );
        if ($powerup) {
            $modifier += max(10, intval(intval($powerup['value']) / 2));
        }
        
        return $modifier;
    }
    
    /**
     * 获取闪避率修正
     */
    public static function getDodgeModifier(int $charId): int {
        $modifier = 0;
        
        $dodgeUp = Database::queryOne(
            "SELECT value FROM character_buffs WHERE char_id = ? AND buff_type = ?",
            [$charId, self::TYPE_DODGE_UP]
        );
        if ($dodgeUp) {
            $modifier += intval($dodgeUp['value']);
        }
        
        $slow = Database::queryOne(
            "SELECT value FROM character_buffs WHERE char_id = ? AND buff_type = ?",
            [$charId, self::TYPE_SLOW]
        );
        if ($slow) {
            $modifier -= intval($slow['value']);
        }
        
        return $modifier;
    }
    
    /**
     * 检查是否被眩晕（无法行动）
     */
    public static function isStunned(int $charId): bool {
        return self::hasBuff($charId, self::TYPE_STUN);
    }
    
    /**
     * 发送buff变化消息通知
     * @param int $charId 角色ID
     * @param string $type buff类型
     * @param bool $isAdded 是否是添加（true=添加，false=移除）
     */
    private static function sendBuffChangeMessage(int $charId, string $type, bool $isAdded): void {
        require_once __DIR__ . '/../daemons/MessageDaemon.php';
        
        // 获取角色信息
        $char = Database::queryOne('SELECT id, name, current_room FROM characters WHERE id = ?', [$charId]);
        if (!$char) return;
        
        $charName = $char['name'];
        $roomId = $char['current_room'] ?? '';
        
        // 获取buff名称
        $buffNames = [
            self::TYPE_POISON => '中毒',
            self::TYPE_STUN => '眩晕',
            self::TYPE_UNCONSCIOUS => '昏迷',
            self::TYPE_ATTACK_UP => '攻击强化',
            self::TYPE_DEFENSE_UP => '防御强化',
            self::TYPE_SLOW => '迟缓',
            self::TYPE_DODGE_UP => '闪避强化',
            self::TYPE_REGEN => '回复',
            self::TYPE_WEAKEN => '虚弱',
            self::TYPE_BANDAGED => '绷带疗伤',
            self::TYPE_DRUNK => '醉酒',
            self::TYPE_KILLER => '通缉',
            self::TYPE_NO_PK_TIME => 'PK冷却',
            self::TYPE_SNAKE_POISON => '蛇毒',
            self::TYPE_ICE_POISON => '寒毒',
            self::TYPE_SLUMBER => '蒙汗药',
            self::TYPE_POWERUP => '运功强化',
            self::TYPE_HEAL => '疗伤',
        ];
        $buffName = $buffNames[$type] ?? $type;
        
        // 特殊状态的自定义消息
        if ($type === self::TYPE_DRUNK) {
            if ($isAdded) {
                $selfMessage = '<span style="color:#FFFF00;font-weight:bold">你的' . $buffName . '状态莫名其妙的出现了。</span>';
                $roomMessage = '<span style="color:#FFFF00">只见' . $charName . '喝得满脸通红，脚步有些踉跄，似乎是醉了。</span>';
            } else {
                $selfMessage = '<span style="color:#00FFFF;font-weight:bold">你的' . $buffName . '状态莫名其妙的消失了。</span>';
                $roomMessage = '<span style="color:#00FFFF">只见' . $charName . '的酒意渐渐退去，神色恢复了正常。</span>';
            }
        } else {
            if ($isAdded) {
                // 添加buff的消息
                $selfMessage = '<span style="color:#FFFF00;font-weight:bold">你的' . $buffName . '状态莫名其妙的出现了。</span>';
                $roomMessage = '<span style="color:#FFFF00">只见' . $charName . '身上泛起一道奇异的光芒，似乎获得了某种力量。</span>';
            } else {
                // 移除buff的消息
                $selfMessage = '<span style="color:#00FFFF;font-weight:bold">你的' . $buffName . '状态莫名其妙的消失了。</span>';
                $roomMessage = '<span style="color:#00FFFF">只见' . $charName . '身上的' . $buffName . '效果渐渐消散了。</span>';
            }
        }
        
        // 给玩家自己发送chat消息
        MessageDaemon::sendToPlayer($charId, $selfMessage, 'chat');
        
        // 给房间内其他玩家发送room消息
        if (!empty($roomId)) {
            MessageDaemon::broadcastToRoom($roomId, $roomMessage, $charId, 'room');
        }
    }
    
    /**
     * 获取状态效果过期消息
     */
    private static function getExpireMessage(string $type): string {
        $messages = [
            self::TYPE_POISON => '毒性已经消退。',
            self::TYPE_STUN => '你从眩晕中恢复过来。',
            self::TYPE_UNCONSCIOUS => '你从昏迷中苏醒过来。',
            self::TYPE_ATTACK_UP => '攻击强化效果已消失。',
            self::TYPE_DEFENSE_UP => '防御强化效果已消失。',
            self::TYPE_SLOW => '迟缓效果已消失。',
            self::TYPE_DODGE_UP => '闪避强化效果已消失。',
            self::TYPE_REGEN => '回复效果已消失。',
            self::TYPE_WEAKEN => '虚弱效果已消失。',
            self::TYPE_BANDAGED => '绷带已经散开，疗伤效果结束。',
            self::TYPE_DRUNK => '你的醉意已经完全消退了。',
            self::TYPE_KILLER => '你的通缉状态已经解除。',
            self::TYPE_NO_PK_TIME => 'PK冷却时间已结束。',
            self::TYPE_SNAKE_POISON => '蛇毒的毒性终于消退了。',
            self::TYPE_ICE_POISON => '寒毒的寒意渐渐散去，你感觉好些了。',
            self::TYPE_SLUMBER => '蒙汗药的药效过去了，你清醒了过来。',
            self::TYPE_POWERUP => '运功强化效果已消散。',
            self::TYPE_HEAL => '疗伤效果结束。',
        ];
        return $messages[$type] ?? '某个状态效果已消失。';
    }
    
    /**
     * 获取状态效果描述（用于显示）
     */
    public static function getBuffDescription(array $buff): string {
        $descriptions = [
            self::TYPE_POISON => '中毒（每回合-' . $buff['value'] . 'HP）',
            self::TYPE_STUN => '眩晕（无法行动）',
            self::TYPE_UNCONSCIOUS => '昏迷（无法行动）',
            self::TYPE_ATTACK_UP => '攻击强化（+' . $buff['value'] . '%）',
            self::TYPE_DEFENSE_UP => '防御强化（+' . $buff['value'] . '%）',
            self::TYPE_SLOW => '迟缓（闪避-' . $buff['value'] . '%）',
            self::TYPE_DODGE_UP => '闪避强化（+' . $buff['value'] . '%）',
            self::TYPE_REGEN => '回复（每回合+' . $buff['value'] . 'HP）',
            self::TYPE_WEAKEN => '虚弱（伤害-' . $buff['value'] . '%）',
            self::TYPE_BANDAGED => '绷带疗伤（每回合+3HP）',
            self::TYPE_DRUNK => '醉酒（精力-' . max(3, intval($buff['value'] / 10)) . '/回合）',
            self::TYPE_KILLER => '通缉（被追杀中）',
            self::TYPE_NO_PK_TIME => 'PK冷却中',
            self::TYPE_SNAKE_POISON => '蛇毒（每回合-10HP -5精力）',
            self::TYPE_ICE_POISON => '寒毒（每回合-5%最大HP）',
            self::TYPE_SLUMBER => '蒙汗药（昏睡无法行动）',
            self::TYPE_POWERUP => '运功强化（攻击+' . $buff['value'] . '%，防御+' . max(10, intval($buff['value'] / 2)) . '%）',
            self::TYPE_HEAL => '疗伤中（每回合+' . (intval($buff['value']) ?: 5) . 'HP）',
        ];
        $desc = $descriptions[$buff['buff_type']] ?? $buff['buff_type'];
        if ($buff['duration'] > 0) {
            $desc .= ' [' . $buff['duration'] . '回合]';
        }
        return $desc;
    }
    
    /**
     * 添加条件效果的统一接口
     * @param int $charId 角色ID
     * @param string $conditionType 条件类型（使用TYPE_*常量）
     * @param array $params 参数数组，支持键值：
     *   - 'value': 效果值（默认根据类型自动设定）
     *   - 'duration': 持续回合数（默认10）
     *   - 'source': 来源描述
     */
    public static function addCondition(int $charId, string $conditionType, array $params = []): bool {
        // 根据条件类型设置默认值
        $defaults = [
            self::TYPE_BANDAGED => ['value' => 3, 'duration' => 10],
            self::TYPE_DRUNK => ['value' => 10, 'duration' => 15],
            self::TYPE_KILLER => ['value' => 1, 'duration' => 100],
            self::TYPE_NO_PK_TIME => ['value' => 1, 'duration' => 60],
            self::TYPE_SNAKE_POISON => ['value' => 10, 'duration' => 8],
            self::TYPE_ICE_POISON => ['value' => 1, 'duration' => 10],
            self::TYPE_SLUMBER => ['value' => 1, 'duration' => 5],
            self::TYPE_POWERUP => ['value' => 30, 'duration' => 5],
            self::TYPE_HEAL => ['value' => 5, 'duration' => 10],
            self::TYPE_POISON => ['value' => 10, 'duration' => 5],
            self::TYPE_STUN => ['value' => 1, 'duration' => 2],
            self::TYPE_REGEN => ['value' => 5, 'duration' => 8],
            self::TYPE_ATTACK_UP => ['value' => 20, 'duration' => 5],
            self::TYPE_DEFENSE_UP => ['value' => 20, 'duration' => 5],
            self::TYPE_SLOW => ['value' => 15, 'duration' => 5],
            self::TYPE_DODGE_UP => ['value' => 15, 'duration' => 5],
            self::TYPE_WEAKEN => ['value' => 15, 'duration' => 5],
        ];
        
        $typeDefaults = $defaults[$conditionType] ?? ['value' => 1, 'duration' => 5];
        
        $value = $params['value'] ?? $typeDefaults['value'];
        $duration = $params['duration'] ?? $typeDefaults['duration'];
        $source = $params['source'] ?? '';
        
        return self::addBuff($charId, $conditionType, intval($value), intval($duration), $source);
    }
    
    /**
     * 获取角色所有活跃状态的描述摘要
     * @return string 状态摘要文本，无状态时返回空字符串
     */
    public static function getConditionSummary(int $charId): string {
        $buffs = self::getActiveBuffs($charId);
        
        if (empty($buffs)) {
            return '';
        }
        
        $descriptions = [];
        foreach ($buffs as $buff) {
            $descriptions[] = self::getBuffDescription($buff);
        }
        
        return implode('、', $descriptions);
    }
    
    /**
     * 检查角色是否处于醉酒状态
     */
    public static function isDrunk(int $charId): bool {
        return self::hasBuff($charId, self::TYPE_DRUNK);
    }
    
    /**
     * 检查角色是否处于中毒状态（包括普通中毒、蛇毒、寒毒）
     */
    public static function isPoisoned(int $charId): bool {
        return self::hasBuff($charId, self::TYPE_POISON)
            || self::hasBuff($charId, self::TYPE_SNAKE_POISON)
            || self::hasBuff($charId, self::TYPE_ICE_POISON);
    }
    
    /**
     * 获取角色的醉酒程度
     * @return int 醉酒程度值，未醉酒返回0
     */
    public static function getDrunkLevel(int $charId): int {
        $buff = Database::queryOne(
            "SELECT value FROM character_buffs WHERE char_id = ? AND buff_type = ?",
            [$charId, self::TYPE_DRUNK]
        );
        return $buff ? intval($buff['value']) : 0;
    }
    
    /**
     * 检查角色是否处于通缉状态
     */
    public static function isKiller(int $charId): bool {
        return self::hasBuff($charId, self::TYPE_KILLER);
    }
    
    /**
     * 检查角色是否处于蒙汗药/昏睡状态
     */
    public static function isSlumbered(int $charId): bool {
        return self::hasBuff($charId, self::TYPE_SLUMBER);
    }
    
    /**
     * 检查角色是否处于PK冷却中
     */
    public static function isNoPkTime(int $charId): bool {
        return self::hasBuff($charId, self::TYPE_NO_PK_TIME);
    }
}
