<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 看自己页面
 * 显示当前登录玩家的外观、装备和健康状态
 */

// 加载游戏配置（定义MODEL_PATH、DAEMON_PATH等常量）
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Character.php';
require_once HELPER_PATH . 'RankHelper.php';
require_once HELPER_PATH . 'ArmorHelper.php';
require_once HELPER_PATH . 'SectHelper.php';

// 要求登录
require_login();

$charId = get_char_id();
$char = CharacterModel::getFullInfo($charId);

if (!$char) {
    die('角色不存在');
}

// 检查是否正在变化
$isTransformed = isset($_SESSION['transform_' . $charId]);
$transformData = $isTransformed ? get_transform_state_from_db($charId) : null;

$displayChar = $char;
$displayName = $char['name'];
$isNpc = false;

if ($isTransformed && !empty($transformData['target_data'])) {
    $displayChar = $transformData['target_data'];
    $displayName = $transformData['target_name'];
    $isNpc = ($transformData['target_type'] ?? '') === 'npc';
}

/**
 * 根据年龄生成年龄描述（自我视角）
 */
function getSelfAgeDesc(int $age, string $gender = 'male'): string
{
    if ($age <= 0) {
        return '年纪不详';
    }
    $maleTerms   = ['孩童', '少年', '青年', '中年男子', '老者', '老人'];
    $femaleTerms = ['孩童', '少女', '青年女子', '中年妇人', '老妪', '老妇人'];
    $terms = ($gender === 'female') ? $femaleTerms : $maleTerms;

    if ($age < 14)       return "{$age}岁的{$terms[0]}";
    elseif ($age < 20)   return "{$age}岁的{$terms[1]}";
    elseif ($age < 35)   return "{$age}多岁的{$terms[2]}";
    elseif ($age < 50)   return "{$age}多岁的{$terms[3]}";
    elseif ($age < 70)   return "{$age}多岁的{$terms[4]}";
    else                 return "{$age}多岁的{$terms[5]}";
}

/**
 * 根据 per (容貌) 和 gender 生成样貌描述
 */
function getSelfAppearanceDesc(int $per, int $age, string $gender): string
{
    if ($age < 14) {
        // 儿童（原始项目 look.c per_msg_kid1~kid4）
        if ($per >= 25) return '眉清目秀，灵气十足';
        elseif ($per >= 20) return '明眸皓齿，神色活泼';
        elseif ($per >= 15) return '大头大脑，憨头憨脑';
        else return '蓬头垢面，衣衫褴褛';
    }

    if ($gender === 'male') {
        // 男性（原始项目 look.c per_msg_male1~male4）
        if ($per >= 25) return '身材伟岸英挺，顾盼之间，气度非凡';
        elseif ($per >= 20) return '英武矫健，器宇轩昂';
        elseif ($per >= 15) return '相貌平平，没什么好看的';
        else return '长的一副惨不忍睹，人人避之唯恐不及的模样';
    }

    // 女性（原始项目 look.c per_msg_female1~female4）
    if ($per >= 25) return '长发如云，肌肤胜雪，不知倾倒了多少英雄豪杰';
    elseif ($per >= 20) return '面容娇嫩艳丽，身形苗条婀娜';
    elseif ($per >= 15) return '虽算不上绝色佳人，也有几分姿色';
    else return '长相比较难看';
}

// 门派与职位
$familyKey = $displayChar['family'] ?? '';
$family = !empty($familyKey) ? SectHelper::getSectName($familyKey) : '无门无派';
$rank   = $displayChar['rank']   ?? '';
$title  = !empty($rank) ? "{$family}{$rank}" : $family;

// 生成可用头衔列表
$availableTitles = [];

// 1. 凡人头衔（基础）
$addedTitle = $char['added_title'] ?? null;
$isGhost = $char['is_ghost'] ?? false;
$wizLevel = $char['wiz_level'] ?? null;
$basicTitle = RankHelper::queryRank($char, $addedTitle, $isGhost, $wizLevel);
$basicTitle = preg_replace('/【\s*|\s*】/', '', $basicTitle);
$availableTitles[] = [
    'value' => 'basic',
    'label' => $basicTitle,
    'type' => '基础'
];

// 2. 门派头衔
$sectInfo = SectHelper::getCharacterSect($charId);
$titleText = '无';
if ($sectInfo && !empty($sectInfo['sect_name'])) {
    $generation = $sectInfo['generation'] ?? 0;
    $sectRank = $sectInfo['sect_rank'] ?? '弟子';
    
    // 数字转中文（支持任意大小）
    if (!function_exists('toChineseNum')) {
        function toChineseNum(int $n): string {
            $c_digit = ["零", "十", "百", "千", "万", "亿", "兆"];
            $c_num = ["零", "一", "二", "三", "四", "五", "六", "七", "八", "九", "十"];
            if ($n < 0) return "负" . toChineseNum(-$n);
            if ($n < 11) return $c_num[$n];
            if ($n < 20) return $c_num[10] . $c_num[$n - 10];
            if ($n < 100) {
                return ($n % 10) ? $c_num[intval($n / 10)] . $c_digit[1] . $c_num[$n % 10] : $c_num[intval($n / 10)] . $c_digit[1];
            }
            return (string)$n;
        }
    }
    
    $genText = $generation > 0 ? '第' . toChineseNum($generation) . '代' : '';
    $titleText = $sectInfo['sect_name'] . $genText . $sectRank;
    
    if ($titleText !== '无') {
        $availableTitles[] = [
            'value' => 'sect',
            'label' => $titleText,
            'type' => '门派'
        ];
    }
}

