<?php
/**
 * 婚姻系统命令
 * 
 * 命令列表：
 *   marry propose <玩家名>  - 求婚（必须在月下老人房间）
 *   marry accept <玩家名>   - 接受求婚（必须在月下老人房间）
 *   marry reject <玩家名>   - 拒绝求婚（必须在月下老人房间）
 *   marry meiren <玩家名>   - 指定媒人（必须在月下老人房间）
 *   marry jiehun            - 正式结婚（需双方同意+媒人在场）
 *   marry divorce           - 离婚（二次确认，必须在月下老人房间）
 *   marry 簿               - 查看姻缘簿
 *   marry                  - 查看婚姻状态
 */

require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../daemons/MessageDaemon.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// 月下老人房间ID
define('YUELAO_ROOM', 'moon/ylt');

/**
 * 婚姻命令主入口
 */
function cmd_marry($charId, $param = '') {
    $me = CharacterModel::find($charId);
    if (!$me) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    $arg = trim($param);

    if (empty($arg)) {
        return marryStatus($me);
    }

    $parts = preg_split('/\s+/', $arg, 2);
    $subCmd = strtolower($parts[0]);
    $targetName = $parts[1] ?? '';

    // 姻缘簿特殊处理
    if ($subCmd === '簿') {
        return marryShowBook();
    }

    switch ($subCmd) {
        case 'propose':
            return marryPropose($charId, $me, $targetName);
        case 'accept':
            return marryAccept($charId, $me, $targetName);
        case 'reject':
            return marryReject($charId, $me, $targetName);
        case 'meiren':
            return marryMeiren($charId, $me, $targetName);
        case 'jiehun':
            return marryJiehun($charId, $me);
        case 'cancel':
        case '取消':
            return marryCancel($charId, $me);
        case 'huange':
        case '换个媒人':
            return marryHuanGe($charId, $me);
        case 'divorce':
        case '离婚':
            return marryDivorce($charId, $me);
        default:
            return marryHelp();
    }
}

/**
 * 检查是否在月下老人房间
 */
function marryCheckRoom($me) {
    if ($me['current_room'] !== YUELAO_ROOM) {
        return [
            'ok' => false,
            'message' => HTML_HIYEL . '你需要到月下老人那里才能办理姻缘事务。' . HTML_NOR
        ];
    }
    return ['ok' => true];
}

/**
 * 查找同房间在线玩家（按名字）
 */
function marryFindPlayerInRoom($me, $name) {
    $sql = "SELECT * FROM characters WHERE name = ? AND current_room = ? AND online = 1 AND id != ?";
    return Database::queryOne($sql, [$name, $me['current_room'], $me['id']]);
}

/**
 * 求婚
 */
