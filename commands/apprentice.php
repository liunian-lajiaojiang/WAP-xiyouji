<?php
/**
 * 拜师命令 (apprentice)
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 用法: apprentice <目标名称> | 拜师 <目标> | baishi <目标>
 * 功能: 向NPC或玩家拜师，加入门派或发起拜师请求
 */

require_once DAEMON_PATH . 'ApprenticeHandler.php';
require_once HELPER_PATH . 'SectHelper.php';

function cmd_apprentice(int $charId, string $param = ''): array {
    // 支持通过 npc_id 参数直接拜师（来自NPC页面的链接）
    $npcId = intval($_GET['npc_id'] ?? 0);
    
    if ($npcId > 0) {
        // 通过NPC ID直接获取门派信息
        $sect = SectHelper::getSectByNpcId($npcId);
        if (!$sect) {
            return ['success' => false, 'message' => '该NPC并不是任何门派的掌门。'];
        }
        
        $me = CharacterModel::find($charId);
        if (!$me) {
            return ['success' => false, 'message' => '角色不存在。'];
        }
        
        // 如果已有门派，提示将触发背叛惩罚，需要确认
        if (!empty($me['family']) && $me['family'] !== $sect['key']) {
            $sectConf    = SectHelper::getSectConfig($me['family']);
            $oldSectName = $sectConf['name'] ?? $me['family'];
            $newSectName = $sect['name'] ?? $sect['key'];

            // 检查 Session 中是否已有确认标记
            $confirmKey = 'apprentice_confirm_' . $charId;
            if (!isset($_SESSION[$confirmKey]) || $_SESSION[$confirmKey] !== $sect['key']) {
                $_SESSION[$confirmKey] = $sect['key'];
                return [
                    'success' => false,
                    'message' => "警告：你目前是{$oldSectName}成员！\n" .
                                 "改换门派拜入{$newSectName}将触发背叛惩罚（损失经验和技能）。\n" .
                                 "如果确认，请再次点击拜师按钮。"
                ];
            }

            // 第二次确认，清除标记并执行
            unset($_SESSION[$confirmKey]);
        }
        
        // 获取NPC名称用于消息显示
        $npcInfo = NpcModel::find($npcId);
        $npcName = $npcInfo['name'] ?? $sect['master_npc'] ?? '师父';
        
        $result = ApprenticeHandler::apprenticeToNpc($charId, $npcId);
        
        if ($result['success']) {
            return [
                'success' => true,
                'message' => $result['message'],
                'redirect' => 'room.php'
            ];
        }
        
        return ['success' => false, 'message' => $result['message']];
    }

    if (empty($param)) {
        return ['success' => false, 'message' => "指令格式：apprentice <对象>\n你可以向掌门NPC直接拜师，或向有门派的玩家发起拜师请求。"];
    }

    $targetName = trim($param);

    // 获取自己信息
    $me = CharacterModel::find($charId);
    if (!$me) {
        return ['success' => false, 'message' => '角色不存在。'];
    }

    $area   = $me['current_area'] ?? '';
    $roomId = $me['current_room'] ?? '';
    if (empty($area) || empty($roomId)) {
        return ['success' => false, 'message' => '你不在任何房间中。'];
    }

    $fullRoomId = (strpos($roomId, '/') !== false) ? $roomId : $area . '/' . $roomId;

    // -------------------------------------------------------
    // 1. 先在房间NPC中查找
    // -------------------------------------------------------
    $room = RoomModel::getFullInfo($area, $fullRoomId);
    $npcs = $room['npcs'] ?? [];

    $targetNpc = null;
    foreach ($npcs as $npc) {
        if (stripos($npc['name'], $targetName) !== false ||
            stripos($npc['npc_id'] ?? '', $targetName) !== false) {
            $targetNpc = $npc;
            break;
        }
    }

    if ($targetNpc) {
        // -------------------------------------------------------
        // 找到NPC，检查是否为掌门NPC
        // -------------------------------------------------------
        $npcId    = intval($targetNpc['id'] ?? 0);
        $sect     = SectHelper::getSectByNpcId($npcId);

        if (!$sect) {
            return ['success' => false, 'message' => $targetNpc['name'] . '并不是任何门派的掌门，你无法向其拜师。'];
        }

        // 如果已有门派，提示将触发背叛惩罚，需要确认
        if (!empty($me['family']) && $me['family'] !== $sect['key']) {
            $sectConf    = SectHelper::getSectConfig($me['family']);
            $oldSectName = $sectConf['name'] ?? $me['family'];
            $newSectName = $sect['name'] ?? $sect['key'];

            // 检查 Session 中是否已有确认标记
            $confirmKey = 'apprentice_confirm_' . $charId;
            if (!isset($_SESSION[$confirmKey]) || $_SESSION[$confirmKey] !== $sect['key']) {
                $_SESSION[$confirmKey] = $sect['key'];
                return [
                    'success' => false,
                    'message' => "警告：你目前是{$oldSectName}成员！\n" .
                                 "改换门派拜入{$newSectName}将触发背叛惩罚（损失经验和技能）。\n" .
                                 "如果确认，请再次执行 apprentice {$targetName} 完成拜师。"
                ];
            }

            // 第二次确认，清除标记并执行
            unset($_SESSION[$confirmKey]);
        }

        $result = ApprenticeHandler::apprenticeToNpc($charId, $npcId);

        if ($result['success']) {
            // 广播到房间
            require_once DAEMON_PATH . 'MessageDaemon.php';
            $playersInRoom = Database::queryAll(
                'SELECT id FROM characters WHERE current_room = ? AND online = 1',
                [$fullRoomId]
            );
            foreach ($playersInRoom as $player) {
                if ($player['id'] == $charId) {
                    $msg = HIY . $result['message'] . NOR;
                } else {
                    $msg = HIY . $me['name'] . '恭恭敬敬地向' . $targetNpc['name'] . '行拜师之礼，正式入门！' . NOR;
                }
                Database::execute(
                    'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
                    [$player['id'], $msg, 'apprentice']
                );
            }

            return [
                'success'    => true,
                'message'    => $result['message'],
                'skip_queue' => true
            ];
        }

        return ['success' => false, 'message' => $result['message']];
    }

    // -------------------------------------------------------
    // 2. 再在在线玩家中查找（同房间）
    // -------------------------------------------------------
    $playersInRoom = Database::queryAll(
        'SELECT id, name, family FROM characters WHERE current_room = ? AND online = 1 AND id != ?',
        [$fullRoomId, $charId]
    );

    $targetPlayer = null;
    foreach ($playersInRoom as $player) {
        if (stripos($player['name'], $targetName) !== false) {
            $targetPlayer = $player;
            break;
        }
    }

    if (!$targetPlayer) {
        return ['success' => false, 'message' => '这里没有叫' . $targetName . '的人。'];
    }

    // 如果已经是该玩家的弟子，改为请安
    $relation = Database::queryOne(
        'SELECT master_id FROM characters WHERE id = ?',
        [$charId]
    );
    if ($relation && intval($relation['master_id']) === intval($targetPlayer['id'])) {
        // 向师父请安
        require_once DAEMON_PATH . 'MessageDaemon.php';
        $allInRoom = Database::queryAll(
            'SELECT id FROM characters WHERE current_room = ? AND online = 1',
            [$fullRoomId]
        );
        foreach ($allInRoom as $player) {
            if ($player['id'] == $charId) {
                $msg = HIY . '你恭恭敬敬地向' . $targetPlayer['name'] . '行礼，叫道：「师父！」' . NOR;
            } elseif ($player['id'] == $targetPlayer['id']) {
                $msg = HIY . $me['name'] . '恭恭敬敬地向你行礼，叫道：「师父！」' . NOR;
            } else {
                $msg = HIY . $me['name'] . '恭恭敬敬地向' . $targetPlayer['name'] . '行礼，叫道：「师父！」' . NOR;
            }
            Database::execute(
                'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
                [$player['id'], $msg, 'apprentice']
            );
        }
        return [
            'success'    => true,
            'message'    => '你恭恭敬敬地向' . $targetPlayer['name'] . '行礼，叫道：「师父！」',
            'skip_queue' => true
        ];
    }

    // 如果已有门派，提示背叛惩罚并要求确认
    if (!empty($me['family']) && !empty($targetPlayer['family']) && $me['family'] !== $targetPlayer['family']) {
        $sectConf    = SectHelper::getSectConfig($me['family']);
        $oldSectName = $sectConf['name'] ?? $me['family'];
        $tSectConf   = SectHelper::getSectConfig($targetPlayer['family']);
        $newSectName = $tSectConf['name'] ?? $targetPlayer['family'];

        $confirmKey = 'apprentice_confirm_' . $charId;
        if (!isset($_SESSION[$confirmKey]) || $_SESSION[$confirmKey] !== 'player_' . $targetPlayer['id']) {
            $_SESSION[$confirmKey] = 'player_' . $targetPlayer['id'];
            return [
                'success' => false,
                'message' => "警告：你目前是{$oldSectName}成员！\n" .
                             "改换门派拜入{$newSectName}将触发背叛惩罚（损失经验和技能）。\n" .
                             "如果确认，请再次执行 apprentice {$targetName} 完成拜师。"
            ];
        }

        unset($_SESSION[$confirmKey]);
    }

    // 发起拜师请求
    $result = ApprenticeHandler::requestApprenticeship($charId, intval($targetPlayer['id']));

    if ($result['success']) {
        // 广播：通知目标玩家和房间内其他人
        require_once DAEMON_PATH . 'MessageDaemon.php';
        $allInRoom = Database::queryAll(
            'SELECT id FROM characters WHERE current_room = ? AND online = 1',
            [$fullRoomId]
        );
        foreach ($allInRoom as $player) {
            if ($player['id'] == $charId) {
                $msg = HIY . '你向' . $targetPlayer['name'] . '恭敬行礼，表示想拜其为师。' . NOR;
            } elseif ($player['id'] == $targetPlayer['id']) {
                $msg = HIY . $me['name'] . '向你恭敬行礼，想拜你为师。如果你愿意收其为弟子，请使用 recruit 指令。' . NOR;
            } else {
                $msg = HIY . $me['name'] . '向' . $targetPlayer['name'] . '恭敬行礼，想拜其为师。' . NOR;
            }
            Database::execute(
                'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
                [$player['id'], $msg, 'apprentice']
            );
        }

        return [
            'success'    => true,
            'message'    => '你向' . $targetPlayer['name'] . '恭敬行礼，表示想拜其为师。等待对方回应……',
            'skip_queue' => true
        ];
    }

    return ['success' => false, 'message' => $result['message']];
}

