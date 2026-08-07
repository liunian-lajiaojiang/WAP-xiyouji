<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 乐府诗社主页面
 * 猜诗游戏 + 懒推进状态机
 * 移植自 LPC: d/city/clubpoem.c
 *
 * 游戏流程:
 *   茶博士每60秒在墙上写一句打乱的诗句
 *   玩家用 answer 命令回答原句
 *   答对获得道行/潜能/读书识字奖励
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
    <title>乐府诗社_<?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <style>
        #poemWall {
            font-family: 'Courier New', 'Noto Sans Mono CJK SC', monospace;
            font-size: 12px;
            line-height: 1.3;
            white-space: pre-wrap;
            text-align: center;
            margin: 2px 0;
            padding: 2px 6px;
            border: 1px solid #555;
            border-radius: 3px;
            background: #1a1a2e;
            color: #ccc;
        }
        #poemWall p { margin: 1px 0; }
        #scrambledText {
            font-size: 13px;
            font-weight: bold;
            letter-spacing: 1px;
        }
        #poemDisplay {
            font-size: 11px;
            text-align: left;
            margin: 2px 0;
            padding: 2px 4px;
            border: 1px solid #333;
            border-radius: 3px;
            background: #111;
            color: #aaa;
        }
        #poemDisplay p { margin: 0; }
        #poemContent p { margin: 0 !important; }
        .reward-msg {
            color: #FFD700;
            font-weight: bold;
            text-align: center;
            margin: 2px 0;
        }
    </style>
</head>
<body>
<p>
    <a href="javascript:location.reload();">乐府诗社</a>&ensp;
    <a href="../functions/room.php">返回游戏</a>&ensp;
    <a href="poem_rule.php">规则</a>&ensp;
    <a href="poem_history.php">答题历史</a>
</p>

<!-- 状态显示区 -->
<p id="statusBar" style="font-weight:bold;">加载中...</p>

<!-- 茶博士墙上题字区 -->
<div id="poemWall" style="display:none;">
    <p style="color:#888; font-size:11px;">茶博士提笔在墙上写道：</p>
    <p id="scrambledText"></p>
</div>

<!-- 当前完整诗词展示（look poem） -->
<div id="poemDisplay" style="display:none;">
    <p id="poemTitle" style="font-weight:bold; text-align:center;"></p>
    <div id="poemContent"></div>
    <p id="currentQuestion" style="margin-top:2px; color:#FFD700;"></p>
</div>

<!-- 答题区 -->
<div id="answerArea" style="display:none; text-align:center; margin:4px 0;">
    <p style="font-weight:bold; margin:2px 0;">回答原句：</p>
    <input type="text" id="answerInput" placeholder="输入原句" style="width:80%; max-width:400px; padding:3px;" autocomplete="off">
    <br>
    <button type="button" id="answerBtn" onclick="submitAnswer()" style="margin-top:3px; padding:3px 12px;">回答</button>
    <p style="font-size:11px; color:#999; margin:2px 0;">提示：去掉空格和逗号后输入原句</p>
</div>

<!-- 奖励提示 -->
<div id="rewardArea" class="reward-msg" style="display:none;"></div>

<!-- 消息区 -->
<p id="msgArea" style="color:#FF6666; text-align:center;"></p>

<!-- 上一题区 -->
<div id="previousArea" style="display:none; margin:3px 0; padding:3px 6px; border:1px solid #444; border-radius:3px;">
    <p style="font-size:11px; color:#888; margin:1px 0;">上一题（仍可作答）：</p>
    <p id="previousScrambled" style="text-align:center; font-weight:bold; margin:1px 0;"></p>
    <p id="previousStatus" style="text-align:center; font-size:11px; margin:1px 0;"></p>
</div>

<br>
<a href="#" onclick="javascript:history.back(-1);">返回</a>
<hr>
<a href="../functions/room.php">返回游戏</a>

<script>
// ─── 全局状态 ──────────────────────────────
let pollTimer = null;
let currentRoundId = 0;
let isAnswering = false;
let lastRewardTime = 0;

// ─── 轮询 ─────────────────────────────────
function poll() {
    fetch('poem_api.php?action=status')
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.success) { schedulePoll(3000); return; }
        updateUI(data);
        schedulePoll(2000);
    })
    .catch(function() { schedulePoll(3000); });
}

function schedulePoll(ms) {
    if (pollTimer) clearTimeout(pollTimer);
    pollTimer = setTimeout(poll, ms);
}