function marryPropose($charId, $me, $targetName) {
    // 检查房间
    $roomCheck = marryCheckRoom($me);
    if (!$roomCheck['ok']) {
        return ['success' => false, 'message' => $roomCheck['message']];
    }

    if (empty($targetName)) {
        return ['success' => false, 'message' => '请输入你想要求婚的对象名称！用法：marry propose <玩家名>'];
    }

    // 检查自己未婚
    if (!empty($me['couple_id'])) {
        return ['success' => false, 'message' => HTML_HIRED . '你已经结婚了！' . HTML_NOR];
    }

    // 不能向自己求婚
    if ($targetName === $me['name']) {
        return ['success' => false, 'message' => '你不能向自己求婚！'];
    }

    // 查找目标玩家（同房间、在线）
    $target = marryFindPlayerInRoom($me, $targetName);
    if (!$target) {
        return ['success' => false, 'message' => "找不到名为 " . h($targetName) . " 的在线玩家，对方可能不在你身边。"];
    }

    // 检查目标未婚
    if (!empty($target['couple_id'])) {
        return ['success' => false, 'message' => h($targetName) . " 已经结婚了！"];
    }

    // 检查是否已有待处理的求婚记录
    $existing = Database::queryOne(
        "SELECT * FROM marry_requests WHERE proposer_id = ?",
        [$charId]
    );
    if ($existing) {
        // 清除旧的求婚记录
        Database::execute("DELETE FROM marry_requests WHERE proposer_id = ?", [$charId]);
    }

    // 检查目标是否已收到其他人的求婚
    $targetExisting = Database::queryOne(
        "SELECT * FROM marry_requests WHERE target_id = ? AND status = 'pending'",
        [$target['id']]
    );
    if ($targetExisting) {
        return ['success' => false, 'message' => h($targetName) . " 已经收到了别人的求婚，请稍后再试。"];
    }

    // 创建求婚记录
    Database::execute(
        "INSERT INTO marry_requests (proposer_id, target_id, status) VALUES (?, ?, 'pending')",
        [$charId, $target['id']]
    );

    // 发送私聊消息给对方（含 [接受求婚] 和 [拒绝求婚] 链接）
    $acceptUrl = "action.php?action=marry&param=" . urlencode("accept " . $me['name']);
    $rejectUrl = "action.php?action=marry&param=" . urlencode("reject " . $me['name']);
    $msg = HTML_HIGRN . "【姻缘】" . h($me['name']) . "向你求婚了！" . HTML_NOR . "<br>";
    $msg .= "<a href=\"{$acceptUrl}\" style=\"color:#ff69b4;font-weight:bold;\">[接受求婚]</a> ";
    $msg .= "<a href=\"{$rejectUrl}\" style=\"color:#999;\">[拒绝求婚]</a>";
    MessageDaemon::sendPrivateMessage($target['id'], $msg, $charId);
        
    // 广播房间消息，让其他玩家看到求婚场景
    $broadcastMsg = HTML_HIMAG . "【求婚】" . HTML_NOR . h($me['name']) . "向" . h($targetName) . "求婚了！场面十分感人！";
    MessageDaemon::broadcastToRoom($me['current_room'], $broadcastMsg, $charId);
    
    return [
        'success' => true,
        'message' => "你向" . h($targetName) . "求婚了，等待对方回应。",
        'skip_queue' => true,
    ];
}

/**
 * 接受求婚
 */
function marryAccept($charId, $me, $proposerName) {
    // 检查房间
    $roomCheck = marryCheckRoom($me);
    if (!$roomCheck['ok']) {
        return ['success' => false, 'message' => $roomCheck['message']];
    }

    if (empty($proposerName)) {
        return ['success' => false, 'message' => '请输入求婚者的名称！用法：marry accept <玩家名>'];
    }

    // 检查自己未婚
    if (!empty($me['couple_id'])) {
        return ['success' => false, 'message' => HTML_HIRED . '你已经结婚了！' . HTML_NOR];
    }

    // 查找求婚者（同房间、在线）
    $proposer = marryFindPlayerInRoom($me, $proposerName);
    if (!$proposer) {
        return ['success' => false, 'message' => "找不到名为 " . h($proposerName) . " 的在线玩家。"];
    }

    // 验证求婚者的 marry_requests 中确实有对自己的求婚记录
    $request = Database::queryOne(
        "SELECT * FROM marry_requests WHERE proposer_id = ? AND target_id = ?",
        [$proposer['id'], $charId]
    );
    if (!$request) {
        return ['success' => false, 'message' => h($proposerName) . " 并没有向你求婚。"];
    }

    // 更新求婚状态为已接受
    Database::execute(
        "UPDATE marry_requests SET status = 'accepted' WHERE proposer_id = ? AND target_id = ?",
        [$proposer['id'], $charId]
    );

    // 通知求婚者
    $notifyMsg = HTML_HIGRN . "【姻缘】" . h($me['name']) . "接受了你的求婚！" . HTML_NOR . "<br>";
    $notifyMsg .= "现在需要找一位在场的朋友做媒人 (marry meiren <名字>)，然后完成婚礼 (marry jiehun)。";
    MessageDaemon::sendPrivateMessage($proposer['id'], $notifyMsg, $charId);
        
    // 广播房间消息
    $broadcastMsg = HTML_HIMAG . "【喜讯】" . HTML_NOR . h($me['name']) . "接受了" . h($proposerName) . "的求婚！";
    MessageDaemon::broadcastToRoom($me['current_room'], $broadcastMsg, $charId);
    
    return [
        'success' => true,
        'message' => "你接受了" . h($proposerName) . "的求婚。请找一位在场的朋友做媒人。",
    ];
}

/**
 * 拒绝求婚
 */
