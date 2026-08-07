<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Character.php';
require_once HELPER_PATH . 'RankHelper.php';
require_once HELPER_PATH . 'SectHelper.php';
require_once HELPER_PATH . 'MoneyHelper.php';

require_login();

$charId = get_char_id();
$char = CharacterModel::find($charId);

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_title') {
    $selectedTitle = $_POST['display_title'] ?? '';
    $sql = "UPDATE `characters` SET `display_title` = ? WHERE `id` = ?";
    Database::execute($sql, [$selectedTitle, $charId]);
    header("Location: score.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_custom_title') {
    $title = trim($_POST['custom_title'] ?? '');
    $daoxing = intval($char['daoxing'] ?? 0);
    
    $titleLength = 0;
    if ($daoxing >= 500000) {
        $titleLength = 8;
    } elseif ($daoxing >= 100000) {
        $titleLength = 4;
    }
    
    if ($title === 'none') {
        Database::execute("UPDATE `characters` SET `added_title` = NULL WHERE `id` = ?", [$charId]);
        header("Location: score.php");
        exit;
    } elseif ($titleLength <= 0) {
        $message = '你目前道行尚浅，还不能设置自定义称号。';
    } elseif (!preg_match('/^[\x{4e00}-\x{9fa5}]+$/u', $title)) {
        $message = '称号只能包含中文汉字。';
    } else {
        Database::execute("UPDATE `characters` SET `added_title` = ? WHERE `id` = ?", [$title, $charId]);
        header("Location: score.php");
        exit;
    }
}

$daoxing = intval($char['daoxing'] ?? 0);
$selfBuyAllowed = $daoxing >= 100000;
$respectBuyAllowed = $daoxing >= 500000;

// 处理购买称呼
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'buy_rank') {
    $rankType = $_POST['rank_type'] ?? '';
    $rankValue = trim($_POST['rank_value'] ?? '');
    $validTypes = ['self', 'self_rude', 'respect'];

    if (!in_array($rankType, $validTypes)) {
        $message = '无效的称呼类型。';
    } elseif ($rankValue === '') {
        $message = '请输入称呼内容。';
    } elseif (!preg_match('/^[\x{4e00}-\x{9fa5}]+$/u', $rankValue)) {
        $message = '称呼只能包含中文汉字。';
    } else {
        $canBuy = ($rankType === 'respect') ? $respectBuyAllowed : $selfBuyAllowed;
        if (!$canBuy) {
            $message = '道行不足，无法购买此称呼。';
        } elseif (!MoneyHelper::hasEnoughMoney($charId, 500000)) {
            $message = '银两不足，需要50万铜钱。';
        } else {
            $rankInfo = [];
            if (!empty($char['rank_info']) && is_string($char['rank_info'])) {
                $decoded = json_decode($char['rank_info'], true);
                if (is_array($decoded)) {
                    $rankInfo = $decoded;
                }
            }
            $rankInfo[$rankType] = $rankValue;
            Database::execute(
                "UPDATE `characters` SET `rank_info` = ? WHERE `id` = ?",
                [json_encode($rankInfo, JSON_UNESCAPED_UNICODE), $charId]
            );
            MoneyHelper::deductMoney($charId, 500000);
            log_game('BUY_RANK', "{$char['name']} 购买称呼 [{$rankType}] = {$rankValue}");
            header("Location: score.php");
            exit;
        }
    }
}

// 处理删除称呼
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_rank') {
    $rankType = $_POST['rank_type'] ?? '';
    $validTypes = ['self', 'self_rude', 'respect'];

    if (!in_array($rankType, $validTypes)) {
        $message = '无效的称呼类型。';
    } else {
        $rankInfo = [];
        if (!empty($char['rank_info']) && is_string($char['rank_info'])) {
            $decoded = json_decode($char['rank_info'], true);
            if (is_array($decoded)) {
                $rankInfo = $decoded;
            }
        }
        if (isset($rankInfo[$rankType])) {
            unset($rankInfo[$rankType]);
            Database::execute(
                "UPDATE `characters` SET `rank_info` = ? WHERE `id` = ?",
                [json_encode($rankInfo, JSON_UNESCAPED_UNICODE), $charId]
            );
            log_game('DEL_RANK', "{$char['name']} 删除称呼 [{$rankType}]");
        }
        header("Location: score.php");
        exit;
    }
}

$displayChar = $char;
$displayName = $char['name'];

$addedTitle = $char['added_title'] ?? null;
$isGhost = $char['is_ghost'] ?? false;
$wizLevel = $char['wiz_level'] ?? null;
$basicTitle = RankHelper::queryRank($char, $addedTitle, $isGhost, $wizLevel);
$basicTitle = preg_replace('/【\s*|\s*】/', '', $basicTitle);

$sectInfo = SectHelper::getCharacterSect($charId);

