<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 任务页面
 */

// 加载游戏配置（定义MODEL_PATH、DAEMON_PATH等常量）
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Character.php';
require_once HELPER_PATH . 'QuestHelper.php';

// 要求登录
require_login();

$charId = get_char_id();
$char = CharacterModel::find($charId);

// 加载开封解谜任务配置（通过QuestHelper接口获取）
$kaifengConfig = [];
$kaifengNpcMap = [];
if (class_exists('QuestHelper')) {
    $kaifengNpcMap = QuestHelper::getNpcMap();
    $questTypes = QuestHelper::getAvailableQuestTypes();
    foreach ($questTypes as $type) {
        $kaifengConfig['quest_pools'][$type] = QuestHelper::getQuestPool($type);
    }
} elseif (file_exists(__DIR__ . '/../config/kaifeng_quests.php')) {
    $kaifengConfig = require __DIR__ . '/../config/kaifeng_quests.php';
    $kaifengNpcMap = $kaifengConfig['npc_map'] ?? [];
}

// 获取灭妖任务
$mieyaoTask = null;
$mieyaoYaoguai = null;
$mieyaoTaskStatus = 'none'; // none, active, killed, expired
try {
    // 获取所有属于该玩家的妖怪记录
    $mieyaoYaoguai = Database::queryOne("SELECT * FROM mieyao_yaoguai WHERE owner_id = ? ORDER BY created_at DESC LIMIT 1", [$charId]);
    $mieyaoState = Database::queryOne("SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = 'mieyao_task'", [$charId]);
    if ($mieyaoState && !empty($mieyaoState['state_value'])) {
        $mieyaoTask = json_decode($mieyaoState['state_value'], true);
    }
    
    // 判断任务状态
    if ($mieyaoYaoguai) {
        if ($mieyaoYaoguai['is_killed']) {
            $mieyaoTaskStatus = 'killed';
        } elseif (strtotime($mieyaoYaoguai['expires_at']) < time()) {
            $mieyaoTaskStatus = 'expired';
        } else {
            $mieyaoTaskStatus = 'active';
        }
    } elseif ($mieyaoTask) {
        $mieyaoTaskStatus = 'expired'; // 只有任务信息但没有妖怪，说明已过期
    }
} catch (Exception $e) {
    error_log("获取灭妖任务失败: " . $e->getMessage());
}

// 获取进行中的任务（先检查过期）
$pendingQuests = [];
$expiredQuests = [];
try {
    QuestHelper::checkExpiredQuests($charId);
    $pendingQuests = QuestHelper::getPendingQuests($charId);
    // 获取已过期的任务
    $sql = "SELECT * FROM character_quests WHERE char_id = ? AND status = 'expired' ORDER BY completed_at DESC LIMIT 5";
    $expiredQuests = Database::queryAll($sql, [$charId]) ?: [];
} catch (Exception $e) {
    error_log("获取进行中任务失败: " . $e->getMessage());
}

// 获取已完成目标但未领奖的任务（done状态）
$doneQuests = [];
try {
    $doneQuests = QuestHelper::getDoneQuests($charId);
} catch (Exception $e) {
    error_log("获取done任务失败: " . $e->getMessage());
}

// 获取已完成的任务（包括 completed 和 expired）
$completedQuests = [];
try {
    $sql = "SELECT * FROM character_quests WHERE char_id = ? AND status IN ('completed', 'expired') ORDER BY completed_at DESC, created_at DESC LIMIT 5";
    $completedQuests = Database::queryAll($sql, [$charId]);
    if (!$completedQuests) $completedQuests = [];
} catch (Exception $e) {
    error_log("获取已完成任务失败: " . $e->getMessage());
}

// 获取祥云颜色计数
$colorData = QuestHelper::getColorCounter($charId);
$colorCount = $colorData['count'];
$colorList = $colorData['colors'];
$colorMultiplier = QuestHelper::getColorMultiplier($charId);

// 获取累计品德值
$questReward = intval($char['quest_reward'] ?? 0);