function marryReject($charId, $me, $proposerName) {
    // 检查房间
    $roomCheck = marryCheckRoom($me);
    if (!$roomCheck['ok']) {
        return ['success' => false, 'message' => $roomCheck['message']];
    }

    if (empty($proposerName)) {
        return ['success' => false, 'message' => '请输入求婚者的名称！用法：marry reject <玩家名>'];
    }

    // 查找求婚者
    $proposer = Database::queryOne(
        "SELECT * FROM characters WHERE name = ?",
        [$proposerName]
    );
    if (!$proposer) {
        return ['success' => false, 'message' => "找不到名为 " . h($proposerName) . " 的玩家。"];
    }

    // 查找对应的 marry_requests 记录
    $request = Database::queryOne(
        "SELECT * FROM marry_requests WHERE proposer_id = ? AND target_id = ? AND status = 'pending'",
        [$proposer['id'], $charId]
    );
    if (!$request) {
        return ['success' => false, 'message' => h($proposerName) . " 并没有向你求婚。"];
    }

    // 删除该求婚记录
    Database::execute(
        "DELETE FROM marry_requests WHERE proposer_id = ? AND target_id = ? AND status = 'pending'",
        [$proposer['id'], $charId]
    );

    // 给求婚者发送私信通知被拒绝
    $notifyMsg = HTML_HIRED . "【姻缘】" . h($me['name']) . "拒绝了你的求婚。" . HTML_NOR;
    MessageDaemon::sendPrivateMessage($proposer['id'], $notifyMsg, $charId);
    
    // 广播房间消息
    $broadcastMsg = HTML_HIMAG . "【遗憾】" . HTML_NOR . h($me['name']) . "拒绝了" . h($proposerName) . "的求婚。";
    MessageDaemon::broadcastToRoom($me['current_room'], $broadcastMsg, $charId);

    return [
        'success' => true,
        'message' => "你拒绝了" . h($proposerName) . "的求婚。",
    ];
}

/**
 * 指定媒人
 */
function marryMeiren($charId, $me, $meirenName) {
    // 检查房间
    $roomCheck = marryCheckRoom($me);
    if (!$roomCheck['ok']) {
        return ['success' => false, 'message' => $roomCheck['message']];
    }

    if (empty($meirenName)) {
        return ['success' => false, 'message' => '请输入媒人的名称！用法：marry meiren <玩家名>'];
    }

    // 检查自己的求婚记录（状态为 accepted）
    $request = Database::queryOne(
        "SELECT * FROM marry_requests WHERE proposer_id = ? AND status = 'accepted'",
        [$charId]
    );
    if (!$request) {
        return ['success' => false, 'message' => '你还没有被接受的求婚记录，请先求婚并等待对方接受。'];
    }

    // 不能指定自己为媒人
    if ($meirenName === $me['name']) {
        return ['success' => false, 'message' => '你不能指定自己为媒人！'];
    }

    // 不能指定目标为媒人
    $target = Database::queryOne(
        "SELECT * FROM characters WHERE id = ?",
        [$request['target_id']]
    );
    if ($target && $meirenName === $target['name']) {
        return ['success' => false, 'message' => '不能指定结婚对象为媒人！'];
    }

    // 查找媒人（同房间、在线）
    $meiren = marryFindPlayerInRoom($me, $meirenName);
    if (!$meiren) {
        return ['success' => false, 'message' => "找不到名为 " . h($meirenName) . " 的在线玩家，对方可能不在你身边。"];
    }

    // 更新求婚记录中的媒人信息
    Database::execute(
        "UPDATE marry_requests SET meiren_id = ?, status = 'meiren_set' WHERE proposer_id = ?",
        [$meiren['id'], $charId]
    );
    
    // 通知媒人
    $notifyMsg = HTML_HIGRN . "【媒人邀请】" . h($me['name']) . "和" . h($target['name']) . "邀请你作为他们的媒人。" . HTML_NOR . "<br>";
    $notifyMsg .= "完成婚礼请输入：<a href='action.php?action=marry&param=jiehun' style='color:#ff69b4;font-weight:bold;'>marry jiehun</a><br>";
    $notifyMsg .= "取消婚礼请输入：<a href='action.php?action=marry&param=cancel' style='color:#999;'>marry cancel</a>";
    MessageDaemon::sendPrivateMessage($meiren['id'], $notifyMsg, $charId);
    
    // 广播房间消息
    $broadcastMsg = HTML_HIMAG . "【媒证】" . HTML_NOR . h($me['name']) . "和" . h($target['name']) . "请" . h($meirenName) . "做媒人，成就一段良缘！";
    MessageDaemon::broadcastToRoom($me['current_room'], $broadcastMsg, $charId);

    return [
        'success' => true,
        'message' => "你指定了" . h($meirenName) . "为媒人。等待媒人宣布完成婚礼...<br>" .
                     "<a href='action.php?action=marry&param=huange' style='color:#ff69b4;'>[换个媒人]</a>",
    ];
}