function toChineseNum($n) {
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

$basicTitlePlain = strip_tags($basicTitle);
$availableTitles = [['value' => 'basic', 'label' => $basicTitle, 'label_plain' => $basicTitlePlain, 'type' => '基础']];
if ($sectInfo && !empty($sectInfo['sect_name'])) {
    $generation = $sectInfo['generation'] ?? 0;
    $sectRank = $sectInfo['sect_rank'] ?? '弟子';
    $genText = $generation > 0 ? '第' . toChineseNum($generation) . '代' : '';
    $titleText = $sectInfo['sect_name'] . $genText . $sectRank;
    $availableTitles[] = ['value' => 'sect', 'label' => $titleText, 'label_plain' => $titleText, 'type' => '门派'];
}

$displayRank = $char['rank'] ?? '';
if (!empty($displayRank)) {
    $availableTitles[] = ['value' => 'rank', 'label' => $displayRank, 'label_plain' => $displayRank, 'type' => '官职'];
}

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
    $availableTitles[] = ['value' => 'official_rank', 'label' => $rankNames[$officialRank], 'label_plain' => $rankNames[$officialRank], 'type' => '科举'];
}

$currentDisplayTitle = $char['display_title'] ?? 'basic';

function getSelectedTitleLabel($value, $availableTitles) {
    foreach ($availableTitles as $title) {
        if ($title['value'] === $value) {
            return $title['label'];
        }
    }
    return $availableTitles[0]['label'] ?? '';
}

$displayedTitle = getSelectedTitleLabel($currentDisplayTitle, $availableTitles);

$keePercent = ($displayChar['max_kee'] > 0) ? intval(($displayChar['kee'] / $displayChar['max_kee']) * 100) : 0;
$ginPercent = ($displayChar['max_gin'] > 0) ? intval(($displayChar['gin'] / $displayChar['max_gin']) * 100) : 0;
$senPercent = ($displayChar['max_sen'] > 0) ? intval(($displayChar['sen'] / $displayChar['max_sen']) * 100) : 0;

$food = (int)($displayChar['food'] ?? 0);
$maxFood = (int)($displayChar['max_food'] ?? 200);

function foodStatusText($food, $maxFood) {
    if ($maxFood <= 0) return '正常';
    $pct = ($food / $maxFood) * 100;
    if ($pct >= 90) return '暴食';
    if ($pct >= 50) return '饱腹';
    if ($pct >= 20) return '有些饿';
    if ($pct > 0) return '饥肠辘辘';
    return '饿极了';
}
$foodStatus = foodStatusText($food, $maxFood);

$water = (int)($displayChar['water'] ?? 0);
$maxWater = (int)($displayChar['max_water'] ?? 200);

function waterStatusText($water, $maxWater) {
    if ($maxWater <= 0) return '正常';
    $pct = ($water / $maxWater) * 100;
    if ($pct >= 90) return '喝撑了';
    if ($pct >= 50) return '不渴';
    if ($pct >= 20) return '有些渴';
    if ($pct > 0) return '口渴难耐';
    return '极度口渴';
}
$waterStatus = waterStatusText($water, $maxWater);

$customTitleAllowed = $daoxing >= 100000;
$customTitleMaxLength = $daoxing >= 500000 ? 4 : 2;

$typeNames = ['self' => '自称', 'self_rude' => '粗鲁自称', 'respect' => '他人称呼'];

