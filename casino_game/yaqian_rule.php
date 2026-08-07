<?php
session_save_path(__DIR__ . '/../sessions');
session_start();
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Character.php';
require_once HELPER_PATH . 'MoneyHelper.php';
require_login();
?>
<!DOCTYPE html>
<html lang="zh-cn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title>押签房规则_<?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
</head>
<body>
<p><a href="yaqian.php">返回押签房</a></p>
<h3>📜 押签房规则</h3>
<p>签客从镶金黑盒中一根一根抽出5根乾坤签，每根为「乾」或「坤」。玩家根据抽签结果押注。</p>

<h4>押签种类与赔率</h4>
<table border="1" style="width:100%; font-size:13px; border-collapse:collapse;">
    <tr><th>种类</th><th>代码</th><th>中奖条件</th><th>赔率(含本金)</th></tr>
    <tr><td style="color:#FFD700;">大乾签</td><td>dqq</td><td>5根全为乾签</td><td>32倍</td></tr>
    <tr><td style="color:#FFD700;">大坤签</td><td>dkq</td><td>5根全为坤签</td><td>32倍</td></tr>
    <tr><td style="color:#C0C0C0;">小乾签</td><td>xqq</td><td>连续4根为乾签</td><td>16倍</td></tr>
    <tr><td style="color:#C0C0C0;">小坤签</td><td>xkq</td><td>连续4根为坤签</td><td>16倍</td></tr>
    <tr><td style="color:#CD7F32;">乾签</td><td>qq</td><td>任意3根为乾签</td><td>2倍</td></tr>
    <tr><td style="color:#CD7F32;">坤签</td><td>kq</td><td>任意3根为坤签</td><td>2倍</td></tr>
</table>

<h4>游戏流程</h4>
<ul>
    <li><strong>押注阶段</strong>(25秒)：所有玩家可在此期间选择种类并下注，每人每轮限押一次</li>
    <li><strong>开奖阶段</strong>(8秒)：签客逐根抽出乾坤签，每1.5秒揭示一根</li>
    <li><strong>结算阶段</strong>(5秒)：显示中奖结果，赢钱自动到账</li>
    <li>结算后自动开始下一轮，无需手动操作</li>
</ul>

<h4>货币说明</h4>
<ul>
    <li>使用<strong>铜钱</strong>下注，1两黄金 = 100两银子 = 10000铜钱</li>
    <li>赢钱时自动合并面额（金/银/铜）</li>
    <li>赢钱收取 <strong>5%</strong> 手续费</li>
</ul>

<h4>注意事项</h4>
<ul>
    <li>多玩家共享同一轮次，同时开奖</li>
    <li>下注后无法撤回，离开页面视为弃注</li>
    <li>签客为庄家，玩家只能押注</li>
</ul>

<br>
<a href="yaqian.php">返回</a>
</body>
</html>
