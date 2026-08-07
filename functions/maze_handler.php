<?php
/**
 * 白虎岭迷宫处理器
 * 根据Session中的迷宫数据和pos参数动态渲染迷宫房间
 */

// 注意：session已经在room.php中启动，这里不需要再次启动

require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/../daemons/BaihulingHandler.php';
require_once __DIR__ . '/../daemons/MessageDaemon.php';

// 检查登录
if (!isset($_SESSION['char_id'])) {
    header('Location: ../index.php');
    exit;
}

$charId = $_SESSION['char_id'];
$char = CharacterModel::find($charId);

if (!$char) {
    die('角色不存在');
}

// 获取当前位置参数
$pos = $_GET['pos'] ?? $_SESSION['baihuling_current_pos_' . $charId] ?? '0,0,0';

// 获取迷宫数据
$mazeKey = 'baihuling_maze_' . $charId;
if (!isset($_SESSION[$mazeKey])) {
    // 没有迷宫数据，传送回入口
    CharacterModel::updatePosition($charId, 'qujing', 'baihuling/entrance');
    header('Location: ../functions/room.php?area=qujing&room=baihuling/entrance');
    exit;
}

$mazeData = $_SESSION[$mazeKey];

// 保存当前位置到Session
$_SESSION['baihuling_current_pos_' . $charId] = $pos;

// 使用BaihulingHandler生成HTML
$handler = new BaihulingHandler();
$html = $handler->generateMazeRoomHtmlPublic($charId, $mazeData, $pos);

// 输出完整的HTML页面
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>白虎岭迷宫</title>
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <style>
    </style>
</head>
<body>
    <div class="container">
        <?php echo $html; ?>
    </div>
</body>
</html>
