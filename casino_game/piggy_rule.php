<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 拱猪房规则页
 * 移植自 LPC: help/specials/pigrules
 */

require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Character.php';
require_once HELPER_PATH . 'MoneyHelper.php';

require_login();
$charId = get_char_id();
$char = CharacterModel::getFullInfo($charId);
if (!$char) {
    redirect('character_select.php');
}

$money = MoneyHelper::getMoneyInventory($charId);
$coinBalance = intval($money['coin']);

// 获取房间ID
$roomId = $_REQUEST['room_id'] ?? 'city/piggy_n';
if (!in_array($roomId, ['city/piggy_n', 'city/piggy_s', 'city/piggy_e', 'city/piggy_w'])) {
    $roomId = 'city/piggy_n';
}
$isPartner = in_array($roomId, ['city/piggy_e', 'city/piggy_w']);
?>
<!DOCTYPE html>
<html lang="zh-cn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title>拱猪规则_<?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
</head>
<body>
<p>
    <a href="piggy.php?room_id=<?= h($roomId) ?>">返回拱猪房</a>&ensp;
    <a href="../functions/room.php?area=<?= h($char['current_area'] ?? 'city') ?>&room=<?= h($char['current_room'] ?? 'piggy_n') ?>">返回游戏</a>
</p>
<p>铜钱: <?= $coinBalance ?>文</p>

<h3>拱猪规则 <?= $isPartner ? '<span style="color:#9932CC;">（搭档模式）</span>' : '' ?></h3>

<p>一副牌五十四张，除大小王外分四个花色：黑桃、红桃、方片、草花。
每个花色按上升顺序有 2 到 10，然后是 J、Q、K、A。拱猪不用大小王，
只用剩下五十二张牌，因此每人手十三张。</p>

<h4>一、发牌</h4>
<ul>
    <li>一副 52 张牌（无大小王），四人各 13 张。</li>
    <li>根据概率，先发给谁、后发给谁没关系，但出牌顺序很重要，
        每把都由上把吃猪的玩家先出。</li>
</ul>

<h4>二、出牌、收牌</h4>
<ol>
    <li><strong>跟花色</strong>：如果这一轮第一个玩家出草花而你手里也有草花，
        那你必须出草花。如果没有这个花色的牌了，可在剩下的牌中随意捡一张出，
        这叫「垫牌」。</li>
    <li><strong>明卖限制</strong>：明卖的牌在本花色第一轮不能出
        （若该花色只有这一张明卖牌，则第一轮仍可出）。</li>
    <li><strong>收牌</strong>：最大的牌是同花色里最大那一张，垫的不算。
        一轮下来你的牌最大，则桌上四张牌都归你收，且下一轮由你先出。</li>
    <li>一把牌打完后，每人看所收的牌算分。</li>
</ol>

<h4>三、算分</h4>
<p>对拱猪来说，不是所有的牌都有分。算分时重要的只有黑桃 Q（猪）、
所有红桃、方片 J（羊）、草花 10（变压器）。红桃 A 有时被称为「血」。
正分为好分。</p>

<table border="1" style="width:100%; font-size:14px; border-collapse:collapse;">
    <tr>
        <th style="padding:4px;">牌名</th>
        <th style="padding:4px;">说明</th>
        <th style="padding:4px;">原始分值</th>
    </tr>
    <tr>
        <td style="padding:4px; color:#000; font-weight:bold;">猪（黑桃 Q）</td>
        <td style="padding:4px;">黑桃 Q，一般负分；收全红时变正</td>
        <td style="padding:4px; color:#FF0000; font-weight:bold;">-100</td>
    </tr>
    <tr>
        <td style="padding:4px; color:#0000FF; font-weight:bold;">羊（方片 J）</td>
        <td style="padding:4px;">方片 J，恒为正分</td>
        <td style="padding:4px; color:#00AA00; font-weight:bold;">+100</td>
    </tr>
    <tr>
        <td style="padding:4px; color:#008800; font-weight:bold;">变压器（草花 10）</td>
        <td style="padding:4px;">独收时 +50；有其他分时按倍率加倍</td>
        <td style="padding:4px; color:#00AA00; font-weight:bold;">+50</td>
    </tr>
    <tr>
        <td style="padding:4px; color:#CC0000; font-weight:bold;">血（红桃 A）</td>
        <td style="padding:4px;">红桃 A，卖血影响所有红桃分</td>
        <td style="padding:4px; color:#FF0000; font-weight:bold;">-50</td>
    </tr>
</table>

<h4>红桃分值表</h4>
<table border="1" style="width:100%; font-size:14px; border-collapse:collapse; text-align:center;">
    <tr>
        <th style="padding:4px;">红桃</th>
        <th style="padding:4px;">A</th>
        <th style="padding:4px;">K</th>
        <th style="padding:4px;">Q</th>
        <th style="padding:4px;">J</th>
        <th style="padding:4px;">10</th>
        <th style="padding:4px;">9</th>
        <th style="padding:4px;">8</th>
        <th style="padding:4px;">7</th>
        <th style="padding:4px;">6</th>
        <th style="padding:4px;">5</th>
        <th style="padding:4px;">4</th>
        <th style="padding:4px;">3</th>
        <th style="padding:4px;">2</th>
    </tr>
    <tr>
        <td style="padding:4px; font-weight:bold;">分</td>
        <td style="padding:4px; color:#FF0000; font-weight:bold;">-50</td>
        <td style="padding:4px; color:#FF0000;">-40</td>
        <td style="padding:4px; color:#FF0000;">-30</td>
        <td style="padding:4px; color:#FF0000;">-20</td>
        <td style="padding:4px; color:#FF6666;">-10</td>
        <td style="padding:4px; color:#FF6666;">-10</td>
        <td style="padding:4px; color:#FF6666;">-10</td>
        <td style="padding:4px; color:#FF6666;">-10</td>
        <td style="padding:4px; color:#FF6666;">-10</td>
        <td style="padding:4px; color:#FF6666;">-10</td>
        <td style="padding:4px; color:#999;">0</td>
        <td style="padding:4px; color:#999;">0</td>
        <td style="padding:4px; color:#999;">0</td>
    </tr>
