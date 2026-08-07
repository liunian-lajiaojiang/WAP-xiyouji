<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 乐府诗社答题历史页面
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

// 获取答题历史（关联轮次表获取题目信息）
$history = Database::queryAll(
    "SELECT a.*, r.poem_author, r.poem_title, r.scrambled, r.first_part, r.second_part
     FROM poem_answers a
     LEFT JOIN poem_rounds r ON a.round_id = r.id
     WHERE a.char_id = ?
     ORDER BY a.created_at DESC
     LIMIT 50",
    [$charId]
);

// 统计数据
$stats = Database::queryOne(
    "SELECT
        COUNT(*) as total,
        SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as correct,
        SUM(CASE WHEN is_correct = 0 THEN 1 ELSE 0 END) as wrong
     FROM poem_answers WHERE char_id = ?",
    [$charId]
);

// 奖励类型映射
$rewardNames = [
    'daoxing' => '道行',
    'potential' => '潜能',
    'literate' => '读书识字',
];
?>

<!DOCTYPE html>
<html lang="zh-cn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title>乐府诗社答题历史_<?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
</head>
<body>
<p>
    <a href="poem.php">返回诗社</a>&ensp;
    <a href="../functions/room.php">返回游戏</a>
</p>

<h3>答题历史</h3>

<p>
    总答题: <strong><?= (int)($stats['total'] ?? 0) ?></strong> 次 &ensp;
    答对: <strong style="color:#00FF00;"><?= (int)($stats['correct'] ?? 0) ?></strong> 次 &ensp;
    答错: <strong style="color:#FF6666;"><?= (int)($stats['wrong'] ?? 0) ?></strong> 次
</p>

<?php if (!empty($history)): ?>
<table border="1" style="width:100%; font-size:12px;">
    <thead>
        <tr>
            <th>时间</th>
            <th>题目</th>
            <th>出处</th>
            <th>你的回答</th>
            <th>结果</th>
            <th>奖励</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($history as $r): ?>
        <tr>
            <td><?= date('m-d H:i', strtotime($r['created_at'])) ?></td>
            <td style="color:#FFD700;"><?= h($r['scrambled'] ?? '') ?></td>
            <td><?= h($r['poem_author'] ?? '') ?>：<?= h($r['poem_title'] ?? '') ?></td>
            <td><?= h($r['answer_text']) ?></td>
            <td style="color:<?= $r['is_correct'] ? '#00FF00' : '#FF6666' ?>;">
                <?= $r['is_correct'] ? '答对' : '答错' ?>
            </td>
            <td>
                <?php if ($r['is_correct'] && !empty($r['reward_type'])): ?>
                    <span style="color:#FFD700;">
                        <?= $rewardNames[$r['reward_type']] ?? $r['reward_type'] ?>
                        +<?= (int)$r['reward_amount'] ?>
                    </span>
                <?php else: ?>
                    -
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<p>暂无答题记录</p>
<?php endif; ?>

<br>
<a href="poem.php">返回诗社</a>
</body>
</html>