// 获取任务统计
$stats = null;
try {
    $statsSql = "SELECT * FROM quest_stats WHERE char_id = ?";
    $stats = Database::queryOne($statsSql, [$charId]);
} catch (Exception $e) {
    error_log("获取任务统计失败: " . $e->getMessage());
}

// 检查玩家背包内是否有水晶球
$hasCrystalBall = false;
try {
    $inventoryItem = Database::queryOne(
        "SELECT ci.item_id, ci.category, gi.name 
         FROM character_inventory ci 
         LEFT JOIN items gi ON ci.item_id = gi.item_id AND ci.category = gi.category 
         WHERE ci.char_id = ? AND ci.quantity > 0 AND ci.item_id = 'crystalball' 
         LIMIT 1",
        [$charId]
    );
    $hasCrystalBall = !empty($inventoryItem);
} catch (Exception $e) {
    error_log("检查水晶球失败: " . $e->getMessage());
    $hasCrystalBall = false;
}

// 任务类型名称
$questTypeNames = [
    QuestHelper::TYPE_KILL => '杀怪',
    QuestHelper::TYPE_GIVE => '给予',
    QuestHelper::TYPE_ASK => '询问',
    QuestHelper::TYPE_FIND => '查找',
    QuestHelper::TYPE_WEAPON => '武器',
    QuestHelper::TYPE_ARMOR => '盔甲',
    QuestHelper::TYPE_CLOTH => '衣服',
    QuestHelper::TYPE_FOOD => '食物',
];

// 颜色代码映射
$colorCodeMap = [
    'red' => '#ff0000',
    'green' => '#00ff00',
    'yellow' => '#ffff00',
    'blue' => '#0000ff',
    'purple' => '#800080',
    'cyan' => '#00ffff',
    'white' => '#ffffff',
];

// 区域名称映射
$areaNameMap = [
    'city' => '长安城',
    'westway' => '城西大道',
    'kaifeng' => '开封府',
    'lingtai' => '灵台方寸',
    'moon' => '月宫',
    'gao' => '高老庄',
    'sea' => '东海',
    'nanhai' => '南海',
    'eastway' => '城东大道',
    'xueshan' => '大雪山',
    'wuzhuang' => '五庄观',
    'death' => '地府',
    'meishan' => '梅山',
    'aolai' => '傲来国',
    'baotou' => '包头',
    'biqitan' => '碧波潭',
    'bqiu' => '比丘国',
    'fengxian' => '凤仙郡',
    'firemount' => '火焰山',
    'jilei' => '积雷山',
    'jjf' => '将军府',
    'longgong' => '龙宫',
    'pansi' => '盘丝洞',
    'pantaohui' => '蟠桃会',
    'putuo' => '普陀山',
    'qilin' => '麒麟山',
    'qujing' => '取经路',
    'sky' => '天宫',
    'tianzhu' => '天竺国',
    'tongtian' => '通天河',
    'wudidong' => '无底洞',
    'wuji' => '乌鸡国',
    'wzg' => '五庄观',
    'yanwu' => '雁门关',
    'yuhua' => '玉华州',
    'zhujie' => '朱紫国',
];

// 获取区域名称
function getAreaName($area, $map) {
    return $map[$area] ?? $area;
}

// 计算剩余时间并格式化
function format_remaining_time($expiresAt) {
    $expireTime = strtotime($expiresAt);
    $currentTime = time();
    $remaining = $expireTime - $currentTime;
    
    if ($remaining <= 0) {
        return '已过期';
    }
    
    $hours = floor($remaining / 3600);
    $minutes = floor(($remaining % 3600) / 60);
    $seconds = $remaining % 60;
    
    $parts = [];
    if ($hours > 0) {
        $parts[] = $hours . '小时';
    }
    if ($minutes > 0) {
        $parts[] = $minutes . '分钟';
    }
    if ($seconds > 0 || empty($parts)) {
        $parts[] = $seconds . '秒';
    }
    
    return implode('', $parts) . '后过期';
}

