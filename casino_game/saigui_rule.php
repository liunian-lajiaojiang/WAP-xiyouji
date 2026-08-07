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
    <title>赛龟房规则_<?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
</head>
<body>
<p><a href="saigui.php">返回赛龟房</a></p>
<h3>赛龟房规则</h3>
<p>青鬏龟童将三只龟放在紫檀龟台边，用兔毛掸将赛龟推入龟台内赛跑。玩家根据各龟表现押注哪只龟先到达终点。</p>

<h4>赛龟种类与赔率</h4>
<table border="1" style="width:100%; font-size:13px; border-collapse:collapse;">
    <tr><th>种类</th><th>代码</th><th>赔率(含本金)</th></tr>
    <tr><td style="color:#FFD700;">长寿龟</td><td>cs</td><td>3倍</td></tr>
    <tr><td style="color:#87CEEB;">千年龟</td><td>qn</td><td>3倍</td></tr>
    <tr><td style="color:#90EE90;">不老龟</td><td>bl</td><td>3倍</td></tr>
</table>

<h4>游戏流程</h4>
<ul>
    <li><strong>押注阶段</strong>(20秒)：所有玩家可在此期间选择龟种并下注，每人每轮限押一次</li>
    <li><strong>赛跑阶段</strong>：三只龟在30格赛道上赛跑，每秒各龟随机前进0-6格，先到终点者获胜</li>
    <li><strong>结算阶段</strong>(6秒)：显示比赛结果，赢钱自动到账</li>
    <li>结算后自动开始下一轮，无需手动操作</li>
</ul>

<h4>特殊规则</h4>
<ul>
    <li><strong>二龟同胜</strong>：两只龟同时到达终点，无赢家，押注作废</li>
    <li><strong>三龟同胜</strong>：三只龟同时到达终点，无赢家，押注作废</li>
    <li>龟接近终点(28格以上)时，有概率被直接推到终点，减少平局</li>
</ul>

<h4>货币说明</h4>
<ul>
    <li>使用<strong>铜钱</strong>下注，1两黄金 = 100两银子 = 10000铜钱</li>
    <li>赢钱时自动合并面额（金/银/铜）</li>
    <li>赢钱收取 <strong>5%</strong> 手续费</li>
</ul>

<h4>注意事项</h4>
<ul>
    <li>多玩家共享同一轮次，同时赛跑</li>
    <li>下注后无法撤回，离开页面视为弃注</li>
    <li>龟童为庄家，玩家只能押注</li>
</ul>

<br>
<a href="saigui.php">返回</a>
</body>
</html>
