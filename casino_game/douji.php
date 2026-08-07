<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 斗鸡房主页面
 * 多人共享轮次 + LPC风格文字动画 + 统一铜钱体系
 * 移植自 LPC: d/city/duchang3.c
 *
 * 文字动画: 逐帧展示斗鸡 HP 变化，配合 ASCII 鸡形艺术
 *   红冠鸡          VS          绿尾鸡
 *   (o>                           <o)
 *   //\                           /\\
 *   V_/                           \_V
 * HP: ████████░░░ 320/450       HP: ██████░░░░ 240/400
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
    <title>斗鸡房_<?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <style>
        #fightArena {
            font-family: 'Courier New', 'Noto Sans Mono CJK SC', monospace;
            font-size: 13px;
            line-height: 1.4;
            white-space: pre;
            text-align: center;
            margin: 8px 0;
            padding: 15px;
            border: 1px solid #555;
            border-radius: 6px;
            background: #111;
            color: #ccc;
        }
        #fightLog {
            font-size: 12px;
            color: #aaa;
            margin: 4px 0;
            min-height: 20px;
            text-align: center;
        }
    </style>
</head>
<body>
<p>
    <a href="javascript:location.reload();">斗鸡房</a>&ensp;
    <a href="../functions/room.php">返回游戏</a>&ensp;
    <a href="douji_rule.php">规则</a>&ensp;
    <a href="douji_history.php">下注历史</a>
</p>

<p>铜钱: <span id="coinBalance"><?= $coinBalance ?>文</span></p>

<!-- 状态显示区 -->
<p id="statusBar" style="font-weight:bold;">加载中...</p>

<!-- 斗鸡场文字动画区 -->
<pre id="fightArena" style="display:none;"></pre>

<!-- 战斗日志 -->
<div id="fightLog"></div>

<!-- 押注区 (status 1: 押注中且未下注) -->
<div id="betArea" style="display:none;">
    <p style="font-weight:bold;">白髯鸡仙说：好，可以押钱了，一赢二。</p>
    <p style="font-weight:bold;">选择押鸡种类</p>
    <div style="display:grid; grid-template-columns:repeat(2,1fr); gap:6px; margin:8px 0;">
        <label style="display:block; padding:10px; border:2px solid #555; border-radius:6px; text-align:center; cursor:pointer;" id="labelHg">
            <input type="radio" name="betKind" value="hg" style="display:none;">
            <span style="font-weight:bold; color:#FF4444; font-size:15px;">红冠鸡</span><br>
            <span style="font-size:12px; color:#999;">赔2倍</span>
        </label>
        <label style="display:block; padding:10px; border:2px solid #555; border-radius:6px; text-align:center; cursor:pointer;" id="labelLw">
            <input type="radio" name="betKind" value="lw" style="display:none;">
            <span style="font-weight:bold; color:#44BB44; font-size:15px;">绿尾鸡</span><br>
            <span style="font-size:12px; color:#999;">赔2倍</span>
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
    <button type="button" id="betBtn" onclick="placeBet()" style="padding:3px 12px;">押鸡</button>
    <p style="font-size:12px; color:#999;">手续费 5% | 每轮限押一次 | 双败赔本（两鸡同归于尽则全输）</p>
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

// 斗鸡动画状态
let fightFrames = null;       // 从 API 获取的所有帧 [[hg_hp, lw_hp], ...]
let localFrameIndex = 0;      // 本地播放到的帧索引
let fightAnimTimer = null;    // setInterval 句柄
let hgInitHp = 400;           // 红冠鸡初始HP
let lwInitHp = 400;           // 绿尾鸡初始HP

const HG_NAME = '红冠鸡';
const LW_NAME = '绿尾鸡';
const HG_COLOR = '#FF4444';
const LW_COLOR = '#44BB44';

// ─── ASCII 鸡形渲染 ────────────────────────
// 红冠鸡 (朝右):    绿尾鸡 (朝左):
//   (o>               <o)
//   //\               /\\
//   V_/               \_V

function renderCockArt(isHgAlive, isLwAlive) {
    let hgArt, lwArt;

    if (isHgAlive) {
        hgArt = '  (o>\n  //\\\n  V_/';
    } else {
        hgArt = '  (x>\n  //\\\n  V_/';
    }

    if (isLwAlive) {
        lwArt = '<o)\n  /\\\\\n  \\_V';
    } else {
        lwArt = '<x)\n  /\\\\\n  \\_V';
    }

    return hgArt + '\n\n         VS\n\n' + lwArt;
}