// 生成开封解谜任务的 NPC 对话消息（参考原始项目风格）
function getKaifengQuestDialog($quest, $npcMap) {
    $questType = $quest['quest_type'] ?? '';
    $targetName = $quest['quest_name'] ?? '';
    $questName = $quest['quest_name'] ?? '';
    
    // 根据任务类型生成不同的对话
    $dialogs = [
        // 杀怪任务（胡敬德）
        'kill' => [
            "站在高处对你说道：最近「{$questName}」为非作歹，你去收拾收拾！",
            "皱着眉头对你说道：「{$questName}」又在作乱了，去制止他们！",
            "站在山头对你说道：「{$questName}」为祸一方，去为民除害吧！",
            "抬头一看对你说道：「{$questName}」的营寨就在前方，去探探虚实！",
        ],
        // 询问任务（殷夫人/陈光蕊）
        'ask' => [
            "击节赞赏，一拍大腿对你说道：好！「{$questName}」那里有你要找的消息，去问问吧！",
            "拍案而起，对你说道：近日听说「{$questName}」知道些事情，去打听打听！",
            "捋着胡须，微微一笑，对你说道：出门在外，去「{$questName}」那里问问消息吧！",
        ],
        // 给予任务（送物）
        'give' => [
            "叹了口气，对你说道：此事还需「{$questName}」相助，你将此物送去！",
            "皱着眉头，对你说道：麻烦你跑一趟，把东西送给「{$questName}」。",
            "点点头，对你说道：有劳去「{$questName}」那里跑一趟。",
        ],
        // 武器任务（相公）
        'weapon' => [
            "点点头，对你说道：正好缺把兵器，去找把「{$questName}」来！",
            "叹了口气，对你说道：去寻一把「{$questName}」来，此事甚急！",
            "招手对你说道：去市面上找一把「{$questName}」来！",
        ],
        // 食物任务（猪八戒）
        'food' => [
            "摸着肚子，对你说道：肚子饿了，去弄些「{$questName}」来！",
            "点点头，对你说道：去寻些「{$questName}」来，此处正缺。",
            "微微一笑，对你说道：去弄些「{$questName}」来，好填饱肚子！",
        ],
        // 衣物任务（香兰）
        'cloth' => [
            "点点头，对你说道：去弄件「{$questName}」来，此处天凉。",
            "叹了口气，对你说道：去寻一件「{$questName}」来，此事紧急！",
            "招手对你说道：去市面上找一件「{$questName}」来！",
        ],
        // 盔甲任务（相婆）
        'armor' => [
            "点点头，对你说道：去寻一副「{$questName}」来，以防不测！",
            "皱着眉头，对你说道：去弄一件「{$questName}」来护身！",
            "招手对你说道：去寻「{$questName}」来，此处正需！",
        ],
        // 穿戴任务（玉兰）
        'wearing' => [
            "点点头，对你说道：去寻一件「{$questName}」来，此处正缺！",
            "微微一笑，对你说道：去弄一个「{$questName}」来！",
            "招手对你说道：去市面上找一个「{$questName}」来！",
        ],
        // 杂项任务（翠兰）
        'misc' => [
            "点点头，对你说道：去寻一个「{$questName}」来！",
            "叹了口气，对你说道：去弄「{$questName}」来，此事甚急！",
            "招手对你说道：去寻「{$questName}」来！",
        ],
    ];
    
    // 获取对应任务类型的对话，如果没有则使用默认对话
    $typeDialogs = $dialogs[$questType] ?? $dialogs['misc'];
    return $typeDialogs[array_rand($typeDialogs)];
}

