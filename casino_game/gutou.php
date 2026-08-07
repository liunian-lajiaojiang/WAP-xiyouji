<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 骨骰房主页面
 * 多人共享轮次 + 逐枚开骰文字动画 + 统一铜钱体系
 * 移植自 LPC: d/city/duchang2.c
 *
 * 逐枚开骰动画: 移植 LPC display_gutou() 的 ASCII 骰面艺术
 *   ┌───┐
 *   │　●　│
 *   │　●　│
 *   │●　●│
 *   └───┘
 * 前端从 API 获取骰子结果，按时间逐枚展示（第6秒第一枚，第12秒第二枚）
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
    <title>骨骰房_<?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <style>
        #diceArea {
            font-family: 'Courier New', 'Noto Sans Mono CJK SC', monospace;
            font-size: 14px;
            line-height: 1.3;
            white-space: pre;
            text-align: center;
            margin: 8px 0;
            padding: 15px;
            border: 1px solid #555;
            border-radius: 6px;
            background: #111;
            color: #ccc;
        }
        .bet-btn {
            display: inline-block;
            padding: 8px 15px;
            margin: 4px;
            border: 2px solid;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            background: transparent;
            transition: all 0.2s;
        }
        .bet-btn:hover { opacity: 0.8; }
        .bet-btn.selected { background: rgba(255,255,255,0.15); }
        .bet-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        #countdown {
            font-size: 1.3em;
            font-weight: bold;
            text-align: center;
            margin: 8px 0;
        }
        #messageArea { text-align: center; margin: 8px 0; min-height: 20px; }
        .all-bets { font-size: 12px; color: #aaa; margin: 5px 0; }
    </style>
</head>
<body>
<p>
    <a href="javascript:location.reload();">骨骰房</a>&ensp;
    <a href="../functions/room.php">返回游戏</a>&ensp;
    <a href="gutou_rule.php">规则</a>&ensp;
    <a href="gutou_history.php">下注历史</a>
</p>

<p>铜钱: <span id="coinBalance"><?= $coinBalance ?>文</span></p>

<div id="countdown"></div>
<div id="messageArea"></div>

<!-- ASCII 骰面展示区 -->
<div id="diceArea"></div>

<!-- 头彩预告区 -->
<div id="headPrizeArea" style="text-align:center; margin:8px 0; font-weight:bold; color:#FF4444;"></div>

<!-- 押注区 -->
<div id="betSection" style="display:none; text-align:center; margin:10px 0;">
    <p style="font-weight:bold;">选择押注类型：</p>
    <div>
        <button class="bet-btn" data-kind="tc" style="border-color:#FF4444; color:#FF4444;" onclick="selectBet('tc')">
            头彩 (1赢36)
        </button>
        <button class="bet-btn" data-kind="sd" style="border-color:#FFA500; color:#FFA500;" onclick="selectBet('sd')">
            双对 (1赢12)
        </button>
        <br>
        <button class="bet-btn" data-kind="qx" style="border-color:#66BBFF; color:#66BBFF;" onclick="selectBet('qx')">
            七星 (1赢6)
        </button>
        <button class="bet-btn" data-kind="sx" style="border-color:#90EE90; color:#90EE90;" onclick="selectBet('sx')">
            散星 (1赢3)
        </button>
    </div>
    <div id="betTypeDesc" style="margin:8px 0; font-size:13px; color:#888;"></div>
    <div style="margin:8px 0;">
        <button onclick="setAmount(100)">100</button>
        <button onclick="setAmount(500)">500</button>
        <button onclick="setAmount(1000)">1000</button>
        <button onclick="setAmount(0)" id="allInBtn">全押</button>
    </div>
    <input type="number" id="betAmount" placeholder="输入铜钱数" min="1" style="width:120px;">
    <button id="submitBetBtn" onclick="submitBet()" disabled>确认下注</button>
</div>

<!-- 本轮下注信息 -->
<div id="myBetInfo" style="display:none; text-align:center; margin:8px 0; padding:8px; border:1px solid #555; border-radius:4px;"></div>

<!-- 所有玩家下注 -->
<div id="allBetsInfo" class="all-bets" style="text-align:center;"></div>

<br>
<a href="../functions/room.php">返回游戏</a>

<script>
// ─── 配置 ─────────────────────────────────────────────
const POLL_INTERVAL = 2000; // 轮询间隔 2秒
const DICE1_REVEAL_TIME = 6;  // 第一枚骰子开出时间(秒)
const DICE2_REVEAL_TIME = 12; // 第二枚骰子开出时间(秒)

const GUTOU_TYPES = {
    tc: { name: '头彩', odds: 36, color: '#FF4444', desc: '两骰与预告号完全一致' },
    sd: { name: '双对', odds: 12, color: '#FFA500', desc: '两骰号相同且为偶数' },
    qx: { name: '七星', odds: 6,  color: '#66BBFF', desc: '两骰之和为七' },
    sx: { name: '散星', odds: 3,  color: '#90EE90', desc: '两骰之和为三、五、九、十一' },
};

const CN_NUM = ['', '一', '二', '三', '四', '五', '六'];

// ─── 状态变量 ─────────────────────────────────────────
let selectedKind = null;
let currentRound = null;
let myBetData = null;
let coinBalance = <?= $coinBalance ?>;
let pollTimer = null;
let revealTimer = null;
let lastStatus = -1;

// ─── ASCII 骰面渲染 (移植自 LPC display_gutou) ────────
function renderDiceFace(n) {
    const faces = {
        1: '┌───┐\n│　　　│\n│　●　│\n│　　　│\n└───┘',
        2: '┌───┐\n│　　　│\n│●　●│\n│　　　│\n└───┘',
        3: '┌───┐\n│●　　│\n│　●　│\n│　　●│\n└───┘',
        4: '┌───┐\n│●　●│\n│　　　│\n│●　●│\n└───┘',
        5: '┌───┐\n│●　●│\n│　●　│\n│●　●│\n└───┘',
        6: '┌───┐\n│●　●│\n│●　●│\n│●　●│\n└───┘',
    };
    return faces[n] || '';
}

function renderShakingCup() {
    return '┌───┐    ┌───┐\n' +
           '│？？？│  │？？？│\n' +
           '│　摇　│  │　摇　│\n' +
           '│？？？│  │？？？│\n' +
           '└───┘    └───┘';
}

function renderHiddenCup() {
    return '┌───┐\n' +
           '│？？？│\n' +
           '│　？　│\n' +
           '│？？？│\n' +
           '└───┘';
}

// ─── UI 更新 ──────────────────────────────────────────
function selectBet(kind) {
    selectedKind = kind;
    document.querySelectorAll('.bet-btn').forEach(btn => {
        btn.classList.toggle('selected', btn.dataset.kind === kind);
    });
    const t = GUTOU_TYPES[kind];
    document.getElementById('betTypeDesc').innerHTML =
        '<span style="color:' + t.color + ';">' + t.name + '</span>：' + t.desc + '（赔率 1赢' + t.odds + '）';
    updateSubmitBtn();
}

function setAmount(amount) {
    if (amount === 0) amount = coinBalance;
    document.getElementById('betAmount').value = amount;
    updateSubmitBtn();
}

function updateSubmitBtn() {
    const amount = parseInt(document.getElementById('betAmount').value) || 0;
    const btn = document.getElementById('submitBetBtn');
    btn.disabled = (!selectedKind || amount <= 0 || amount > coinBalance || myBetData !== null);
}

function updateCoinBalance(newBalance) {
    coinBalance = newBalance;
    document.getElementById('coinBalance').textContent = newBalance + '文';
    document.getElementById('allInBtn').onclick = function() { setAmount(coinBalance); };
}

// ─── 下注提交 ─────────────────────────────────────────
function submitBet() {
    const amount = parseInt(document.getElementById('betAmount').value) || 0;
    if (!selectedKind || amount <= 0) return;

    document.getElementById('submitBetBtn').disabled = true;
    document.getElementById('submitBetBtn').textContent = '下注中...';

    const formData = new FormData();
    formData.append('action', 'bet');
    formData.append('kind', selectedKind);
    formData.append('amount', amount);

    fetch('gutou_api.php?action=bet', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showMessage(data.message, '#00FF00');
            updateCoinBalance(data.coinBalance);
            fetchStatus(); // 立即刷新状态
        } else {
            showMessage(data.message, '#FF6666');
        }
        document.getElementById('submitBetBtn').disabled = false;
        document.getElementById('submitBetBtn').textContent = '确认下注';
    })
    .catch(err => {
        showMessage('网络错误，请重试', '#FF6666');
        document.getElementById('submitBetBtn').disabled = false;
        document.getElementById('submitBetBtn').textContent = '确认下注';
    });
}

