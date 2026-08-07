<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 骨骰房规则页
 * 移植自 LPC: d/city/duchang2.c 中的匾文和 item_desc
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
    <title>骨骰房规则_<?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
</head>
<body>
<p>
    <a href="gutou.php">返回骨骰房</a>&ensp;
    <a href="../functions/room.php">返回游戏</a>
</p>
<p>铜钱: <?= $coinBalance ?>文</p>

<h3>骨骰房规则</h3>

<p>这里摆着一个八仙桌，中间摆着一个银盘。赌客们正聚精会神地下赌。
正席是一位胖子，手里不断玩弄着一对玉骰，他就是这里的庄东。</p>

<h4>押注类型与赔率</h4>
<table border="1" style="width:100%; font-size:14px;">
    <tr><th>类型</th><th>代码</th><th>中奖条件</th><th>赔率</th></tr>
    <tr>
        <td style="color:#FF4444; font-weight:bold;">头彩</td>
        <td>tc</td>
        <td>两骰点数与开盘前预告的头彩号完全一致</td>
        <td>1赢36</td>
    </tr>
    <tr>
        <td style="color:#FFA500; font-weight:bold;">双对</td>
        <td>sd</td>
        <td>两骰号相同，且为偶数（2对、4对、6对）</td>
        <td>1赢12</td>
    </tr>
    <tr>
        <td style="color:#66BBFF; font-weight:bold;">七星</td>
        <td>qx</td>
        <td>两骰之和为七</td>
        <td>1赢6</td>
    </tr>
    <tr>
        <td style="color:#90EE90; font-weight:bold;">散星</td>
        <td>sx</td>
        <td>两骰之和为三、五、九、十一</td>
        <td>1赢3</td>
    </tr>
</table>

<h4>游戏流程</h4>
<ol>
    <li><strong>押注阶段（24秒）</strong>：庄东先掷两枚玉骰确定头彩号（两数不同），并公开展示。玩家根据头彩号选择押注类型和金额下注。</li>
    <li><strong>开骰阶段（18秒）</strong>：庄东喊"封盘停押"，将玉骰扔进金盅摇动。第6秒开出第一枚骰子，第12秒开出第二枚骰子。</li>
    <li><strong>结算阶段（6秒）</strong>：根据两枚骰子结果判定中奖类型，按赔率赔付。</li>
    <li>结算完毕后自动开始新轮次。</li>
</ol>

<h4>头彩预告机制</h4>
<p>每轮押注阶段开始时，庄东会公开掷两枚玉骰确定头彩号（如3和5）。
如果开骰结果恰好是3和5（顺序也需一致），则押"头彩"的玩家以36倍赔率中奖。
由于两枚骰子点数不同的组合有30种，头彩概率为 1/36。</p>

<h4>注意事项</h4>
<ul>
    <li>每人每轮只能押注一次</li>
    <li>使用<strong>铜钱</strong>作为赌注货币</li>
    <li>赢钱收取 <strong>5%</strong> 手续费</li>
    <li>若开骰结果不满足任何中奖条件，则为"空盘"，所有注码归庄东</li>
</ul>

<h4>原始命令（LPC）</h4>
<pre style="background:#222; color:#ccc; padding:10px; border-radius:4px; font-size:13px;">
押头彩骰： gutou tc &lt;数目&gt; &lt;货币&gt;
押双对骰： gutou sd &lt;数目&gt; &lt;货币&gt;
押七星骰： gutou qx &lt;数目&gt; &lt;货币&gt;
押散星骰： gutou sx &lt;数目&gt; &lt;货币&gt;
</pre>

<br>
<a href="gutou.php">返回骨骰房</a>
</body>
</html>