// 3. 官职
$displayRank = $char['rank'] ?? '';
if (!empty($displayRank)) {
    $availableTitles[] = [
        'value' => 'rank',
        'label' => $displayRank,
        'type' => '官职'
    ];
}

// 4. 科举官阶
$officialRank = intval($char['official_rank'] ?? 0);
$rankNames = [
    0 => '白丁',
    1 => '秀才',
    2 => '举人',
    3 => '进士',
    4 => '翰林',
    5 => '侍郎',
];
if ($officialRank > 0 && isset($rankNames[$officialRank])) {
    $availableTitles[] = [
        'value' => 'official_rank',
        'label' => $rankNames[$officialRank],
        'type' => '科举'
    ];
}

// 获取当前选择的头衔
$currentDisplayTitle = $char['display_title'] ?? 'basic';

// 根据选择的value获取实际显示的头衔标签
function getSelectedTitleLabel($value, $availableTitles) {
    foreach ($availableTitles as $title) {
        if ($title['value'] === $value) {
            return $title['label'];
        }
    }
    // 默认返回第一个
    return $availableTitles[0]['label'] ?? '';
}
$displayedTitle = getSelectedTitleLabel($currentDisplayTitle, $availableTitles);

// 年龄与容貌描述
$gender        = $displayChar['gender'] ?? 'male';
$age           = intval($displayChar['age'] ?? 0);
$per           = intval($displayChar['per'] ?? 10);
$pronoun       = ($gender === 'female') ? '她' : '他';

$ageDesc        = getSelfAgeDesc($age, $gender);
$appearanceDesc = getSelfAppearanceDesc($per, $age, $gender);

// 气血健康百分比（用于颜色）
$keePercent = ($char['max_kee'] > 0) ? intval($char['kee'] / $char['max_kee'] * 100) : 0;
$ginPercent = ($char['max_gin'] > 0) ? intval($char['gin'] / $char['max_gin'] * 100) : 0;
$senPercent = ($char['max_sen'] > 0) ? intval($char['sen'] / $char['max_sen'] * 100) : 0;

function healthColor(int $pct): string {
    if ($pct > 80) return '#00cc00';
    if ($pct > 50) return '#cccc00';
    return '#cc0000';
}

// 获取装备列表
$equipment = CharacterModel::getEquipment($charId);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,user-scalable=no">
    <title><?= h($char['name']) ?> - 看自己 - 西游记MUD</title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
</head>
<body>

<?php if ($isTransformed && !empty($transformData)): ?>
<div style="color: #ff6600; font-weight: bold; margin-bottom: 10px;">
    【变化中】你当前变成了 <?= h($displayName) ?>，原形是 <?= h($char['name']) ?>！
</div>
<?php endif; ?>

<!-- 门派称号 + 名字 -->
<div style="font-size: 16px; font-weight: bold; margin-bottom: 5px;">
    <?php if ($isTransformed): ?>
        【<?= h($displayChar['title'] ?? $displayName) ?>】<?= h($displayName) ?>
    <?php else: ?>
        【<?= $displayedTitle ?>】<?= h($displayName) ?>
    <?php endif; ?>
</div>

<br>

<!-- 外观描述 -->
<div>
    <?php if ($isTransformed && !empty($displayChar['description'])): ?>
        <?= nl2br(h($displayChar['description'])) ?>
    <?php else: ?>
        你是一位<?= h($ageDesc) ?>。<br>
        生得<?= h($appearanceDesc) ?>。
    <?php endif; ?>
</div>

<br>

<!-- 当前装备 -->
<?php if (!empty($equipment)): ?>
<div>
    <strong>你身上穿戴着：</strong><br>
    <table border="0" cellpadding="0" cellspacing="0">
    <?php foreach ($equipment as $eq): ?>
        <?php
            $slotLabel = '';
            if (!empty($eq['equip_slot'])) {
                $slotLabel = ArmorHelper::getSlotName($eq['equip_slot']);
            } elseif (!empty($eq['item_type']) && $eq['item_type'] === 'weapon') {
                $slotLabel = '武器';
            }
            // 构建装备属性摘要
            $eqSummary = '';
            if (($eq['item_type'] ?? '') === 'weapon' && intval($eq['weapon_damage'] ?? 0) > 0) {
                $eqSummary .= ' 伤害' . intval($eq['weapon_damage']);
            }
            if (($eq['item_type'] ?? '') === 'armor' && intval($eq['armor_value'] ?? 0) > 0) {
                $eqSummary .= ' 防御' . intval($eq['armor_value']);
            }
            $briefBonuses = ['str_bonus'=>'臂力','dodge_bonus'=>'闪避','parry_bonus'=>'招架'];
            foreach ($briefBonuses as $f => $l) {
                $bv = intval($eq[$f] ?? 0);
                if ($bv > 0) $eqSummary .= " +{$bv}{$l}";
            }
        ?>
        <tr>
            <td style="padding-right: 10px; color: #aaa;">
                <?= !empty($slotLabel) ? '（' . h($slotLabel) . '）' : '' ?>
            </td>
            <td><?= h($eq['item_name']) ?><span style="color:#888;"><?= h($eqSummary) ?></span></td>
        </tr>
    <?php endforeach; ?>
    </table>
</div>
<br>
<?php else: ?>
<div style="color: #999;">你目前没有装备任何物品。</div>
<br>
<?php endif; ?>

<a href="#" onclick="javascript:history.back(-1);">返回</a>
<hr>
<a href="room.php">返回游戏</a>

</body>
</html>

