<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 押签房主页面
 * 多人共享轮次 + 逐根抽签动画 + 统一铜钱体系
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
    <title>押签房_<?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
</head>
<body>
<p>
    <a href="javascript:location.reload();">🎴押签房</a>&ensp;
    <a href="../functions/room.php">返回游戏</a>&ensp;
    <a href="yaqian_rule.php">规则</a>&ensp;
    <a href="yaqian_history.php">下注历史</a>
</p>

<p>💰铜钱: <span id="coinBalance"><?= $coinBalance ?>文</span></p>

<!-- 状态显示区 -->
<p id="statusBar" style="font-weight:bold;">加载中...</p>

<!-- 抽签动画区 -->
<div id="signArea" style="display:none; text-align:center; margin:10px 0; padding:10px; border:1px solid #444; border-radius:8px;">
    <p style="margin-bottom:8px;">签客正在从镶金黑盒中抽签...</p>
    <div style="display:flex; justify-content:center; gap:8px;">
        <span class="sign-slot" id="sign-0" style="display:inline-block; width:50px; height:60px; line-height:60px; border:2px solid #8B6914; border-radius:6px; font-size:20px; font-weight:bold; background:#1a1a2e; color:#FFD700;">？</span>
        <span class="sign-slot" id="sign-1" style="display:inline-block; width:50px; height:60px; line-height:60px; border:2px solid #8B6914; border-radius:6px; font-size:20px; font-weight:bold; background:#1a1a2e; color:#FFD700;">？</span>
        <span class="sign-slot" id="sign-2" style="display:inline-block; width:50px; height:60px; line-height:60px; border:2px solid #8B6914; border-radius:6px; font-size:20px; font-weight:bold; background:#1a1a2e; color:#FFD700;">？</span>
        <span class="sign-slot" id="sign-3" style="display:inline-block; width:50px; height:60px; line-height:60px; border:2px solid #8B6914; border-radius:6px; font-size:20px; font-weight:bold; background:#1a1a2e; color:#FFD700;">？</span>
        <span class="sign-slot" id="sign-4" style="display:inline-block; width:50px; height:60px; line-height:60px; border:2px solid #8B6914; border-radius:6px; font-size:20px; font-weight:bold; background:#1a1a2e; color:#FFD700;">？</span>
    </div>
</div>

<!-- 押注区 -->
<div id="betArea" style="display:none;">
    <p style="font-weight:bold;">选择押签种类</p>
    <div style="display:grid; grid-template-columns:repeat(2,1fr); gap:6px; margin:8px 0;">
        <label style="display:block; padding:6px; border:1px solid #555; border-radius:6px; text-align:center;">
            <input type="radio" name="betKind" value="dqq" style="display:none;">
            <span style="font-weight:bold; color:#FFD700;">大乾签</span><br>
            <span style="font-size:12px; color:#999;">5根全乾 | 赔32倍</span>
        </label>
        <label style="display:block; padding:6px; border:1px solid #555; border-radius:6px; text-align:center;">
            <input type="radio" name="betKind" value="dkq" style="display:none;">
            <span style="font-weight:bold; color:#FFD700;">大坤签</span><br>
            <span style="font-size:12px; color:#999;">5根全坤 | 赔32倍</span>
        </label>
        <label style="display:block; padding:6px; border:1px solid #555; border-radius:6px; text-align:center;">
            <input type="radio" name="betKind" value="xqq" style="display:none;">
            <span style="font-weight:bold; color:#C0C0C0;">小乾签</span><br>
            <span style="font-size:12px; color:#999;">连续4乾 | 赔16倍</span>
        </label>
        <label style="display:block; padding:6px; border:1px solid #555; border-radius:6px; text-align:center;">
            <input type="radio" name="betKind" value="xkq" style="display:none;">
            <span style="font-weight:bold; color:#C0C0C0;">小坤签</span><br>
            <span style="font-size:12px; color:#999;">连续4坤 | 赔16倍</span>
        </label>
        <label style="display:block; padding:6px; border:1px solid #555; border-radius:6px; text-align:center;">
            <input type="radio" name="betKind" value="qq" style="display:none;">
            <span style="font-weight:bold; color:#CD7F32;">乾签</span><br>
            <span style="font-size:12px; color:#999;">任意≥3乾 | 赔2倍</span>
        </label>
        <label style="display:block; padding:6px; border:1px solid #555; border-radius:6px; text-align:center;">
            <input type="radio" name="betKind" value="kq" style="display:none;">
            <span style="font-weight:bold; color:#CD7F32;">坤签</span><br>
            <span style="font-size:12px; color:#999;">任意≥3坤 | 赔2倍</span>
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
    <button type="button" id="betBtn" onclick="placeBet()" style="padding:3px 12px;">🎴 押签</button>
    <p style="font-size:12px; color:#999;">手续费 5% | 每轮限押一次</p>
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
let pollTimer = null;
let currentRoundId = 0;
let lastStatus = -1;
let lastRevealedCount = 0;
let isPlacingBet = false;

// 选中押签种类高亮
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
    if (!selected) {
        showMsg('请选择押签种类');
        return;
    }
    let amount = parseInt(document.getElementById('betAmount').value) || 0;
    if (amount <= 0) {
        showMsg('请输入有效的下注金额');
        return;
    }

    isPlacingBet = true;
    let btn = document.getElementById('betBtn');
    btn.disabled = true;
    btn.textContent = '押注中...';

    let formData = new FormData();
    formData.append('action', 'bet');
    formData.append('kind', selected.value);
    formData.append('amount', amount);

    fetch('yaqian_api.php?action=bet', {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            showMsg('');
            document.getElementById('coinBalance').textContent = data.coinBalance + '文';
            poll(); // 立即刷新状态
        } else {
            showMsg(data.message);
        }
    })
    .catch(function(err) {
        showMsg('网络错误，请重试');
    })
    .finally(function() {
        isPlacingBet = false;
        btn.disabled = false;
        btn.textContent = '🎴 押签';
    });
}

