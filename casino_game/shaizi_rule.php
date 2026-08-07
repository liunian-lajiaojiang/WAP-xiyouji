<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 骰子房规则页
 * 移植自 LPC: d/city/shaizi-room.c 中的匾文和 item_desc
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
    <title>骰子房规则_<?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
</head>
<body>
<p>
    <a href="shaizi.php">返回骰子房</a>&ensp;
    <a href="../functions/room.php">返回游戏</a>
</p>
<p>铜钱: <?= $coinBalance ?>文</p>

<h3>骰子房规则</h3>

<p>这间屋里摆着一张八仙桌，庄家公孙大娘正在吆五喝六，赌客们围了一大圈。
桌上放着两粒象牙骰子，一个青花瓷碗。墙上有块牌子写着赌规。</p>

<h4>双骰点数计算</h4>
<table border="1" style="width:100%; font-size:14px;">
    <tr><th>类型</th><th>条件</th><th>点数</th><th>示例</th></tr>
    <tr>
        <td style="color:#FFD700; font-weight:bold;">对子</td>
        <td>两骰点数相同</td>
        <td>100 + 面值</td>
        <td>两个4 = 104（四对）</td>
    </tr>
    <tr>
        <td style="color:#66BBFF; font-weight:bold;">散点</td>
        <td>两骰点数不同，取和的个位</td>
        <td>(骰1+骰2) % 10</td>
        <td>3+5=8（八点）</td>
    </tr>
    <tr>
        <td style="color:#FF6666; font-weight:bold;">蹩十</td>
        <td>两骰之和为10的倍数</td>
        <td>0（最小）</td>
        <td>4+6=10（蹩十）</td>
    </tr>
</table>

<h4>点数大小比较</h4>
<ul>
    <li><strong>对子</strong> 永远大于 <strong>散点</strong></li>
    <li>对子之间比面值：六对 > 五对 > 四对 > 三对 > 二对 > 一对</li>
    <li>散点之间比模10值：九点 > 八点 > ... > 一点 > 蹩十(0)</li>
    <li>对子 vs 散点：对子胜（如一对也大于九点）</li>
</ul>

<h4>庄家制</h4>
<ul>
    <li><strong>坐庄</strong>：玩家可主动坐庄，需交纳 <strong>1000文</strong> 铜钱保证金</li>
    <li><strong>赌注上限</strong>：庄家可设定赌注上限（500-10000文），玩家下注不能超过此限</li>
    <li><strong>庄家职责</strong>：庄家须在所有玩家下注后，下不少于总下注额的赌注，然后开掷</li>
    <li><strong>NPC庄家</strong>：30秒内无人坐庄，公孙大娘自动坐庄（赌注上限2000文）</li>
    <li><strong>让庄</strong>：庄家在开掷前可让庄退出，保证金退还，玩家赌注退还</li>
    <li><strong>保证金退还</strong>：每轮结算后，庄家保证金自动退还</li>
</ul>

<h4>游戏流程</h4>
<ol>
    <li><strong>等待庄家（30秒）</strong>：玩家可坐庄。超时后NPC公孙大娘自动坐庄。</li>
    <li><strong>下注阶段（30秒）</strong>：所有玩家（非庄家）可下注，每人限押一次，金额不超过庄家设定上限。</li>
    <li><strong>庄家下注</strong>：庄家须下不少于玩家总下注额的赌注，触发开掷。NPC庄家在25秒或有玩家下注后自动下注。</li>
    <li><strong>掷骰阶段</strong>：所有赌客依次掷骰（每人间隔4秒），庄家最后掷骰（增加悬念）。ASCII骰面逐个展示。</li>
    <li><strong>结算阶段（10秒）</strong>：玩家点数与庄家逐一比较，大者胜。</li>
    <li>结算完毕后自动开始新轮次。</li>
</ol>

<h4>结算规则</h4>
<table border="1" style="width:100%; font-size:14px;">
    <tr><th>情况</th><th>结果</th></tr>
    <tr><td>玩家点数 > 庄家点数</td><td>玩家赢，获 2倍 赌注</td></tr>
    <tr><td>玩家点数 &le; 庄家点数</td><td>玩家输，赌注归庄家</td></tr>
    <tr><td>庄家</td><td>获所有输家的赌注 - 自己的下注（净盈亏）</td></tr>
</table>

<h4>超时机制</h4>
<ul>
    <li>等待庄家超时(30秒)：NPC公孙大娘自动坐庄</li>
    <li>下注阶段超时(30秒)：
        <ul>
            <li>无人下注 → 取消本轮，退还庄家保证金</li>
            <li>NPC庄家 → 自动下注并开掷</li>
            <li>玩家庄家未下注 → 取消本轮，没收保证金，退还玩家赌注</li>
        </ul>
    </li>
    <li>NPC庄家提前下注：25秒后若有玩家下注，NPC自动下注开掷</li>
</ul>

<h4>注意事项</h4>
<ul>
    <li>每人每轮只能下注一次</li>
    <li>使用<strong>铜钱</strong>作为赌注货币</li>
    <li>庄家最后掷骰，与每个玩家逐一比点数</li>
    <li>下注阶段可取消下注（退还赌注）</li>
    <li>最大玩家数：10人</li>
</ul>

<h4>原始命令（LPC）</h4>
<pre style="background:#222; color:#ccc; padding:10px; border-radius:4px; font-size:13px;">
坐庄：        shaizi zuo &lt;数目&gt;
下注：        shaizi ya &lt;数目&gt;
取消下注：    shaizi qu
让庄：        shaizi rang
查看赌桌：    look table
</pre>

<br>
<a href="shaizi.php">返回骰子房</a>
</body>
</html>
