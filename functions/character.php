<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 其他玩家详情页面
 * 显示玩家信息、外观描述和交互选项
 */



// 加载游戏配置（定义MODEL_PATH、DAEMON_PATH等常量）
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Character.php';
require_once HELPER_PATH . 'RankHelper.php';
require_once HELPER_PATH . 'SectHelper.php';
require_once HELPER_PATH . 'ArmorHelper.php';
require_once DAEMON_PATH . 'MessageDaemon.php';

// 要求登录
require_login();
$targetCharId = intval($_GET['id'] ?? 0);
$currentCharId = get_char_id();

// 不能查看自己
if ($targetCharId == $currentCharId) {
    redirect('score.php');
}

// 获取目标玩家信息
$targetChar = CharacterModel::find($targetCharId);
if (!$targetChar) {
    die('玩家不存在');
}

// 获取当前玩家信息（用于计算尊称等）
$currentChar = CharacterModel::find($currentCharId);

// 获取目标玩家的用户名
$targetUser = Database::queryOne("SELECT username FROM users WHERE id = ?", [$targetChar['user_id']]);
$targetUsername = $targetUser ? $targetUser['username'] : '未知';

// 检查目标玩家是否正在变形
$targetTransformData = get_transform_state_from_db($targetCharId);
$isTargetTransformed = !empty($targetTransformData);

// 如果玩家正在变化，使用变化后的信息
$displayChar = $targetChar;
$displayName = $targetChar['name'];
$displayDescription = '';
if ($isTargetTransformed && !empty($targetTransformData['target_data'])) {
    $displayChar = $targetTransformData['target_data'];
    $displayName = $targetTransformData['target_name'];
    $displayDescription = $displayChar['description'] ?? '';
}

/**
 * 根据年龄生成年龄描述
 */

function getPlayerAgeDesc(int $age, string $gender = 'male'): string
{
    if ($age <= 0) {
        return '看起来年纪不详';
    }
    if ($age < 14) {
        return "{$age}岁左右";
    } elseif ($age < 20) {
        return "{$age}岁左右";
    } elseif ($age < 35) {
        return "{$age}多岁";
    } elseif ($age < 50) {
        return "{$age}多岁";
    } elseif ($age < 70) {
        return "{$age}多岁";
    } else {
        return "{$age}多岁";
    }
}

/**
 * 根据 per (容貌) 和 gender 生成样貌描述
 */

function getPlayerAppearanceDesc(int $per, int $age, string $gender): string
{
    // 儿童 (age < 14)
    if ($age < 14) {
        $kidDesc = [
            25 => ['眉清目秀，灵气十足', '天真可爱，神态可掬', '粉雕玉琢，色若春晓'],
            20 => ['明眸皓齿，神色活泼', '聪明伶俐，讨人喜欢', '细皮嫩肉，唇红齿白'],
            15 => ['大头大脑，憨头憨脑', '黄发垂髫，小手小脚'],
            0  => ['蓬头垢面，衣衫褴褛', '呆头呆脑，面无表情', '瘦骨嶙峋，四肢纤细']
        ];
        if ($per >= 25) $descs = $kidDesc[25];
        elseif ($per >= 20) $descs = $kidDesc[20];
        elseif ($per >= 15) $descs = $kidDesc[15];
        else $descs = $kidDesc[0];
        return $descs[array_rand($descs)];
    }
    // 男性
    if ($gender === 'male') {
        $maleDesc = [
            25 => ['身材伟岸英挺，顾盼之间，气度非凡', '英俊挺拔，风流倜傥，确实是一表人才'],
            20 => ['英武矫健，器宇轩昂', '相貌堂堂，眉目清秀', '相貌堂堂，令人拜服'],
            15 => ['相貌平平，没什么好看的', '相貌丑陋，令人生畏', '尖嘴猴腮，贼眉鼠眼'],
            0  => ['长的一副惨不忍睹，人人避之唯恐不及的模样', '长的歪瓜裂枣，一副无可救药的德行', '面目狰狞，光头肿脸，一副刚被人揍过的衰相']
        ];
        if ($per >= 25) $descs = $maleDesc[25];
        elseif ($per >= 20) $descs = $maleDesc[20];
        elseif ($per >= 15) $descs = $maleDesc[15];
        else $descs = $maleDesc[0];
        return $descs[array_rand($descs)];
    }
    // 女性
    $femaleDesc = [
        25 => ['冰肌玉肤，滑腻似雪，不知倾倒了多少英雄好汉', '美艳绝伦，目不暇接，嫣然一笑，当真是媚态横生，风情万种', '娇靥如花，肌肤胜雪，端的是我见犹怜'],
        20 => ['颇有几分姿色，颇能吸引人', '面容娇嫩艳丽，身形苗条婀娜', '樱唇嫣红，眼波流转，顾盼投足之间，确有一番风姿'],
        15 => ['虽不算绝色佳人，也有几分姿色', '姿色平庸，颇有几分姿色'],
        0  => ['长相比较难看', '丑陋不堪']
    ];
    if ($per >= 25) $descs = $femaleDesc[25];
    elseif ($per >= 20) $descs = $femaleDesc[20];
    elseif ($per >= 15) $descs = $femaleDesc[15];
    else $descs = $femaleDesc[0];
    return $descs[array_rand($descs)];
}

