<?php
/**
 * 祭法宝命令 (ji)
 * 
 * 支持两种类型法宝:
 *   A. 剧情法宝（items表，trap_type=trap/bind）→ 困人/束缚
 *   B. 自造法宝（character_fabao表，fabao_type=weapon）→ 四阶段战斗攻击
 * 
 * 用法: ji <目标>
 * 
 * 自造法宝攻击流程（还原 LPC obj/fabao.c）:
 * 1. 检查目标在战斗中
 * 2. 法力(mana) >= 500、精神(sen) >= 500
 * 3. 四阶段判定: 道行对抗 → 经验闪避 → 法力收取 → 命中伤害
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/../models/Room.php';
require_once __DIR__ . '/../helpers/FabaoHelper.php';
require_once __DIR__ . '/../daemons/MessageDaemon.php';
require_once __DIR__ . '/../daemons/CombatDaemon.php';

function cmd_ji(int $charId, string $param = ''): array {
    // 获取角色信息
    $me = CharacterModel::find($charId);
    if (!$me) {
        return ['success' => false, 'message' => '角色不存在。'];
    }

    // 1. 解析目标参数
    if (empty(trim($param))) {
        return ['success' => false, 'message' => '你要祭起法宝对付谁？'];
    }
    $param = trim($param);

    // ★ 支持通过 fabao_id 指定法宝（从弹窗选择，可能是 inventory id 或 character_fabao id）
    $fabaoId = intval($_GET['fabao_id'] ?? 0);
    // ★ 支持通过 fabao_type 区分法宝类型（剧情法宝 vs 自造法宝）
    $fabaoSource = $_GET['fabao_source'] ?? '';  // 'inventory' | 'crafted'

    // ========== 尝试自造法宝（character_fabao 表） ==========
    $craftedFabao = null;
    if ($fabaoId > 0 && $fabaoSource === 'crafted') {
        // 通过 character_fabao.id 直接获取
        $craftedFabao = Database::queryOne(
            "SELECT * FROM character_fabao WHERE id = ? AND owner_id = ? AND fabao_type = 'weapon'",
            [$fabaoId, $charId]
        );
        if ($craftedFabao) {
            return cmd_ji_crafted($charId, $me, $param, $craftedFabao);
        }
    }
    // 如果没指定 source，尝试按 id 查找自造法宝
    if ($fabaoId > 0 && !$craftedFabao) {
        $craftedFabao = Database::queryOne(
            "SELECT * FROM character_fabao WHERE id = ? AND owner_id = ? AND fabao_type = 'weapon'",
            [$fabaoId, $charId]
        );
        if ($craftedFabao) {
            return cmd_ji_crafted($charId, $me, $param, $craftedFabao);
        }
    }

    // ========== 剧情法宝（items 表 character_inventory） ==========
    // 2. 获取要使用的法宝
    if ($fabaoId > 0) {
        // 通过 inventory ID 直接获取指定法宝
        $fabaoItem = Database::queryOne(
            "SELECT ci.*, i.name AS item_name, i.fabao, i.trap_type, i.trap_ratio, 
                    i.qi_defense, i.shen_defense, i.is_real
             FROM character_inventory ci
             JOIN items i ON ci.item_id = i.item_id
             WHERE ci.id = ? AND ci.char_id = ? AND i.fabao = 1
             LIMIT 1",
            [$fabaoId, $charId]
        );

        if (!$fabaoItem) {
            return ['success' => false, 'message' => '你身上没有选中的法宝。'];
        }
    } else {
        // 传统方式：检查角色是否装备了法宝（equipped=1 且 items.fabao=1）
        $fabaoItem = Database::queryOne(
            "SELECT ci.*, i.name AS item_name, i.fabao, i.trap_type, i.trap_ratio, 
                    i.qi_defense, i.shen_defense, i.is_real
             FROM character_inventory ci
             JOIN items i ON ci.item_id = i.item_id
             WHERE ci.char_id = ? AND ci.equipped = 1 AND i.fabao = 1
             LIMIT 1",
            [$charId]
        );

        if (!$fabaoItem) {
            // ★ 没有剧情法宝，尝试找自造法宝
            $craftedFabao = Database::queryOne(
                "SELECT * FROM character_fabao WHERE owner_id = ? AND fabao_type = 'weapon' AND equipped = 1 LIMIT 1",
                [$charId]
            );
            if ($craftedFabao) {
                return cmd_ji_crafted($charId, $me, $param, $craftedFabao);
            }
            return ['success' => false, 'message' => '你身上没有装备法宝。'];
        }
    }

    // ★ 如果选中的法宝未装备，自动装备（先卸下其他已装备法宝）
    if ($fabaoId > 0 && empty($fabaoItem['equipped'])) {
        // 只卸下其他已装备的法宝，不影响武器/防具
        Database::execute(
            "UPDATE character_inventory ci
             INNER JOIN items i ON ci.item_id = i.item_id
             SET ci.equipped = 0
             WHERE ci.char_id = ? AND ci.equipped = 1 AND i.fabao = 1",
            [$charId]
        );
        // 装备选中的法宝
        Database::execute(
            "UPDATE character_inventory SET equipped = 1 WHERE id = ? AND char_id = ?",
            [$fabaoId, $charId]
        );
        $fabaoItem['equipped'] = 1;
    }

    $fabaoName = $fabaoItem['item_name'] ?? '法宝';
    $trapType = $fabaoItem['trap_type'] ?? 'none';

    // ★ 防护：trap_type='none' 且非金刚琢(zhuo_real)的物品不是战斗法宝
    if ($trapType === 'none' && ($fabaoItem['item_id'] ?? '') !== 'zhuo_real') {
        return ['success' => false, 'message' => $fabaoName . '不是战斗法宝，无法祭起。'];
    }

    // 3. 检查角色在战斗中（active_combats表）
    if ($fabaoId <= 0) {
        $combat = Database::queryOne(
            "SELECT * FROM active_combats WHERE char_id = ? LIMIT 1",
            [$charId]
        );

        if (!$combat) {
            return ['success' => false, 'message' => '你现在没有在战斗中，无法祭起法宝。'];
        }
    }

    // 4. 检查法力(mana) >= 500
    $mana = $me['mana'] ?? 0;
    if ($mana < 500) {
        return ['success' => false, 'message' => '你的法力不足，无法驱动法宝。'];
    }

    // 5. 检查精神(sen) >= 200（LPC 原文：sen >= 200）
    $sen = $me['sen'] ?? 0;
    if ($sen < 200) {
        return ['success' => false, 'message' => '你的精神不足，无法驱动法宝。'];
    }

    // 6. 先清理已过期的陷阱状态
    FabaoHelper::checkAndReleaseExpired();
    $freshBeingUsed = Database::queryOne(
        "SELECT being_used FROM character_inventory WHERE id = ? AND char_id = ?",
        [$fabaoItem['id'] ?? 0, $charId]
    );
    if (!empty($freshBeingUsed['being_used'])) {
        $activeTrap = Database::queryOne(
            "SELECT id FROM fabao_trap_state 
             WHERE trapper_id = ? AND fabao_item_id = ? AND is_released = 0 
             LIMIT 1",
            [$charId, $fabaoItem['item_id'] ?? '']
        );
        if (!$activeTrap) {
            Database::execute(
                "UPDATE character_inventory SET being_used = 0 WHERE id = ? AND char_id = ?",
                [$fabaoItem['id'] ?? 0, $charId]
            );
        } else {
            return ['success' => false, 'message' => $fabaoName . '正在使用中，无法再次发动。'];
        }
    }

    // 7. 检查耐用度
    $durability = FabaoHelper::checkDurability($charId, $fabaoItem);
    if (!$durability['ok']) {
        return ['success' => false, 'message' => HTML_HIRED . $durability['message'] . HTML_NOR];
    }

    // 8. 目标验证
    $room = RoomModel::getFullInfo($me['current_area'], $me['current_room']);
    $victimData = null;
    $victimType = '';

    if ($room && !empty($room['npcs'])) {
        foreach ($room['npcs'] as $npc) {
            if (stripos($npc['name'], $param) !== false || 
                (isset($npc['npc_id']) && stripos($npc['npc_id'], $param) !== false)) {
                $victimData = $npc;
                $victimType = 'npc';
                break;
            }
        }
    }

    if (!$victimData) {
        $player = Database::queryOne(
            "SELECT * FROM characters WHERE current_area = ? AND current_room = ? AND name LIKE ? AND id != ? LIMIT 1",
            [$me['current_area'], $me['current_room'], '%' . $param . '%', $charId]
        );

        if ($player) {
            $victimData = $player;
            $victimType = 'player';
        }
    }

    if (!$victimData) {
        return ['success' => false, 'message' => '这里没有' . $param . '。'];
    }

    $victimName = $victimData['name'] ?? $param;
    $victimId = $victimData['id'] ?? 0;
    $myName = $me['name'] ?? '某人';

    // 9. 真假法宝判定
    $isReal = FabaoHelper::isRealFabao($fabaoItem);

    if (!$isReal) {
        Database::execute(
            "UPDATE characters SET mana = mana - 200 WHERE id = ?",
            [$charId]
        );

        $msg = HTML_HIRED . $myName . '祭起' . $fabaoName . '，但法宝毫无反应...似乎是个赝品。' . HTML_NOR;

        return [
            'success' => true,
            'type' => 'fabao_fake',
            'output' => $msg,
        ];
    }

    // 10. AP vs DP 成功率计算
    $attackerData = [
        'spells_skill' => $me['spells_skill'] ?? 0,
        'combat_exp'   => $me['combat_exp'] ?? 0,
        'kar'          => $me['kar'] ?? 100,
        'sen'          => $me['sen'] ?? 1,
        'max_sen'      => $me['max_sen'] ?? 1,
    ];

    $victimCalcData = [
        'spells_skill' => $victimData['spells_skill'] ?? 0,
        'combat_exp'   => $victimData['combat_exp'] ?? 0,
        'kar'          => $victimData['kar'] ?? 100,
        'sen'          => $victimData['sen'] ?? 1,
        'max_sen'      => $victimData['max_sen'] ?? 1,
    ];

    $success = FabaoHelper::calculateSuccess($attackerData, $victimCalcData, $fabaoItem);

    if (!$success) {
        Database::execute(
            "UPDATE characters SET mana = mana - 200 WHERE id = ?",
            [$charId]
        );

        $msg = HTML_HIRED . $myName . '祭起' . $fabaoName . '，但' . $victimName . '灵光一闪，躲开了法宝的吸力！' . HTML_NOR;

        return [
            'success' => true,
            'type' => 'fabao_fail',
            'output' => $msg,
        ];
    }

    // === 剧情法宝成功 ===
    $trapType = $fabaoItem['trap_type'] ?? 'trap';
    
    // 金刚琢特殊处理
    if ($fabaoItem['item_id'] === 'zhuo_real') {
        $stealResult = FabaoHelper::stealWeapon($attackerData, $victimCalcData, $fabaoItem);
                
        if (!$stealResult['success']) {
            Database::execute(
                "UPDATE characters SET mana = mana - 200 WHERE id = ?",
                [$charId]
            );
                    
            return [
                'success' => true,
                'type' => 'fabao_steal_fail',
                'output' => $stealResult['message']
            ];
        }
                
        Database::execute(
            "UPDATE characters SET mana = mana - 400 WHERE id = ?",
            [$charId]
        );
                
        $durResult = FabaoHelper::consumeDurability($charId, $fabaoItem);
        $msg = $stealResult['message'];
                
        if (!empty($durResult['removed'])) {
            $msg .= "\n" . HTML_HIRED . $durResult['message'] . HTML_NOR;
        }
                
        $roomId = ($me['current_area'] ?? '') . '/' . ($me['current_room'] ?? '');
        MessageDaemon::broadcastToRoom($roomId, $msg, $charId, 'fabao');
                
        return [
            'success' => true,
            'type' => 'fabao_steal',
            'output' => $msg,
            'steal_result' => $stealResult
        ];
    }
    
    // 结束当前战斗
    CombatDaemon::endCombat($charId);
    if ($victimType === 'player') {
        CombatDaemon::endCombat(intval($victimId));
    }

    if ($trapType === 'trap') {
        // 葫芦/净瓶型：困人
        $trapResult = FabaoHelper::trapInFabao($me, $victimData, $fabaoItem);

        Database::execute(
            "UPDATE characters SET mana = mana - 400 WHERE id = ?",
            [$charId]
        );

        $durResult = FabaoHelper::consumeDurability($charId, $fabaoItem);

        $msg = HTML_HIYEL . $myName . '祭起' . $fabaoName . '，一道金光闪过，' . $victimName . '被吸入了' . $fabaoName . '之中！' . HTML_NOR;

        if (!empty($durResult['removed'])) {
            $msg .= "\n" . HTML_HIRED . $durResult['message'] . HTML_NOR;
        }

        $roomId = ($me['current_area'] ?? '') . '/' . ($me['current_room'] ?? '');
        MessageDaemon::broadcastToRoom($roomId, $msg, $charId, 'fabao');

        return [
            'success' => true,
            'type' => 'fabao_trap',
            'output' => $msg,
            'trap_result' => $trapResult,
        ];

    } else {
        // 绳子型(bind)：束缚
        $bindResult = FabaoHelper::bindWithFabao($me, $victimData, $fabaoItem);

        Database::execute(
            "UPDATE characters SET mana = mana - 400 WHERE id = ?",
            [$charId]
        );

        $durResult = FabaoHelper::consumeDurability($charId, $fabaoItem);

        $msg = HTML_HIYEL . $myName . '祭起' . $fabaoName . '，' . $victimName . '被' . $fabaoName . '紧紧缠住，动弹不得！' . HTML_NOR;

        if (!empty($durResult['removed'])) {
            $msg .= "\n" . HTML_HIRED . $durResult['message'] . HTML_NOR;
        }

        $roomId = ($me['current_area'] ?? '') . '/' . ($me['current_room'] ?? '');
        MessageDaemon::broadcastToRoom($roomId, $msg, $charId, 'fabao');

        return [
            'success' => true,
            'type' => 'fabao_bind',
            'output' => $msg,
            'bind_result' => $bindResult,
        ];
    }
}

/**
 * 自造法宝战斗攻击（还原 LPC obj/fabao.c ji() 四阶段判定）
 * 
 * @param int $charId 攻击者角色ID
 * @param array $me 攻击者角色数据
 * @param string $param 目标名称
 * @param array $craftedFabao 自造法宝数据（character_fabao 表）
 * @return array 结果
 */
