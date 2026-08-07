<?php
session_save_path(__DIR__ . '/../sessions');
session_start();
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_login();

// 从 dudaxiao.php 获取常量定义
define('ODDS', 2);
define('COMMISSION_RATE', 0.05);
?>
<!DOCTYPE html>
<html lang="zh-cn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title>赌大小规则_<?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
</head>
<body>
    <p><a href="dudaxiao.php">返回赌大小</a></p>
    <h3>📜 赌大小规则</h3>
    <ul>
        <li>使用 <strong>铜钱</strong> 作为赌注货币</li>
        <li>骰子点数 1-3 为小，4-6 为大</li>
        <li>猜对获得下注金额的 <?= ODDS ?> 倍（含本金）</li>
        <li>猜错损失全部下注金额，<strong>不收取任何费用</strong></li>
        <li>猜对赢钱后收取 <strong><?= (COMMISSION_RATE * 100) ?>%</strong> 手续费</li>
        <li>黄金可通过打怪、任务、交易获得</li>
    </ul>
    <h3>💰 货币说明</h3>
    <ul>
        <li>1 两黄金 = 100 两银子 = 10000 铜钱</li>
        <li>赌大小使用铜钱下注，赢钱时自动合并面额</li>
    </ul>
    <br>
    <a href="dudaxiao.php">返回</a>
</body>
</html>
