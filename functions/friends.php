<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 好友列表页面
 */

// 加载游戏配置（定义MODEL_PATH、DAEMON_PATH等常量）
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Character.php';

// 要求登录
require_login();

$charId = get_char_id();
$char = CharacterModel::find($charId);

// 处理好友操作
$action = $_GET['action'] ?? '';
$friendId = intval($_GET['id'] ?? 0);
$message = '';
$messageType = '';

if ($action && $friendId) {
    switch ($action) {
        case 'accept':
            // 验证请求是发给自己的
            $request = Database::queryOne(
                "SELECT * FROM friends WHERE id = ? AND to_character_id = ? AND status = 'pending'",
                [$friendId, $charId]
            );
            if ($request) {
                Database::execute(
                    "UPDATE friends SET status = 'accepted', resolved_at = NOW() WHERE id = ?",
                    [$friendId]
                );
                $message = '已接受好友请求！';
                $messageType = 'success';
            } else {
                $message = '好友请求不存在或已处理。';
                $messageType = 'error';
            }
            break;

        case 'reject':
            $request = Database::queryOne(
                "SELECT * FROM friends WHERE id = ? AND to_character_id = ? AND status = 'pending'",
                [$friendId, $charId]
            );
            if ($request) {
                Database::execute(
                    'DELETE FROM friends WHERE id = ?',
                    [$friendId]
                );
                $message = '已拒绝好友请求。';
                $messageType = 'success';
            } else {
                $message = '好友请求不存在或已处理。';
                $messageType = 'error';
            }
            break;

        case 'delete':
            // 验证这条好友关系涉及当前角色
            $friendship = Database::queryOne(
                "SELECT * FROM friends WHERE id = ? AND (from_character_id = ? OR to_character_id = ?) AND status = 'accepted'",
                [$friendId, $charId, $charId]
            );
            if ($friendship) {
                Database::execute(
                    'DELETE FROM friends WHERE id = ?',
                    [$friendId]
                );
                $message = '已删除好友。';
                $messageType = 'success';
            } else {
                $message = '好友关系不存在。';
                $messageType = 'error';
            }
            break;
    }
}

// 获取已通过的好友列表（双向查询）
$friends = Database::queryAll(
    "SELECT f.*,
            CASE WHEN f.from_character_id = ? THEN f.to_character_id ELSE f.from_character_id END AS friend_char_id
     FROM friends f
     WHERE (f.from_character_id = ? OR f.to_character_id = ?) AND f.status = 'accepted'
     ORDER BY f.resolved_at DESC",
    [$charId, $charId, $charId]
);

// 获取好友的角色信息
$friendList = [];
if ($friends) {
    foreach ($friends as $f) {
        $friendCharId = $f['friend_char_id'];
        $friendChar = CharacterModel::find($friendCharId);
        if ($friendChar) {
            $friendList[] = [
                'id' => $f['id'],
                'char_id' => $friendCharId,
                'name' => $friendChar['name'],
                'family' => $friendChar['family'] ?? '无门无派',
                'online' => isset($friendChar['online']) ? $friendChar['online'] : false,
            ];
        }
    }
}

// 获取待处理的好友请求（收到的）
$pendingRequests = Database::queryAll(
    "SELECT f.*, c.name AS from_name, c.family AS from_family
     FROM friends f
     LEFT JOIN characters c ON f.from_character_id = c.id
     WHERE f.to_character_id = ? AND f.status = 'pending'
     ORDER BY f.created_at DESC",
    [$charId]
);

// 获取已发送的待确认请求
$sentRequests = Database::queryAll(
    "SELECT f.*, c.name AS to_name, c.family AS to_family
     FROM friends f
     LEFT JOIN characters c ON f.to_character_id = c.id
     WHERE f.from_character_id = ? AND f.status = 'pending'
     ORDER BY f.created_at DESC",
    [$charId]
);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,user-scalable=no">
    <title>好友_西游记mud</title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
