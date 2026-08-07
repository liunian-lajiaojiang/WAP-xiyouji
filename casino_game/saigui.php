<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 赛龟房主页面
 * 多人共享轮次 + LPC风格文字动画 + 统一铜钱体系
 * 移植自 LPC: d/city/duchang4.c
 *
 * 文字动画: 移植 LPC display_gui() 的 ASCII 艺术渲染
 *   ＼－－－／
 *   （长寿龟）＞
 *   ／－－－＼
 * 前端从 API 获取所有帧，用 setInterval 本地播放动画
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
    <title>赛龟房_<?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <style>
        #raceTrack {
            font-family: 'Courier New', 'Noto Sans Mono CJK SC', monospace;
            font-size: 13px;
            line-height: 1.3;
            white-space: pre;
            overflow-x: auto;
            margin: 8px 0;
            padding: 10px;
            border: 1px solid #555;
            border-radius: 6px;
            background: #111;
            color: #ccc;
        }
    </style>
</head>
<body>
<p>
    <a href="javascript:location.reload();">赛龟房</a>&ensp;
    <a href="../functions/room.php">返回游戏</a>&ensp;
    <a href="saigui_rule.php">规则</a>&ensp;
    <a href="saigui_history.php">下注历史</a>
</p>

<p>铜钱: <span id="coinBalance"><?= $coinBalance ?>文</span></p>

<!-- 状态显示区 -->
<p id="statusBar" style="font-weight:bold;">加载中...</p>

<!-- 赛道文字动画区 (status 2/3) -->
<pre id="raceTrack" style="display:none;"></pre>

<!-- 押注区 (status 1: 押注中且未下注) -->
<div id="betArea" style="display:none;">
    <p style="font-weight:bold;">选择押龟种类（一赢三）</p>
    <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin:8px 0;">
        <label style="display:block; padding:8px; border:1px solid #555; border-radius:6px; text-align:center;">
            <input type="radio" name="betKind" value="cs" style="display:none;">
            <span style="font-weight:bold; color:#FFD700;">长寿龟</span><br>
            <span style="font-size:12px; color:#999;">赔3倍</span>
        </label>
        <label style="display:block; padding:8px; border:1px solid #555; border-radius:6px; text-align:center;">
            <input type="radio" name="betKind" value="qn" style="display:none;">
            <span style="font-weight:bold; color:#87CEEB;">千年龟</span><br>
            <span style="font-size:12px; color:#999;">赔3倍</span>
        </label>
        <label style="display:block; padding:8px; border:1px solid #555; border-radius:6px; text-align:center;">
            <input type="radio" name="betKind" value="bl" style="display:none;">
            <span style="font-weight:bold; color:#90EE90;">不老龟</span><br>
            <span style="font-size:12px; color:#999;">赔3倍</span>
        </label>
    </div>

    <p style="font-weight:bold;">下注金额(铜钱)</p>
    <div style="margin:6px 0;">
        <button type="button" onclick="setAmount(100)" style="padding:3px 8px;">100</button>
        <button type="button" onclick="setAmount(500)" style="padding:3px 8px;">500</button>
        <button type="button" onclick="setAmount(1000)" style="padding:3px 8px;">1000</button>
        <button type="button" onclick="setAmount(5000)" style="padding:3px 8px;">5000</button>
        <button type="button" onclick="setAllIn()" style="padding:3px 8px;">全押</button>
    </div>
    <input type="number" id="betAmount" placeholder="输入铜钱数" min="1" style="width:120px;">
    <button type="button" id="betBtn" onclick="placeBet()" style="padding:3px 12px;">押龟</button>
    <p style="font-size:12px; color:#999;">手续费 5% | 每轮限押一次 | 二龟/三龟同胜无赢家</p>
</div>

<!-- 已下注提示 -->
<div id="betPlacedArea" style="display:none; padding:8px; border:1px solid #8B6914; border-radius:6px; margin:8px 0; text-align:center;">
    <p id="betPlacedText" style="margin:0;"></p>
</div>