// 解析 rank_info
$rankInfo = [];
if (!empty($char['rank_info']) && is_string($char['rank_info'])) {
    $decoded = json_decode($char['rank_info'], true);
    if (is_array($decoded)) {
        $rankInfo = $decoded;
    }
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>状态</title>
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <style>
        .title { color: #ffa500; text-align: center; font-size: 22px; margin-bottom: 20px; }
        .red { color: #ff3333; }
        .green { color: #33ff33; }
        .cyan { color: #33ffff; }
        .blue { color: #3366ff; }
        .yellow { color: #ffcc00; }
        .brown { color: #aa6633; }
        .gray { color: #888888; }
        hr { margin: 15px 0; border: none; border-top: 1px solid #444; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #444; padding: 8px; text-align: left; }
        th { background: #333; }
    </style>
</head>
<body>
    <div class="title">状态</div>

    <?php if ($message): ?>
        <p style="color: #ffff00; font-weight: bold;"><?= h($message) ?></p>
    <?php endif; ?>

    <p>【<?= $displayedTitle ?>】<?= h($displayName) ?></p>
    
    <p><span class="blue">姓 名:</span> <?= h($displayName) ?> <span class="blue">性别:</span> <?= $displayChar['gender'] === 'female' ? '女性' : '男性' ?></p>
    <p><span class="red">【气血】</span> <?= $displayChar['kee'] ?> /<?= $displayChar['max_kee'] ?> <span class="green">[<?= $keePercent ?>%]</span></p>
    <p><span class="green">【精气】</span> <?= $displayChar['gin'] ?> /<?= $displayChar['max_gin'] ?> <span class="green">[<?= $ginPercent ?>%]</span></p>
    <p><span class="cyan">【精神】</span> <?= $displayChar['sen'] ?> /<?= $displayChar['max_sen'] ?> <span class="green">[<?= $senPercent ?>%]</span></p>
    <p><span class="red">【内力】</span> <?= $displayChar['force'] ?? 0 ?> /<?= $displayChar['max_force'] ?></p>
    <p><span class="cyan">【法力】</span> <?= $displayChar['mana'] ?? $displayChar['max_mana'] ?> /<?= $displayChar['max_mana'] ?></p>

    <p><span class="green">【食物】</span> <?= $food ?> /<?= $maxFood ?> <span class="green">[<?= $foodStatus ?>]</span></p>
    <p><span class="blue">【饮水】</span> <?= $water ?> /<?= $maxWater ?> <span class="green">[<?= $waterStatus ?>]</span></p>

    <p><span class="brown">【武学】</span> <?= number_format($displayChar['combat_exp'] ?? 0) ?> 收益(100%)</p>
    <p><span class="brown">【道行】</span> <?= number_format($displayChar['daoxing'] ?? 0) ?> 收益(100%)</p>
    <p><span class="yellow">【潜能】</span> <?= number_format($displayChar['potential'] ?? 0) ?></p>
    <p><span class="brown">【官职】</span> <?= $officialRank ?></p>
    <p><span class="orange">【官拜】</span> <?= $officialRank > 0 ? h($rankNames[$officialRank]) : '无' ?></p>
    <p><span class="brown">【俸禄】</span> <?= $officialRank > 0 ? ($officialRank * 500) . '两白银' : '0两白银' ?></p>
    
    <p><span class="brown">【杀气】</span> <span style="color: #ff0000; font-weight: bold;"><?= number_format($displayChar['bellicosity'] ?? 0) ?></span>
    <?php
    $bellicosity = intval($displayChar['bellicosity'] ?? 0);
    $corBonus = intval($bellicosity / 50);
    if ($bellicosity > 0) {
        echo '<span class="gray"> (胆识+' . $corBonus . ')</span>';
    }
    ?>
    </p>
    <p><span class="gray">
    <?php
    if ($bellicosity == 0) {
        echo '你目前没有杀气。';
    } elseif ($bellicosity <= 100) {
        echo '你的杀气较轻，可以学习佛法类技能。';
    } elseif ($bellicosity <= 500) {
        echo '你的杀气较重，无法学习佛法类技能。';
    } else {
        echo '你的杀气极重，身上散发着令人胆寒的血腥气息！';
    }
    ?>
    </span></p>

    <hr>
    <h3 style="color: #ffd700;">头衔设置</h3>
    
    <form method="POST" style="margin-bottom: 20px;">
        <input type="hidden" name="action" value="save_title">
        选择显示头衔：
            <select name="display_title">
                <?php foreach ($availableTitles as $title): ?>
                    <option value="<?= h($title['value']) ?>" <?= $title['value'] === $currentDisplayTitle ? 'selected' : '' ?>>
                        [<?= h($title['type']) ?>] <?= h($title['label_plain']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">确定</button>
    </form>

    <hr>
    <h3 style="color: #ffd700;">自定义称号</h3>
    
    <?php if (!$customTitleAllowed): ?>
        <p style="color: #aa0000;">道行不足，无法设置自定义称号。</p>
    <?php else: ?>
        <form method="POST" style="margin-bottom: 15px;">
            <input type="hidden" name="action" value="set_custom_title">
            <label>自定义称号（最多<?= $customTitleMaxLength ?>个汉字）：</label>
            <input type="text" name="custom_title" maxlength="<?= $customTitleMaxLength ?>" 
                value="<?= h($addedTitle ?? '') ?>" placeholder="输入称号">
            <button type="submit">设置</button>
            <?php if ($addedTitle): ?>
                <button type="submit" name="custom_title" value="none">删除</button>
            <?php endif; ?>
        </form>
    <?php endif; ?>

    <hr>
    <h3 style="color: #ffd700;">购买称呼</h3>
    
    <table style="margin-bottom: 15px;">
        <tr><th>类型</th><th>当前设置</th><th>操作</th></tr>
        <?php foreach ($typeNames as $type => $name): ?>
            <tr>
                <td><?= h($name) ?></td>
                <td><?= !empty($rankInfo[$type] ?? null) ? h($rankInfo[$type]) : '<span style="color: #666;">未设置</span>' ?></td>
                <td>
                    <?php 
                    $canBuy = ($type === 'respect') ? $respectBuyAllowed : $selfBuyAllowed;
                    $hasMoney = MoneyHelper::hasEnoughMoney($charId, 500000);
                    ?>
                    <?php if ($canBuy && $hasMoney): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="buy_rank">
                            <input type="hidden" name="rank_type" value="<?= h($type) ?>">
                            <input type="text" name="rank_value" maxlength="4" placeholder="称呼">
                            <button type="submit">购买(50万)</button>
                        </form>
                    <?php else: ?>
                        <span style="color: #666; font-size: 12px;"><?= !$canBuy ? '道行不足' : '银两不足' ?></span>
                    <?php endif; ?>
                    <?php if (!empty($rankInfo[$type] ?? null)): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="delete_rank">
                            <input type="hidden" name="rank_type" value="<?= h($type) ?>">
                            <button type="submit">删除</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>

    <hr>
    <a href="#" onclick="javascript:history.back(-1);">返回</a>
    <hr>
    <a href="room.php">返回游戏</a>
</body>
</html>