/**
 * 正式结婚（由媒人或求婚者执行）
 */
function marryJiehun($charId, $me) {
    // 检查房间
    $roomCheck = marryCheckRoom($me);
    if (!$roomCheck['ok']) {
        return ['success' => false, 'message' => $roomCheck['message']];
    }

    // 先尝试查找媒人记录
    $request = Database::queryOne(
        "SELECT * FROM marry_requests WHERE meiren_id = ? AND status = 'meiren_set'",
        [$charId]
    );

    if ($request) {
        // 情况1：当前用户是媒人
        return marryJiehunByMeiren($charId, $me, $request);
    }

    // 再尝试查找求婚者记录（无媒人的情况）
    $request = Database::queryOne(
        "SELECT * FROM marry_requests WHERE proposer_id = ? AND status = 'accepted' AND meiren_id IS NULL",
        [$charId]
    );

    if ($request) {
        // 情况2：当前用户是求婚者，且没有媒人
        return marryJiehunByProposer($charId, $me, $request);
    }

    return ['success' => false, 'message' => '你无法执行完成婚礼。'];
}

/**
 * 媒人执行完成婚礼
 */
function marryJiehunByMeiren($charId, $me, $request) {
    $proposerId = $request['proposer_id'];
    $targetId = $request['target_id'];

    // 验证求婚者在场且在线且未婚
    $proposer = Database::queryOne(
        "SELECT * FROM characters WHERE id = ? AND current_room = ? AND online = 1",
        [$proposerId, $me['current_room']]
    );
    if (!$proposer) {
        return ['success' => false, 'message' => '求婚者不在场或不在线，无法完成婚礼。'];
    }
    if (!empty($proposer['couple_id'])) {
        Database::execute("DELETE FROM marry_requests WHERE proposer_id = ?", [$proposerId]);
        return ['success' => false, 'message' => h($proposer['name']) . ' 已经结婚了！'];
    }

    // 验证被求婚者在场且在线且未婚
    $target = Database::queryOne(
        "SELECT * FROM characters WHERE id = ? AND current_room = ? AND online = 1",
        [$targetId, $me['current_room']]
    );
    if (!$target) {
        return ['success' => false, 'message' => '被求婚者不在场或不在线，无法完成婚礼。'];
    }
    if (!empty($target['couple_id'])) {
        Database::execute("DELETE FROM marry_requests WHERE proposer_id = ?", [$proposerId]);
        return ['success' => false, 'message' => h($target['name']) . ' 已经结婚了！'];
    }

    // 执行结婚
    $proposerName = $proposer['name'];
    $targetName = $target['name'];
    $meirenName = $me['name'];

    Database::execute(
        'UPDATE characters SET couple_id = ?, couple_name = ? WHERE id = ?',
        [$targetId, $targetName, $proposerId]
    );
    Database::execute(
        'UPDATE characters SET couple_id = ?, couple_name = ? WHERE id = ?',
        [$proposerId, $proposerName, $targetId]
    );

    // 设置结婚状态（用于婚礼服务）
    Database::execute(
        "INSERT INTO character_temp (char_id, temp_key, temp_value) VALUES (?, 'marrying', ?) ON DUPLICATE KEY UPDATE temp_value = ?",
        [$proposerId, $targetId, $targetId]
    );
    Database::execute(
        "INSERT INTO character_temp (char_id, temp_key, temp_value) VALUES (?, 'marrying', ?) ON DUPLICATE KEY UPDATE temp_value = ?",
        [$targetId, $proposerId, $proposerId]
    );

    // 婚礼广播
    $weddingMsg = HTML_HIYEL . "【喜讯】" . h($proposerName) . "和" . h($targetName) . "在" . h($meirenName) . "的见证下喜结连理，恭喜恭喜！" . HTML_NOR;
    MessageDaemon::broadcastToAll($weddingMsg, $charId, 'xyj');

    // 清除求婚记录
    Database::execute("DELETE FROM marry_requests WHERE proposer_id = ?", [$proposerId]);

    return [
        'success' => true,
        'message' => HTML_HIYEL . "恭喜！" . h($proposerName) . "和" . h($targetName) . "在你的见证下喜结连理！" . HTML_NOR,
    ];
}

