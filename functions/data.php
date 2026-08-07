<?php
/**
 * 完整玩家数据展示页面 (data.php)
 * 展示角色的全部详细属性与修行状态
 */
session_save_path(__DIR__ . '/../sessions');
session_start();

// 加载游戏配置（定义MODEL_PATH、DAEMON_PATH等常量）
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Character.php';
require_once HELPER_PATH . 'RankHelper.php';
require_once HELPER_PATH . 'ExpHelper.php';
require_once HELPER_PATH . 'AttributeHelper.php';
require_once HELPER_PATH . 'SectHelper.php';
require_once HELPER_PATH . 'MoneyHelper.php';
require_once HELPER_PATH . 'AgeHelper.php';
require_once DAEMON_PATH . 'ApprenticeHandler.php';

// 要求登录
require_login();

$charId = get_char_id();

$char   = CharacterModel::getFullInfo($charId);

// 更新角色年龄
AgeHelper::updateAge($charId);

if (!$char) {
    echo '角色数据加载失败。<a href="room.php">返回游戏</a>';
    exit;
}

// -----------------------------------------------------------------------
// 辅助：中文数字（已在 functions.php 定义，这里是局部封装）
// -----------------------------------------------------------------------

/**
 * 数字转中文（支持任意大小）
 */
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

/**
 * 将数字转换为中文表示
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */
function toChineseNumber(int $i): string
{
    static $c_digit = ["零", "十", "百", "千", "万", "亿", "兆"];
    static $c_num = ["零", "一", "二", "三", "四", "五", "六", "七", "八", "九", "十"];

    if ($i < 0) {
        return "负" . toChineseNumber(-$i);
    }
    if ($i < 11) {
        return $c_num[$i];
    }
    if ($i < 20) {
        return $c_num[10] . $c_num[$i - 10];
    }
    if ($i < 100) {
        if ($i % 10) {
            return $c_num[intval($i / 10)] . $c_digit[1] . $c_num[$i % 10];
        } else {
            return $c_num[intval($i / 10)] . $c_digit[1];
        }
    }
    if ($i < 1000) {
        if ($i % 100 == 0) {
            return $c_num[intval($i / 100)] . $c_digit[2];
        } else if ($i % 100 < 10) {
            return $c_num[intval($i / 100)] . $c_digit[2] . $c_num[0] . toChineseNumber($i % 100);
        } else if ($i % 100 < 20) {
            return $c_num[intval($i / 100)] . $c_digit[2] . $c_num[1] . toChineseNumber($i % 100);
        } else {
            return $c_num[intval($i / 100)] . $c_digit[2] . toChineseNumber($i % 100);
        }
    }
    if ($i < 10000) {
        if ($i % 1000 == 0) {
            return $c_num[intval($i / 1000)] . $c_digit[3];
        } else if ($i % 1000 < 100) {
            return $c_num[intval($i / 1000)] . $c_digit[3] . $c_digit[0] . toChineseNumber($i % 1000);
        } else {
            return $c_num[intval($i / 1000)] . $c_digit[3] . toChineseNumber($i % 1000);
        }
    }
    if ($i < 100000000) {
        if ($i % 10000 == 0) {
            return toChineseNumber(intval($i / 10000)) . $c_digit[4];
        } else if ($i % 10000 < 1000) {
            return toChineseNumber(intval($i / 10000)) . $c_digit[4] . $c_digit[0] . toChineseNumber($i % 10000);
        } else {
            return toChineseNumber(intval($i / 10000)) . $c_digit[4] . toChineseNumber($i % 10000);
        }
    }
    if ($i < 1000000000000) {
        if ($i % 100000000 == 0) {
            return toChineseNumber(intval($i / 100000000)) . $c_digit[5];
        } else if ($i % 100000000 < 10000000) {
            return toChineseNumber(intval($i / 100000000)) . $c_digit[5] . $c_digit[0] . toChineseNumber($i % 100000000);
        } else {
            return toChineseNumber(intval($i / 100000000)) . $c_digit[5] . toChineseNumber($i % 100000000);
        }
    }
    if ($i % 1000000000000 == 0) {
        return toChineseNumber(intval($i / 1000000000000)) . $c_digit[6];
    } else if ($i % 1000000000000 < 100000000000) {
        return toChineseNumber(intval($i / 1000000000000)) . $c_digit[6] . $c_digit[0] . toChineseNumber($i % 1000000000000);
    } else {
        return toChineseNumber(intval($i / 1000000000000)) . $c_digit[6] . toChineseNumber($i % 1000000000000);
    }
}

