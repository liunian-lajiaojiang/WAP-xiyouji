<?php
/**
 * 练功独立页面
 * 自行渲染练功界面，样式集成在本文件中
 */

session_save_path(__DIR__ . '/../sessions');
session_start();

define('IN_GAME', true);

require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

// 确保数据库表结构完整
Database::addMarriedColumn();
Database::addSleepInvitationsTable();
Database::addKeeZeroTimeColumn();
Database::addGuestStatusColumn();
Database::addBabyColumns();

// 加载模型
require_once MODEL_PATH . 'Character.php';
require_once MODEL_PATH . 'Room.php';
require_once MODEL_PATH . 'Npc.php';
require_once MODEL_PATH . 'Item.php';
require_once MODEL_PATH . 'User.php';

// 加载守护进程
require_once DAEMON_PATH . 'CombatDaemon.php';
require_once DAEMON_PATH . 'MessageDaemon.php';
require_once DAEMON_PATH . 'NatureDaemon.php';
require_once DAEMON_PATH . 'LoginDaemon.php';
require_once DAEMON_PATH . 'CommandDaemon.php';
require_once DAEMON_PATH . 'ActionRouter.php';
require_once DAEMON_PATH . 'QujingHandler.php';

// 加载辅助类
require_once HELPER_PATH . 'ArmorHelper.php';
require_once HELPER_PATH . 'WeaponHelper.php';
require_once HELPER_PATH . 'FabaoHelper.php';
require_once HELPER_PATH . 'SkillManager.php';
require_once HELPER_PATH . 'SpellHelper.php';
require_once HELPER_PATH . 'AttributeHelper.php';
require_once HELPER_PATH . 'ExpHelper.php';
require_once HELPER_PATH . 'CombatMessages.php';
require_once HELPER_PATH . 'CombatSystemHelper.php';

// 加载命令函数
$commandFiles = glob(__DIR__ . '/../commands/*.php');
foreach ($commandFiles as $file) {
    require_once $file;
}

// 要求登录
require_login();

$charId = get_char_id();
$char = CharacterModel::find($charId);

if (!$char) {
    redirect('../index.php');
    exit;
}

// 运行定时检查
QujingHandler::runTimedChecks();

// 处理练习操作（type + times 参数）
$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($action === 'practice') {
    $type = $_POST['type'] ?? $_GET['type'] ?? '';
    $times = $_POST['times'] ?? $_GET['times'] ?? '1';

    if (!empty($type)) {
        if (!function_exists('cmd_practice')) {
            $message = '练习系统未加载';
        } else {
            $paramStr = $type . ' ' . $times;
            $result = cmd_practice($charId, $paramStr);
            $message = $result['output'] ?? $result['message'] ?? '';
        }

        $_SESSION['flash_message'] = [
            'type' => ($result['success'] ?? false) ? 'success' : 'error',
            'content' => $message,
            'timestamp' => time()
        ];

        // 返回房间页
        redirect('room.php');
        exit;
    }
}

// 获取练功页面数据
$data = ActionRouter::renderPracticePagePublic($charId);
$combatExp = $data['combatExp'];
$potential = $data['potential'];
$availablePotential = $data['availablePotential'];
$potentialCostPerRound = $data['potentialCostPerRound'];
$skillMap = $data['skillMap'];
$skills = $data['skills'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,user-scalable=no">
    <title>练功_西游记mud</title>
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/skills.css">
    <style>
        h3 { text-align: left; }
        .btn-practice { color: #1a73e8; text-decoration: none; cursor: pointer; }
        .btn-practice:hover { color: #1558b0; }
        .tag-disabled { color: #999; }
    </style>
</head>
<body>
<div class="skill-page">
<h3>【练功】 <a href="room.php" style="font-size:0.8em;">返回</a></h3>

<div class="info-bar">
    <strong>武学：</strong><?php echo number_format($combatExp); ?> &nbsp;&nbsp;
    <strong>潜能：</strong><?php echo $availablePotential; ?> / <?php echo $potential; ?> &nbsp;&nbsp;
    <strong>每次消耗：</strong><?php echo $potentialCostPerRound; ?> 点
</div>

<h4>可练习的技能</h4>
<?php if (empty($skillMap)): ?>
    <p class="no-data">你还没有激发任何技能。请先到 <a href="skills_enable.php" style="color: #1a73e8;">技能激发</a> 页面设置技能映射。</p>
<?php else: ?>
    <table class="skill-table">
        <tr>
            <th>技能类型</th>
            <th>当前映射</th>
            <th>等级</th>
            <th>有效</th>
            <th>操作</th>
        </tr>
        <?php foreach ($skills as $s): ?>
        <tr>
            <td><span class="skill-name"><?php echo h($s['typeName']); ?></span> <span class="skill-id">(<?php echo h($s['type']); ?>)</span></td>
            <td><span class="skill-name"><?php echo h($s['skillName']); ?></span> <span class="skill-id">(<?php echo h($s['skillId']); ?>)</span></td>
            <td><span class="lv"><?php echo $s['skillLevel']; ?></span></td>
            <td><span class="lv"><?php echo $s['effectiveLevel']; ?></span></td>
            <td>
                <?php if ($s['canPractice']): ?>
                    <a href="javascript:void(0)" onclick="practiceSkill('<?php echo urlencode($s['type']); ?>')" class="btn-practice">练习</a>
                <?php else: ?>
                    <?php if ($availablePotential <= 0): ?>
                        <span class="tag-disabled">潜能不足</span>
                    <?php else: ?>
                        <span class="tag-disabled" title="需要修为: <?php echo number_format($s['requiredExp']); ?>">武学经验不足</span>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<script>
function practiceSkill(type) {
    var times = prompt('请输入练习次数（可用潜能: <?php echo $availablePotential; ?>）', '1');
    if (times === null) return;
    times = parseInt(times);
    if (isNaN(times) || times < 1) {
        alert('请输入有效的练习次数');
        return;
    }
    window.location.href = 'skills_practice.php?action=practice&type=' + type + '&times=' + times;
}
</script>

<a href="room.php" class="btn-back">返回游戏</a>
</div>
</body>
</html>