/**
 * 求婚者执行完成婚礼（无媒人）
 */
function marryJiehunByProposer($charId, $me, $request) {
    $targetId = $request['target_id'];

    // 验证被求婚者在场且在线且未婚
    $target = Database::queryOne(
        "SELECT * FROM characters WHERE id = ? AND current_room = ? AND online = 1",
        [$targetId, $me['current_room']]
    );
    if (!$target) {
        return ['success' => false, 'message' => '你的另一半不在场或不在线，无法完成婚礼。'];
    }
    if (!empty($target['couple_id'])) {
        Database::execute("DELETE FROM marry_requests WHERE proposer_id = ?", [$charId]);
        return ['success' => false, 'message' => h($target['name']) . ' 已经结婚了！'];
    }

    // 执行结婚
    $targetName = $target['name'];
    $proposerName = $me['name'];

    Database::execute(
        'UPDATE characters SET couple_id = ?, couple_name = ? WHERE id = ?',
        [$targetId, $targetName, $charId]
    );
    Database::execute(
        'UPDATE characters SET couple_id = ?, couple_name = ? WHERE id = ?',
        [$charId, $proposerName, $targetId]
    );

    // 设置结婚状态（用于婚礼服务）
    Database::execute(
        "INSERT INTO character_temp (char_id, temp_key, temp_value) VALUES (?, 'marrying', ?) ON DUPLICATE KEY UPDATE temp_value = ?",
        [$charId, $targetId, $targetId]
    );
    Database::execute(
        "INSERT INTO character_temp (char_id, temp_key, temp_value) VALUES (?, 'marrying', ?) ON DUPLICATE KEY UPDATE temp_value = ?",
        [$targetId, $charId, $charId]
    );

    // 婚礼广播
    $weddingMsg = HTML_HIYEL . "【喜讯】" . h($proposerName) . "和" . h($targetName) . "喜结连理，恭喜恭喜！" . HTML_NOR;
    MessageDaemon::broadcastToAll($weddingMsg, $charId, 'xyj');

    // 清除求婚记录
    Database::execute("DELETE FROM marry_requests WHERE proposer_id = ?", [$charId]);

    return [
        'success' => true,
        'message' => HTML_HIYEL . "恭喜！你和" . h($targetName) . "喜结连理！" . HTML_NOR,
    ];
}

/**
 * 离婚（需要双方在場，一方发起，另一方同意）
 */