/**
 * 将秒数转换为中文时长描述
 * 例：二月三天二十小时十一分钟
 */
function secondsToChineseDuration(int $seconds): string
{
    if ($seconds <= 0) return '片刻';

    $minutes = intdiv($seconds, 60);
    $hours   = intdiv($minutes, 60);
    $days    = intdiv($hours, 24);
    $months  = intdiv($days, 30);

    $remainDays    = $days  % 30;
    $remainHours   = $hours % 24;
    $remainMinutes = $minutes % 60;

    $parts = [];
    if ($months  > 0) $parts[] = toChineseNumber($months)  . '月';
    if ($remainDays    > 0) $parts[] = toChineseNumber($remainDays)    . '天';
    if ($remainHours   > 0) $parts[] = toChineseNumber($remainHours)   . '小时';
    if ($remainMinutes > 0) $parts[] = toChineseNumber($remainMinutes) . '分钟';

    return implode('', $parts) ?: '片刻';
}

/**
 * 气血百分比文字描述
 */
function keeStatusText(int $kee, int $maxKee): string {
    if ($maxKee <= 0) return '未知';
    $pct = ($kee / $maxKee) * 100;
    if ($pct >= 100) return '满血';
    if ($pct >= 75)  return '良好';
    if ($pct >= 50)  return '轻伤';
    if ($pct >= 25)  return '重伤';
    if ($pct > 0)    return '濒死';
    return '已死亡';
}

/**
 * 精神百分比文字描述
 */
function senStatusText(int $sen, int $maxSen): string {
    if ($maxSen <= 0) return '未知';
    $pct = ($sen / $maxSen) * 100;
    if ($pct >= 100) return '精神饱满';
    if ($pct >= 75)  return '尚可';
    if ($pct >= 50)  return '有些疲惫';
    if ($pct >= 25)  return '精神不振';
    return '精疲力竭';
}

/**
 * 食物状态文字
 */
function foodStatusText(int $food, int $maxFood): string {
    if ($maxFood <= 0) return '正常';
    $pct = ($food / $maxFood) * 100;
    if ($pct >= 90) return '暴食';
    if ($pct >= 50) return '饱腹';
    if ($pct >= 20) return '有些饿';
    if ($pct > 0)   return '饥肠辘辘';
    return '饿极了';
}

/**
 * 饮水状态文字
 */
function waterStatusText(int $water, int $maxWater): string
{
    if ($maxWater <= 0) return '正常';
    $pct = ($water / $maxWater) * 100;
    if ($pct >= 90) return '喝撑了';
    if ($pct >= 50) return '不渴';
    if ($pct >= 20) return '有些渴';
    if ($pct > 0)   return '口渴难耐';
    return '极度口渴';
}

// 西游世界中文日期转换（参考原始项目）
function chineseDate(int $time): string
{
    static $cNum = ["零", "一", "二", "三", "四", "五", "六", "七", "八", "九", "十"];
    static $symDee = ["子", "丑", "寅", "卯", "辰", "巳", "午", "未", "申", "酉", "戌", "亥"];
    static $time0 = 850348800; // 1996/12/12 GMT
    
    if ($time <= $time0) {
        $time = 0;
    } else {
        $time -= $time0;
    }

    $year = intdiv($time, 86400);
    $time %= 86400;
    $month = intdiv($time, 7200);
    $time %= 7200;
    $day = intdiv($time, 1440);
    $time %= 1440;
    $hour2 = intdiv($time, 120); // 一时辰(两小时)
    $time %= 120;
    $quarter = intdiv($time, 30); // 一时辰的四分之一
    
    // 中文数字转换函数
    $chineseNumber = function(int $i) use ($cNum): string {
        if ($i < 0) return "负" . $chineseNumber(-$i);
        if ($i < 11) return $cNum[$i];
        if ($i < 20) return $cNum[10] . $cNum[$i - 10];
        if ($i < 100) {
            if ($i % 10 == 0) {
                return $cNum[intdiv($i, 10)] . $cNum[1];
            } else {
                return $cNum[intdiv($i, 10)] . $cNum[1] . $cNum[$i % 10];
            }
        }
        return (string)$i;
    };

    return sprintf("西游%s年%s月%s日%s时%s刻",
        $chineseNumber($year + 1),
        $chineseNumber($month + 1),
        $chineseNumber($day + 1),
        $symDee[$hour2],
        $chineseNumber($quarter + 1)
    );
}

