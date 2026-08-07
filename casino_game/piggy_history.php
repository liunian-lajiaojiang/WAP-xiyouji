<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 拱猪房参与历史页面
 */

require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Character.php';
require_once HELPER_PATH . 'MoneyHelper.php';

require_login();
$charId = get_char_id();
$char = CharacterModel::getFullInfo($charId);
if (!$char) {
    redirect('character_select.php');
}

$money = MoneyHelper::getMoneyInventory($charId);
$coinBalance = intval($money['coin']);

// 获取房间ID
$roomId = $_REQUEST['room_id'] ?? 'city/piggy_n';
if (!in_array($roomId, ['city/piggy_n', 'city/piggy_s', 'city/piggy_e', 'city/piggy_w'])) {
    $roomId = 'city/piggy_n';
}

// 获取参与历史（关联轮次表获取本局结果摘要）
$history = Database::queryAll(
    "SELECT b.*, r.result_summary, r.game_mode
     FROM piggy_bets b
     LEFT JOIN piggy_rounds r ON b.round_id = r.id
     WHERE b.char_id = ?
     ORDER BY b.created_at DESC
     LIMIT 50",
    [$charId]
);

// 座位方向中文名
$seatNames = [
    'east'  => '东',
    'north' => '北',
    'west'  => '西',
    'south' => '南',
];
?>
<!DOCTYPE html>
<html lang="zh-cn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title>拱猪历史_<?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
</head>
<body>
<p>
    <a href="piggy.php?room_id=<?= h($roomId) ?>">返回拱猪房</a>&ensp;
    <a href="../functions/room.php?area=<?= h($char['current_area'] ?? 'city') ?>&room=<?= h($char['current_room'] ?? 'piggy_n') ?>">返回游戏</a>
</p>
<p>铜钱: <?= $coinBalance ?>文</p>

<h3>拱猪历史记录</h3>

<?php if (!empty($history)): ?>
<table border="1" style="width:100%; font-size:12px; border-collapse:collapse;">
    <thead>
        <tr>
            <th style="padding:4px;">时间</th>
            <th style="padding:4px;">轮次</th>
            <th style="padding:4px;">座位</th>
            <th style="padding:4px;">得分</th>
            <th style="padding:4px;">猪头</th>
            <th style="padding:4px;">等级分变化</th>
            <th style="padding:4px;">铜钱变化</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($history as $r):
            $rankChange = intval($r['rank_after']) - intval($r['rank_before']);
            $handScore = intval($r['hand_score']);
            $coinChange = intval($r['coin_change']);
            $isPighead = intval($r['is_pighead']);
        ?>
        <tr>
            <td style="padding:4px; white-space:nowrap;"><?= date('m-d H:i', strtotime($r['created_at'])) ?></td>
            <td style="padding:4px; text-align:center;">#<?= h($r['round_id']) ?></td>
            <td style="padding:4px; text-align:center;"><?= h($seatNames[$r['seat']] ?? $r['seat']) ?></td>
            <td style="padding:4px; text-align:right; color:<?= $handScore >= 0 ? '#00AA00' : '#FF0000' ?>; font-weight:bold;">
                <?= $handScore >= 0 ? '+' . $handScore : $handScore ?>
            </td>
            <td style="padding:4px; text-align:center; color:<?= $isPighead ? '#FF0000' : '#999' ?>;">
                <?= $isPighead ? '猪头' : '—' ?>
            </td>
            <td style="padding:4px; text-align:right; color:<?= $rankChange >= 0 ? '#00AA00' : '#FF0000' ?>;">
                <?= $rankChange >= 0 ? '+' . $rankChange : $rankChange ?>
                <span style="color:#999; font-size:11px;">(<?= intval($r['rank_before']) ?>→<?= intval($r['rank_after']) ?>)</span>
            </td>
            <td style="padding:4px; text-align:right; color:<?= $coinChange >= 0 ? '#00AA00' : '#FF0000' ?>;">
                <?= $coinChange >= 0 ? '+' . $coinChange : $coinChange ?>文
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<p>暂无拱猪记录</p>
<?php endif; ?>

<br>
<a href="piggy.php?room_id=<?= h($roomId) ?>">返回拱猪房</a>
</body>
</html>