// ─── HP 血条渲染 ───────────────────────────
function renderHpBar(currentHp, maxHp, name, color) {
    const barWidth = 20;
    let ratio = Math.max(0, currentHp / maxHp);
    let filled = Math.round(ratio * barWidth);
    let empty = barWidth - filled;

    let bar = '';
    for (let i = 0; i < filled; i++) bar += '█';
    for (let i = 0; i < empty; i++) bar += '░';

    return name + ': ' + bar + ' ' + currentHp + '/' + maxHp;
}

// ─── 渲染整个斗鸡场 ────────────────────────
function renderArena(hgHp, lwHp) {
    let isHgAlive = hgHp >= 15;
    let isLwAlive = lwHp >= 15;

    let html = '';

    // 鸡名
    html += '<span style="color:' + HG_COLOR + '; font-weight:bold;">' + HG_NAME + '</span>';
    html += '                         ';
    html += '<span style="color:' + LW_COLOR + '; font-weight:bold;">' + LW_NAME + '</span>';
    html += '\n\n';

    // ASCII 鸡形
    let hgArt = isHgAlive ? '  (o>' : '  (x>';
    let lwArt = isLwAlive ? '<o)' : '<x)';
    let hgArt2 = '  //\\';
    let lwArt2 = '/\\\\';
    let hgArt3 = '  V_/';
    let lwArt3 = '\\_V';

    html += '<span style="color:' + HG_COLOR + ';">' + hgArt + '</span>';
    html += '                     ';
    html += '<span style="color:' + LW_COLOR + ';">' + lwArt + '</span>';
    html += '\n';
    html += '<span style="color:' + HG_COLOR + ';">' + hgArt2 + '</span>';
    html += '                     ';
    html += '<span style="color:' + LW_COLOR + ';">' + lwArt2 + '</span>';
    html += '\n';
    html += '<span style="color:' + HG_COLOR + ';">' + hgArt3 + '</span>';
    html += '                     ';
    html += '<span style="color:' + LW_COLOR + ';">' + lwArt3 + '</span>';
    html += '\n\n';

    // HP 血条
    html += '<span style="color:' + HG_COLOR + ';">' + renderHpBar(hgHp, hgInitHp, HG_NAME, HG_COLOR) + '</span>';
    html += '\n';
    html += '<span style="color:' + LW_COLOR + ';">' + renderHpBar(lwHp, lwInitHp, LW_NAME, LW_COLOR) + '</span>';

    return html;
}

// ─── 战斗日志生成 ──────────────────────────
function getFightLog(hgHp, lwHp, prevHgHp, prevLwHp, frameIdx) {
    if (frameIdx === 0) {
        return '白髯鸡仙将两只鸡抱起，拿出铁啄熟练地安上，把鸡放进栏内。';
    }

    let hgDmg = prevHgHp - hgHp;
    let lwDmg = prevLwHp - lwHp;

    let logs = [];

    if (lwDmg > 0) {
        if (lwDmg > 40) {
            logs.push('红冠鸡暴击绿尾鸡，造成' + lwDmg + '点伤害！');
        } else if (lwDmg === 0) {
            logs.push('绿尾鸡闪避了红冠鸡的攻击！');
        } else {
            logs.push('红冠鸡啄向绿尾鸡，造成' + lwDmg + '点伤害。');
        }
    }

    if (hgDmg > 0) {
        if (hgDmg > 40) {
            logs.push('绿尾鸡暴击红冠鸡，造成' + hgDmg + '点伤害！');
        } else if (hgDmg === 0) {
            logs.push('红冠鸡闪避了绿尾鸡的攻击！');
        } else {
            logs.push('绿尾鸡啄向红冠鸡，造成' + hgDmg + '点伤害。');
        }
    }

    if (logs.length === 0) {
        return '第' + frameIdx + '回合：双方僵持不下。';
    }

    return '第' + frameIdx + '回合：' + logs.join(' ');
}