<!-- 结算结果 -->
<div id="resultArea" style="display:none; padding:10px; border:2px solid; border-radius:8px; margin:8px 0; text-align:center;">
</div>

<!-- 本轮下注列表 -->
<div id="allBetsArea" style="display:none; margin:8px 0;">
    <p style="font-weight:bold; font-size:13px;">本轮下注</p>
    <div id="allBetsList" style="font-size:12px; color:#aaa;"></div>
</div>

<p id="msgArea" style="color:#FF6666;"></p>

<br>
<a href="#" onclick="javascript:history.back(-1);">返回</a>
<hr>
<a href="../functions/room.php">返回游戏</a>

<script>
// ─── 全局状态 ──────────────────────────────
let pollTimer = null;
let currentRoundId = 0;
let isPlacingBet = false;

// 赛跑动画状态
let raceFrames = null;       // 从 API 获取的所有帧 [[cs,qn,bl], ...]
let localFrameIndex = 0;     // 本地播放到的帧索引
let raceAnimTimer = null;    // setInterval 句柄
let lastSyncedFrameIndex = 0; // 上次 API 同步的帧索引

const GUI_NAMES = ['长寿龟', '千年龟', '不老龟'];
const GUI_COLORS = ['#FFD700', '#87CEEB', '#90EE90'];

// ─── LPC 风格 ASCII 赛道渲染 (移植自 display_gui) ────
// LPC 原始渲染:
//   line = "｜" + ".."*position + turtle_art + "  "*(30-position) + "｜"
//   turtle_art:
//     ＼－－－／     (龟壳顶)
//     （长寿龟）＞   (龟身)
//     ／－－－＼     (龟壳底)

function renderTrack(positions, finishLine) {
    // 龟壳图形 (全角字符，宽度与龟身一致)
    const SHELL_TOP = '＼－－－／  ';   // 12字符宽 (5全角+2半角)
    const SHELL_BOT = '／－－－＼  ';   // 12字符宽

    let html = '';
    for (let i = 0; i < 3; i++) {
        let pos = positions[i];
        let name = GUI_NAMES[i];
        let color = GUI_COLORS[i];

        // 构建赛道线
        let dots = '';
        let spaces = '';
        for (let j = 0; j < pos; j++) dots += '..';
        for (let j = pos; j < finishLine; j++) spaces += '  ';

        let shellMid = '（' + name + '）＞';

        let line1 = '｜' + dots + SHELL_TOP + spaces + '｜';
        let line2 = '｜' + dots + shellMid + spaces + '｜';
        let line3 = '｜' + dots + SHELL_BOT + spaces + '｜';

        html += '<span style="color:' + color + ';">' + line1 + '</span>\n';
        html += '<span style="color:' + color + '; font-weight:bold;">' + line2 + '</span>\n';
        html += '<span style="color:' + color + ';">' + line3 + '</span>\n';
    }
    return html;
}

// ─── 赛跑动画本地播放 ─────────────────────
function startRaceAnimation(frames, startFrameIndex, frameInterval) {
    raceFrames = frames;
    localFrameIndex = startFrameIndex;
    lastSyncedFrameIndex = startFrameIndex;

    // 立即渲染当前帧
    renderRaceFrame();

    // 启动本地定时器 (每 frameInterval 秒推进一帧)
    if (raceAnimTimer) clearInterval(raceAnimTimer);
    if (frames.length > 1) {
        raceAnimTimer = setInterval(function() {
            localFrameIndex++;
            if (localFrameIndex >= frames.length - 1) {
                localFrameIndex = frames.length - 1;
                // 最后一帧，停止本地定时器 (等 API 推进到 status 3)
                clearInterval(raceAnimTimer);
                raceAnimTimer = null;
            }
            renderRaceFrame();
        }, frameInterval * 1000);
    }
}

function renderRaceFrame() {
    if (!raceFrames || raceFrames.length === 0) return;
    let positions = raceFrames[localFrameIndex] || [0, 0, 0];
    let pre = document.getElementById('raceTrack');
    pre.innerHTML = renderTrack(positions, 30);
}

