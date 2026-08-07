<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 赌大小下注历史页面
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

// 获取下注历史
$history = Database::queryAll(
    "SELECT * FROM dudaxiao_history WHERE char_id = ? ORDER BY created_at DESC LIMIT 50",
    [$charId]
);
?>
<!DOCTYPE html>
<html lang="zh-cn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title>下注历史_<?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
</head>
<body>
    <p>
        <a href="dudaxiao.php">返回赌大小</a>&ensp;
        <a href="../functions/room.php">返回游戏</a>
    </p>
    <p>💰铜钱: <?= $coinBalance ?>文</p>

    <h3>📊 下注记录</h3>
    <?php if (!empty($history)): ?>
        <table border="1" style="width:100%;font-size:13px;">
            <thead>
                <tr>
                    <th>时间</th>
                    <th>下注</th>
                    <th>押</th>
                    <th>骰子</th>
                    <th>结果</th>
                    <th>盈亏</th>
                    <th>手续费</th>
                    <th>余额</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $record): ?>
                    <tr>
                        <td><?= date('m-d H:i', strtotime($record['created_at'])) ?></td>
                        <td><?= $record['bet_amount'] ?>文</td>
                        <td><?= h($record['bet_choice']) ?></td>
                        <td><?= $record['dice_result'] ?>点</td>
                        <td style="color:<?= $record['is_win'] ? '#00FF00' : '#FF6666' ?>"><?= $record['is_win'] ? '赢' : '输' ?></td>
                        <td style="color:<?= $record['is_win'] ? '#00FF00' : '#FF6666' ?>">
                            <?= $record['is_win'] ? '+' . ($record['win_amount'] - $record['commission']) : '-' . $record['bet_amount'] ?>文
                        </td>
                        <td><?= $record['commission'] ?>文</td>
                        <td><?= $record['gold_after'] ?>文</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>暂无下注记录</p>
    <?php endif; ?>
    <br>
    <a href="dudaxiao.php">返回</a>
</body>
</html>