// ─── 斗鸡动画本地播放 ─────────────────────
function startFightAnimation(frames, startFrameIndex, frameInterval, initHgHp, initLwHp) {
    fightFrames = frames;
    localFrameIndex = startFrameIndex;
    hgInitHp = initHgHp;
    lwInitHp = initLwHp;

    // 立即渲染当前帧
    renderFightFrame();

    // 启动本地定时器 (每 frameInterval 秒推进一帧)
    if (fightAnimTimer) clearInterval(fightAnimTimer);
    if (frames.length > 1) {
        fightAnimTimer = setInterval(function() {
            let prevFrame = fightFrames[localFrameIndex] || [hgInitHp, lwInitHp];
            localFrameIndex++;
            if (localFrameIndex >= frames.length - 1) {
                localFrameIndex = frames.length - 1;
                // 最后一帧，停止本地定时器 (等 API 推进到 status 3)
                clearInterval(fightAnimTimer);
                fightAnimTimer = null;
            }
            renderFightFrame(prevFrame);
        }, frameInterval * 1000);
    }
}

function renderFightFrame(prevFrame) {
    if (!fightFrames || fightFrames.length === 0) return;
    let frame = fightFrames[localFrameIndex] || [hgInitHp, lwInitHp];
    let hgHp = frame[0];
    let lwHp = frame[1];

    let pre = document.getElementById('fightArena');
    pre.innerHTML = renderArena(hgHp, lwHp);

    // 战斗日志
    let logEl = document.getElementById('fightLog');
    if (prevFrame) {
        logEl.innerHTML = getFightLog(hgHp, lwHp, prevFrame[0], prevFrame[1], localFrameIndex);
    } else if (localFrameIndex === 0) {
        logEl.innerHTML = getFightLog(hgHp, lwHp, hgHp, lwHp, 0);
    }
}

function stopFightAnimation() {
    if (fightAnimTimer) {
        clearInterval(fightAnimTimer);
        fightAnimTimer = null;
    }
    fightFrames = null;
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
    if (!selected) { showMsg('请选择押鸡种类'); return; }
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

    fetch('douji_api.php?action=bet', { method: 'POST', body: formData })
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
        btn.textContent = '押鸡';
    });
}

function showMsg(msg) {
    document.getElementById('msgArea').textContent = msg;
}

