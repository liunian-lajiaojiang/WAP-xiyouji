<?php
/**
 * 技能激发独立页面
 * 直接调用 ActionRouter 的 renderEnablePage 方法渲染技能管理界面
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

// 处理技能放弃操作（abandon + param + level）
$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($action === 'abandon') {
    $abandonParam = $_POST['param'] ?? $_GET['param'] ?? '';
    $abandonLevel = $_POST['level'] ?? $_GET['level'] ?? '';
    // 把 level 拼入 param，如 "unarmed level=5"
    if (!empty($abandonLevel) && intval($abandonLevel) > 0) {
        $abandonParam .= ' level=' . intval($abandonLevel);
    }
    $abandonResult = ActionRouter::dispatch($charId, 'abandon', $abandonParam, $char);
    $message = $abandonResult['output'] ?? $abandonResult['message'] ?? '操作失败。';

    $_SESSION['flash_message'] = [
        'type' => ($abandonResult['success'] ?? false) ? 'success' : 'error',
        'content' => $message,
        'timestamp' => time()
    ];

    // 刷新回当前页面
    redirect('skills_enable.php');
    exit;
}

// 处理技能激发操作（POST/GET type + skill 参数）
$type = $_POST['type'] ?? $_GET['type'] ?? '';
$skill = $_POST['skill'] ?? $_GET['skill'] ?? '';

if (!empty($type)) {
    if (!function_exists('cmd_enable')) {
        $message = '技能系统未加载';
    } else {
        $paramStr = ($skill === 'none') ? "$type none" : "$type $skill";
        $result = cmd_enable($charId, $paramStr);
        $message = $result['output'] ?? $result['message'] ?? '';
    }

    $_SESSION['flash_message'] = [
        'type' => 'success',
        'content' => $message,
        'timestamp' => time()
    ];

    // 刷新回当前页面
    redirect('skills_enable.php');
    exit;
}

// 获取技能激发页面数据
$data = ActionRouter::renderEnablePagePublic($charId);
$activeTypes = $data['activeTypes'];
$currentMap = $data['currentMap'];
$skillsByType = $data['skillsByType'];
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,user-scalable=no">
    <title>技能激发_西游记mud</title>
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/skills.css">
    <style>
        .skill-page { /* auto width */ }
        h3 { text-align: left; }
        h5 { color: #cc7000; margin: 6px 0 3px 0; }
        .tag-mapped { color: #008800; font-size: 0.85em; }
        .tag-none { color: #666; font-size: 0.85em; }
        .btn-enable { color: #1a73e8; text-decoration: none; }
        .btn-enable:hover { color: #1558b0; }
        .btn-disable { color: #cc0000; text-decoration: none; margin-left: 6px; }
        .btn-disable:hover { color: #aa0000; }
        .btn-abandon { color: #cc5500; text-decoration: none; margin-left: 6px; cursor: pointer; }
        .btn-abandon:hover { color: #b84400; }
    </style>
    <script>
    function abandonSkill(skillId, skillName, skillLevel) {
        if (skillLevel <= 0) { return; }
        var input = prompt('放弃「' + skillName + '」(等级' + skillLevel + ')\n\n请输入要放弃的等级数：\n（输入 ' + skillLevel + ' 则完全放弃，输入 0 取消）');
        if (input === null || input === '') return;
        var level = parseInt(input);
        if (isNaN(level) || level < 0) { alert('请输入有效的数字。'); return; }
        if (level === 0) return;
        if (level > skillLevel) { level = skillLevel; }
        var msg = (level >= skillLevel)
            ? '确定要完全放弃「' + skillName + '」(等级' + skillLevel + ')？\n此操作不可撤销！'
            : '确定要放弃「' + skillName + '」的 ' + level + ' 级吗？\n将从 ' + skillLevel + ' 级降至 ' + (skillLevel - level) + ' 级。\n此操作不可撤销！';
        if (!confirm(msg)) return;
        window.location.href = 'skills_enable.php?action=abandon&param=' + encodeURIComponent(skillId) + '&level=' + level;
    }
    </script>
</head>
<body>
<div class="skill-page">
<h3>【技能激发】 <a href="room.php" style="font-size:0.8em;">返回</a></h3>

<!-- 当前技能映射 -->
<h4>当前技能映射</h4>
<table class="skill-table">
    <tr>
        <th>技能类型</th>
        <th>当前激发</th>
        <th>等级</th>
        <th>有效</th>
        <th>操作</th>
    </tr>
    <?php
    $hasMapped = false;
    foreach ($activeTypes as $type => $desc):
        $hasMapped = true;
        $mapped = $currentMap[$type] ?? null;
        $mappedName = $mapped ? SkillManager::getSkillChineseName($mapped) : '无';
        $rawLevel = SkillManager::querySkill($charId, $type, true);
        $finalLevel = SkillManager::querySkill($charId, $type, false);
    ?>
    <tr>
        <td><span class="skill-name"><?php echo h($desc); ?></span> <span class="skill-id">(<?php echo h($type); ?>)</span></td>
        <td><?php if ($mapped): ?><span class="tag-mapped"><?php echo h($mappedName); ?></span> <span class="skill-id">(<?php echo h($mapped); ?>)</span><?php else: ?><span class="tag-none">无</span><?php endif; ?></td>
        <td><span class="lv"><?php echo $rawLevel; ?></span></td>
        <td><span class="lv"><?php echo $finalLevel; ?></span></td>
        <td>
            <?php if ($mapped): ?>
            <a href="skills_enable.php?type=<?php echo h($type); ?>&amp;skill=none" class="btn-disable">取消</a>
            <?php else: ?>
                <?php $availableSkills = $skillsByType[$type] ?? []; ?>
                <?php if (!empty($availableSkills)): ?>
                <a href="#enable_<?php echo h($type); ?>" class="btn-enable">激发</a>
                <?php else: ?>
                <span class="tag-none">-</span>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($rawLevel > 0): ?>
            <a href="javascript:abandonSkill('<?php echo h($type); ?>','<?php echo h($desc); ?>',<?php echo $rawLevel; ?>)" class="btn-abandon">放弃</a>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$hasMapped): ?>
    <tr><td colspan="5" class="no-data">你现在没有使用任何特殊技能。</td></tr>
    <?php endif; ?>
</table>

<!-- 可激发的技能列表 -->
<?php
$hasEnableable = false;
foreach ($activeTypes as $type => $desc):
    $typeSkills = $skillsByType[$type] ?? [];
    if (empty($typeSkills)) continue;
    $hasEnableable = true;
    $currentMapped = $currentMap[$type] ?? null;
?>
<h4 id="enable_<?php echo h($type); ?>"><?php echo h($desc); ?></h4>
<table class="skill-table">
    <tr>
        <th>技能</th>
        <th>等级</th>
        <th>有效</th>
        <th>操作</th>
    </tr>
    <?php foreach ($typeSkills as $skill):
        $skillId = $skill['skill_id'];
        $skillName = $skill['name'] ?? SkillManager::getSkillChineseName($skillId);
        $skillLevel = $skill['level'] ?? 0;
        $effectiveLevel = SkillManager::querySkill($charId, $skillId, false);
        $isMapped = ($currentMapped === $skillId);
    ?>
    <tr>
        <td><span class="skill-name"><?php echo h($skillName); ?></span> <span class="skill-id">(<?php echo h($skillId); ?>)</span></td>
        <td><span class="lv"><?php echo $skillLevel; ?></span></td>
        <td><span class="lv"><?php echo $effectiveLevel; ?></span></td>
        <td>
            <?php if ($isMapped): ?>
            <span class="tag-mapped">已激发</span>
            <a href="skills_enable.php?type=<?php echo h($type); ?>&amp;skill=none" class="btn-disable">取消</a>
            <?php else: ?>
            <a href="skills_enable.php?type=<?php echo h($type); ?>&amp;skill=<?php echo h($skillId); ?>" class="btn-enable">激发</a>
            <?php endif; ?>
            <a href="javascript:abandonSkill('<?php echo h($skillId); ?>','<?php echo h($skillName); ?>',<?php echo $skillLevel; ?>)" class="btn-abandon">放弃</a>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endforeach; ?>
<?php if (!$hasEnableable): ?>
<p class="no-data">你还没有学会任何可以激发的特殊技能。</p>
<?php endif; ?>

<a href="room.php" class="btn-back">返回游戏</a>
</div>
</body>
</html>