// ─── UI 更新 ──────────────────────────────
function updateUI(data) {
    let cur = data.current;
    let bar = document.getElementById('statusBar');
    let poemWall = document.getElementById('poemWall');
    let scrambledText = document.getElementById('scrambledText');
    let poemDisplay = document.getElementById('poemDisplay');
    let poemTitle = document.getElementById('poemTitle');
    let poemContent = document.getElementById('poemContent');
    let currentQuestion = document.getElementById('currentQuestion');
    let answerArea = document.getElementById('answerArea');
    let previousArea = document.getElementById('previousArea');
    let msgArea = document.getElementById('msgArea');

    // 状态栏
    if (cur.isAnswered) {
        bar.innerHTML = '<span style="color:#00FF00;">当前题已被 ' + h(cur.answeredBy) + ' 答对</span>' +
            ' — 下一题 ' + cur.remaining + ' 秒后出';
    } else {
        bar.innerHTML = '<span style="color:#FFD700;">茶博士出题了！</span>' +
            ' — 剩余 ' + cur.remaining + ' 秒';
    }

    // 墙上题字
    poemWall.style.display = 'block';
    if (cur.isAnswered) {
        scrambledText.innerHTML = '<span style="color:#888; text-decoration:line-through;">' + h(cur.scrambled) + '</span>' +
            '<br><br><span style="color:#00FF00;">答：' + h(cur.answer) + '</span>';
    } else {
        scrambledText.innerHTML = '<span style="color:#FFD700;">' + h(cur.scrambled) + '</span>';
    }

    // 完整诗词展示 (look poem)
    poemDisplay.style.display = 'block';
    poemTitle.innerHTML = h(cur.poemAuthor) + '：' + h(cur.poemTitle);
    let contentHtml = '';
    if (cur.poemContent && cur.poemContent.length > 0) {
        cur.poemContent.forEach(function(line) {
            contentHtml += '<p style="margin:2px 0;">' + h(line) + '</p>';
        });
    }
    poemContent.innerHTML = contentHtml;

    if (cur.isAnswered) {
        currentQuestion.innerHTML = '当前题目已答对：<strong style="color:#00FF00;">' +
            h(cur.firstPart) + '  ' + h(cur.secondPart) + '</strong>';
    } else {
        currentQuestion.innerHTML = '当前题目：<strong style="color:#FFD700;">' + h(cur.scrambled) + '</strong>';
    }

    // 答题区：当前题可答则答当前题，否则若上一题可答则答上一题
    if (!cur.isAnswered) {
        answerArea.style.display = 'block';
        currentRoundId = cur.id;
        document.querySelector('#answerArea p').textContent = '回答原句：';
    } else if (data.previous && !data.previous.isAnswered) {
        answerArea.style.display = 'block';
        currentRoundId = data.previous.id;
        document.querySelector('#answerArea p').textContent = '回答上一题原句：';
    } else {
        answerArea.style.display = 'none';
    }

    // 上一题
    if (data.previous) {
        previousArea.style.display = 'block';
        document.getElementById('previousScrambled').textContent = data.previous.scrambled;
        let prevStatus = document.getElementById('previousStatus');
        if (data.previous.isAnswered) {
            prevStatus.innerHTML = '<span style="color:#00FF00;">已被 ' + h(data.previous.answeredBy) + ' 答对</span>' +
                ' — 答案：' + h(data.previous.answer);
        } else {
            prevStatus.innerHTML = '<span style="color:#FFD700;">尚未答对，仍可作答</span>';
        }
    } else {
        previousArea.style.display = 'none';
    }

    // 答错次数提示
    if (data.wrongAttempts > 0) {
        msgArea.innerHTML = '<span style="color:#FFA500;">本小时答错 ' + data.wrongAttempts +
            ' 次（超过 ' + data.maxWrongAttempts + ' 次将受罚）</span>';
    } else {
        msgArea.innerHTML = '';
    }
}

// ─── 答题提交 ─────────────────────────────
function submitAnswer() {
    if (isAnswering) return;
    let input = document.getElementById('answerInput');
    let answer = input.value.trim();
    if (!answer) { showMsg('请输入答案'); return; }
    if (!currentRoundId) { showMsg('暂无题目'); return; }

    isAnswering = true;
    let btn = document.getElementById('answerBtn');
    btn.disabled = true;
    btn.textContent = '提交中...';

    let formData = new FormData();
    formData.append('action', 'answer');
    formData.append('answer', answer);
    formData.append('round_id', currentRoundId);

    fetch('poem_api.php?action=answer', { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success && data.correct) {
            // 答对了！
            showMsg('');
            showReward(data.message, data.reward);
            input.value = '';
            poll(); // 立即刷新
        } else if (data.success && !data.correct) {
            showMsg(data.message);
            input.value = '';
            input.focus();
        } else {
            showMsg(data.message);
        }
    })
    .catch(function() { showMsg('网络错误，请重试'); })
    .finally(function() {
        isAnswering = false;
        btn.disabled = false;
        btn.textContent = '回答';
    });
}

function showMsg(msg) {
    document.getElementById('msgArea').textContent = msg;
}

function showReward(message, reward) {
    let el = document.getElementById('rewardArea');
    let rewardText = '';
    if (reward) {
        let typeNames = { daoxing: '道行', potential: '潜能', literate: '读书识字' };
        rewardText = ' （' + (typeNames[reward.type] || reward.type) + ' +' + reward.amount + '）';
    }
    el.innerHTML = message + ' <span style="color:#00FF00;">' + rewardText + '</span>';
    el.style.display = 'block';
    // 3秒后淡出
    setTimeout(function() {
        el.style.display = 'none';
    }, 5000);
}

function h(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// 回车提交
document.getElementById('answerInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        submitAnswer();
    }
});

// 启动轮询
poll();
</script>
</body>
</html>