// 获取对玩家的尊称
$respectTitle = RankHelper::queryRespect($displayChar);

// 获取玩家的门派信息
$familyKey = $displayChar['family'] ?? '';
$family = !empty($familyKey) ? SectHelper::getSectName($familyKey) : '无门无派';
$rank = $displayChar['rank'] ?? '';
$title = !empty($rank) ? "{$family}{$rank}" : $family;

// 生成可用头衔列表
$availableTitles = [];

// 1. 凡人头衔（基础）
$addedTitle = $targetChar['added_title'] ?? null;
$isGhost = $targetChar['is_ghost'] ?? false;
$wizLevel = $targetChar['wiz_level'] ?? null;
$basicTitle = RankHelper::queryRank($targetChar, $addedTitle, $isGhost, $wizLevel);
$basicTitle = preg_replace('/【\s*|\s*】/', '', $basicTitle);
$availableTitles[] = [
    'value' => 'basic',
    'label' => $basicTitle,
    'type' => '基础'
];

// 2. 门派头衔
$sectInfo = SectHelper::getCharacterSect($targetCharId);
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
$displayRank = $targetChar['rank'] ?? '';
if (!empty($displayRank)) {
    $availableTitles[] = [
        'value' => 'rank',
        'label' => $displayRank,
        'type' => '官职'
    ];
}

// 4. 科举官阶
$officialRank = intval($targetChar['official_rank'] ?? 0);
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
$currentDisplayTitle = $targetChar['display_title'] ?? 'basic';

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

// 生成年龄描述
$ageDesc = getPlayerAgeDesc($displayChar['age'] ?? 0, $displayChar['gender']);

// 生成样貌描述
$appearanceDesc = getPlayerAppearanceDesc($displayChar['per'] ?? 10, $displayChar['age'] ?? 25, $displayChar['gender']);

// 根据性别选择代词
$pronoun = ($displayChar['gender'] === 'female') ? '她' : '他';

// 构建完整的外观描述
$fullAppearance = "{$pronoun}是一位{$ageDesc}" . ($displayChar['gender'] === 'male' ? '男子' : '女子') . "。\n生得{$appearanceDesc}";

// 获取目标玩家的装备信息
$equipment = CharacterModel::getEquipment($targetCharId);

// 检查是否在同一房间
$sameRoom = ($targetChar['current_room'] === $currentChar['current_room']);

// 如果在同一房间且目标玩家在线，只向被查看的玩家发送消息（不广播）
if ($sameRoom && $targetChar['online']) {
    $lookMessage = "{$currentChar['name']}正在仔细打量你。";
    MessageDaemon::sendPrivateMessage($targetCharId, $lookMessage, $currentCharId);
}

// 自定义描述（待实现）
$customDesc = ''; // TODO: 从数据库读取玩家自定义描述
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,user-scalable=no">
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
    <title><?= h($targetChar['name']) ?> - 西游记MUD</title>
