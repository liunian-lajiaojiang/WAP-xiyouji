<?php
/**
 * 收徒命令 (recruit)
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 用法: recruit [<弟子名称>] | 收徒 [<弟子>] | shoutu [<弟子>]
 * 功能:
 *   - 无参数：显示所有待处理的拜师请求列表
 *   - recruit <名称>：接受该玩家的拜师请求，建立师徒关系
 */

require_once DAEMON_PATH . 'ApprenticeHandler.php';
require_once HELPER_PATH . 'SectHelper.php';

function cmd_recruit(int $charId, string $param = ''): array {
    $me = CharacterModel::find($charId);
    if (!$me) {
        return ['success' => false, 'message' => '角色不存在。'];
    }

    // -------------------------------------------------------
    // 无参数：显示待处理的拜师请求列表
    // -------------------------------------------------------
    if (empty($param)) {
        $requestsResult = ApprenticeHandler::getPendingRequests($charId);
        if (!$requestsResult['success']) {
            return ['success' => false, 'message' => $requestsResult['message']];
        }

        $incoming = $requestsResult['data']['incoming'] ?? [];
        $outgoing = $requestsResult['data']['outgoing'] ?? [];

        if (empty($incoming) && empty($outgoing)) {
            return ['success' => true, 'message' => '目前没有任何待处理的拜师请求。'];
        }

        $lines = [];

        if (!empty($incoming)) {
            $lines[] = '=== 收到的拜师请求（想拜你为师）===';
            foreach ($incoming as $req) {
                $sectConf = SectHelper::getSectConfig($req['sect_name'] ?? '');
                $sectName = $sectConf['name'] ?? ($req['sect_name'] ?? '未知门派');
                $time     = $req['created_at'] ?? '';
                $lines[] = sprintf(
                    '  %s（等级 %d）— 门派：%s  时间：%s',
                    $req['from_char_name'],
                    intval($req['from_char_level'] ?? 0),
                    $sectName,
                    $time
                );
            }
            $lines[] = '  用 recruit <名称> 接受请求。';
        }

        if (!empty($outgoing)) {
            $lines[] = '=== 你发出的拜师请求 ===';
            foreach ($outgoing as $req) {
                $sectConf = SectHelper::getSectConfig($req['sect_name'] ?? '');
                $sectName = $sectConf['name'] ?? ($req['sect_name'] ?? '未知门派');
                $lines[] = sprintf(
                    '  向 %s 请求拜师（门派：%s）',
                    $req['to_char_name'],
                    $sectName
                );
            }
        }

        return ['success' => true, 'message' => implode("\n", $lines)];
    }

    // -------------------------------------------------------
    // 有参数：检查自己资格，然后接受该玩家的拜师请求
    // -------------------------------------------------------

    // 检查自己是否有门派
    if (empty($me['family'])) {
        return ['success' => false, 'message' => '你并不属于任何门派，你必须先加入一个门派才能收徒。'];
    }

    // 检查门派辈分（generation > 0 才能收徒）
    if (intval($me['generation'] ?? 0) <= 0) {
        return ['success' => false, 'message' => '你乃弃徒，请先求本门师父将你重列门墙，方可收徒。'];
    }

    $targetName = trim($param);

    // 在待处理请求中查找匹配的玩家
    $requestsResult = ApprenticeHandler::getPendingRequests($charId);
    if (!$requestsResult['success']) {
        return ['success' => false, 'message' => $requestsResult['message']];
    }

    $incoming     = $requestsResult['data']['incoming'] ?? [];
    $matchRequest = null;

    foreach ($incoming as $req) {
        if (stripos($req['from_char_name'], $targetName) !== false) {
            $matchRequest = $req;
            break;
        }
    }

    if (!$matchRequest) {
        // 没有找到对应请求，检查是否在同一房间内可以主动邀请
        $area   = $me['current_area'] ?? '';
        $roomId = $me['current_room'] ?? '';
        $fullRoomId = (strpos($roomId, '/') !== false) ? $roomId : $area . '/' . $roomId;

        $targetPlayer = null;
        $playersInRoom = Database::queryAll(
            'SELECT id, name, family FROM characters WHERE current_room = ? AND online = 1 AND id != ?',
            [$fullRoomId, $charId]
        );
        foreach ($playersInRoom as $player) {
            if (stripos($player['name'], $targetName) !== false) {
                $targetPlayer = $player;
                break;
            }
        }

        if (!$targetPlayer) {
            return ['success' => false, 'message' => '你没有收到来自 ' . $targetName . ' 的拜师请求，且此人不在你身边。'];
        }

        // 已经是自己弟子
        $rel = Database::queryOne(
            'SELECT master_id FROM characters WHERE id = ?',
            [intval($targetPlayer['id'])]
        );
        if ($rel && intval($rel['master_id']) === $charId) {
            return ['success' => false, 'message' => $targetPlayer['name'] . '已经是你的弟子了。'];
        }

        // 对方没有向自己发请求，发出收徒邀请通知
        require_once DAEMON_PATH . 'MessageDaemon.php';
        $allInRoom = Database::queryAll(
            'SELECT id FROM characters WHERE current_room = ? AND online = 1',
            [$fullRoomId]
        );
        foreach ($allInRoom as $player) {
            if ($player['id'] == $charId) {
                $msg = HIY . '你想要收' . $targetPlayer['name'] . '为弟子，等待对方用 apprentice 指令同意。' . NOR;
            } elseif ($player['id'] == $targetPlayer['id']) {
                $msg = HIY . $me['name'] . '想要收你为弟子。如果你愿意拜其为师，请使用 apprentice 指令。' . NOR;
            } else {
                $msg = HIY . $me['name'] . '想要收' . $targetPlayer['name'] . '为弟子。' . NOR;
            }
            Database::execute(
                'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
                [$player['id'], $msg, 'apprentice']
            );
        }

        return [
            'success'    => true,
            'message'    => '你想要收' . $targetPlayer['name'] . '为弟子，等待对方同意……',
            'skip_queue' => true
        ];
    }

    // 找到拜师请求，执行接受
    $apprenticeId = intval($matchRequest['from_character_id']);
    $result       = ApprenticeHandler::acceptApprenticeship($charId, $apprenticeId);

    if (!$result['success']) {
        return ['success' => false, 'message' => $result['message']];
    }

    $apprenticeName = $matchRequest['from_char_name'];
    $data           = $result['data'] ?? [];

    // 广播消息到当前房间
    require_once DAEMON_PATH . 'MessageDaemon.php';

    $area   = $me['current_area'] ?? '';
    $roomId = $me['current_room'] ?? '';
    $fullRoomId = (strpos($roomId, '/') !== false) ? $roomId : $area . '/' . $roomId;

    $allInRoom = Database::queryAll(
        'SELECT id FROM characters WHERE current_room = ? AND online = 1',
        [$fullRoomId]
    );

    $isBetray = $data['is_betray'] ?? false;
    $sectConf = SectHelper::getSectConfig($data['sect_name'] ?? '');
    $sectName = $sectConf['name'] ?? ($data['sect_name'] ?? '');

    foreach ($allInRoom as $player) {
        if ($player['id'] == $charId) {
            $msg = HIY . $result['message'] . NOR;
        } elseif ($player['id'] == $apprenticeId) {
            if ($isBetray) {
                $msg = HIY . '你决定投入' . $me['name'] . '门下！\n'
                     . '你跪了下来向' . $me['name'] . '恭恭敬敬地磕了四个响头，叫道：「师父！」' . NOR;
            } else {
                $msg = HIY . '你决定拜' . $me['name'] . '为师。\n'
                     . '你跪了下来向' . $me['name'] . '恭恭敬敬地磕了四个响头，叫道：「师父！」' . NOR;
            }
        } else {
            $msg = HIY . $apprenticeName . '正式拜入' . $me['name'] . '门下，成为' . $sectName . '弟子！' . NOR;
        }
        Database::execute(
            'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
            [$player['id'], $msg, 'apprentice']
        );
    }

    // 给弟子发送通知（如果不在同一房间）
    $apprenticeInRoom = false;
    foreach ($allInRoom as $player) {
        if ($player['id'] == $apprenticeId) {
            $apprenticeInRoom = true;
            break;
        }
    }
    if (!$apprenticeInRoom) {
        $generation = $data['generation'] ?? 1;
        Database::execute(
            'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
            [
                $apprenticeId,
                HIY . sprintf('恭喜你成为%s的第%s代弟子！', $sectName, self_chinese_number($generation)) . NOR,
                'apprentice'
            ]
        );
    }

    return [
        'success'    => true,
        'message'    => $result['message'],
        'skip_queue' => true
    ];
}

/**
 * 内部辅助：整数转中文数字（用于第X代弟子）
 */
function self_chinese_number(int $num): string {
    $chars = ['零', '一', '二', '三', '四', '五', '六', '七', '八', '九', '十'];
    if ($num <= 0)  return '零';
    if ($num <= 10) return $chars[$num];
    if ($num < 20)  return '十' . $chars[$num - 10];
    if ($num < 100) {
        $ten = intdiv($num, 10);
        $one = $num % 10;
        return ($ten > 1 ? $chars[$ten] : '') . '十' . ($one > 0 ? $chars[$one] : '');
    }
    return (string)$num;
}

