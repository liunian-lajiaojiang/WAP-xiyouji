<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 灭妖任务妖怪页面
 */

// 加载游戏配置（定义MODEL_PATH、DAEMON_PATH等常量）
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Character.php';

// 要求登录
require_login();

$yaoguaiId = $_GET['id'] ?? 0;

// 查询妖怪信息
$yaoguai = Database::queryOne(
    "SELECT * FROM mieyao_yaoguai WHERE id = ?",
    [$yaoguaiId]
);

if (!$yaoguai) {
    die('妖怪不存在');
}

$charId = get_char_id();
$char = CharacterModel::getFullInfo($charId);

// 检查妖怪是否属于当前玩家
$isOwner = ($yaoguai['owner_id'] == $charId);

// 检查妖怪是否已被杀
$isKilled = ($yaoguai['is_killed'] == 1);

// 检查妖怪是否过期
$isExpired = (strtotime($yaoguai['expires_at']) < time());

// 是否在同一房间（用于自动发起击杀）
$isInSameRoom = ($yaoguai['area'] === ($char['current_area'] ?? '') && $yaoguai['room_id'] === ($char['current_room'] ?? ''));
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title><?= h($yaoguai['npc_name']) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>

<p style="font-size: 18px; font-weight: bold; color: #ff0000;" class="npc-name">
    <?= h($yaoguai['npc_name']) ?>
    <?php
    // 如果妖怪没有 title，根据道行生成一个
    $title = $yaoguai['title'] ?? '';
    if (empty($title)) {
        $daoxing = $yaoguai['daoxing'] ?? 0;
        if ($daoxing > 10000) {
            $title = '万年老妖';
        } elseif ($daoxing > 1000) {
            $title = '千年精怪';
        } elseif ($daoxing > 100) {
            $title = '百年修行';
        } elseif ($daoxing > 10) {
            $title = '十数年修行';
        } else {
            $title = '小精怪';
        }
    }
    if (!empty($title)):
    ?>
    <span class="npc-title">[<?= h($title) ?>]</span>
    <?php endif; ?>
    </p>

<?php if ($isKilled): ?>
    <div class="warning">
        此妖怪已被杀死！
    </div>
<?php elseif ($isExpired): ?>
    <div class="warning">
        此妖怪已消失！
    </div>
<?php else: ?>
    <?php if (!$isOwner): ?>
        <div class="warning">
            这不是你的灭妖任务妖怪！
        </div>
    <?php else: ?>
        <p><strong>这是你的灭妖任务妖怪！</strong></p>
    <?php endif; ?>
<?php endif; ?>

<div class="npc-desc">
    <?= h($yaoguai['npc_name']) ?> 是一个 <?= h($yaoguai['title']) ?>妖怪。
    <?php if (!empty($yaoguai['face'])): ?>
        <?= h($yaoguai['face']) ?>。
    <?php endif; ?>
</div>


<br>
<div class="action-links">
    <?php if (!$isKilled && !$isExpired && $isOwner): ?>
        <a href="action.php?action=kill_yaoguai&yaoguai_id=<?= intval($yaoguai['id']) ?>" class="kill-btn">击杀！</a>
        <?php if ($isInSameRoom): ?>
            <script>
                // 如果玩家已经在同一房间，延迟自动发起击杀请求
                setTimeout(function(){
                    window.location.href = 'action.php?action=kill_yaoguai&yaoguai_id=<?= intval($yaoguai['id']) ?>';
                }, 600);
            </script>
        <?php endif; ?>
    <?php endif; ?>
    <br>
    <hr>
    <a href="<?= room_url($yaoguai['area'], $yaoguai['room_id']) ?>">返回</a> | 
    <a href="room.php">返回当前位置</a>
</div>

</body>
</html>