</head>
<body>
<table border="0" cellpadding="0" cellspacing="0">

<!-- 好友列表 -->
    <tr>
        <td colspan="2" style="font-weight: bold; padding-top: 10px;">【我的好友】</td>
    </tr>
    <?php if (empty($friendList)): ?>
    <tr>
        <td colspan="2" style="padding-left: 20px; color: #888;">
            还没有好友，去认识一些朋友吧！
        </td>
    </tr>
    <?php else: ?>
    <?php foreach ($friendList as $friend): ?>
    <tr>
        <td colspan="2" style="padding-left: 20px;">
            <div style="border-left: 3px solid #00cc00; padding-left: 10px; margin-bottom: 5px;">
                <a href="character.php?id=<?= $friend['char_id'] ?>"><?= h($friend['name']) ?></a>
                <span style="color: #888;">（<?= h($friend['family']) ?>）</span>
                <?php if (!empty($friend['online'])): ?>
                <span style="color: #00cc00;">在线</span>
                <?php else: ?>
                <span style="color: #888;">离线</span>
                <?php endif; ?>
                <a href="friends.php?action=delete&id=<?= $friend['id'] ?>" style="color: #ff6600; font-size: 12px;" onclick="return confirm('确定要删除好友 <?= h($friend['name']) ?> 吗？')">【删除】</a>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($message): ?>
    <tr>
        <td colspan="2" style="padding-top: 5px; color: <?= $messageType === 'success' ? '#00cc00' : '#ff6600' ?>;">
            <?= h($message) ?>
        </td>
    </tr>
    <?php endif; ?>

    <tr>
        <td colspan="2"></td>
    </tr>

    <!-- 待处理的好友请求 -->
    <tr>
        <td colspan="2" style="font-weight: bold; padding-top: 10px;">【好友请求】</td>
    </tr>
    <?php if (empty($pendingRequests)): ?>
    <tr>
        <td colspan="2" style="padding-left: 20px; color: #888;">
            没有待处理的好友请求。
        </td>
    </tr>
    <?php else: ?>
    <?php foreach ($pendingRequests as $req): ?>
    <tr>
        <td colspan="2" style="padding-left: 20px;">
            <div style="border-left: 3px solid #ff6600; padding-left: 10px; margin-bottom: 5px;">
                <a href="character.php?id=<?= $req['from_character_id'] ?>"><?= h($req['from_name']) ?></a>
                <span style="color: #888;">（<?= h($req['from_family'] ?? '无门无派') ?>）</span>
                请求加你为好友
                <br>
                <a href="friends.php?action=accept&id=<?= $req['id'] ?>" style="color: #00cc00;">【接受】</a>
                <a href="friends.php?action=reject&id=<?= $req['id'] ?>" style="color: #ff6600;">【拒绝】</a>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>

    <tr>
        <td colspan="2"></td>
    </tr>

    <!-- 已发送的待确认请求 -->
    <tr>
        <td colspan="2" style="font-weight: bold; padding-top: 10px;">【已发送请求】</td>
    </tr>
    <?php if (empty($sentRequests)): ?>
    <tr>
        <td colspan="2" style="padding-left: 20px; color: #888;">
            没有待确认的请求。
        </td>
    </tr>
    <?php else: ?>
    <?php foreach ($sentRequests as $req): ?>
    <tr>
        <td colspan="2" style="padding-left: 20px;">
            <div style="border-left: 3px solid #8888ff; padding-left: 10px; margin-bottom: 5px;">
                <a href="character.php?id=<?= $req['to_character_id'] ?>"><?= h($req['to_name']) ?></a>
                <span style="color: #888;">（<?= h($req['to_family'] ?? '无门无派') ?>）</span>
                <span style="color: #888;">等待对方确认...</span>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>

    <tr>
        <td colspan="2"></td>
    </tr>
</table>
<br>
<a href="#" onclick="javascript:history.back(-1);">返回</a>
<hr>
<a href="room.php">返回游戏</a>
</body>
</html>