function marryDivorce($charId, $me) {
    // 检查房间
    $roomCheck = marryCheckRoom($me);
    if (!$roomCheck['ok']) {
        return ['success' => false, 'message' => $roomCheck['message']];
    }

    // 检查已婚
    if (empty($me['couple_id'])) {
        return ['success' => false, 'message' => '你还没有结婚！'];
    }

    $spouseId = $me['couple_id'];
    $spouseName = $me['couple_name'];

    // 查找同房间的配偶
    $spouse = marryFindPlayerInRoom($me, $spouseName);
    if (!$spouse) {
        return ['success' => false, 'message' => '离婚需要双方都在场，你的配偶不在当前房间。'];
    }

    // 验证配偶的 couple_id 是否匹配（防止数据不一致）
    if ($spouse['couple_id'] != $charId) {
        return ['success' => false, 'message' => '数据异常，请联系管理员。'];
    }

    // 检查是否有离婚请求记录（无论是谁发起的）
    $divorceRequest = Database::queryOne(
        "SELECT * FROM marry_requests WHERE 
         ((proposer_id = ? AND target_id = ?) OR (proposer_id = ? AND target_id = ?)) 
         AND status = 'divorce_pending'",
        [-$charId, $spouseId, -$spouseId, $charId]
    );

    if ($divorceRequest) {
        // 如果当前用户是目标方（被请求方），则执行离婚
        if ($divorceRequest['target_id'] == $charId) {
            // 执行离婚
            Database::execute(
                'UPDATE characters SET couple_id = NULL, couple_name = NULL WHERE id = ? OR id = ?',
                [$charId, $spouseId]
            );

            // 清除 character_temp 中的 marrying 状态
            Database::execute(
                "DELETE FROM character_temp WHERE char_id IN (?, ?) AND temp_key = 'marrying'",
                [$charId, $spouseId]
            );

            // 清除 player_homes.spouse_id
            Database::execute(
                'UPDATE player_homes SET spouse_id = NULL WHERE spouse_id = ? OR spouse_id = ?',
                [$charId, $spouseId]
            );

            // 广播离婚消息
            $divorceMsg = HTML_HICYN . "【告示】" . h($me['name']) . "和" . h($spouseName) . "从此一别两宽，各生欢喜。" . HTML_NOR;
            MessageDaemon::broadcastToAll($divorceMsg, $charId, 'xyj');

            // 清除离婚请求记录
            Database::execute(
                "DELETE FROM marry_requests WHERE 
                 ((proposer_id = ? AND target_id = ?) OR (proposer_id = ? AND target_id = ?)) 
                 AND status = 'divorce_pending'",
                [-$charId, $spouseId, -$spouseId, $charId]
            );

            return [
                'success' => true,
                'message' => "你已和" . h($spouseName) . "离婚。",
            ];
        } else {
            // 当前用户是发起方，提示等待对方同意
            return [
                'success' => true,
                'message' => HTML_HIRED . "你已经向" . h($spouseName) . "提出了离婚，等待对方同意。" . HTML_NOR . 
                            "<br>对方输入 <a href='action.php?action=marry&param=divorce' style='color:#ff69b4;'>marry divorce</a> 表示同意。",
            ];
        }
    }

    // 第一次调用 - 创建离婚请求
    Database::execute(
        "INSERT INTO marry_requests (proposer_id, target_id, status) VALUES (?, ?, 'divorce_pending')",
        [-$charId, $spouseId]
    );

    // 通知配偶
    $notifyMsg = HTML_HIRED . "【离婚通知】" . h($me['name']) . "向你提出了离婚。" . HTML_NOR . "<br>";
    $notifyMsg .= "如果你同意离婚，请输入 <a href='action.php?action=marry&param=divorce' style='color:#ff69b4;font-weight:bold;'>marry divorce</a> 表示同意。";
    MessageDaemon::sendPrivateMessage($spouseId, $notifyMsg, $charId);

    return [
        'success' => true,
        'message' => HTML_HIRED . "你向" . h($spouseName) . "提出了离婚，等待对方同意。" . HTML_NOR .
                    "<br>如果对方同意离婚，系统会自动为你们办理离婚手续。",
    ];
}

/**
 * 查看婚姻状态
 */
function marryStatus($me) {
    if (empty($me['couple_id'])) {
        return ['success' => true, 'message' => '你目前未婚。'];
    }
    return ['success' => true, 'message' => "你的配偶是：" . h($me['couple_name'])];
}

/**
 * 显示帮助信息
 */
function marryHelp() {
    $output = [];
    $output[] = HTML_HIYEL . '【婚姻系统】' . HTML_NOR;
    $output[] = '可用命令：';
    $output[] = '  marry propose <玩家名> - 向对方求婚（需在月下老人处）';
    $output[] = '  marry accept <玩家名>  - 接受对方的求婚（需在月下老人处）';
    $output[] = '  marry reject <玩家名>  - 拒绝对方的求婚（需在月下老人处）';
    $output[] = '  marry meiren <玩家名>  - 指定媒人（需在月下老人处）';
    $output[] = '  marry jiehun           - 正式结婚（需双方+媒人在场）';
    $output[] = '  marry divorce          - 离婚（需在月下老人处，二次确认）';
    $output[] = '  marry 簿              - 查看姻缘簿';
    $output[] = '  marry                  - 查看婚姻状态';
    return ['success' => false, 'message' => implode("\n", $output)];
}

/**
 * 显示姻缘簿
 */
function marryShowBook() {
    // 获取所有已婚玩家（每对夫妻只显示一次，id < couple_id）
    $sql = "SELECT c1.name as name1, c2.name as name2
            FROM characters c1
            INNER JOIN characters c2 ON c1.couple_id = c2.id
            WHERE c1.couple_id IS NOT NULL
            AND c1.id < c1.couple_id
            ORDER BY c1.id";
    $marriedCouples = Database::queryAll($sql);

    // 构建HTML输出
    $html = HTML_HIYEL . "【姻缘簿】" . HTML_NOR . "<br><br>";
    $html .= marryHelp()['message'] . "<br>";
    $html .= "<hr><br>";

    if (empty($marriedCouples)) {
        $html .= "<span style=\"color: #999;\">暂时没有记录...</span><br><br>";
    } else {
        foreach ($marriedCouples as $couple) {
            $html .= h($couple['name1']) . "❤️" . h($couple['name2']) . "<br>";
        }
        $html .= "<br>";
    }

    $html .= "<a href=\"javascript:history.back()\">返回</a>";

    return [
        'success' => true,
        'html' => $html,
    ];
}