// -----------------------------------------------------------------------
// 获取角色各类数据
// -----------------------------------------------------------------------

// --- 基本信息 ---
$gender     = $char['gender'] ?? 'male';
$genderText = ($gender === 'female') ? '女' : '男';
$race       = $char['race'] ?? 'human';
$family     = $char['family'] ?? '';  // 门派 key

// 仙衔（playerRank）
$addedTitle = $char['added_title'] ?? null;
$isGhost    = (bool)($char['is_ghost'] ?? false);
$wizLevel   = $char['wiz_level'] ?? null;
$playerRank = RankHelper::queryRank($char, $addedTitle, $isGhost, $wizLevel);

// 头衔选择逻辑
$currentDisplayTitle = $char['display_title'] ?? 'basic';
$displayedTitle = $playerRank; // 默认显示基础仙衔
// 若选择了门派头衔
if ($currentDisplayTitle === 'sect') {
    $_sectInfo = SectHelper::getCharacterSect($charId);
    if ($_sectInfo && !empty($_sectInfo['sect_name'])) {
        $_gen = $_sectInfo['generation'] ?? 0;
        $_genText = $_gen > 0 ? '第' . toChineseNumber($_gen) . '代' : '';
        $displayedTitle = $_sectInfo['sect_name'] . $_genText . ($_sectInfo['sect_rank'] ?? '弟子');
    }
} elseif ($currentDisplayTitle === 'rank' && !empty($char['rank'])) {
    $displayedTitle = $char['rank'];
}

// 官职
$rank = $char['rank'] ?? '';

// 阵营
$sectConfig  = SectHelper::getSectConfig($family);
$alignmentNum = $sectConfig['alignment'] ?? 0;
switch ((int)$alignmentNum) {
    case 1:  $alignmentText = '仙'; break;
    case -1: $alignmentText = '妖'; break;
    case 0:  $alignmentText = '人'; break;
    default: $alignmentText = '未知'; break;
}

// 玩家住所（暂无专属字段，使用当前区域+房间）
$currentArea = $char['current_area'] ?? '';
$currentRoom = $char['current_room'] ?? '';

// 帮会（用门派名称代替）
$sectInfo   = SectHelper::getCharacterSect($charId);
$sectName   = $sectInfo ? $sectInfo['sect_name'] : '无门无派（江湖散人）';
$sectRank   = $sectInfo ? ($sectInfo['sect_rank'] ?? '弟子') : '—';
$generation = $sectInfo ? (int)($sectInfo['generation'] ?? 0) : 0;

// --- 个人信息 ---
$age      = $char['age'] ?? 0;
$ageText  = $age > 0 ? '[' . toChineseNumber($age) . ']' : '年岁不详';

// 生日（注册时间）
$createTime   = $char['create_time'] ?? null;
$birthdayTimestamp = $createTime ? strtotime($createTime) : time();
// 参考原始项目：减去14天
$birthdayText = chineseDate($birthdayTimestamp - 14 * 60 * 24 * 60);

// 师门信息
$masterResult = ApprenticeHandler::getMasterInfo($charId);
$masterName   = '';
if ($masterResult['success'] && !empty($masterResult['data'])) {
    $masterName = $masterResult['data']['name'] ?? '';
}
if (empty($masterName)) {
    $masterName = $char['master_name'] ?? '';
}
$masterText = !empty($masterName) ? $masterName : '无师自通';

// 门派头衔信息
$sectInfo = SectHelper::getCharacterSect($charId);
$titleText = '无';
if ($sectInfo && !empty($sectInfo['sect_name'])) {
    $generation = $sectInfo['generation'] ?? 0;
    $sectRank = $sectInfo['sect_rank'] ?? '弟子';
    $genText = $generation > 0 ? '第' . toChineseNumber($generation) . '代' : '';
    $titleText = $sectInfo['sect_name'] . $genText . $sectRank;
}


// --- 生命状态 ---
$kee    = (int)($char['kee']    ?? 0);
$maxKee = (int)($char['max_kee'] ?? 1);
$gin    = (int)($char['gin']    ?? 0);
$maxGin = (int)($char['max_gin'] ?? 1);
$sen    = (int)($char['sen']    ?? 0);
$maxSen = (int)($char['max_sen'] ?? 1);