// 获取开封解谜 NPC 名称
function getKaifengNpcName($questType, $npcMap) {
    foreach ($npcMap as $npc) {
        if (($npc['quest_type'] ?? '') === $questType) {
            return $npc['name'] ?? '';
        }
    }
    return '';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,user-scalable=no">
    <title>任务_西游记mud</title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
</head>
<body>
<table border="0" cellpadding="0" cellspacing="0">
    <tr>
        <td colspan="2" style="font-size: 16px; font-weight: bold;">【 <?= h($char['name']) ?> 的任务 】</td>
    </tr>
    
    <?php if (!empty($stats)): ?>
    <tr>
        <td colspan="2" style="padding-top: 10px;">
            <div style="font-weight: bold;">任务统计：</div>
            <div style="padding-left: 20px;">
                总任务数：<?= $stats['total_quests'] ?? 0 ?>
                <?php if ($colorCount > 0): ?>
                <br>祥云颜色：<?= $colorCount ?> 种（赴京请赏倍率 ×<?= $colorMultiplier ?>）
                <br>累计品德：<?= $questReward ?> 点
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php endif; ?>
    
    <tr>
        <td colspan="2"></td>
    </tr>
    
    <!-- 灭妖任务 -->
<tr>
<td colspan="2" style="font-weight: bold; padding-top: 10px;">【灭妖任务】</td>
</tr>
<?php if ($mieyaoTaskStatus === 'active'): ?>
<tr>
<td colspan="2" style="padding-left: 20px;">
<div style="border-left: 3px solid #ff6600; padding-left: 10px; margin-bottom: 5px;">
<span style="font-weight: bold;">寻找妖怪：<?= h($mieyaoYaoguai['npc_name'] ?? $mieyaoTask['name'] ?? '妖怪') ?></span>
<br>
<span style="color: #555;">
<?php if ($mieyaoYaoguai): ?>
袁天罡在你耳边低声说道：最近<?= h(getAreaName($mieyaoYaoguai['area'], $areaNameMap)) ?>一带出现了一个叫做<?= h($mieyaoYaoguai['npc_name']) ?>的妖怪，你快去把他除掉吧！
<?php if ($mieyaoYaoguai['expires_at']): ?>
<br><span id="countdown"><?= h(format_remaining_time($mieyaoYaoguai['expires_at'])) ?></span>

<script>
// 获取过期时间戳
const expireTime = <?= strtotime($mieyaoYaoguai['expires_at']) ?>;

// 更新倒计时的函数
function updateCountdown() {
    const now = Math.floor(Date.now() / 1000);
    const remaining = expireTime - now;
    
    if (remaining <= 0) {
        document.getElementById('countdown').textContent = '已过期';
        return;
    }
    
    const hours = Math.floor(remaining / 3600);
    const minutes = Math.floor((remaining % 3600) / 60);
    const seconds = remaining % 60;
    
    let parts = [];
    if (hours > 0) {
        parts.push(hours + '小时');
    }
    if (minutes > 0) {
        parts.push(minutes + '分钟');
    }
    if (seconds > 0 || parts.length === 0) {
        parts.push(seconds + '秒');
    }
    
    document.getElementById('countdown').textContent = parts.join('') + '后过期';
}

// 每秒更新一次
setInterval(updateCountdown, 1000);
</script>
<?php endif; ?>
<?php endif; ?>
</span>
<br>
<?php if ($hasCrystalBall): ?>
<a href="action.php?action=auto_find_yaoguai" style="color: #ff6600; text-decoration: underline;">→ 自动寻怪</a>
<?php else: ?>
<span style="color: #888;">→ 需要水晶球才能自动寻怪</span>
<?php endif; ?>
&nbsp;
<a href="action.php?action=abandon_mieyao" style="color: #999; text-decoration: underline;" onclick="return confirm('确定要放弃灭妖任务吗？放弃后任务等级会降低1级。')">'放弃'</a>
</div>
</td>
</tr>
<?php elseif ($mieyaoTaskStatus === 'killed'): ?>
<tr>
<td colspan="2" style="padding-left: 20px; color: #666;">
你目前没有灭妖任务，请先去袁天罡那里领取任务。
</td>
</tr>
<?php elseif ($mieyaoTaskStatus === 'expired'): ?>
<tr>
<td colspan="2" style="padding-left: 20px; color: #ff9900;">
灭妖任务已过期，请去袁天罡那里重新领取任务。
</td>
</tr>
<?php else: ?>
<tr>
<td colspan="2" style="padding-left: 20px; color: #888;">
你目前没有灭妖任务，请先去袁天罡那里领取任务。
</td>
</tr>
<?php endif; ?>

<!-- 开封解谜任务 -->
<tr>
<td colspan="2" style="font-weight: bold; padding-top: 10px;">【开封解谜】</td>
</tr>
<?php if (empty($pendingQuests) && empty($doneQuests)): ?>
<tr>
<td colspan="2" style="padding-left: 20px; color: #888;">
目前没有进行中的开封解谜任务。
</td>
</tr>
<?php else: ?>
<?php foreach ($pendingQuests as $quest): ?>
<?php 
    $questType = $quest['quest_type'] ?? '';
    $npcName = getKaifengNpcName($questType, $kaifengNpcMap);
    $dialog = getKaifengQuestDialog($quest, $kaifengNpcMap);
    $colorCode = $quest['color_code'] ?? 'white';
    $borderColor = $colorCodeMap[$colorCode] ?? '#ffffff';
    // 开封任务不再限制时间，不再显示剩余时间
?>
<tr>
<td colspan="2" style="padding-left: 20px;">
<div style="border-left: 3px solid <?= $borderColor ?>; padding-left: 10px; margin-bottom: 5px;">
<span style="font-weight: bold; color: #e0c060;">任务类型：<?= h($questTypeNames[$questType] ?? $questType) ?></span>
<br>
<span style="color: #ddd;">
<?= h($npcName) ?>：<?= h($dialog) ?>
</span>

</div>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>

<!-- ★ 已完成目标待领奖的任务（done状态） -->
<?php if (!empty($doneQuests)): ?>
<tr>
<td colspan="2" style="font-weight: bold; padding-top: 10px; color: #ff6600;">【待领奖任务】</td>
</tr>
<?php foreach ($doneQuests as $quest): ?>
<?php 
    $questType = $quest['quest_type'] ?? '';
    $npcName = getKaifengNpcName($questType, $kaifengNpcMap);
    $colorCode = $quest['color_code'] ?? 'white';
    $borderColor = $colorCodeMap[$colorCode] ?? '#ffffff';
    $questName = $quest['quest_name'] ?? '';
?>
<tr>
<td colspan="2" style="padding-left: 20px;">
<div style="border-left: 3px solid <?= $borderColor ?>; padding-left: 10px; margin-bottom: 5px; background: rgba(255,102,0,0.05);">
<span style="font-weight: bold; color: #ff6600;">⚡ 任务已完成！</span>
<span style="font-weight: bold;"><?= h($questTypeNames[$questType] ?? $questType) ?></span>
<br>
<span style="color: #ff6600;">
快去「<?= h($npcName) ?>」那里领赏！
</span>

</div>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
    
    <tr>
        <td colspan="2"></td>
    </tr>
    
    <!-- 最近完成的任务 -->
    <?php if (!empty($completedQuests)): ?>
    <tr>
        <td colspan="2" style="font-weight: bold; padding-top: 10px;">【最近完成】</td>
    </tr>
    <?php foreach ($completedQuests as $quest): 
        $isExpired = ($quest['status'] ?? '') === 'expired';
        $statusColor = $isExpired ? '#999' : '#666';
        $statusText = $isExpired ? '已过期' : '已完成';
        $timeField = $quest['completed_at'] ?? $quest['created_at'] ?? '';
    ?>
    <tr>
        <td colspan="2" style="padding-left: 20px; color: <?= $statusColor ?>;">
            <div style="margin-bottom: 3px;">
                <?= h($questTypeNames[$quest['quest_type'] ?? ''] ?? $quest['quest_type']) ?>：
                <?= h($quest['quest_name']) ?>
                <span style="font-size: 12px;">
                    (<?= $statusText ?>于 <?= !empty($timeField) ? date('Y-m-d H:i', strtotime($timeField)) : '' ?>)
                </span>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php endif; ?>
</table>
<br>
<a href="#" onclick="javascript:history.back(-1);">返回</a>
<hr>
<a href="room.php">返回游戏</a>
</body>
</html>