function showMsg(msg) {
    document.getElementById('msgArea').textContent = msg;
}

function poll() {
    fetch('yaqian_api.php?action=status')
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.success) {
            schedulePoll(3000);
            return;
        }
        updateUI(data);
        let interval = (data.round.status === 2) ? 1000 : 2000;
        schedulePoll(interval);
    })
    .catch(function(err) {
        schedulePoll(3000);
    });
}

function schedulePoll(ms) {
    if (pollTimer) clearTimeout(pollTimer);
    pollTimer = setTimeout(poll, ms);
}

function updateUI(data) {
    let r = data.round;
    let myBet = data.myBet;

    // 更新铜钱
    document.getElementById('coinBalance').textContent = data.coinBalance + '文';

    // 状态栏
    let bar = document.getElementById('statusBar');
    if (r.status === 1) {
        bar.innerHTML = '🕐 <span style="color:#FFD700;">押注中</span> — 剩余 ' + r.bettingRemaining + ' 秒';
    } else if (r.status === 2) {
        bar.innerHTML = '🎴 <span style="color:#FF6347;">开奖中</span> — 签客正在抽签...';
    } else if (r.status === 3) {
        bar.innerHTML = '✅ <span style="color:#00FF00;">已开奖</span> — ' + (r.winKindName || '') + ' — 下一轮 ' + r.settleRemaining + ' 秒后开始';
    } else {
        bar.textContent = '等待开始...';
    }

    // 抽签动画区
    let signArea = document.getElementById('signArea');
    if (r.status === 2 || r.status === 3) {
        signArea.style.display = 'block';
        let signs = (r.status >= 3) ? r.allSigns : r.visibleSigns;
        for (let i = 0; i < 5; i++) {
            let slot = document.getElementById('sign-' + i);
            if (i < signs.length) {
                let isQian = signs[i] === '1';
                if (slot.textContent !== (isQian ? '乾' : '坤')) {
                    slot.textContent = isQian ? '乾' : '坤';
                    slot.style.color = isQian ? '#FFD700' : '#87CEEB';
                    slot.style.borderColor = isQian ? '#FFD700' : '#87CEEB';
                    slot.style.transition = 'transform 0.3s';
                    slot.style.transform = 'scale(1.3)';
                    setTimeout(function() { slot.style.transform = 'scale(1)'; }, 300);
                }
            } else {
                slot.textContent = '？';
                slot.style.color = '#FFD700';
                slot.style.borderColor = '#8B6914';
            }
        }
    } else {
        signArea.style.display = 'none';
        // 重置签槽
        for (let i = 0; i < 5; i++) {
            document.getElementById('sign-' + i).textContent = '？';
        }
    }

    // 押注区
    let betArea = document.getElementById('betArea');
    let betPlacedArea = document.getElementById('betPlacedArea');
    if (r.status === 1 && !myBet) {
        betArea.style.display = 'block';
        betPlacedArea.style.display = 'none';
    } else {
        betArea.style.display = 'none';
        if (myBet && !myBet.isSettled) {
            betPlacedArea.style.display = 'block';
            document.getElementById('betPlacedText').innerHTML =
                '已押 <strong style="color:#FFD700;">' + myBet.kindName + '</strong> ' + myBet.amount + '文铜钱，等待开奖...';
        } else {
            betPlacedArea.style.display = 'none';
        }
    }

    // 结算结果
    let resultArea = document.getElementById('resultArea');
    if (r.status === 3 && myBet && myBet.isSettled) {
        resultArea.style.display = 'block';
        if (myBet.isWin) {
            resultArea.style.borderColor = '#00FF00';
            resultArea.style.background = '#0a2a0a';
            resultArea.innerHTML =
                '<p style="margin:0; color:#00FF00; font-size:16px; font-weight:bold;">🎉 中奖！</p>' +
                '<p style="margin:4px 0;">押 ' + myBet.kindName + ' | 开 ' + (r.winKindName || '') + '</p>' +
                '<p style="margin:0; color:#FFD700;">赢得 ' + myBet.winAmount + '文 | 手续费 ' + myBet.commission + '文</p>' +
                '<p style="margin:0; color:#00FF00; font-weight:bold;">净赚 +' + myBet.netWin + '文</p>';
        } else {
            resultArea.style.borderColor = '#FF6666';
            resultArea.style.background = '#2a0a0a';
            resultArea.innerHTML =
                '<p style="margin:0; color:#FF6666; font-size:16px; font-weight:bold;">❌ 未中</p>' +
                '<p style="margin:4px 0;">押 ' + myBet.kindName + ' | 开 ' + (r.winKindName || '') + '</p>' +
                '<p style="margin:0; color:#FF6666;">损失 -' + myBet.amount + '文</p>';
        }
    } else if (r.status === 3 && !myBet) {
        resultArea.style.display = 'block';
        resultArea.style.borderColor = '#555';
        resultArea.style.background = '#1a1a2e';
        resultArea.innerHTML =
            '<p style="margin:0; color:#aaa;">本轮未下注</p>' +
            '<p style="margin:0; color:#FFD700;">开奖结果: ' + (r.winKindName || '') + '</p>';
    } else {
        resultArea.style.display = 'none';
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

    // 状态变化时重置
    if (currentRoundId !== r.id) {
        currentRoundId = r.id;
        lastRevealedCount = 0;
        showMsg('');
    }
    lastStatus = r.status;
}

// 启动轮询
poll();
</script>
</body>
</html>