function showMessage(msg, color) {
    const el = document.getElementById('messageArea');
    el.innerHTML = '<span style="color:' + (color || '#ccc') + ';">' + msg + '</span>';
}

// ─── 状态渲染 ─────────────────────────────────────────
function renderState(data) {
    const r = data.round;
    const oldStatus = lastStatus;
    lastStatus = r.status;

    // 更新铜钱
    if (data.coinBalance !== undefined) {
        updateCoinBalance(data.coinBalance);
    }

    // 更新我的下注
    myBetData = data.myBet;

    // 倒计时
    const cd = document.getElementById('countdown');
    const diceArea = document.getElementById('diceArea');
    const headPrize = document.getElementById('headPrizeArea');
    const betSection = document.getElementById('betSection');
    const myBetInfo = document.getElementById('myBetInfo');

    // 状态 1: 押注中
    if (r.status === 1) {
        cd.innerHTML = '<span style="color:#FFD700;">押注倒计时: <strong>' + r.bettingRemaining + '</strong> 秒</span>';
        headPrize.innerHTML = '庄东叫道：头彩骰号' + CN_NUM[r.bigDice[0]] + CN_NUM[r.bigDice[1]] + '！';

        // 显示预告骰面
        diceArea.innerHTML =
            '庄东将两枚玉骰往银盘中一撒：\n\n' +
            renderDiceFace(r.bigDice[0]) + '    ' + renderDiceFace(r.bigDice[1]) +
            '\n\n头彩号: ' + r.bigDice[0] + ' 和 ' + r.bigDice[1];

        // 显示押注区
        if (!myBetData) {
            betSection.style.display = 'block';
        } else {
            betSection.style.display = 'none';
        }
        updateSubmitBtn();
    }

    // 状态 2: 开骰中（逐枚开骰）
    else if (r.status === 2) {
        cd.innerHTML = '<span style="color:#FFA500;">开骰中... </span>';
        headPrize.innerHTML = '本盘头彩骰号: ' + CN_NUM[r.bigDice[0]] + CN_NUM[r.bigDice[1]] +
                              ' （封盘停押！）';
        betSection.style.display = 'none';

        // 逐枚开骰动画
        renderDiceReveal(r);
    }

    // 状态 3: 已结算
    else if (r.status === 3) {
        cd.innerHTML = '<span style="color:#90EE90;">结算倒计时: <strong>' + r.settleRemaining + '</strong> 秒</span>';
        headPrize.innerHTML = '本盘头彩骰号: ' + CN_NUM[r.bigDice[0]] + CN_NUM[r.bigDice[1]];
        betSection.style.display = 'none';

        // 显示完整结果
        let resultText = '庄东叫道：' + CN_NUM[r.dice1] + CN_NUM[r.dice2] + '……' +
                         r.winnerName + '！\n\n';
        diceArea.innerHTML = resultText +
            renderDiceFace(r.dice1) + '    ' + renderDiceFace(r.dice2) +
            '\n\n点数: ' + r.dice1 + ' + ' + r.dice2 + ' = ' + r.diceSum;

        // 显示我的下注结果
        if (myBetData && myBetData.isSettled) {
            if (myBetData.isWin) {
                myBetInfo.innerHTML = '<span style="color:#00FF00; font-weight:bold;">' +
                    '恭喜！押' + myBetData.kindName + ' ' + myBetData.amount + '文 → ' +
                    '赢得 ' + myBetData.winAmount + '文（手续费' + myBetData.commission + '文）' +
                    ' 净赚 +' + myBetData.netWin + '文</span>';
            } else {
                myBetInfo.innerHTML = '<span style="color:#FF6666;">' +
                    '押' + myBetData.kindName + ' ' + myBetData.amount + '文 → 未中，损失 ' + myBetData.amount + '文</span>';
            }
            myBetInfo.style.display = 'block';
        }
    }

    // 状态 0: 空闲（过渡）
    else {
        cd.innerHTML = '<span style="color:#888;">等待新轮次...</span>';
        headPrize.innerHTML = '';
        diceArea.innerHTML = '';
        betSection.style.display = 'none';
        myBetInfo.style.display = 'none';
    }

    // 状态变化时清空消息
    if (oldStatus !== -1 && oldStatus !== r.status) {
        if (r.status === 1) showMessage('新轮次开始，请下注！', '#FFD700');
        else if (r.status === 2) showMessage('封盘停押！庄东摇骰中...', '#FFA500');
        else if (r.status === 3) {
            if (myBetData && myBetData.isSettled) {
                showMessage(myBetData.isWin ? '中奖了！' : '未中奖', myBetData.isWin ? '#00FF00' : '#FF6666');
            } else {
                showMessage('本轮结算完毕', '#ccc');
            }
        }
    }

    // 显示所有玩家下注
    renderAllBets(data.allBets);
}