/**
 * 取消婚礼（媒人专用）
 */
function marryCancel($charId, $me) {
    // 检查房间
    $roomCheck = marryCheckRoom($me);
    if (!$roomCheck['ok']) {
        return ['success' => false, 'message' => $roomCheck['message']];
    }

    // 检查是否是媒人
    $request = Database::queryOne(
        "SELECT * FROM marry_requests WHERE meiren_id = ? AND status = 'meiren_set'",
        [$charId]
    );
    if (!$request) {
        return ['success' => false, 'message' => '你不是媒人，无法取消婚礼。'];
    }

    $proposerId = $request['proposer_id'];
    $targetId = $request['target_id'];

    // 获取双方信息
    $proposer = Database::queryOne("SELECT * FROM characters WHERE id = ?", [$proposerId]);
    $target = Database::queryOne("SELECT * FROM characters WHERE id = ?", [$targetId]);
    
    if (!$proposer || !$target) {
        return ['success' => false, 'message' => '新人信息异常。'];
    }

    // 清除求婚记录
    Database::execute("DELETE FROM marry_requests WHERE proposer_id = ?", [$proposerId]);

    // 通知双方
    $notifyMsg = HTML_HIRED . "【婚礼取消】" . HTML_NOR . "媒人" . h($me['name']) . "取消了" . h($proposer['name']) . "和" . h($target['name']) . "的婚礼。";
    MessageDaemon::sendPrivateMessage($proposerId, $notifyMsg, $charId);
    MessageDaemon::sendPrivateMessage($targetId, $notifyMsg, $charId);

    // 广播房间消息
    $broadcastMsg = HTML_HIMAG . "【取消】" . HTML_NOR . "媒人" . h($me['name']) . "取消了" . h($proposer['name']) . "和" . h($target['name']) . "的婚礼。";
    MessageDaemon::broadcastToRoom($me['current_room'], $broadcastMsg, $charId);

    return [
        'success' => true,
        'message' => "你取消了" . h($proposer['name']) . "和" . h($target['name']) . "的婚礼。",
    ];
}

/**
 * 换个媒人（求婚者专用）
 */
function marryHuanGe($charId, $me) {
    // 检查房间
    $roomCheck = marryCheckRoom($me);
    if (!$roomCheck['ok']) {
        return ['success' => false, 'message' => $roomCheck['message']];
    }

    // 检查是否是求婚者
    $request = Database::queryOne(
        "SELECT * FROM marry_requests WHERE proposer_id = ? AND status = 'meiren_set'",
        [$charId]
    );
    if (!$request) {
        return ['success' => false, 'message' => '你没有已指定的媒人，无法更换。'];
    }

    $oldMeirenId = $request['meiren_id'];
    $oldMeiren = Database::queryOne("SELECT * FROM characters WHERE id = ?", [$oldMeirenId]);
    $oldMeirenName = $oldMeiren ? $oldMeiren['name'] : '未知';

    // 清除旧媒人记录，重置为 accepted 状态
    Database::execute(
        "UPDATE marry_requests SET meiren_id = NULL, status = 'accepted' WHERE proposer_id = ?",
        [$charId]
    );

    // 通知旧媒人
    if ($oldMeirenId) {
        $notifyMsg = HTML_HIGRN . "【媒人卸任】" . HTML_NOR . h($me['name']) . "已更换媒人，你不再担任他们的媒人。";
        MessageDaemon::sendPrivateMessage($oldMeirenId, $notifyMsg, $charId);
    }

    // 获取目标（被求婚者）
    $target = Database::queryOne("SELECT * FROM characters WHERE id = ?", [$request['target_id']]);
    $targetName = $target ? $target['name'] : '未知';

    return [
        'success' => true,
        'message' => "你已更换媒人（原媒人：" . h($oldMeirenName) . "）。现在可以重新指定媒人：marry meiren <玩家名>",
    ];
}