$keePercent = $maxKee > 0 ? round(($kee / $maxKee) * 100) : 0;
$ginPercent = $maxGin > 0 ? round(($gin / $maxGin) * 100) : 0;
$senPercent = $maxSen > 0 ? round(($sen / $maxSen) * 100) : 0;

// 获取状态文本
$keeStatus = keeStatusText($kee, $maxKee);
$senStatus = senStatusText($sen, $maxSen);
$isSick = isset($char['is_sick']) && $char['is_sick'];

// 食物/饮水
$food    = (int)($char['food']     ?? 0);
$maxFood = (int)($char['max_food'] ?? 200);
$water   = (int)($char['water']     ?? 0);
$maxWater= (int)($char['max_water'] ?? 200);

$foodStatus = foodStatusText($food, $maxFood);
$waterStatus = waterStatusText($water, $maxWater);

// --- 六维属性 ---
$allAttrs = AttributeHelper::getAllAttributes($char);
$strVal = $allAttrs['str'];
$conVal = $allAttrs['con'];
$dexVal = $char['dex'] ?? 10;
$cpsVal = $allAttrs['cps'];
$intVal = $allAttrs['int'];
$spiVal = $allAttrs['spi'];
$perVal = $allAttrs['per'];
$karVal = $allAttrs['kar'];
$corVal = $allAttrs['cor'];

// --- 记录 ---
$lastDeathTime = $char['last_death_time'] ?? null;
$lastDeathText = $lastDeathTime ? date('Y-m-d H:i', strtotime($lastDeathTime)) : '从未死亡';
$pkCount = (int)($char['pks'] ?? $char['PKS'] ?? 0);

// --- 财富 ---
$balance = (int)($char['balance'] ?? 0);
// 身上带的钱（从背包获取）
$moneyInventory = MoneyHelper::getMoneyInventory($charId);
$gold = $moneyInventory['gold'];
$silver = $moneyInventory['silver'];
$copper = $moneyInventory['coin'];

// --- 战斗属性（基于属性计算简化版） ---
// 武器伤害力：基于 str
$weaponDamage = 1 + intdiv($strVal * 3, 10);
// 物理防御力：基于 con
$physicalDefense = 1 + intdiv($conVal * 2, 10);
// 物理暴击概率：基于 cor (胆识)
$critChance = $corVal * 2;  // 百分比
// 物理伤害加成：基于 str 和 combat_exp
$combatExp    = (int)($char['combat_exp'] ?? 0);
$damageBonus  = intdiv($strVal, 2) + intdiv($combatExp, 10000);
// 物理伤害减免：基于 con
$damageReduction = $conVal * 3;  // 固定减免值
// 阵营额外减免：正/邪阵营 +5%，中立 0%
$alignmentBonus = ($alignmentNum != 0) ? 5 : 0;
// 门派增益（从门派配置中获取）
$sectBonus = '';
if (!empty($family) && !empty($sectConfig)) {
    $bonuses = $sectConfig['skills']['bonus'] ?? [];
    $bonusParts = [];
    foreach ($bonuses as $type => $val) {
        $bonusParts[] = "{$type}+{$val}";
    }
    $sectBonus = !empty($bonusParts) ? implode('，', $bonusParts) : '无';
} else {
    $sectBonus = '无（未入门派）';
}
// 职位增益
$rankBonus = !empty($rank) ? "{$rank}（参见门派权限）" : '无';

// --- 法术属性 ---
$maxMana    = (int)($char['max_mana'] ?? 0);
$mana       = (int)($char['mana']     ?? 0);
$maxForce   = (int)($char['max_force'] ?? 0);
$force      = (int)($char['force']    ?? 0);
$maxAtman   = (int)($char['max_atman'] ?? 0);
$atman      = (int)($char['atman']    ?? 0);

// 法术攻击力：基于 spi 和 int
$spellAttack  = intdiv(($spiVal + $intVal) * 2, 1);
// 法术防御力：基于 int
$spellDefense = $intVal * 3;
// 法术伤害减免：基于 spi
$spellReduction = intdiv($spiVal * 2, 1);

// --- 装备信息 ---
$equipment = $char['equipment'] ?? [];

// --- 修行境界 ---
$daoxing    = (int)($char['daoxing']    ?? 0);
$dxDesc     = describe_dx($daoxing);
$expDesc    = describe_exp($combatExp);
$faliDesc   = describe_fali($maxMana);
$neiliDesc  = describe_neili($maxForce);

