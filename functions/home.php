<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

Database::addGuestStatusColumn();
Database::addBabyColumns();

require_once MODEL_PATH . 'Character.php';
require_once HELPER_PATH . 'HomeHelper.php';

require_login();

$charId = get_char_id();
$char = CharacterModel::getFullInfo($charId);
$home = HomeHelper::getHome($charId);
$invitedHome = HomeHelper::getVisitableHome($charId);

$isVisitor = false;
$visitedHome = null;

$selectedHomeId = $_GET['home_id'] ?? null;

if ($selectedHomeId && $invitedHome && $invitedHome['id'] == $selectedHomeId) {
    $isVisitor = true;
    $visitedHome = $invitedHome;
    $home = $visitedHome;
    $items = [];
    $babies = HomeHelper::getBabies($home['id']);
    $guests = HomeHelper::getGuests($home['id']);
} elseif ($home) {
    $items = HomeHelper::getStoredItems($home['id']);
    $babies = HomeHelper::getBabies($home['id']);
    $guests = HomeHelper::getGuests($home['id']);
} else if ($invitedHome) {
    $isVisitor = true;
    $visitedHome = $invitedHome;
    $home = $visitedHome;
    $items = [];
    $babies = HomeHelper::getBabies($home['id']);
    $guests = HomeHelper::getGuests($home['id']);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title>房产_WAP西游记2012</title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
    <link rel="stylesheet" href="../assets/css/home.css">
</head>
<body>

<div id="message-area"></div>

<?php if (!$home): ?>
<p>你还没有房产，结婚后才能购置房产。</p>
<p><a href="room.php">返回游戏</a></p>
<?php else: ?>

<?php if ($home && $invitedHome && !$isVisitor && !$selectedHomeId): ?>
<p style="color:#FFD700;">【邀请提示】你有收到访客邀请！</p>
<p>请选择要访问的家：</p>
<p>[<a href="home.php">进入自己的家</a>]</p>
<p>[<a href="home.php?home_id=<?php echo $invitedHome['id']; ?>">访问邀请人的家</a>]</p>
<hr>
<?php endif; ?>

<?php if ($isVisitor): ?>
<p style="color:#FFD700;">【访客模式】你正在访问他人的家</p>
<?php endif; ?>

<p>【<?php echo h($home['room_name']); ?>】</p>
<p><?php echo nl2br(h($home['room_desc'])); ?></p>
<br>
<span>【床铺】<?php echo h($home['bed_name']); ?></span>
<p><?php echo h($home['bed_desc']); ?></p>

<?php if (!$isVisitor && !empty($items)): ?>
<br>
<span>【存放的物品】</span>
<?php foreach ($items as $item): ?>
<p>
<span style="color:#66ccff;"><?php echo h($item['name'] ?? '未知物品'); ?></span><?php if ($item['quantity'] > 1): ?> x<?php echo $item['quantity']; ?><?php endif; ?>
[<a href="javascript:void(0);" onclick="executeHomeAction('retrieve', '<?php echo intval($item['id']); ?>')">取出</a>]
</p>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($babies)): ?>
<br>
<span>【孩子】</span>
<?php foreach ($babies as $baby): ?>
<p><?php echo h($baby['name']); ?>（<?php echo $baby['gender'] === 'male' ? '男' : '女'; ?>）
- 年龄: <?php echo intval($baby['age'] ?? 0); ?>岁
- 饥饿度: <?php echo intval($baby['hunger'] ?? 0); ?>%
<?php if (!$isVisitor): ?>
[<a href="javascript:void(0);" onclick="executeHomeAction('feedbaby', '<?php echo urlencode($baby['name']); ?>')">喂养</a>]
<?php endif; ?>
</p>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!$isVisitor && !empty($guests)): ?>
<span>【访客】</span>
<?php foreach ($guests as $guest): ?>
<p><?php echo h($guest['name'] ?? '未知'); ?> [<a href="javascript:void(0);" onclick="executeHomeAction('kick', '<?php echo urlencode($guest['name'] ?? ''); ?>')">移除</a>]</p>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!$isVisitor): ?>
<br>
<span>【操作】</span>
<table border="0">
<tr>
<td><a href="javascript:void(0);" onclick="showInputModal('name', '修改房间名', '请输入新的房间名：')">修改房间名</a></td>
<td><a href="javascript:void(0);" onclick="showInputModal('desc', '修改描述', '请输入新的描述：')">修改描述</a></td>
</tr>
<tr>
<td><a href="inventory.php?home_id=<?php echo intval($home['id']); ?>">存物品</a></td>
<td><a href="javascript:void(0);" onclick="showInputModal('invite', '邀请访客', '请输入要邀请的玩家名：')">邀请访客</a></td>
</tr>
<tr>
<td><a href="javascript:void(0);" onclick="showInputModal('baby', '生育小孩', '请输入：名字 male 或 名字 female')">生育小孩</a></td>
<td><a href="javascript:void(0);" onclick="executeHomeAction('leave')">离开回家</a></td>
</tr>
</table>
<?php else: ?>
<br>
<p><a href="javascript:void(0);" onclick="executeHomeAction('leave')">离开</a></p>
<?php endif; ?>

<?php endif; ?>

<br>
<hr>
<a href="room.php">返回游戏</a>

<!-- 输入弹窗 -->
<div id="input-modal" class="modal">
    <div class="modal-content">
        <h3 id="modal-title">输入</h3>
        <p id="modal-prompt">请输入：</p>
        <input type="text" id="modal-input" placeholder="">
        <div style="text-align: center;">
            <button class="btn-primary" onclick="submitModalInput()">确定</button>
            <button class="btn-secondary" onclick="closeModal()">取消</button>
        </div>
    </div>
</div>

<script src="../assets/js/home.js"></script>

</body>
</html>