// ─── 轮询 ─────────────────────────────────
function poll() {
    fetch('douji_api.php?action=status')
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
    let arena = document.getElementById('fightArena');
    let betArea = document.getElementById('betArea');
    let betPlacedArea = document.getElementById('betPlacedArea');
    let resultArea = document.getElementById('resultArea');
    let fightLog = document.getElementById('fightLog');

    // 默认隐藏所有区域
    betArea.style.display = 'none';
    betPlacedArea.style.display = 'none';
    resultArea.style.display = 'none';

    if (r.status === 1) {
        // ── 押注中 ──
        stopFightAnimation();
        arena.style.display = 'none';
        fightLog.innerHTML = '';

        bar.innerHTML = '<span style="color:#FFD700;">押注中</span> — 剩余 ' + r.bettingRemaining + ' 秒';

        // 显示鸡的初始HP
        if (r.hgInitHp && r.lwInitHp) {
            hgInitHp = r.hgInitHp;
            lwInitHp = r.lwInitHp;
            arena.style.display = 'block';
            arena.innerHTML = renderArena(r.hgInitHp, r.lwInitHp);
            fightLog.innerHTML = '白髯鸡仙从左右鸡笼里各拿出一只红冠鸡和一只绿尾鸡。';
        }

        if (!myBet) {
            betArea.style.display = 'block';
        } else {
            betPlacedArea.style.display = 'block';
            document.getElementById('betPlacedText').innerHTML =
                '已押 <strong style="color:' + (myBet.kind === 'hg' ? HG_COLOR : LW_COLOR) + ';">' +
                myBet.kindName + '</strong> ' + myBet.amount + '文铜钱，等待开斗...';
        }

    } else if (r.status === 2) {
        // ── 斗鸡中: 文字动画 ──
        bar.innerHTML = '<span style="color:#FF6347;">斗鸡中</span> — 白髯鸡仙说声：停押，斗鸡！';
        betArea.style.display = 'none';

        // 首次进入或帧数据更新时启动本地动画
        if (!fightFrames || (r.fightFrames && r.fightFrames.length > fightFrames.length)) {
            if (r.fightFrames && r.fightFrames.length > 0) {
                hgInitHp = r.hgInitHp || hgInitHp;
                lwInitHp = r.lwInitHp || lwInitHp;
                startFightAnimation(r.fightFrames, r.frameIndex || 0, r.fightFrameInterval || 1, hgInitHp, lwInitHp);
            }
        } else if (fightFrames && r.frameIndex !== undefined) {
            // 同步: 如果 API 的帧索引超前本地，跳到 API 帧索引
            if (r.frameIndex > localFrameIndex) {
                localFrameIndex = r.frameIndex;
                renderFightFrame();
            }
        }

        arena.style.display = 'block';

        if (myBet) {
            betPlacedArea.style.display = 'block';
            document.getElementById('betPlacedText').innerHTML =
                '已押 <strong style="color:' + (myBet.kind === 'hg' ? HG_COLOR : LW_COLOR) + ';">' +
                myBet.kindName + '</strong> ' + myBet.amount + '文铜钱，观战中...';
        }

    } else if (r.status === 3) {
        // ── 已结算 ──
        stopFightAnimation();

        let resultText;
        if (r.isDoubleLoss) {
            resultText = '白髯鸡仙叹息道：双败赔本！';
        } else {
            resultText = '白髯鸡仙说道：' + r.winnerName + '获胜！';
        }

        bar.innerHTML = '<span style="color:#00FF00;">已结算</span> — ' + resultText +
            ' — 下一轮 ' + r.settleRemaining + ' 秒后开始';

        // 渲染最终斗鸡场
        arena.style.display = 'block';
        if (r.hgFinalHp !== undefined && r.lwFinalHp !== undefined) {
            hgInitHp = r.hgInitHp || hgInitHp;
            lwInitHp = r.lwInitHp || lwInitHp;
            arena.innerHTML = renderArena(r.hgFinalHp, r.lwFinalHp);
            fightLog.innerHTML = resultText;
        }

        // 显示结算结果
        if (myBet && myBet.isSettled) {
            resultArea.style.display = 'block';
            if (myBet.isWin) {
                resultArea.style.borderColor = '#00FF00';
                resultArea.style.background = '#0a2a0a';
                resultArea.innerHTML =
                    '<p style="margin:0; color:#00FF00; font-size:16px; font-weight:bold;">中奖！</p>' +
                    '<p style="margin:4px 0;">押 ' + myBet.kindName + ' | ' + r.winnerName + '获胜</p>' +
                    '<p style="margin:0; color:#FFD700;">赢得 ' + myBet.winAmount + '文 | 手续费 ' + myBet.commission + '文</p>' +
                    '<p style="margin:0; color:#00FF00; font-weight:bold;">净赚 +' + myBet.netWin + '文</p>';
            } else {
                resultArea.style.borderColor = '#FF6666';
                resultArea.style.background = '#2a0a0a';
                let lossReason = r.isDoubleLoss ? '双败赔本' : (r.winnerName + '获胜');
                resultArea.innerHTML =
                    '<p style="margin:0; color:#FF6666; font-size:16px; font-weight:bold;">未中</p>' +
                    '<p style="margin:4px 0;">押 ' + myBet.kindName + ' | ' + lossReason + '</p>' +
                    '<p style="margin:0; color:#FF6666;">损失 -' + myBet.amount + '文</p>';
            }
        } else if (!myBet) {
            resultArea.style.display = 'block';
            resultArea.style.borderColor = '#555';
            resultArea.style.background = '#1a1a2e';
            let resultText2 = r.isDoubleLoss ? '双败赔本' : (r.winnerName + '获胜');
            resultArea.innerHTML =
                '<p style="margin:0; color:#aaa;">本轮未下注</p>' +
                '<p style="margin:0; color:#FFD700;">结果: ' + resultText2 + '</p>';
        }

    } else {
        stopFightAnimation();
        arena.style.display = 'none';
        fightLog.innerHTML = '';
        bar.textContent = '等待开始...';
    }

    // 本轮下注列表
    let allBetsArea = document.getElementById('allBetsArea');
    let allBetsList = document.getElementById('allBetsList');
    if (data.allBets && data.allBets.length > 0) {
        allBetsArea.style.display = 'block';
        let html = '';
        data.allBets.forEach(function(b) {
            let color = b.kindName === '红冠鸡' ? HG_COLOR : LW_COLOR;
            html += '<span style="margin-right:12px; color:' + color + ';">' +
                    b.charName + ' 押' + b.kindName + ' ' + b.amount + '文</span>';
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