// --- 取经/天宫进度 ---
$tiangongNum  = (int)($char['tiangong_number'] ?? $char['tiangong/number'] ?? 0);

// 查询取经任务状态
$qujingQuest = Database::queryOne(
    "SELECT q.quest_id, q.status, q.start_time 
     FROM character_quests q 
     WHERE q.char_id = ? AND q.quest_type = 'qujing_escort' AND q.status = 'active' LIMIT 1",
    [$charId]
);

if ($qujingQuest) {
    require_once DAEMON_PATH . 'QujingHandler.php';
    $questDef = QujingHandler::getQuestDefinition($qujingQuest['quest_id']);
    $questName = $questDef['name'] ?? $qujingQuest['quest_id'];
    $qujingText = "正在护送取经人，当前关卡：{$questName}";
} else {
    // 检查是否有已完成的取经记录
    $completedCount = Database::queryOne(
        "SELECT COUNT(*) as count FROM qujing_history WHERE char_id = ?",
        [$charId]
    );
    if ($completedCount && $completedCount['count'] > 0) {
        $qujingText = '已完成' . toChineseNumber($completedCount['count']) . '道劫难';
    } else {
        $qujingText = '尚未踏上取经之路';
    }
}

if ($tiangongNum > 0) {
    $tiangongText = '你大闹天宫已过' . toChineseNumber($tiangongNum) . '关';
} else {
    $tiangongText = '尚未大闹天宫';
}

