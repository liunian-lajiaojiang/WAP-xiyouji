<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 骰子房主页面
 * 庄家制 + 双骰点数 + 多人轮流掷骰 + ASCII骰面展示
 * 移植自 LPC: d/city/shaizi-room.c
 *
 * 双骰点数规则:
 *   对子(两骰相同): 100+面值，如两个4=104(四对)
 *   散点(两骰不同): (骰1+骰2)%10，如3+5=8(八点)
 *   蹩十(模10为0): 0(最小)
 *   对子 > 散点; 对子间比面值; 散点间比模10值
 *
 * 庄家制:
 *   玩家可坐庄(交保证金1000文)，设赌注上限(500-10000文)
 *   庄家最后掷骰，与每个玩家逐一比点数
 *   30秒无庄家 → NPC公孙大娘自动坐庄
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
    <title>骰子房_<?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <style>
        #diceArea {
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
        #countdown {
            font-size: 1.2em;
            font-weight: bold;
            text-align: center;
            margin: 8px 0;
        }
        #messageArea { text-align: center; margin: 8px 0; min-height: 20px; }
        .all-bets { font-size: 12px; color: #aaa; margin: 5px 0; }
        .action-btn {
            display: inline-block;
            padding: 8px 18px;
            margin: 4px;
            border: 2px solid #FFD700;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            color: #FFD700;
            background: transparent;
            transition: all 0.2s;
        }
        .action-btn:hover { opacity: 0.8; }
        .action-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        .dealer-info {
            text-align: center;
            margin: 8px 0;
            padding: 8px;
            border: 1px solid #444;
            border-radius: 4px;
            background: rgba(255,215,0,0.05);
        }
        .roll-entry {
            margin: 6px 0;
            padding: 6px;
            border-bottom: 1px dashed #333;
        }
        .roll-entry.dealer { border-color: #FFD700; background: rgba(255,215,0,0.05); }
    </style>
</head>
<body>
<p>
    <a href="javascript:location.reload();">骰子房</a>&ensp;
    <a href="../functions/room.php">返回游戏</a>&ensp;
    <a href="shaizi_rule.php">规则</a>&ensp;
    <a href="shaizi_history.php">下注历史</a>
</p>

<p>铜钱: <span id="coinBalance"><?= $coinBalance ?>文</span></p>

<div id="countdown"></div>
<div id="messageArea"></div>

<!-- 庄家信息区 -->
<div id="dealerInfo" class="dealer-info" style="display:none;"></div>

<!-- ASCII 骰面展示区 -->
<div id="diceArea"></div>

<!-- 坐庄区 -->
<div id="zuozhuangSection" style="display:none; text-align:center; margin:10px 0;">
    <p>还没有庄家，你可以坐庄！</p>
    <p>坐庄需交保证金 <strong style="color:#FFD700;">1000文</strong> 铜钱</p>
    <label>赌注上限:
        <select id="betLimit">
            <option value="500">500文</option>
            <option value="1000">1000文</option>
            <option value="2000" selected>2000文</option>
            <option value="5000">5000文</option>
            <option value="10000">10000文</option>
        </select>
    </label>
    <br>
    <button class="action-btn" onclick="doZuozhuang()">坐庄</button>
</div>

<!-- 下注区 -->
<div id="betSection" style="display:none; text-align:center; margin:10px 0;">
    <p style="font-weight:bold;">下注金额（铜钱）</p>
    <div style="margin:6px 0;">
        <button onclick="setAmount(50)">50</button>
        <button onclick="setAmount(100)">100</button>
        <button onclick="setAmount(500)">500</button>
        <button onclick="setAmount(1000)">1000</button>
        <button onclick="setAmount(0)" id="allInBtn">全押</button>
    </div>
    <input type="number" id="betAmount" placeholder="输入铜钱数" min="1" style="width:120px;">
    <button id="submitBetBtn" class="action-btn" onclick="submitBet()">确认下注</button>
    <p id="betHint" style="font-size:12px; color:#888;"></p>
</div>

<!-- 庄家操作区 -->
<div id="dealerActions" style="display:none; text-align:center; margin:10px 0;">
    <button class="action-btn" id="dealerBetBtn" onclick="dealerBet()">庄家下注开掷</button>
    <button class="action-btn" style="border-color:#FF6666; color:#FF6666;" onclick="doRetire()">让庄</button>
</div>

<!-- 取消下注 -->
<div id="cancelSection" style="display:none; text-align:center; margin:6px 0;">
    <button class="action-btn" style="border-color:#FF6666; color:#FF6666;" onclick="cancelBet()">取消下注</button>
</div>

<!-- 我的下注信息 -->
<div id="myBetInfo" style="display:none; text-align:center; margin:8px 0; padding:8px; border:1px solid #555; border-radius:4px;"></div>

<!-- 所有玩家下注 -->
<div id="allBetsInfo" class="all-bets" style="text-align:center;"></div>

<br>
<a href="../functions/room.php">返回游戏</a>

<script>
// ─── 配置 ─────────────────────────────────────────────
const POLL_INTERVAL = 2000; // 轮询间隔 2秒
const ROLL_INTERVAL = 4;    // 每人掷骰间隔(秒)

// ─── 状态变量 ─────────────────────────────────────────
let coinBalance = <?= $coinBalance ?>;
let pollTimer = null;
let animTimer = null;
let lastStatus = -1;
let currentData = null;
let displayedRollIndex = -1; // 当前已展示到的掷骰序号

// ─── ASCII 骰面渲染 ───────────────────────────────────
function renderDiceFace(n) {
    const faces = {
        0: '┌───┐\n│　　　│\n│　　　│\n│　　　│\n└───┘',
        1: '┌───┐\n│　　　│\n│　●　│\n│　　　│\n└───┘',
        2: '┌───┐\n│　　　│\n│●　●│\n│　　　│\n└───┘',
        3: '┌───┐\n│●　　│\n│　●　│\n│　　●│\n└───┘',
        4: '┌───┐\n│●　●│\n│　　　│\n│●　●│\n└───┘',
        5: '┌───┐\n│●　●│\n│　●　│\n│●　●│\n└───┘',
        6: '┌───┐\n│●　●│\n│●　●│\n│●　●│\n└───┘',
    };
    return faces[n] || '';
}

function renderShakingDice() {
    return '┌───┐    ┌───┐\n' +
           '│？？？│  │？？？│\n' +
           '│　摇　│  │　摇　│\n' +
           '│？？？│  │？？？│\n' +
           '└───┘    └───┘';
}

// ─── UI 更新 ──────────────────────────────────────────
function updateCoinBalance(newBalance) {
    coinBalance = newBalance;
    document.getElementById('coinBalance').textContent = newBalance + '文';
}

function setAmount(amount) {
    if (amount === 0) amount = coinBalance;
    document.getElementById('betAmount').value = amount;
}

function showMessage(msg, color) {
    const el = document.getElementById('messageArea');
    el.innerHTML = '<span style="color:' + (color || '#ccc') + ';">' + msg + '</span>';
}

// ─── API 调用 ─────────────────────────────────────────
function apiCall(action, params) {
    const formData = new FormData();
    formData.append('action', action);
    for (const key in params) {
        formData.append(key, params[key]);
    }
    return fetch('shaizi_api.php?action=' + action, {
        method: 'POST',
        body: formData
    }).then(r => r.json());
}

// 坐庄
function doZuozhuang() {
    const betLimit = document.getElementById('betLimit').value;
    apiCall('zuozhuang', { bet_limit: betLimit })
    .then(data => {
        if (data.success) {
            showMessage(data.message, '#00FF00');
            if (data.coinBalance !== undefined) updateCoinBalance(data.coinBalance);
            fetchStatus();
        } else {
            showMessage(data.message, '#FF6666');
        }
    })
    .catch(() => showMessage('网络错误', '#FF6666'));
}

// 下注
function submitBet() {
    const amount = parseInt(document.getElementById('betAmount').value) || 0;
    if (amount <= 0) return;

    document.getElementById('submitBetBtn').disabled = true;
    document.getElementById('submitBetBtn').textContent = '下注中...';

    apiCall('bet', { bet_amount: amount })
    .then(data => {
        if (data.success) {
            showMessage(data.message, '#00FF00');
            if (data.coinBalance !== undefined) updateCoinBalance(data.coinBalance);
            fetchStatus();
        } else {
            showMessage(data.message, '#FF6666');
        }
        document.getElementById('submitBetBtn').disabled = false;
        document.getElementById('submitBetBtn').textContent = '确认下注';
    })
    .catch(() => {
        showMessage('网络错误', '#FF6666');
        document.getElementById('submitBetBtn').disabled = false;
        document.getElementById('submitBetBtn').textContent = '确认下注';
    });
}

// 庄家下注（触发掷骰）
function dealerBet() {
    const amount = parseInt(document.getElementById('betAmount').value) || 0;
    if (amount <= 0) {
        showMessage('请输入下注金额', '#FF6666');
        return;
    }

    document.getElementById('dealerBetBtn').disabled = true;

    apiCall('bet', { bet_amount: amount })
    .then(data => {
        if (data.success) {
            showMessage(data.message, '#00FF00');
            if (data.coinBalance !== undefined) updateCoinBalance(data.coinBalance);
            fetchStatus();
        } else {
            showMessage(data.message, '#FF6666');
        }
        document.getElementById('dealerBetBtn').disabled = false;
    })
    .catch(() => {
        showMessage('网络错误', '#FF6666');
        document.getElementById('dealerBetBtn').disabled = false;
    });
}

// 取消下注
function cancelBet() {
    apiCall('cancel', {})
    .then(data => {
        if (data.success) {
            showMessage(data.message, '#00FF00');
            if (data.coinBalance !== undefined) updateCoinBalance(data.coinBalance);
            fetchStatus();
        } else {
            showMessage(data.message, '#FF6666');
        }
    })
    .catch(() => showMessage('网络错误', '#FF6666'));
}

// 让庄
function doRetire() {
    if (!confirm('确定要让庄吗？保证金将退还。')) return;
    apiCall('retire', {})
    .then(data => {
        if (data.success) {
            showMessage(data.message, '#00FF00');
            if (data.coinBalance !== undefined) updateCoinBalance(data.coinBalance);
            fetchStatus();
        } else {
            showMessage(data.message, '#FF6666');
        }
    })
    .catch(() => showMessage('网络错误', '#FF6666'));
}

// ─── 状态渲染 ─────────────────────────────────────────
function renderState(data) {
    currentData = data;
    const oldStatus = lastStatus;
    lastStatus = data.status;

    if (data.coinBalance !== undefined) {
        updateCoinBalance(data.coinBalance);
    }

    const cd = document.getElementById('countdown');
    const diceArea = document.getElementById('diceArea');
    const dealerInfo = document.getElementById('dealerInfo');
    const zuozhuangSec = document.getElementById('zuozhuangSection');
    const betSec = document.getElementById('betSection');
    const dealerActions = document.getElementById('dealerActions');
    const cancelSec = document.getElementById('cancelSection');
    const myBetInfo = document.getElementById('myBetInfo');
    const betHint = document.getElementById('betHint');

    // 庄家信息
    if (data.dealer) {
        dealerInfo.style.display = 'block';
        let html = '庄家: <strong style="color:#FFD700;">' + data.dealer.name + '</strong>';
        if (data.dealer.isNPC) html += '（NPC）';
        html += ' | 赌注上限: ' + data.maxBet + '文 | 总下注: ' + data.totalBet + '文';
        if (data.dealerBet > 0) html += ' | 庄家已押: ' + data.dealerBet + '文';
        dealerInfo.innerHTML = html;
    } else {
        dealerInfo.style.display = 'none';
    }

    // 状态 0: 等待庄家
    if (data.status === 0) {
        cd.innerHTML = '<span style="color:#888;">等待庄家入座... <strong>' + data.remaining + '</strong> 秒后NPC自动坐庄</span>';
        diceArea.innerHTML = '八仙桌上空空荡荡，正虚位以待庄家。\n\n' + renderShakingDice();
        zuozhuangSec.style.display = data.dealer ? 'none' : 'block';
        betSec.style.display = 'none';
        dealerActions.style.display = 'none';
        cancelSec.style.display = 'none';
        myBetInfo.style.display = 'none';
    }

    // 状态 1: 下注中
    else if (data.status === 1) {
        cd.innerHTML = '<span style="color:#FFD700;">下注倒计时: <strong>' + data.remaining + '</strong> 秒</span>';
        diceArea.innerHTML = '';
        zuozhuangSec.style.display = 'none';

        if (data.isDealer) {
            // 庄家视角
            betSec.style.display = 'none';
            cancelSec.style.display = 'none';
            if (!data.dealerBet || data.dealerBet === 0) {
                dealerActions.style.display = 'block';
                document.getElementById('dealerBetBtn').disabled = false;
                const totalPlayer = data.totalBet;
                betHint.textContent = totalPlayer > 0 ? '玩家共下注 ' + totalPlayer + ' 文，庄家所押不能少于这个数目。' : '还没人下注，等大家下完了再开掷。';
                betHint.style.display = 'block';
                // 也显示下注输入
                betSec.style.display = 'block';
                document.getElementById('submitBetBtn').style.display = 'none';
            } else {
                dealerActions.style.display = 'none';
            }
        } else {
            dealerActions.style.display = 'none';
            // 玩家视角
            if (data.myBet) {
                // 已下注
                betSec.style.display = 'none';
                cancelSec.style.display = 'block';
                myBetInfo.innerHTML = '已下注: <strong style="color:#FFD700;">' + data.myBet.amount + '文</strong>';
                myBetInfo.style.display = 'block';
            } else {
                // 可以下注
                betSec.style.display = 'block';
                document.getElementById('submitBetBtn').style.display = '';
                cancelSec.style.display = 'none';
                myBetInfo.style.display = 'none';
                betHint.textContent = '赌注上限: ' + data.maxBet + '文 | 最少50文';
                betHint.style.display = 'block';
            }
        }
    }

    // 状态 2: 掷骰中（多人轮流掷骰动画）
    else if (data.status === 2) {
        cd.innerHTML = '<span style="color:#FFA500;">掷骰中... 还需 <strong>' + data.remaining + '</strong> 秒</span>';
        zuozhuangSec.style.display = 'none';
        betSec.style.display = 'none';
        dealerActions.style.display = 'none';
        cancelSec.style.display = 'none';
        myBetInfo.style.display = 'none';

        // 渲染掷骰动画
        renderRollAnimation(data);
    }

    // 状态 3: 已结算
    else if (data.status === 3) {
        cd.innerHTML = '<span style="color:#90EE90;">结算完毕，<strong>' + data.remaining + '</strong> 秒后开始新轮次</span>';
        zuozhuangSec.style.display = 'none';
        betSec.style.display = 'none';
        dealerActions.style.display = 'none';
        cancelSec.style.display = 'none';

        // 渲染结算结果
        renderSettlement(data);
    }

    // 状态变化时显示消息
    if (oldStatus !== -1 && oldStatus !== data.status) {
        if (data.status === 0) showMessage('等待新庄家入座...', '#888');
        else if (data.status === 1) showMessage('开始下注！', '#FFD700');
        else if (data.status === 2) showMessage('封盘！庄家喊道：好！现在开掷，大家一个一个来。', '#FFA500');
        else if (data.status === 3) {
            if (data.myBet) {
                if (data.myBet.isWin) {
                    showMessage('恭喜！你赢了！获得 ' + data.myBet.winAmount + '文铜钱', '#00FF00');
                } else {
                    showMessage('可惜！你输了 ' + data.myBet.amount + '文铜钱', '#FF6666');
                }
            } else if (data.isDealer) {
                showMessage('本轮结算完毕', '#FFD700');
            } else {
                showMessage('本轮结算完毕', '#ccc');
            }
        }
    }

    // 显示所有玩家下注
    renderAllBets(data);
}

// ─── 多人轮流掷骰动画 ─────────────────────────────────
function renderRollAnimation(data) {
    const diceArea = document.getElementById('diceArea');
    const rolls = data.rollResults || [];
    const elapsed = Math.floor((data.animationTime - data.remaining));

    // 计算当前应该展示到第几个
    const targetIndex = Math.min(Math.floor(elapsed / ROLL_INTERVAL), rolls.length - 1);

    if (targetIndex === displayedRollIndex && diceArea.innerHTML !== '') return;
    displayedRollIndex = targetIndex;

    let html = '';
    for (let i = 0; i <= targetIndex && i < rolls.length; i++) {
        const r = rolls[i];
        const dealerClass = r.is_dealer ? ' dealer' : '';
        html += '<div class="roll-entry' + dealerClass + '">';
        html += '<strong>' + r.char_name + (r.is_dealer ? '（庄家）' : '') + '</strong>\n';
        html += r.action_msg + '\n\n';
        html += r.dice1_art + '    ' + r.dice2_art;
        html += '\n\n点数: ' + r.point_name;
        html += '</div>';
    }

    // 如果还没全部展示，显示摇骰动画
    if (targetIndex < rolls.length - 1) {
        html += '<div class="roll-entry">';
        html += '下一位准备...\n\n' + renderShakingDice();
        html += '</div>';
    } else if (data.remaining > 2) {
        html += '<div class="roll-entry">所有人掷完，等待结算...</div>';
    }

    diceArea.innerHTML = html;
}

// ─── 结算结果渲染 ─────────────────────────────────────
function renderSettlement(data) {
    const diceArea = document.getElementById('diceArea');
    const results = data.results || [];
    const myBetInfo = document.getElementById('myBetInfo');

    let html = '<div style="text-align:center; margin-bottom:8px;">';
    html += '庄家点数: <strong style="color:#FFD700;">' + (data.dealerPointName || '?') + '</strong>';
    html += '</div>';

    html += '<table style="width:100%; font-size:12px; border-collapse:collapse;">';
    html += '<tr style="border-bottom:1px solid #444;"><th>赌客</th><th>下注</th><th>骰子</th><th>点数</th><th>结果</th><th>盈亏</th></tr>';

    results.forEach(function(r) {
        const isMe = (r.charName === data.charName);
        const highlight = isMe ? 'background:rgba(255,255,255,0.05);' : '';
        const dealerTag = r.isDealer ? '（庄家）' : '';
        html += '<tr style="border-bottom:1px solid #222; ' + highlight + '">';
        html += '<td>' + r.charName + dealerTag + '</td>';
        html += '<td>' + r.betAmount + '文</td>';
        html += '<td>' + r.point1 + '+' + r.point2 + '</td>';
        html += '<td>' + r.pointName + '</td>';
        if (r.isDealer) {
            html += '<td>-</td>';
            html += '<td style="color:' + (r.winAmount >= r.betAmount ? '#00FF00' : '#FF6666') + ';">';
            html += (r.winAmount - r.betAmount >= 0 ? '+' : '') + (r.winAmount - r.betAmount) + '文</td>';
        } else {
            html += '<td style="color:' + (r.isWin ? '#00FF00' : '#FF6666') + ';">' + (r.isWin ? '赢' : '输') + '</td>';
            html += '<td style="color:' + (r.isWin ? '#00FF00' : '#FF6666') + ';">';
            html += r.isWin ? '+' + (r.winAmount - r.betAmount) + '文' : '-' + r.betAmount + '文</td>';
        }
        html += '</tr>';
    });
    html += '</table>';

    diceArea.innerHTML = html;

    // 我的下注结果
    if (data.myBet) {
        if (data.myBet.isWin) {
            myBetInfo.innerHTML = '<span style="color:#00FF00; font-weight:bold;">' +
                '你赢了！下注 ' + data.myBet.amount + '文 → 获得 ' + data.myBet.winAmount + '文' +
                '（净赚 +' + (data.myBet.winAmount - data.myBet.amount) + '文）</span>';
        } else {
            myBetInfo.innerHTML = '<span style="color:#FF6666;">' +
                '你输了。下注 ' + data.myBet.amount + '文 → 损失 ' + data.myBet.amount + '文</span>';
        }
        myBetInfo.style.display = 'block';
    } else if (data.isDealer && data.results) {
        // 庄家视角
        const dealerResult = results.find(function(r) { return r.isDealer; });
        if (dealerResult) {
            const net = dealerResult.winAmount - dealerResult.betAmount;
            myBetInfo.innerHTML = '<span style="color:' + (net >= 0 ? '#00FF00' : '#FF6666') + '; font-weight:bold;">' +
                '庄家结算: 押 ' + dealerResult.betAmount + '文 → 收入 ' + dealerResult.winAmount + '文' +
                '（' + (net >= 0 ? '净赚 +' : '净亏 ') + net + '文）</span>';
            myBetInfo.style.display = 'block';
        }
    }
}

// ─── 所有玩家下注列表 ─────────────────────────────────
function renderAllBets(data) {
    const el = document.getElementById('allBetsInfo');
    if (!data.playerBets || data.playerBets.length === 0) {
        el.innerHTML = '';
        return;
    }
    let html = '本轮下注: ';
    data.playerBets.forEach(function(b) {
        html += b.charName + ' ' + b.betAmount + '文  ';
    });
    el.innerHTML = html;
}

// ─── 轮询 ─────────────────────────────────────────────
function fetchStatus() {
    fetch('shaizi_api.php?action=status')
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // 状态变化时重置动画索引
            if (lastStatus !== data.status) {
                displayedRollIndex = -1;
            }
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
document.getElementById('betAmount').addEventListener('input', function() {});
startPolling();
</script>
</body>
</html>
