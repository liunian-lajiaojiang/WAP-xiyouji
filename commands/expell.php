<?php
/**
 * 逐出师门命令 (expell)
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 用法: expell <弟子名称> | 逐出 <弟子> | zhuchu <弟子>
 * 功能: 将弟子逐出师门，解除师徒关系
 */

require_once DAEMON_PATH . 'ApprenticeHandler.php';

function cmd_expell(int $charId, string $param = ''): array {
    if (empty($param)) {
        return ['success' => false, 'message' => "指令格式：expell <弟子名称>\n你要将谁逐出师门？"];
    }

    $me = CharacterModel::find($charId);
    if (!$me) {
        return ['success' => false, 'message' => '角色不存在。'];
    }

    $targetName = trim($param);

    // -------------------------------------------------------
    // 查找弟子：先查同房间在线玩家，再查数据库
    // -------------------------------------------------------
    $area   = $me['current_area'] ?? '';
    $roomId = $me['current_room'] ?? '';
    $fullRoomId = (strpos($roomId, '/') !== false) ? $roomId : $area . '/' . $roomId;

    $apprenticeChar = null;

    // 同房间在线玩家中查找
    $playersInRoom = Database::queryAll(
        'SELECT id, name, family FROM characters WHERE current_room = ? AND online = 1 AND id != ?',
        [$fullRoomId, $charId]
    );
    foreach ($playersInRoom as $player) {
        if (stripos($player['name'], $targetName) !== false) {
            $apprenticeChar = $player;
            break;
        }
    }

    // 如果同房间没找到，在所有弟子中查找（不限在线）
    if (!$apprenticeChar) {
        $allApprentices = Database::queryAll(
            'SELECT id, name, family, current_room FROM characters WHERE master_id = ?',
            [$charId]
        );
        foreach ($allApprentices as $ap) {
            if (stripos($ap['name'], $targetName) !== false) {
                $apprenticeChar = $ap;
                break;
            }
        }
    }

    if (!$apprenticeChar) {
        return ['success' => false, 'message' => '找不到名叫 ' . $targetName . ' 的弟子，或此人不是你的弟子。'];
    }

    $apprenticeId = intval($apprenticeChar['id']);

    // -------------------------------------------------------
    // 执行逐出
    // -------------------------------------------------------
    $result = ApprenticeHandler::expellApprentice($charId, $apprenticeId);

    if (!$result['success']) {
        return ['success' => false, 'message' => $result['message']];
    }

    $apprenticeName = $apprenticeChar['name'];

    // -------------------------------------------------------
    // 广播消息
    // -------------------------------------------------------
    require_once DAEMON_PATH . 'MessageDaemon.php';

    // 获取房间内所有玩家
    $allInRoom = Database::queryAll(
        'SELECT id FROM characters WHERE current_room = ? AND online = 1',
        [$fullRoomId]
    );

    foreach ($allInRoom as $player) {
        if ($player['id'] == $charId) {
            $msg = HIR . '你对着' . $apprenticeName . '说道：从今天起，你我师徒恩断情绝，你走吧！' . NOR;
        } elseif ($player['id'] == $apprenticeId) {
            $msg = HIR . $me['name'] . '对着你说道：从今天起，你我师徒恩断情绝，你走吧！' . "\n"
                 . HIR . $me['name'] . '对着你说道：江湖风波，善恶无形，好自为之……' . NOR;
        } else {
            $msg = HIR . $me['name'] . '对着' . $apprenticeName . '说道：从今天起，你我师徒恩断情绝，你走吧！' . NOR;
        }
        Database::execute(
            'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
            [$player['id'], $msg, 'apprentice']
        );
    }

    // 如果弟子不在同一房间，单独通知弟子
    $apprenticeInRoom = false;
    foreach ($allInRoom as $player) {
        if ($player['id'] == $apprenticeId) {
            $apprenticeInRoom = true;
            break;
        }
    }
    if (!$apprenticeInRoom) {
        Database::execute(
            'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
            [
                $apprenticeId,
                HIR . "\n你被师父" . $me['name'] . "开革出师门了！\n" . NOR,
                'apprentice'
            ]
        );
    }

    return [
        'success'    => true,
        'message'    => '你将' . $apprenticeName . '逐出师门。',
        'skip_queue' => true
    ];
}