// --- 修行时间 ---
$onlineSeconds = (int)($char['mud_age'] ?? 0);
$cultivationTime = secondsToChineseDuration($onlineSeconds);
?>
<!DOCTYPE html>
<html lang="zh-CN">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<head>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <meta charset="UTF-8">
    <title>个人数据</title>
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/data.css">
</head>
<body>
    <div class="title">个人数据</div>
    <p><span class="cyan">仙衔:</span> 【<?= $currentDisplayTitle === 'basic' ? $playerRank : h($displayedTitle) ?>】</p>
    <p><span class="cyan">官拜:</span> <span class="highlight-green"><?= h($rank ?: '无') ?></span></p>
    <p><span class="cyan">阵营:</span> <span class="green"><?= h($alignmentText) ?></span></p>
    <p><span class="cyan">住宅:</span> <?= h($currentArea . ' ' . $currentRoom) ?></p>
    <p><span class="cyan">帮会:</span> [无]</p>
    <p><span class="cyan">年龄:</span> <?= h($ageText) ?></p>
    <p><span class="cyan">生日:</span> <span class="green">[<?= h($birthdayText) ?>]</span></p>
    <p><span class="cyan">性别:</span> <span class="green">[<?= h($genderText) ?>]</span></p>
    <p><span class="cyan">门派:</span> <span class="green">[<?= h($sectInfo ? $sectInfo['sect_name'] : '无门派') ?>]</span> <?= h($titleText) ?></p>
    <p><span class="cyan">师承:</span> <span class="green">[<?= h($sectInfo ? $sectInfo['sect_name'] : '无门派') ?>]</span> <span class="purple"><?= h($masterText) ?></span></p>

    <p><span class="red">【气血】</span> <span class="white"><?= $kee ?>/<?= $maxKee ?></span> <span class="red">[<?= h($keeStatus) ?>]</span></p>
    <p><span class="red">【精神】</span> <span class="white"><?= $sen ?>/<?= $maxSen ?></span> <span class="red">[<?= h($senStatus) ?><?= $isSick ? ' 生病' : '' ?>]</span></p>
    <p><span class="cyan">【食物】</span> <span class="white"><?= $food ?>/<?= $maxFood ?></span> <span class="red">[<?= h($foodStatus) ?>]</span></p>
    <p><span class="cyan">【饮水】</span> <span class="white"><?= $water ?>/<?= $maxWater ?></span> <span class="green">[<?= h($waterStatus) ?>]</span></p>

    <p><span class="cyan">体格:</span> <span class="green">[ <?= $strVal ?> ]</span> <span class="cyan">根骨:</span> <span class="green">[ <?= $conVal ?> ]</span></p>
    <p><span class="cyan">定力:</span> <span class="green">[ <?= $cpsVal ?> ]</span> <span class="cyan">胆识:</span> <span class="green">[ <?= $corVal ?> ]</span></p>
    <p><span class="cyan">悟性:</span> <span class="green">[ <?= $intVal ?> ]</span> <span class="cyan">灵性:</span> <span class="green">[ <?= $spiVal ?> ]</span></p>
    <p><span class="cyan">容貌:</span> <span class="green">[ <?= $perVal ?> ]</span> <span class="cyan">福缘:</span> <span class="green">[ <?= $karVal ?> ]</span></p>

    <p><span class="green">【上次死亡】</span> <?= h($lastDeathText) ?></p>

    <p><span class="green">【杀害玩家】</span> <span class="red"><?= $pkCount ?></span> 位</p>

    <p><span class="yellow">【存 款】</span> <?php
        // 存款显示的是 balance（钱庄存款），以铜钱为单位，转换为金、银、铜显示
        $balanceGold = intval($balance / 10000);
        $balanceRemaining = $balance % 10000;
        $balanceSilver = intval($balanceRemaining / 100);
        $balanceCopper = $balanceRemaining % 100;
        
        $output = '';
        if ($balanceGold > 0) {
            $output .= toChineseNumber($balanceGold) . '金';
        }
        if ($balanceSilver > 0) {
            $output .= toChineseNumber($balanceSilver) . '两白银';
        }
        if ($balanceCopper > 0 || $output === '') {
            $output .= toChineseNumber($balanceCopper) . '文铜板';
        }
        echo $output;
    ?></p>
    <p><span class="yellow">【银票】</span> 0</p>

    <p><span class="cyan">【武器伤害力】</span> <span class="red"><?= $weaponDamage ?>(+0)+0(+0)</span></p>
    <p><span class="cyan">【物理防御力】</span> <?= $physicalDefense ?>(+0)</p>

    <p><span class="red">【物理暴击概率】 <?= $critChance ?>%</span></p>
    <p><span class="purple">【物理伤害加成】 妖怪:0% 玩家:0%</span></p>
    <p><span class="purple">【物理伤害减免】 妖怪:0% 玩家:0%</span></p>
    <p><span class="cyan">【阵营额外减免】 <?= $alignmentBonus ?>%</span></p>

    <p><span class="yellow">【门派增益】 +0(0%)物理防御</span></p>
    <p><span class="green">【阵营增益】 +0(0%)物理防御</span></p>
    <p><span class="green">【职位增益】 +0(0%)武器伤害</span></p>

    <p><span class="cyan">【法术攻击力】 <?= $spellAttack ?></span></p>
    <p><span class="cyan">【法术防御力】 <?= $spellDefense ?></span></p>
    <p><span class="cyan">【法术伤害减免】 <?= $spellReduction ?>%</span></p>

    <?php if (!empty($family)): ?>
    <p class="purple">- <?= h($sectInfo ? $sectInfo['sect_name'] : $family) ?> -</p>
    <?php endif; ?>

    <p><span class="cyan">【会心一击概率】</span>:0%</p>
    <p><span class="cyan">【伤害额外减免】</span>:<?= $spellReduction ?>%</p>

    <p><span class="orange">【装备及BUFFER 增益】</span></p>
    <p><span class="green">轻功 : 0</span></p>
    <p><span class="green">法术 : 0</span></p>
    <p><span class="green">防御 : <?= $physicalDefense ?></span></p>

    <p><span class="purple"><a href="qujing_progress.php" style="color: purple;font-weight: bold;text-decoration: none;">西天取经:</a></span> <?= h($qujingText) ?>。</p>
    <p><span class="red">大闹天宫:</span> <?= h($tiangongText) ?>。</p>
    <p><span class="green">道行境界:</span> <span class="purple">[<?= h($dxDesc) ?>]</span> <span class="green">武学境界:</span> <span class="purple">[<?= h($expDesc) ?>]</span></p>
    <p><span class="green">法力修为:</span> <span class="purple">[<?= h($faliDesc) ?>]</span> <span class="green">内力修为:</span> <span class="purple">[<?= h($neiliDesc) ?>]</span></p>

    <p><span class="gray">为求取真经你已经经历了</span> <span class="red"><?= h($cultivationTime) ?>的岁月</span></p>

    <div class="links">
        <hr>
        <a href="#" onclick="javascript:history.back(-1);">返回</a>
        &nbsp;&nbsp;|&nbsp;&nbsp;
        <a href="score.php">查看状态</a>
        &nbsp;&nbsp;|&nbsp;&nbsp;
        <a href="room.php">返回游戏</a>
    </div>
</body>
</html>
