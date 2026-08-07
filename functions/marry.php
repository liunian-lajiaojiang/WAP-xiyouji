<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

require_login();
$charId = get_char_id();
$char = Database::queryOne('SELECT * FROM characters WHERE id = ?', [$charId]);

// 查询已婚夫妻列表
$couples = Database::queryAll(
    'SELECT c1.name as name1, c2.name as name2 FROM characters c1 INNER JOIN characters c2 ON c1.couple_id = c2.id WHERE c1.couple_id IS NOT NULL AND c1.id < c1.couple_id ORDER BY c1.id'
);

// 判断是否在月下老人房间
$inMoonRoom = ($char['current_room'] === 'moon/ylt');

// 姻缘操作状态
$isMarried = !empty($char['couple_id']);

// 查询与我相关的求婚记录
$proposalAsProposer = Database::queryOne(
    'SELECT * FROM marry_requests WHERE proposer_id = ? AND status IN (?, ?, ?) ORDER BY created_at DESC LIMIT 1',
    [$charId, 'pending', 'accepted', 'meiren_set']
);
$proposalAsTarget = Database::queryOne(
    'SELECT * FROM marry_requests WHERE target_id = ? AND status IN (?, ?, ?) ORDER BY created_at DESC LIMIT 1',
    [$charId, 'pending', 'accepted', 'meiren_set']
);

// 确定当前活跃的求婚记录
$activeProposal = null;
$myRole = null; // 'proposer', 'target', 'meiren'
if ($proposalAsProposer && $proposalAsTarget) {
    // 取最近的一条
    $activeProposal = (strtotime($proposalAsProposer['created_at']) >= strtotime($proposalAsTarget['created_at']))
        ? $proposalAsProposer : $proposalAsTarget;
    $myRole = ($activeProposal['id'] === $proposalAsProposer['id']) ? 'proposer' : 'target';
} elseif ($proposalAsProposer) {
    $activeProposal = $proposalAsProposer;
    $myRole = 'proposer';
} elseif ($proposalAsTarget) {
    $activeProposal = $proposalAsTarget;
    $myRole = 'target';
}

// 检查我是否是某条 meiren_set 记录的媒人
$proposalAsMeiren = null;
if ($inMoonRoom && !$isMarried && !$activeProposal) {
    $proposalAsMeiren = Database::queryOne(
        'SELECT * FROM marry_requests WHERE meiren_id = ? AND status = ? ORDER BY created_at DESC LIMIT 1',
        [$charId, 'meiren_set']
    );
    if ($proposalAsMeiren) {
        $activeProposal = $proposalAsMeiren;
        $myRole = 'meiren';
    }
}

// 获取对方/媒人名称
$partnerName = '';
$meirenName = '';
if ($activeProposal) {
    if ($myRole === 'proposer') {
        $target = Database::queryOne('SELECT name FROM characters WHERE id = ?', [$activeProposal['target_id']]);
        $partnerName = $target ? $target['name'] : '未知';
        if ($activeProposal['meiren_id']) {
            $meiren = Database::queryOne('SELECT name FROM characters WHERE id = ?', [$activeProposal['meiren_id']]);
            $meirenName = $meiren ? $meiren['name'] : '未知';
        }
    } elseif ($myRole === 'target') {
        $proposer = Database::queryOne('SELECT name FROM characters WHERE id = ?', [$activeProposal['proposer_id']]);
        $partnerName = $proposer ? $proposer['name'] : '未知';
        if ($activeProposal['meiren_id']) {
            $meiren = Database::queryOne('SELECT name FROM characters WHERE id = ?', [$activeProposal['meiren_id']]);
            $meirenName = $meiren ? $meiren['name'] : '未知';
        }
    } elseif ($myRole === 'meiren') {
        $proposer = Database::queryOne('SELECT name FROM characters WHERE id = ?', [$activeProposal['proposer_id']]);
        $target = Database::queryOne('SELECT name FROM characters WHERE id = ?', [$activeProposal['target_id']]);
        $partnerName = ($proposer ? $proposer['name'] : '未知') . ' 和 ' . ($target ? $target['name'] : '未知');
    }
}