</table>

<h4>收全红与全收</h4>
<ul>
    <li><strong>收全红</strong>：一个玩家收齐 13 张红桃（含 2、3、4），
        红桃分由 -200 变为 <span style="color:#00AA00; font-weight:bold;">+200</span>，
        且所收的猪也变正分。</li>
    <li><strong>全收</strong>：一个玩家收齐红桃、猪、羊、变压器四种有分牌，
        其余三人各得一个猪头。</li>
</ul>

<h4>四、卖牌（亮牌）</h4>
<p>刚发完牌时，有四张特殊的牌可以亮：猪、羊、变压器、血。亮牌分明卖和暗卖两种。</p>
<table border="1" style="width:100%; font-size:14px; border-collapse:collapse;">
    <tr>
        <th style="padding:4px;">卖法</th>
        <th style="padding:4px;">倍率</th>
        <th style="padding:4px;">说明</th>
    </tr>
    <tr>
        <td style="padding:4px; font-weight:bold;">暗卖</td>
        <td style="padding:4px;">分值 ×2</td>
        <td style="padding:4px;">可随时出，只要跟花色</td>
    </tr>
    <tr>
        <td style="padding:4px; font-weight:bold;">明卖</td>
        <td style="padding:4px;">分值 ×4</td>
        <td style="padding:4px;">不能在本花色第一轮出</td>
    </tr>
</table>
<ul>
    <li>暗亮的猪值 -200，暗亮的变压器值 +100（或所得牌分乘四）。</li>
    <li>亮血影响<strong>所有红桃</strong>：明卖血后红桃 9 值 -10×4 = -40，以此类推。</li>
    <li>一把牌若全部明卖，最高得分（全收）将值 12800 分。</li>
</ul>

<h4>五、全收（摊牌）</h4>
<ul>
    <li>出牌进行到第 8 轮后，玩家可要求全收（claim），认为手中剩余的牌都最大。</li>
    <li>要求全收需所有其他玩家同意（claim yes/no）。全部同意则摊牌结算。</li>
    <li>有人反对则继续打下去。</li>
</ul>

<h4>六、猪头（出去了）</h4>
<ul>
    <li>四个玩家不停打下去，分数累加。第一个总分低于 <strong>-2000</strong> 分的玩家得猪头。</li>
    <li>全收时，未收齐的其余三人各得一个猪头。</li>
    <li>每得一个猪头，扣除 <strong>3 点等级分</strong>。</li>
</ul>

<h4>七、入场费</h4>
<ul>
    <li>入座需缴纳 <strong>50 文铜钱</strong> 作为入场费。</li>
    <li>开局前离座可退还入场费；开牌后不得退出。</li>
    <li>铜钱结算：按本局得分比例分配奖池；得猪头者另罚铜钱。</li>
</ul>

<h4>八、术语</h4>
<ul>
    <li><strong>拱猪</strong>：一轮第一张出黑桃，而猪（黑桃 Q）还没出来。</li>
    <li><strong>放血</strong>：一轮第一张出红桃。</li>
    <li><strong>吃猪</strong>：在一轮牌里收到猪。</li>
    <li><strong>猪圈</strong>：黑桃 A、K，危险牌。</li>
    <li><strong>羊圈</strong>：方片 A、K、Q。</li>
</ul>

<?php if ($isPartner): ?>
<h4 style="color:#9932CC;">九、搭档模式（双人拱猪房特有）</h4>
<ul>
    <li><strong>搭档关系</strong>：东西一队，南北一队。四人仍各自独立出牌，但计分时搭档分数合并。</li>
    <li><strong>分数均摊</strong>：每局结束时，搭档两人的原始分数相加后各取一半。
        <br>例：东家得 -100 分，西家得 +50 分，则两人最终各得 (-100+50)/2 = <strong>-25 分</strong>。</li>
    <li><strong>全收规则</strong>：全收者及其搭档各得全收分数的一半；仅对方队伍两人得猪头。</li>
    <li><strong>猪头判定</strong>：按搭档合并后的累计总分判定，总分低于 -2000 则得猪头。</li>
    <li><strong>出牌不变</strong>：跟花色、卖牌、明卖限制等规则与普通拱猪完全相同，搭档间不能交流手牌。</li>
</ul>
<?php endif; ?>

<p style="color:#888; font-size:12px;">规则移植自 LPC help/specials/pigrules，酸黄瓜 九八·一·三十</p>

<br>
<a href="piggy.php?room_id=<?= h($roomId) ?>">返回拱猪房</a>
</body>
</html>