function stopRaceAnimation() {
    if (raceAnimTimer) {
        clearInterval(raceAnimTimer);
        raceAnimTimer = null;
    }
    raceFrames = null;
    localFrameIndex = 0;
}

// ─── 押注操作 ─────────────────────────────
document.querySelectorAll('input[name="betKind"]').forEach(function(input) {
    input.addEventListener('change', function() {
        document.querySelectorAll('input[name="betKind"]').forEach(function(r) {
            r.parentElement.style.borderColor = r.checked ? '#FFD700' : '#555';
            r.parentElement.style.background = r.checked ? '#3a3520' : '';
        });
    });
});

function setAmount(amount) {
    document.getElementById('betAmount').value = amount;
}

function setAllIn() {
    let balance = parseInt(document.getElementById('coinBalance').textContent) || 0;
    document.getElementById('betAmount').value = balance;
}

function placeBet() {
    if (isPlacingBet) return;
    let selected = document.querySelector('input[name="betKind"]:checked');
    if (!selected) { showMsg('请选择押龟种类'); return; }
    let amount = parseInt(document.getElementById('betAmount').value) || 0;
    if (amount <= 0) { showMsg('请输入有效的下注金额'); return; }

    isPlacingBet = true;
    let btn = document.getElementById('betBtn');
    btn.disabled = true;
    btn.textContent = '押注中...';

    let formData = new FormData();
    formData.append('action', 'bet');
    formData.append('kind', selected.value);
    formData.append('amount', amount);

    fetch('saigui_api.php?action=bet', { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showMsg('');
            document.getElementById('coinBalance').textContent = data.coinBalance + '文';
            poll();
        } else {
            showMsg(data.message);
        }
    })
    .catch(function() { showMsg('网络错误，请重试'); })
    .finally(function() {
        isPlacingBet = false;
        btn.disabled = false;
        btn.textContent = '押龟';
    });
}

function showMsg(msg) {
    document.getElementById('msgArea').textContent = msg;
}