// 同房间在线玩家列表（用于指定媒人或求婚）
$roomPlayers = [];
if ($inMoonRoom && !$isMarried && $activeProposal && $activeProposal['status'] === 'accepted') {
    // 情况D：排除自己和对方
    $otherId = ($myRole === 'proposer') ? $activeProposal['target_id'] : $activeProposal['proposer_id'];
    $roomPlayers = Database::queryAll(
        'SELECT id, name FROM characters WHERE current_room = ? AND online = 1 AND id != ? AND id != ?',
        ['moon/ylt', $charId, $otherId]
    );
} elseif ($inMoonRoom && !$isMarried && !$activeProposal) {
    // 情况F：排除自己，只查未婚
    $roomPlayers = Database::queryAll(
        'SELECT id, name FROM characters WHERE current_room = ? AND online = 1 AND id != ? AND couple_id IS NULL',
        ['moon/ylt', $charId]
    );
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title>姻缘簿</title>
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
</head>
<body>
<div class="npc-content">
    <h3 style="color:#ff69b4;">📖 姻缘簿</h3>

    <!-- 已婚夫妻列表 -->
    <div class="npc-info">
        <p style="color:#ffd700;">天下有缘人：</p>
        <?php if (!empty($couples)): ?>
            <?php foreach ($couples as $couple): ?>
                <p><?php echo h($couple['name1']); ?> ❤ <?php echo h($couple['name2']); ?></p>
            <?php endforeach; ?>
        <?php else: ?>
            <p>暂无有缘人</p>
        <?php endif; ?>
    </div>

    <!-- 姻缘操作区 -->
    <div class="npc-actions">

    <?php if (!$inMoonRoom): ?>
        <!-- 不在月下老人房间 -->
        <p>需要前往月下老人处（杏林月老亭）才能进行姻缘操作。</p>

    <?php elseif ($isMarried): ?>
        <!-- 情况A：已婚玩家 -->
        <p>你的配偶：<?php echo h($char['couple_name']); ?></p>
        <p>[<a href="action.php?action=marry&param=divorce" style="color:#ff69b4;">离婚</a>]</p>

    <?php elseif ($activeProposal): ?>

        <?php if ($activeProposal['status'] === 'pending' && $myRole === 'target'): ?>
            <!-- 情况B：别人向我求婚 -->
            <p style="color:#ffd700;"><?php echo h($partnerName); ?>向你求婚！</p>
            <p>[<a href="action.php?action=marry&param=accept+<?php echo urlencode($partnerName); ?>" style="color:#ff69b4;">接受</a>] [<a href="action.php?action=marry&param=reject+<?php echo urlencode($partnerName); ?>" style="color:#999;">拒绝</a>]</p>

        <?php elseif ($activeProposal['status'] === 'pending' && $myRole === 'proposer'): ?>
            <!-- 情况C：我已求婚等待回复 -->
            <p>等待 <?php echo h($partnerName); ?> 答应你的求婚...</p>

        <?php elseif ($activeProposal['status'] === 'accepted'): ?>
            <!-- 情况D：求婚已被接受，指定媒人 -->
            <p style="color:#ffd700;">恭喜！指定一位媒人来完成婚礼</p>
            <?php if (!empty($roomPlayers)): ?>
                <?php foreach ($roomPlayers as $player): ?>
                    <p>
                        <?php echo h($player['name']); ?>
                        [<a href="action.php?action=marry&param=meiren+<?php echo urlencode($player['name']); ?>" style="color:#ff69b4;">指定为媒人</a>]
                    </p>
                <?php endforeach; ?>
            <?php else: ?>
                <p>当前房间没有其他在线玩家可担任媒人</p>
                <?php if ($myRole === 'proposer'): ?>
                <p>[<a href="action.php?action=marry&param=jiehun" style="color:#ff69b4;">直接完成婚礼</a>]</p>
                <?php endif; ?>

            <?php endif; ?>
        <?php elseif ($activeProposal['status'] === 'meiren_set'): ?>
            <?php if ($myRole === 'meiren'): ?>
                <!-- 情况E:我是媒人,可以执行完成婚礼 -->
                <p><?php echo h($partnerName); ?> 请你主持婚礼</p>
                <p>[<a href="action.php?action=marry&param=jiehun" style="color:#ff69b4;">完成婚礼</a>] [<a href="action.php?action=marry&param=cancel" style="color:#999;">取消婚礼</a>]</p>
            <?php else: ?>
                <!-- 情况E:我是新人,等待媒人宣布 -->
                <p>等待媒人 <?php echo h($meirenName); ?> 宣布完成婚礼...</p>
                <p>[<a href="action.php?action=marry&param=huange" style="color:#ff69b4;">换个媒人</a>]</p>
            <?php endif; ?>

        <?php endif; ?>

    <?php else: ?>
        <!-- 情况F：未婚，无进行中的求婚流程 -->
        <p style="color:#ffd700;">在此选择心仪之人：</p>
        <?php if (!empty($roomPlayers)): ?>
            <?php foreach ($roomPlayers as $player): ?>
                <p>
                    <?php echo h($player['name']); ?>
                    [<a href="action.php?action=marry&param=propose+<?php echo urlencode($player['name']); ?>" style="color:#ff69b4;">求婚</a>]
                </p>
            <?php endforeach; ?>
        <?php else: ?>
            <p>当前没有其他未婚玩家在此</p>
        <?php endif; ?>

    <?php endif; ?>

    </div>

    <!-- 返回链接 -->
    <div class="actions">
        <a href="room.php?area=moon&room=moon/ylt">返回</a>
    </div>
</div>
</body>
</html>
