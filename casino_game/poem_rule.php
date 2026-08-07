<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 乐府诗社规则页
 * 移植自 LPC: d/city/clubpoem.c 中的 item_desc qishi
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
?>
<!DOCTYPE html>
<html lang="zh-cn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title>乐府诗社规则_<?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
</head>
<body>
<p>
    <a href="poem.php">返回诗社</a>&ensp;
    <a href="../functions/room.php">返回游戏</a>
</p>

<h3>乐府诗社规则</h3>

<p>高楼之上，满城繁华尽收眼底。文人骚客都喜欢在这里品茶吟诗，谈古论今。</p>

<h4>猜诗游戏</h4>
<p>由茶博士将一句诗词的若干字颠倒次序，写在墙上。能准确答出原句者为胜。</p>

<p>例如：茶博士提笔在墙上写道：<strong>离离原上草一荣一枯岁</strong><br>
你应该回答：<strong>answer 离离原上草一岁一枯荣</strong></p>

<h4>游戏流程</h4>
<ol>
    <li><strong>出题</strong>：茶博士每 60 秒在墙上写一句打乱顺序的诗句。</li>
    <li><strong>答题</strong>：玩家输入原句进行回答，茶博士裁判对错。</li>
    <li><strong>轮换</strong>：答对或 60 秒到期后，茶博士出下一题。旧题保留为"上一题"，仍可作答。</li>
</ol>

<h4>奖励机制</h4>
<p>答对后随机获得以下奖励之一：</p>
<table border="1" style="width:100%; font-size:14px;">
    <tr><th>奖励类型</th><th>数量</th><th>说明</th></tr>
    <tr>
        <td style="color:#FFD700; font-weight:bold;">道行</td>
        <td>+4~9</td>
        <td>增加角色的道行</td>
    </tr>
    <tr>
        <td style="color:#87CEEB; font-weight:bold;">潜能</td>
        <td>+3~6</td>
        <td>增加角色的潜能</td>
    </tr>
    <tr>
        <td style="color:#90EE90; font-weight:bold;">读书识字</td>
        <td>+4~9</td>
        <td>提升读书识字技能</td>
    </tr>
</table>

<h4>惩罚机制</h4>
<p>答错超过 <strong>10</strong> 次（自上次答对起计），神识(sen)将被设为 -1，需休息恢复。答对后答错计数器自动重置。</p>

<h4>答题消耗</h4>
<p>每次答题（无论对错）消耗 <strong>5~20</strong> 点神识(sen)，请量力而行。</p>

<h4>参与条件</h4>
<ul>
    <li>必须身处<strong>乐府诗社</strong>（长安城内）</li>
    <li><strong>茶博士</strong>必须在场才能裁判答题</li>
</ul>

<h4>注意事项</h4>
<ul>
    <li>每人可同时回答当前题和上一题</li>
    <li>每道题只有第一个答对的玩家能获得奖励</li>
    <li>答题时去掉空格和逗号，输入纯文字即可</li>
    <li>潜能奖励受上限限制（potential - learned_points ≤ 100），超限时改奖道行</li>
    <li>诗词库共收录 <strong>319</strong> 首唐诗</li>
</ul>

<h4>原始命令（LPC）</h4>
<pre style="background:#222; color:#ccc; padding:10px; border-radius:4px; font-size:13px;">
answer &lt;原句&gt;
look poem    （查看当前完整诗词和题目）
look qishi   （查看游戏规则）
</pre>

<h4>启事原文</h4>
<pre style="background:#222; color:#ccc; padding:10px; border-radius:4px; font-size:13px;">
本社新增猜诗游戏，由茶博士将一句诗词的若干字
颠倒次序，写在墙上。能准确答出(answer)原句者为胜。

例如：茶博士提笔在墙上写道：离离原上草一荣一枯岁
你应该回答：answer 离离原上草一岁一枯荣
</pre>

<br>
<a href="poem.php">返回诗社</a>
</body>
</html>