function cmd_ji_crafted(int $charId, array $me, string $param, array $craftedFabao): array {
    $fabaoName = $craftedFabao['name'] ?? '法宝';
    $myName = $me['name'] ?? '某人';

    // 1. 检查是否在战斗中
    $combat = Database::queryOne(
        "SELECT * FROM active_combats WHERE char_id = ? LIMIT 1",
        [$charId]
    );

    if (!$combat) {
        return ['success' => false, 'message' => '你只有在战斗中才可以祭' . $fabaoName . '。'];
    }

    // 2. 检查法宝是否装备
    if (empty($craftedFabao['equipped'])) {
        // 自动装备
        Database::execute(
            "UPDATE character_fabao SET equipped = 0 WHERE owner_id = ? AND fabao_type = 'weapon'",
            [$charId]
        );
        Database::execute(
            "UPDATE character_fabao SET equipped = 1 WHERE id = ?",
            [$craftedFabao['id']]
        );
    }

    // 3. 检查法力(mana) >= 500（LPC 原文）
    $mana = $me['mana'] ?? 0;
    if ($mana < 500) {
        return ['success' => false, 'message' => '你的法力不能控制' . $fabaoName . '。'];
    }

    // 4. 检查精神(sen) >= 200（LPC 原文）
    $sen = $me['sen'] ?? 0;
    if ($sen < 200) {
        return ['success' => false, 'message' => '你现在神智不清，很难驾驭' . $fabaoName . '。'];
    }

    // 5. 验证目标
    $targetId = intval($combat['target_id'] ?? 0);
    $targetType = $combat['target_type'] ?? 'npc';
    
    if ($targetType === 'npc') {
        $target = Database::queryOne("SELECT * FROM npcs WHERE id = ? LIMIT 1", [$targetId]);
    } elseif ($targetType === 'yaoguai') {
        $target = Database::queryOne("SELECT * FROM mieyao_yaoguai WHERE id = ? LIMIT 1", [$targetId]);
    } else {
        $target = CharacterModel::find($targetId);
    }

    if (!$target) {
        return ['success' => false, 'message' => '你的攻击目标不在这里。'];
    }

    $targetName = $target['name'] ?? '目标';

    // 6. 消耗法力 300（LPC: attacker->add("mana", -300)）
    Database::execute(
        "UPDATE characters SET mana = mana - 300 WHERE id = ?",
        [$charId]
    );

    // 7. 消耗精神 100（LPC: attacker->add("sen", -100)）
    Database::execute(
        "UPDATE characters SET sen = sen - 100 WHERE id = ?",
        [$charId]
    );

    // 8. 攻击者 busy 3~6秒（LPC: attacker->start_busy(3+random(3))）
    $busySeconds = 3 + mt_rand(0, 3);
    set_player_busy($charId, $busySeconds);

    // 9. 开场消息（LPC 原文）
    $openingMsg = HIC . "\n{$myName}抖足精神，大喝一声\"看法宝！\"，只见{$fabaoName}「呼」地一下飞到半空，\n" . NOR;
    $openingMsg .= HIC . "霎那间天色一变，风声大作！{$fabaoName}带出一道低啸向{$targetName}凌空击来！\n\n" . NOR;

    // 10. 四阶段判定
    $defenderType = ($targetType === 'player') ? 'player' : 'npc';
    $jiResult = FabaoHelper::fabaoJiAttack($me, $target, $craftedFabao, $defenderType);

    // 11. 组装消息
    $messages = [$openingMsg];
    $messages = array_merge($messages, $jiResult['messages']);

    // 12. 如果攻击成功且有伤害，应用伤害到目标
    if ($jiResult['success'] && $jiResult['damage'] > 0) {
        $damage = $jiResult['damage'];
        $damageType = $jiResult['damage_type'];
        
        if ($targetType === 'player') {
            // 对玩家：通过 SpellHelper 的 applyDamage 逻辑
            if ($damageType === 'qi' || $damageType === 'both') {
                Database::execute(
                    "UPDATE characters SET kee = GREATEST(0, kee - ?) WHERE id = ?",
                    [$damage, $targetId]
                );
            }
            if ($damageType === 'shen' || $damageType === 'both') {
                Database::execute(
                    "UPDATE characters SET sen = GREATEST(0, sen - ?) WHERE id = ?",
                    [$damage, $targetId]
                );
            }
        } elseif ($targetType === 'npc') {
            // 对NPC：更新 npcs 表
            if ($damageType === 'qi' || $damageType === 'both') {
                Database::execute(
                    "UPDATE npcs SET kee = GREATEST(0, kee - ?) WHERE id = ?",
                    [$damage, $targetId]
                );
            }
            if ($damageType === 'shen' || $damageType === 'both') {
                Database::execute(
                    "UPDATE npcs SET sen = GREATEST(0, sen - ?) WHERE id = ?",
                    [$damage, $targetId]
                );
            }
            
            // 检查NPC是否被击杀
            $npcHp = Database::queryOne("SELECT kee FROM npcs WHERE id = ?", [$targetId]);
            if ($npcHp && intval($npcHp['kee'] ?? 0) <= 0) {
                // NPC死亡，调用 CombatDaemon 处理
                CombatDaemon::endCombat($charId);
                $messages[] = HIRED . "\n{$targetName}被{$fabaoName}击中要害，当场毙命！" . NOR;
                // 注意：完整的NPC死亡处理（尸体、掉落）需要额外逻辑
            }
        } elseif ($targetType === 'yaoguai') {
            Database::execute(
                "UPDATE mieyao_yaoguai SET kee = GREATEST(0, kee - ?) WHERE id = ?",
                [$damage, $targetId]
            );
        }
    }

    // 13. 广播消息到房间
    $msg = implode("\n", $messages);
    $roomId = ($me['current_area'] ?? '') . '/' . ($me['current_room'] ?? '');
    MessageDaemon::broadcastToRoom($roomId, $msg, $charId, 'fabao');

    // 14. 更新攻击者本地数据（法力/精神已在上面更新，刷新数据）
    $updatedMe = CharacterModel::find($charId);

    return [
        'success' => true,
        'type' => 'fabao_ji_attack',
        'output' => $msg,
        'ji_result' => $jiResult,
        'mana_left' => $updatedMe['mana'] ?? $mana,
        'sen_left' => $updatedMe['sen'] ?? $sen,
    ];
}
