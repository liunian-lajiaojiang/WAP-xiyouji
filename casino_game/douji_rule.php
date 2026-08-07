<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 斗鸡房规则页
 * 移植自 LPC: d/city/duchang3.c 中的房间描述和 item_desc
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
?>
<!DOCTYPE html>
<html lang="zh-cn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title>斗鸡房规则_<?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
</head>
<body>
<p>
    <a href="douji.php">返回斗鸡房</a>&ensp;
    <a href="../functions/room.php">返回游戏</a>
</p>
<p>铜钱: <?= $coinBalance ?>文</p>

<h3>斗鸡房规则</h3>

<p>斗鸡房里一左一右放着两个青竹鸡笼，一个鸡笼里关着一群红冠鸡，
另一个鸡笼里关着一群绿尾鸡。屋子正中是七尺见方的斗鸡场，由一
圈低低的青玉栏杆围成。一位白髯鸡仙正在张罗着斗鸡。</p>

<h4>押注类型与赔率</h4>
<table border="1" style="width:100%; font-size:14px;">
    <tr><th>类型</th><th>代码</th><th>说明</th><th>赔率</th></tr>
    <tr>
        <td style="color:#FF4444; font-weight:bold;">红冠鸡</td>
        <td>hg</td>
        <td>押红冠鸡获胜</td>
        <td>1赢2</td>
    </tr>
    <tr>
        <td style="color:#44BB44; font-weight:bold;">绿尾鸡</td>
        <td>lw</td>
        <td>押绿尾鸡获胜</td>
        <td>1赢2</td>
    </tr>
</table>

<h4>游戏流程</h4>
<ol>
    <li><strong>准备阶段</strong>：白髯鸡仙从左右鸡笼里各拿出一只红冠鸡和一只绿尾鸡，展示给众人看。</li>
    <li><strong>押注阶段（20秒）</strong>：白髯鸡仙说"好，可以押钱了，一赢二。"玩家选择押红冠鸡或绿尾鸡，并下注铜钱。</li>
    <li><strong>斗鸡阶段</strong>：白髯鸡仙说声"停押，斗鸡。"将两只鸡抱起，拿出铁啄熟练地安上，把鸡放进栏内。两只鸡开始互相攻击，每秒一回合，直到一方倒下。</li>
    <li><strong>结算阶段（6秒）</strong>：根据斗鸡结果赔付。结算完毕后自动开始新轮次。</li>
</ol>

<h4>双败赔本机制</h4>
<p>如果两只鸡在同回合内双双倒下（HP同时低于15），则为<strong style="color:#FF6666;">双败赔本</strong>，
所有玩家都输掉赌注。这是斗鸡房最刺激的机制——就算你押的鸡也死了，一样赔本！</p>

<h4>战斗机制</h4>
<ul>
    <li>鸡的初始HP：<strong>400或500</strong>（随机，移植自 LPC douji.c: max_kee = 400 + random2(2)*100）</li>
    <li>每回合双方同时攻击，造成 <strong>10-30</strong> 点伤害</li>
    <li><strong>5%</strong> 几率暴击（2倍伤害）</li>
    <li><strong>10%</strong> 几率闪避（0伤害）</li>
    <li>死亡线：HP < <strong>15</strong>（移植自 LPC gamble_perform: ji->query("kee")<15）</li>
    <li>最多 <strong>60</strong> 回合（安全限制，超时按HP高低判定）</li>
</ul>

<h4>注意事项</h4>
<ul>
    <li>每人每轮只能押注一次</li>
    <li>使用<strong>铜钱</strong>作为赌注货币</li>
    <li>赢钱收取 <strong>5%</strong> 手续费</li>
    <li>双败时所有玩家都输，无赢家</li>
</ul>

<h4>原始命令（LPC）</h4>
<pre style="background:#222; color:#ccc; padding:10px; border-radius:4px; font-size:13px;">
押红冠鸡： dou hg &lt;数目&gt; &lt;货币&gt;
押绿尾鸡： dou lw &lt;数目&gt; &lt;货币&gt;
</pre>

<h4>牌子上写着的字</h4>
<pre style="background:#222; color:#ccc; padding:10px; border-radius:4px; font-size:13px;">
押红冠鸡： dou hg &lt;数目&gt; &lt;货币&gt;
押绿尾鸡： dou lw &lt;数目&gt; &lt;货币&gt;
</pre>

<br>
<a href="douji.php">返回斗鸡房</a>
</body>
</html>