// ─── 轮询 ─────────────────────────────────
function poll() {
    fetch('saigui_api.php?action=status')
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
    let r = data.round;
    let myBet = data.myBet;

    document.getElementById('coinBalance').textContent = data.coinBalance + '文';

    let bar = document.getElementById('statusBar');
    let raceTrack = document.getElementById('raceTrack');
    let betArea = document.getElementById('betArea');
    let betPlacedArea = document.getElementById('betPlacedArea');
    let resultArea = document.getElementById('resultArea');

    // 默认隐藏所有区域
    betArea.style.display = 'none';
    betPlacedArea.style.display = 'none';
    resultArea.style.display = 'none';

    if (r.status === 1) {
        // ── 押注中 ──
        stopRaceAnimation();
        raceTrack.style.display = 'none';

        bar.innerHTML = '<span style="color:#FFD700;">押注中</span> — 剩余 ' + r.bettingRemaining + ' 秒';
        if (!myBet) {
            betArea.style.display = 'block';
        } else {
            betPlacedArea.style.display = 'block';
            document.getElementById('betPlacedText').innerHTML =
                '已押 <strong style="color:#FFD700;">' + myBet.kindName + '</strong> ' + myBet.amount + '文铜钱，等待赛跑...';
        }

    } else if (r.status === 2) {
        // ── 赛跑中: 文字动画 ──
        bar.innerHTML = '<span style="color:#FF6347;">赛跑中</span> — 龟童正在用兔毛掸赶龟...';

        // 首次进入或帧数据更新时启动本地动画
        if (!raceFrames || (r.raceFrames && r.raceFrames.length > raceFrames.length)) {
            if (r.raceFrames && r.raceFrames.length > 0) {
                startRaceAnimation(r.raceFrames, r.frameIndex || 0, r.raceFrameInterval || 1);
            }
        } else if (raceFrames && r.frameIndex !== undefined) {
            // 同步: 如果 API 的帧索引超前本地，跳到 API 帧索引
            if (r.frameIndex > localFrameIndex) {
                localFrameIndex = r.frameIndex;
                renderRaceFrame();
            }
        }

        raceTrack.style.display = 'block';

        if (myBet) {
            betPlacedArea.style.display = 'block';
            document.getElementById('betPlacedText').innerHTML =
                '已押 <strong style="color:#FFD700;">' + myBet.kindName + '</strong> ' + myBet.amount + '文铜钱，观战中...';
        }

    } else if (r.status === 3) {
        // ── 已结算: 静态展示最终赛道 ──
        stopRaceAnimation();

        let resultText = r.winnerName
            ? '青鬏龟童喜道：' + r.winnerName + '获胜！'
            : ('青鬏龟童叹道：' + (r.winnerReason || '无赢家') + '！');
        bar.innerHTML = '<span style="color:#00FF00;">已结算</span> — ' + resultText +
            ' — 下一轮 ' + r.settleRemaining + ' 秒后开始';

        // 渲染最终赛道位置
        raceTrack.style.display = 'block';
        if (r.guis) {
            let finalPositions = r.guis.map(function(g) { return g.position; });
            raceTrack.innerHTML = renderTrack(finalPositions, r.finishLine);
        }

        // 显示结算结果
        if (myBet && myBet.isSettled) {
            resultArea.style.display = 'block';
            if (myBet.isWin) {
                resultArea.style.borderColor = '#00FF00';
                resultArea.style.background = '#0a2a0a';
                resultArea.innerHTML =
                    '<p style="margin:0; color:#00FF00; font-size:16px; font-weight:bold;">中奖！</p>' +
                    '<p style="margin:4px 0;">押 ' + myBet.kindName + ' | ' + (r.winnerName || '') + '获胜</p>' +
                    '<p style="margin:0; color:#FFD700;">赢得 ' + myBet.winAmount + '文 | 手续费 ' + myBet.commission + '文</p>' +
                    '<p style="margin:0; color:#00FF00; font-weight:bold;">净赚 +' + myBet.netWin + '文</p>';
            } else {
                resultArea.style.borderColor = '#FF6666';
                resultArea.style.background = '#2a0a0a';
                let lossReason = r.winnerName
                    ? '开' + r.winnerName
                    : (r.winnerReason || '无赢家');
                resultArea.innerHTML =
                    '<p style="margin:0; color:#FF6666; font-size:16px; font-weight:bold;">未中</p>' +
                    '<p style="margin:4px 0;">押 ' + myBet.kindName + ' | ' + lossReason + '</p>' +
                    '<p style="margin:0; color:#FF6666;">损失 -' + myBet.amount + '文</p>';
            }
        } else if (!myBet) {
            resultArea.style.display = 'block';
            resultArea.style.borderColor = '#555';
            resultArea.style.background = '#1a1a2e';
            let resultText2 = r.winnerName
                ? r.winnerName + '获胜'
                : (r.winnerReason || '无赢家');
            resultArea.innerHTML =
                '<p style="margin:0; color:#aaa;">本轮未下注</p>' +
                '<p style="margin:0; color:#FFD700;">结果: ' + resultText2 + '</p>';
        }

    } else {
        stopRaceAnimation();
        raceTrack.style.display = 'none';
        bar.textContent = '等待开始...';
    }

    // 本轮下注列表
    let allBetsArea = document.getElementById('allBetsArea');
    let allBetsList = document.getElementById('allBetsList');
    if (data.allBets && data.allBets.length > 0) {
        allBetsArea.style.display = 'block';
        let html = '';
        data.allBets.forEach(function(b) {
            html += '<span style="margin-right:12px;">' + b.charName + ' 押' + b.kindName + ' ' + b.amount + '文</span>';
        });
        allBetsList.innerHTML = html;
    } else {
        allBetsArea.style.display = 'none';
    }

    // 轮次变化时重置
    if (currentRoundId !== r.id) {
        currentRoundId = r.id;
        showMsg('');
    }
}

// 启动轮询
poll();
</script>
</body>
</html>