</head>
<body>
    <?php
    // 检查当前登录用户是否正在变成这个目标角色
    $isTransformedToThis = false;
    if (isset($_SESSION['transform_' . $currentCharId])) {
        $transformData = $_SESSION['transform_' . $currentCharId];
        if ($transformData['target_id'] == $targetCharId) {
            $isTransformedToThis = true;
        }
    }

    // 检查目标角色是否正在变化（我们无法直接知道，但可以给个提示）
    ?>
    <span>
        <?php if ($isTargetTransformed): ?>
            【<?= h($displayChar['title'] ?? $displayName) ?>】<?= h($displayName) ?>
        <?php else: ?>
            【<?= $displayedTitle ?>】<?= h($displayName) ?>(<?= h($targetUsername) ?>)
        <?php endif; ?>
    </span>
    <?php if ($targetChar['online']): ?>
        <span style="color: #00cc00;">&lt;在线&gt;</span>
    <?php else: ?>
        <span style="color: #999;">&lt;离线&gt;</span>
    <?php endif; ?>
    <br>
    <br>
    <?php if (!empty($customDesc)): ?>
        <?= nl2br(h($customDesc)) ?>
    <?php endif; ?>
    <?php if ($isTargetTransformed && !empty($displayDescription)): ?>
        <?= nl2br(h($displayDescription)) ?>
    <?php else: ?>
        <?= nl2br(h($fullAppearance)) ?>
    <?php endif; ?>
    <br>
    <?php if (!empty($equipment)): ?>
    <br>
    <strong><?= h($displayName) ?>身上穿戴着：</strong><br>
    <table border="0" cellpadding="0" cellspacing="0">
    <?php foreach ($equipment as $eq): ?>
        <?php
            $slotLabel = '';
            if (!empty($eq['equip_slot'])) {
                $slotLabel = ArmorHelper::getSlotName($eq['equip_slot']);
            } elseif (!empty($eq['item_type']) && $eq['item_type'] === 'weapon') {
                $slotLabel = '武器';
            } elseif (!empty($eq['armor_type'])) {
                $slotLabel = ArmorHelper::getSlotName($eq['armor_type']);
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
    <?php endif; ?>
    <br>
    <?php
    // 师徒关系显示（还原原始项目 look 命令的谱系关系判定）
    require_once HELPER_PATH . 'SectHelper.php';
    require_once DAEMON_PATH . 'ApprenticeHandler.php';

    $relationResult = ApprenticeHandler::getRelationship($currentCharId, $targetCharId);
    if (!empty($relationResult['related']) && !empty($relationResult['title'])):
        $relationTitle = $relationResult['title'];
        $relationDesc  = '';
        switch ($relationResult['relation']) {
            case 'master':          $relationDesc = '是你的师父'; break;
            case 'apprentice':      $relationDesc = '是你的弟子'; break;
            case 'senior':          $relationDesc = '是你的师兄'; break;
            case 'junior':          $relationDesc = '是你的师弟'; break;
            case 'uncle_senior':    $relationDesc = '是你的师伯'; break;
            case 'uncle_junior':    $relationDesc = '是你的师叔'; break;
            case 'nephew':          $relationDesc = '是你的师侄'; break;
            case 'ancestor':        $relationDesc = '是你的师祖'; break;
            case 'descendant':      $relationDesc = '是你的徒孙'; break;
        }
        // 性别修正称谓
        if ($targetChar['gender'] === 'female') {
            $relationTitle = str_replace(['师兄', '师弟', '师伯', '师叔'], ['师姐', '师妹', '师姑', '师姨'], $relationTitle);
            $relationDesc  = str_replace(['师兄', '师弟', '师伯', '师叔'], ['师姐', '师妹', '师姑', '师姨'], $relationDesc);
        }
    ?>
    <span style="color: #dda0dd;">【<?= h($relationTitle) ?>】<?= h($displayName) ?><?= $relationDesc ?></span>
    <br>
    <?php endif; ?>
    <br>
    <!-- 始终显示对话 -->
    <a href="#" onclick="startPrivateChat(<?= $targetCharId ?>, '<?= h($targetChar['name']) ?>'); return false;">对话</a>&ensp;
    <?php

    // 师徒相关代码（移到外面，始终加载）
    // 获取当前玩家的门派信息
    $currentFamily = $currentChar['family'] ?? '';

    // 获取目标玩家的门派信息
    $targetFamily = $targetChar['family'] ?? '';

    // 检查目标是否是当前玩家的师父
    $isMyMaster = ApprenticeHandler::isApprenticeOf($currentCharId, $targetCharId);

    // 检查目标是否是当前玩家的弟子
    $isMyApprentice = ApprenticeHandler::isApprenticeOf($targetCharId, $currentCharId);

    // 检查是否可以向目标玩家拜师（目标有门派，当前玩家无门派，且当前玩家不是目标的弟子）
    $canApprentice = false;
    if (!empty($targetFamily) && empty($currentFamily)) {
        if (!$isMyMaster) {
            $canApprentice = true;
        }
    }

    // 检查当前玩家是否可以收徒（有门派且目标无门派）
    $canRecruit = false;
    if (!empty($currentFamily) && empty($targetFamily)) {
        // 检查当前玩家是否已经是目标的师父
        $alreadyMaster = ApprenticeHandler::isApprenticeOf($targetCharId, $currentCharId);
        if (!$alreadyMaster) {
            $canRecruit = true;
        }
    }

    // 检查是否有待处理的拜师请求
    $pendingRequests = ApprenticeHandler::getPendingRequests($currentCharId);
    $hasIncomingRequest = false;
    $hasOutgoingRequest = false;
    foreach ($pendingRequests['data']['incoming'] ?? [] as $req) {
        if ($req['from_character_id'] == $targetCharId) {
            $hasIncomingRequest = true;
            break;
        }
    }
    foreach ($pendingRequests['data']['outgoing'] ?? [] as $req) {
        if ($req['to_character_id'] == $targetCharId) {
            $hasOutgoingRequest = true;
            break;
        }
    }

    // 始终显示师徒相关选项
    if ($canApprentice && !$hasOutgoingRequest): ?>
        <a href="action.php?action=apprentice&param=<?= urlencode($targetChar['name']) ?>">拜师</a>&ensp;
    <?php endif; ?>

    <?php if ($isMyMaster): ?>
        <a href="action.php?action=leaveSect&confirm=1" onclick="return confirm('真的要背叛师门吗？将承受惩罚！')">判师</a>&ensp;
        <a href="action.php?action=family&param=tree">查看谱系</a>&ensp;
    <?php endif; ?>

    <?php if ($canRecruit && !$hasIncomingRequest): ?>
        <a href="action.php?action=recruit&param=<?= urlencode($targetChar['name']) ?>">收徒</a>&ensp;
    <?php endif; ?>

    <?php if ($hasIncomingRequest): ?>
        <a href="action.php?action=acceptApprentice&target=<?= $targetCharId ?>">接受拜师</a>&ensp;
        <a href="action.php?action=rejectApprentice&target=<?= $targetCharId ?>">拒绝拜师</a>&ensp;
    <?php endif; ?>

    <?php if ($hasOutgoingRequest): ?>
        <span style="color: #999;">等待对方回应拜师请求...</span>&ensp;
    <?php endif; ?>

    <?php if ($isMyMaster): ?>
        <a href="skills_learn.php?master_id=<?= $targetCharId ?>">学习</a>&ensp;
    <?php endif; ?>
    <br>

    <!-- 不在同一房间时显示提示 -->
    <?php if (!$sameRoom || !$targetChar['online']): ?>
        <p style="color: #999;">
            <?php if (!$targetChar['online']): ?>
                该玩家当前离线。
            <?php else: ?>
                该玩家不在你所在的房间。
            <?php endif; ?>
        </p>
    <?php else: ?>

        <!-- 在同一房间且玩家在线时显示更多交互选项 -->
        <a href="action.php?action=greet&target=<?= $targetCharId ?>">请了</a>&ensp;
        <a href="action.php?action=thank&target=<?= $targetCharId ?>">感谢</a>&ensp;
        <a href="action.php?action=bow&target=<?= $targetCharId ?>">鞠躬</a>&ensp;
        <a href="action.php?action=kiss&target=<?= $targetCharId ?>">亲吻</a>&ensp;
        <br>
        <!-- 战斗相关 -->
        <a href="action.php?action=huimeng&target=<?= $targetCharId ?>">回梦</a>&ensp;
        <a href="action.php?action=cast&param=<?= urlencode('mihun on ' . $displayName) ?>">迷魂</a>&ensp;
        
        <a href="action.php?action=fight&target=<?= $targetCharId ?>">切磋</a>&ensp;
        <a href="action.php?action=kill&target=<?= $targetCharId ?>" onclick="return confirm('你真的要杀TA吗？')">击杀</a>&ensp;
        <a href="action.php?action=check&target=<?= $targetCharId ?>">探查</a>&ensp;
        <br>
        <!-- 好友相关交互 -->
        
        <?php if (($currentChar['following_id'] ?? null) == $targetCharId): ?>
        <a href="action.php?action=follow">取消跟随</a>&ensp;
        <?php else: ?>
        <a href="action.php?action=follow&target=<?= $targetCharId ?>">跟随</a>&ensp;
        <?php endif; ?>
        <a href="action.php?action=transform&target=<?= $targetCharId ?>">变成</a>&ensp;
        <a href="inventory.php?give_to=<?= $targetCharId ?>&give_to_name=<?= urlencode($displayName) ?>">给予</a>&ensp;
        <a href="action.php?action=add_friend&target=<?= $targetCharId ?>">加好友</a>&ensp;
        <?php if (($_GET['from'] ?? '') === 'kantai'): ?>
        <br>
        <!-- 擂台挑战（仅在观礼台显示） -->
        <a href="action.php?action=challenge&target=<?= $targetCharId ?>&area=city&room=<?= urlencode('city/misc/kantai') ?>" style="color: #FFD700; font-weight: bold;">挑战</a>&ensp;
        <?php endif; ?>
    <?php endif; ?>
    <br>
    <br>
    <div id="message-box" style="display:none; padding:10px; margin:10px 0; border-radius:5px;"></div>
    <a href="#" onclick="javascript:history.back(-1);">返回</a>
    <hr>
    <a href="room.php">返回游戏</a>

    <script src="../assets/js/character.js"></script>
</body>
</html>