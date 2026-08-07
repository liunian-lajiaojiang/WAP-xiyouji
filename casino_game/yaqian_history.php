<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 押签房下注历史页面
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

// 获取铜钱余额
$money = MoneyHelper::getMoneyInventory($charId);
$coinBalance = intval($money['coin']);

// 获取下注历史（已结算的记录）
$history = Database::queryAll(
    "SELECT b.*, r.signs, r.win_kind
     FROM yaqian_bets b
     LEFT JOIN yaqian_rounds r ON b.round_id = r.id
     WHERE b.char_id = ? AND b.is_settled = 1
     ORDER BY b.created_at DESC
     LIMIT 50",
    [$charId]
);

// 签种类映射
$signTypeNames = [
    'dqq' => '大乾签', 'dkq' => '大坤签',
    'xqq' => '小乾签', 'xkq' => '小坤签',
    'qq'  => '乾签',   'kq'  => '坤签',
];
?>
<!DOCTYPE html>
<html lang="zh-cn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title>押签历史_<?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
</head>
<body>
<p>
    <a href="yaqian.php">返回押签房</a>&ensp;
    <a href="../functions/room.php">返回游戏</a>
</p>
<p>💰铜钱: <?= $coinBalance ?>文</p>

<h3>📊 押签记录</h3>
<?php if (!empty($history)): ?>
    <table border="1" style="width:100%; font-size:12px; border-collapse:collapse;">
        <thead>
            <tr>
                <th>时间</th>
                <th>押注</th>
                <th>金额</th>
                <th>5签</th>
                <th>开奖</th>
                <th>结果</th>
                <th>盈亏</th>
                <th>手续费</th>
                <th>余额</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($history as $r): ?>
                <?php
                    $signDisplay = '';
                    if ($r['signs']) {
                        $signDisplay = implode(' ', array_map(function($s) {
                            return $s === '1' ? '乾' : '坤';
                        }, str_split($r['signs'])));
                    }
                    $winName = $signTypeNames[$r['win_kind']] ?? $r['win_kind'];
                    $betName = $signTypeNames[$r['bet_kind']] ?? $r['bet_kind'];
                ?>
                <tr>
                    <td><?= date('m-d H:i', strtotime($r['created_at'])) ?></td>
                    <td><?= h($betName) ?></td>
                    <td><?= $r['bet_amount'] ?>文</td>
                    <td style="font-size:11px;"><?= h($signDisplay) ?></td>
                    <td><?= h($winName) ?></td>
                    <td style="color:<?= $r['is_win'] ? '#00FF00' : '#FF6666' ?>;">
                        <?= $r['is_win'] ? '赢' : '输' ?>
                    </td>
                    <td style="color:<?= $r['is_win'] ? '#00FF00' : '#FF6666' ?>;">
                        <?= $r['is_win'] ? '+' . ($r['win_amount'] - $r['commission']) : '-' . $r['bet_amount'] ?>文
                    </td>
                    <td><?= $r['commission'] ?>文</td>
                    <td><?= $r['coin_after'] ?? '-' ?>文</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>暂无押签记录</p>
<?php endif; ?>
<br>
<a href="yaqian.php">返回</a>
</body>
</html>
