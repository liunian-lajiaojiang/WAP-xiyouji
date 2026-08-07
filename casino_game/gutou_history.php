<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 骨骰房下注历史页面
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

// 获取下注历史（关联轮次表获取骰子结果）
$history = Database::queryAll(
    "SELECT b.*, r.big_dice, r.res_dice, r.winner as round_winner
     FROM gutou_bets b
     LEFT JOIN gutou_rounds r ON b.round_id = r.id
     WHERE b.char_id = ?
     ORDER BY b.created_at DESC
     LIMIT 50",
    [$charId]
);

// 类型名称映射
$kindNames = [
    'tc' => '头彩',
    'sd' => '双对',
    'qx' => '七星',
    'sx' => '散星',
];

// 中文数字
$cnNum = ['', '一', '二', '三', '四', '五', '六'];
?>
<!DOCTYPE html>
<html lang="zh-cn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title>骨骰房历史_<?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
</head>
<body>
<p>
    <a href="gutou.php">返回骨骰房</a>&ensp;
    <a href="../functions/room.php">返回游戏</a>
</p>
<p>铜钱: <?= $coinBalance ?>文</p>

<h3>骨骰房下注记录</h3>

<?php if (!empty($history)): ?>
<table border="1" style="width:100%; font-size:12px;">
    <thead>
        <tr>
            <th>时间</th>
            <th>轮次</th>
            <th>押注</th>
            <th>金额</th>
            <th>头彩号</th>
            <th>开骰</th>
            <th>结果</th>
            <th>盈亏</th>
            <th>手续费</th>
            <th>余额</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($history as $r):
            $big = json_decode($r['big_dice'] ?? '[0,0]', true) ?: [0, 0];
            $res = json_decode($r['res_dice'] ?? '[0,0]', true) ?: [0, 0];
            $bigText = $cnNum[$big[0]] . $cnNum[$big[1]];
            $resText = $cnNum[$res[0]] . $cnNum[$res[1]];
            $winnerName = $r['round_winner'] ? ($kindNames[$r['round_winner']] ?? $r['round_winner']) : '空盘';
            $netWin = $r['is_win'] ? ($r['win_amount'] - $r['commission']) : 0;
        ?>
        <tr>
            <td><?= date('m-d H:i', strtotime($r['created_at'])) ?></td>
            <td>#<?= $r['round_id'] ?></td>
            <td style="color:#FFD700;"><?= $kindNames[$r['bet_kind']] ?? $r['bet_kind'] ?></td>
            <td><?= $r['bet_amount'] ?>文</td>
            <td><?= $bigText ?>（<?= $big[0] ?><?= $big[1] ?>）</td>
            <td><?= $resText ?>（<?= $res[0] ?>+<?= $res[1] ?>=<?= $res[0] + $res[1] ?>）</td>
            <td><?= $winnerName ?></td>
            <td style="color:<?= $r['is_win'] ? '#00FF00' : '#FF6666' ?>">
                <?= $r['is_win'] ? '+' . $netWin : '-' . $r['bet_amount'] ?>文
            </td>
            <td><?= $r['commission'] ?>文</td>
            <td><?= $r['coin_after'] ?? '-' ?>文</td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<p>暂无下注记录</p>
<?php endif; ?>

<br>
<a href="gutou.php">返回骨骰房</a>
</body>
</html>
