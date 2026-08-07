<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 骰子房下注历史页面
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

// 获取下注历史（关联轮次表获取庄家点数）
$history = Database::queryAll(
    "SELECT b.*, r.dealer_name, r.dealer_point, r.dealer_point_name, r.dealer_point1, r.dealer_point2
     FROM shaizi_bets b
     LEFT JOIN shaizi_rounds r ON b.round_id = r.id
     WHERE b.char_id = ?
     ORDER BY b.created_at DESC
     LIMIT 50",
    [$charId]
);
?>
<!DOCTYPE html>
<html lang="zh-cn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title>骰子房历史_<?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
</head>
<body>
<p>
    <a href="shaizi.php">返回骰子房</a>&ensp;
    <a href="../functions/room.php">返回游戏</a>
</p>
<p>铜钱: <?= $coinBalance ?>文</p>

<h3>骰子房下注记录</h3>

<?php if (!empty($history)): ?>
<table border="1" style="width:100%; font-size:12px;">
    <thead>
        <tr>
            <th>时间</th>
            <th>轮次</th>
            <th>角色</th>
            <th>下注</th>
            <th>我的骰子</th>
            <th>我的点数</th>
            <th>庄家</th>
            <th>庄家骰子</th>
            <th>庄家点数</th>
            <th>结果</th>
            <th>盈亏</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($history as $r):
            $isDealer = (int)$r['is_dealer'] === 1;
            $myDice = ($r['point1'] !== null && $r['point2'] !== null)
                ? $r['point1'] . '+' . $r['point2']
                : '-';
            $dealerDice = ($r['dealer_point1'] !== null && $r['dealer_point2'] !== null)
                ? $r['dealer_point1'] . '+' . $r['dealer_point2']
                : '-';
            $netWin = 0;
            if ($isDealer) {
                $netWin = (int)$r['win_amount'] - (int)$r['bet_amount'];
            } elseif ($r['is_win'] !== null) {
                $netWin = (int)$r['is_win'] === 1
                    ? (int)$r['win_amount'] - (int)$r['bet_amount']
                    : -(int)$r['bet_amount'];
            }
        ?>
        <tr>
            <td><?= date('m-d H:i', strtotime($r['created_at'])) ?></td>
            <td>#<?= $r['round_id'] ?></td>
            <td><?= $isDealer ? '庄家' : '闲家' ?></td>
            <td><?= $r['bet_amount'] ?>文</td>
            <td><?= $myDice ?></td>
            <td><?= h($r['point_name'] ?? '-') ?></td>
            <td><?= h($r['dealer_name'] ?? '-') ?></td>
            <td><?= $dealerDice ?></td>
            <td><?= h($r['dealer_point_name'] ?? '-') ?></td>
            <?php if ($isDealer): ?>
                <td style="color:#FFD700;">庄家</td>
            <?php elseif ($r['is_win'] !== null): ?>
                <td style="color:<?= (int)$r['is_win'] === 1 ? '#00FF00' : '#FF6666' ?>;">
                    <?= (int)$r['is_win'] === 1 ? '赢' : '输' ?>
                </td>
            <?php else: ?>
                <td>-</td>
            <?php endif; ?>
            <td style="color:<?= $netWin >= 0 ? '#00FF00' : '#FF6666' ?>;">
                <?= $netWin >= 0 ? '+' : '' ?><?= $netWin ?>文
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<p>暂无下注记录</p>
<?php endif; ?>

<br>
<a href="shaizi.php">返回骰子房</a>
</body>
</html>