// ─── 逐枚开骰动画 ─────────────────────────────────────
function renderDiceReveal(r) {
    const diceArea = document.getElementById('diceArea');
    const elapsed = r.revealElapsed;

    if (elapsed < DICE1_REVEAL_TIME) {
        // 摇骰阶段
        diceArea.innerHTML = '庄东将两枚玉骰扔进金盅，摇将起来...\n\n' + renderShakingCup();
    } else if (elapsed < DICE2_REVEAL_TIME) {
        // 第一枚已开
        diceArea.innerHTML = '金盅倒扣在银盘上，第一枚玉骰滚了出来：\n\n' +
            renderDiceFace(r.dice1) + '    ' + renderHiddenCup() +
            '\n\n第一枚: ' + r.dice1 + ' 点';
    } else {
        // 两枚都开了
        diceArea.innerHTML = '第二枚玉骰也滚了出来：\n\n' +
            renderDiceFace(r.dice1) + '    ' + renderDiceFace(r.dice2) +
            '\n\n第一枚: ' + r.dice1 + ' 点  第二枚: ' + r.dice2 + ' 点' +
            '\n两骰之和: ' + (r.dice1 + r.dice2) +
            '\n\n等待结算...';
    }
}

// ─── 所有玩家下注列表 ─────────────────────────────────
function renderAllBets(allBets) {
    const el = document.getElementById('allBetsInfo');
    if (!allBets || allBets.length === 0) {
        el.innerHTML = '';
        return;
    }
    let html = '本轮下注: ';
    allBets.forEach(function(b) {
        html += b.charName + '押' + b.kindName + b.amount + '文  ';
    });
    el.innerHTML = html;
}

// ─── 轮询 ─────────────────────────────────────────────
function fetchStatus() {
    fetch('gutou_api.php?action=status')
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            renderState(data);
        }
    })
    .catch(err => console.error('poll error:', err));
}

function startPolling() {
    if (pollTimer) clearInterval(pollTimer);
    fetchStatus();
    pollTimer = setInterval(fetchStatus, POLL_INTERVAL);
}

// ─── 初始化 ───────────────────────────────────────────
document.getElementById('allInBtn').onclick = function() { setAmount(coinBalance); };
document.getElementById('betAmount').addEventListener('input', updateSubmitBtn);
startPolling();
</script>
</body>
</html>
