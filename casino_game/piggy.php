<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 拱猪房主页面 (拱猪北房)
 * 四人拱猪对战前端 — 通过 AJAX 轮询 piggy_api.php (2秒间隔)
 * 移植自 LPC: d/city/piggy.c
 *
 * 状态机:
 *   0=等人  1=等发牌  2=等卖牌  3=出牌  4=算分
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

// 从 referer 或参数获取房间ID，区分普通/双人拱猪
$roomId = $_REQUEST['room_id'] ?? '';
if (!$roomId) {
    // 从 HTTP_REFERER 提取房间ID
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (preg_match('#room=([^&]+)#', $referer, $m)) {
        $roomId = urldecode($m[1]);
    }
}
// 默认值
if (!in_array($roomId, ['city/piggy_n', 'city/piggy_s', 'city/piggy_e', 'city/piggy_w'])) {
    $roomId = 'city/piggy_n';
}
$isPartner = in_array($roomId, ['city/piggy_e', 'city/piggy_w']);
$roomName = [
    'city/piggy_n' => '拱猪北房',
    'city/piggy_s' => '拱猪南房',
    'city/piggy_e' => '双人拱猪房（东）',
    'city/piggy_w' => '双人拱猪房（西）',
][$roomId];
?>
<!DOCTYPE html>
<html lang="zh-cn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
    <title><?= h($roomName) ?>_<?= h(SERVER_NAME) ?></title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <style>
        /* ── 顶部栏 ── */
        .top-bar { margin-bottom: 6px; }
        .top-bar a { margin-right: 4px; }

        /* ── 状态条 ── */
        #statusBar {
            font-weight: bold;
            text-align: center;
            padding: 6px;
            border: 1px solid #555;
            border-radius: 6px;
            margin: 6px 0;
        }

        /* ── 牌桌 (3x3 网格) ── */
        .game-table {
            display: grid;
            grid-template-columns: 1fr 1.4fr 1fr;
            grid-template-rows: auto auto auto;
            gap: 6px;
            margin: 8px 0;
        }
        .seat {
            border: 2px solid #444;
            border-radius: 8px;
            padding: 8px;
            text-align: center;
            min-height: 70px;
        }
        .seat.me { border-color: #FFD700; background: rgba(255,215,0,0.08); }
        .seat.current { border-color: #00FF00; box-shadow: 0 0 6px rgba(0,255,0,0.4); }
        .seat.empty-seat { border-style: dashed; border-color: #666; }
        .seat-north { grid-column: 2; grid-row: 1; }
        .seat-west  { grid-column: 1; grid-row: 2; }
        .seat-east  { grid-column: 3; grid-row: 2; }
        .seat-south { grid-column: 2; grid-row: 3; }

        .seat-dir { font-weight: bold; font-size: 14px; }
        .seat-name { font-size: 13px; margin: 2px 0; }
        .seat-status { font-size: 11px; color: #999; }
        .seat-badges { font-size: 10px; margin: 2px 0; }
        .badge-me  { background: #FFD700; color: #000; padding: 0 4px; border-radius: 3px; }
        .badge-npc { background: #555; color: #ccc; padding: 0 4px; border-radius: 3px; }
        .seat-card { margin-top: 4px; font-size: 14px; font-weight: bold; }

        /* ── 中央区 ── */
        .center {
            grid-column: 2; grid-row: 2;
            border: 1px solid #555;
            border-radius: 8px;
            padding: 8px;
            text-align: center;
            min-height: 100px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .center-info { font-size: 13px; color: #ccc; margin: 2px 0; }
        .center-suit { font-size: 15px; font-weight: bold; margin: 4px 0; }

        /* ── 卖牌展示 ── */
        #soldArea { margin: 6px 0; text-align: center; }
        .sold-badge {
            display: inline-block;
            padding: 2px 8px;
            margin: 2px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }

        /* ── 手牌区 ── */
        #handArea { margin: 8px 0; }
        #handArea .hand-title { font-weight: bold; margin-bottom: 4px; }
        .hand-cards {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }
        .card-btn {
            display: inline-block;
            padding: 6px 8px;
            border: 1px solid #555;
            border-radius: 5px;
            background: rgba(255,255,255,0.06);
            cursor: pointer;
            font-size: 13px;
            font-weight: bold;
            transition: all 0.15s;
            position: relative;
        }
        .card-btn:hover { background: rgba(255,255,255,0.15); border-color: #aaa; }
        .card-btn:disabled { opacity: 0.5; cursor: default; }
        .card-static {
            display: inline-block;
            padding: 6px 8px;
            border: 1px solid #444;
            border-radius: 5px;
            font-size: 13px;
            font-weight: bold;
        }
        .card-misc {
            font-size: 9px;
            color: #FFD700;
            vertical-align: super;
            margin-left: 1px;
        }
        .card-sell-btns {
            display: block;
            margin-top: 2px;
            font-size: 11px;
        }
        .card-sell-btns button {
            padding: 1px 6px;
            margin: 0 1px;
            font-size: 11px;
            cursor: pointer;
        }

        /* ── 操作区 ── */
        #actionArea { margin: 8px 0; text-align: center; }
        .action-btn {
            display: inline-block;
            padding: 6px 16px;
            margin: 3px;
            border: 2px solid #FFD700;
            border-radius: 6px;
            background: transparent;
            color: #FFD700;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
        }
        .action-btn:hover { background: rgba(255,215,0,0.15); }
        .action-btn.green { border-color: #00FF00; color: #00FF00; }
        .action-btn.green:hover { background: rgba(0,255,0,0.12); }
        .action-btn.red { border-color: #FF6666; color: #FF6666; }
        .action-btn.red:hover { background: rgba(255,102,102,0.12); }

        /* ── 积分表 ── */
        #scoringArea { margin: 8px 0; }
        .score-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .score-table th, .score-table td {
            border: 1px solid #444;
            padding: 4px 8px;
            text-align: center;
        }
        .score-table th { background: rgba(255,255,255,0.08); }

        /* ── 结果区 ── */
        #resultArea {
            margin: 8px 0;
            padding: 10px;
            border: 1px solid #555;
            border-radius: 8px;
            white-space: pre-wrap;
            font-size: 13px;
        }
        .collected-list { font-size: 12px; margin: 4px 0; }
        .collected-list .col-dir { font-weight: bold; }

        /* ── 消息区 ── */
        #msgArea {
            text-align: center;
            min-height: 20px;
            margin: 6px 0;
            font-size: 13px;
        }
    </style>
</head>
<body>

<!-- ── 顶部栏 ── -->
<p class="top-bar">
    <a href="javascript:location.reload();">返回<?= h($roomName) ?></a>&ensp;
    <a href="piggy_rule.php?room_id=<?= h($roomId) ?>">拱猪规则</a>&ensp;
    <a href="piggy_history.php?room_id=<?= h($roomId) ?>">历史记录</a>&ensp;
    <a href="../functions/room.php?area=<?= h($char['current_area'] ?? 'city') ?>&room=<?= h($char['current_room'] ?? 'piggy_n') ?>">返回游戏</a>
</p>
<p>铜钱: <span id="coinBalance"><?= $coinBalance ?>文</span></p>

<!-- ── 状态条 ── -->
<div id="statusBar">加载中...</div>

<!-- ── 卖牌展示 ── -->
<div id="soldArea"></div>

<!-- ── 牌桌 ── -->
<div class="game-table">
    <div class="seat seat-north" id="seat-north"></div>
    <div class="seat seat-west"  id="seat-west"></div>
    <div class="center"          id="centerArea"></div>
    <div class="seat seat-east"  id="seat-east"></div>
    <div class="seat seat-south" id="seat-south"></div>
</div>

<!-- ── 手牌区 ── -->
<div id="handArea" style="display:none;">
    <div class="hand-title">我的手牌</div>
    <div class="hand-cards" id="handCards"></div>
</div>

<!-- ── 操作区 ── -->
<div id="actionArea"></div>

<!-- ── 积分表 ── -->
<div id="scoringArea"></div>

<!-- ── 结果区 ── -->
<div id="resultArea" style="display:none;"></div>

<!-- ── 消息区 ── -->
<div id="msgArea"></div>

<hr>
<a href="../functions/room.php?area=<?= h($char['current_area'] ?? 'city') ?>&room=<?= h($char['current_room'] ?? 'piggy_n') ?>">返回游戏</a>

<script>
// ═══════════════════════════════════════════════════════
// 配置与常量
// ═══════════════════════════════════════════════════════
const POLL_INTERVAL = 2000; // 轮询间隔 2秒
const ROOM_ID = '<?= $roomId ?>';
const IS_PARTNER = <?= $isPartner ? 'true' : 'false' ?>;

// 花色信息: 颜色 + 符号 + 中文名
const SUIT_INFO = {
    spade:   { color: '#4169E1', symbol: '♠', name: '黑桃' },
    heart:   { color: '#DC143C', symbol: '♥', name: '红桃' },
    diamond: { color: '#FF1493', symbol: '♦', name: '方片' },
    club:    { color: '#DAA520', symbol: '♣', name: '草花' },
};

// 特殊牌标签
const MISC_LABEL = {
    pig: '猪', blood: '血', sheep: '羊', doubler: '变压器',
};

// 方向信息
const DIR_INFO = {
    north: { cn: '北', label: '北家' },
    west:  { cn: '西', label: '西家' },
    east:  { cn: '东', label: '东家' },
    south: { cn: '南', label: '南家' },
};
const DIRS = ['east', 'south', 'west', 'north'];

// 座位状态文案
const SEAT_STATUS_TEXT = {
    empty: '空位',
    filled: '已入座',
    asked_for_deal: '已准备',
    selling: '卖牌中',
    passed: '已停卖',
    playing: '出牌中',
};

// 状态超时(秒) — 用于显示倒计时参考
const STATUS_TIMEOUT = { 0: 60, 1: 30, 2: 30, 3: 30, 4: 15 };
const NPC_PLAY_DELAY = 3; // NPC出牌延迟(秒)

// ═══════════════════════════════════════════════════════
// 状态变量
// ═══════════════════════════════════════════════════════
let pollTimer = null;
let lastStatus = -1;
let isBusy = false; // 防止重复提交

// ═══════════════════════════════════════════════════════
// 工具函数
// ═══════════════════════════════════════════════════════

/** HTML 转义 */
function esc(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/** 显示消息 */
function showMessage(msg, color) {
    const el = document.getElementById('msgArea');
    if (!msg) { el.innerHTML = ''; return; }
    el.innerHTML = '<span style="color:' + (color || '#ccc') + ';">' + esc(msg) + '</span>';
}

/** 根据花色获取颜色 */
function suitColor(suit) {
    return (SUIT_INFO[suit] || {}).color || '#888';
}

/** 根据牌名(中文字符串)推断花色颜色 */
function cardNameColor(name) {
    if (!name) return '#888';
    if (name.indexOf('黑桃') === 0) return SUIT_INFO.spade.color;
    if (name.indexOf('红桃') === 0) return SUIT_INFO.heart.color;
    if (name.indexOf('方片') === 0) return SUIT_INFO.diamond.color;
    if (name.indexOf('草花') === 0) return SUIT_INFO.club.color;
    return '#888';
}

/** 渲染单张牌(静态展示) */
function renderCardStatic(card) {
    if (!card) return '<span style="color:#666;">—</span>';
    const color = suitColor(card.suit);
    const si = SUIT_INFO[card.suit] || { symbol: '' };
    let miscLabel = '';
    if (card.misc && MISC_LABEL[card.misc]) {
        miscLabel = '<sup class="card-misc">' + MISC_LABEL[card.misc] + '</sup>';
    }
    return '<span style="color:' + color + ';">' + si.symbol + ' ' + esc(card.name) + miscLabel + '</span>';
}

/** 渲染手牌按钮 */
function renderCardButton(card, clickable, onclickExpr) {
    const color = suitColor(card.suit);
    const si = SUIT_INFO[card.suit] || { symbol: '' };
    let miscLabel = '';
    if (card.misc && MISC_LABEL[card.misc]) {
        miscLabel = '<sup class="card-misc">' + MISC_LABEL[card.misc] + '</sup>';
    }
    const inner = '<span style="color:' + color + ';">' + si.symbol + ' ' + esc(card.name) + miscLabel + '</span>';
    if (clickable) {
        return '<button class="card-btn" onclick="' + onclickExpr + '">' + inner + '</button>';
    }
    return '<span class="card-static">' + inner + '</span>';
}

/** 获取已卖牌的 misc 集合 */
function getSoldMiscs(data) {
    const set = {};
    (data.sold_display || []).forEach(function(s) { set[s.misc] = true; });
    return set;
}

// ═══════════════════════════════════════════════════════
// API 通信
// ═══════════════════════════════════════════════════════

/** 获取状态 */
function fetchStatus() {
    fetch('piggy_api.php?action=status&room_id=' + encodeURIComponent(ROOM_ID))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                updateUI(data);
            }
        })
        .catch(function(err) {
            console.error('poll error:', err);
        });
}

/** 发送动作 (POST) */
function sendAction(action, params) {
    if (isBusy) return;
    isBusy = true;
    params = params || {};
    params.action = action;
    params.room_id = ROOM_ID;
    const formData = new FormData();
    for (const key in params) {
        formData.append(key, params[key]);
    }
    fetch('piggy_api.php?action=' + encodeURIComponent(action) + '&room_id=' + encodeURIComponent(ROOM_ID), {
        method: 'POST',
        body: formData
    })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.message) {
                showMessage(data.message, data.success ? '#90EE90' : '#FF6666');
            }
            fetchStatus(); // 立即刷新
        })
        .catch(function() {
            showMessage('网络错误，请重试', '#FF6666');
        })
        .finally(function() {
            isBusy = false;
        });
}

// ═══════════════════════════════════════════════════════
// 动作函数
// ═══════════════════════════════════════════════════════
function doSit(dir)     { sendAction('sit',   { dir: dir }); }
function doLeave()      { sendAction('leave'); }
function doDeal()       { sendAction('deal'); }
function doSell(card, flag) { sendAction('sell', { card: card, flag: flag }); }
function doPass()       { sendAction('pass'); }
function doPlay(card)   { sendAction('play', { card: card }); }
function doClaim(type)  { sendAction('claim', { type: type }); }

// ═══════════════════════════════════════════════════════
// UI 渲染
// ═══════════════════════════════════════════════════════

/** 主渲染入口 */
function updateUI(data) {
    const status = data.status;
    const mySeat = data.my_seat || '';
    const seats = data.seats || {};
    const gameInfo = data.game_info || {};
    const tableCards = data.table_cards || {};
    const claim = data.claim || { claimer: '', east: 'no', north: 'no', west: 'no', south: 'no' };

    // 状态条
    renderStatusBar(data);

    // 座位
    renderSeats(seats, mySeat, status, tableCards, gameInfo, claim);

    // 中央区
    renderCenter(data);

    // 卖牌展示
    renderSold(data);

    // 手牌
    renderHand(data);

    // 操作区
    renderActions(data);

    // 积分表
    renderScoring(data);

    // 结果区 (status 4)
    renderResult(data);

    // 状态变化时清空消息
    if (lastStatus !== -1 && lastStatus !== status) {
        showMessage('');
    }
    lastStatus = status;
}

/** 状态条 */
function renderStatusBar(data) {
    const el = document.getElementById('statusBar');
    const timeout = STATUS_TIMEOUT[data.status] || 0;
    const remaining = Math.max(0, timeout - (data.elapsed || 0));
    let html = '【' + esc(data.status_text || '未知') + '】';
    // 搭档模式提示
    if (data.is_partner) {
        html += ' <span style="color:#9932CC;font-size:11px;">[搭档模式]</span>';
        if (data.my_seat && data.partner_name) {
            html += ' <span style="color:#9932CC;font-size:11px;">搭档: ' + esc(data.partner_name) + '</span>';
        }
    }
    if (data.status === 3 && data.is_my_turn) {
        html = '<span style="color:#00FF00;">【轮到你出牌】</span>';
    } else if (data.status === 3 && data.current_player) {
        const dir = DIR_INFO[data.current_player];
        // 检查是否是NPC在出牌
        const curSeat = (data.seats || {})[data.current_player] || {};
        if (curSeat.is_npc) {
            const npcRemaining = Math.max(0, NPC_PLAY_DELAY - (data.elapsed || 0));
            html = '<span style="color:#FFD700;">【' + esc(dir ? dir.label : data.current_player) + '(NPC) 思考中... ' + npcRemaining + '秒】</span>';
        } else {
            html = '<span style="color:#FFD700;">【' + esc(dir ? dir.label : data.current_player) + ' 出牌中...】</span>';
        }
    } else if (timeout > 0 && data.status < 4) {
        html += ' 剩余 ' + remaining + ' 秒';
    }
    el.innerHTML = html;
}

/** 渲染四个座位 */
function renderSeats(seats, mySeat, status, tableCards, gameInfo, claim) {
    DIRS.forEach(function(dir) {
        const el = document.getElementById('seat-' + dir);
        const s = seats[dir] || { name: '「空」', is_npc: false, status: 'empty', is_me: false };
        const info = DIR_INFO[dir];
        const isCurrent = (status === 3 && gameInfo.next === dir);
        const isEmpty = (s.status === 'empty');

        let cls = 'seat seat-' + dir;
        if (s.is_me) cls += ' me';
        if (isCurrent) cls += ' current';
        if (isEmpty) cls += ' empty-seat';
        el.className = cls;

        let html = '<div class="seat-dir">' + esc(info.label) + '</div>';

        // 玩家名
        if (isEmpty) {
            html += '<div class="seat-name" style="color:#666;">空位</div>';
        } else {
            html += '<div class="seat-name">' + esc(s.name) + '</div>';
        }

        // 徽章
        let badges = '';
        if (s.is_me)  badges += '<span class="badge-me">我</span> ';
        if (s.is_npc) badges += '<span class="badge-npc">NPC</span>';
        // 搭档标记
        if (IS_PARTNER && mySeat) {
            const partnerMap = { east: 'west', west: 'east', north: 'south', south: 'north' };
            if (partnerMap[mySeat] === dir) {
                badges += '<span style="color:#9932CC;font-size:10px;">搭档</span>';
            }
        }
        html += '<div class="seat-badges">' + badges + '</div>';

        // 座位状态
        const stText = SEAT_STATUS_TEXT[s.status] || s.status;
        if (!isEmpty) {
            html += '<div class="seat-status">' + esc(stText) + '</div>';
        }

        // 桌面出的牌
        const tc = tableCards[dir];
        if (tc) {
            html += '<div class="seat-card">' + renderCardStatic(tc) + '</div>';
        }

        // 全收请求标记
        if (claim && claim.claimer === dir) {
            html += '<div style="font-size:11px;color:#FF6347;">要求全收</div>';
        }
        if (claim && claim[dir] === 'yes' && dir !== claim.claimer) {
            html += '<div style="font-size:11px;color:#90EE90;">已同意</div>';
        }

        el.innerHTML = html;
    });
}

/** 中央区 */
function renderCenter(data) {
    const el = document.getElementById('centerArea');
    const status = data.status;
    const gi = data.game_info || {};
    const claim = data.claim || {};

    let html = '';

    if (status === 0) {
        html += '<div class="center-info">等待玩家入座...</div>';
        const filled = DIRS.filter(function(d) {
            return (data.seats || {})[d] && (data.seats[d]).status !== 'empty';
        }).length;
        html += '<div class="center-info">已入座 ' + filled + '/4</div>';
        if (filled < 4) {
            html += '<div class="center-info" style="color:#999;">超时未满将由电脑玩家填位</div>';
        }
    } else if (status === 1) {
        html += '<div class="center-info">等待玩家准备发牌</div>';
        const ready = DIRS.filter(function(d) {
            return (data.seats || {})[d] && (data.seats[d]).status === 'asked_for_deal';
        }).length;
        html += '<div class="center-info">已准备 ' + ready + '/4</div>';
    } else if (status === 2) {
        html += '<div class="center-info">卖牌阶段</div>';
        const passed = DIRS.filter(function(d) {
            return (data.seats || {})[d] && (data.seats[d]).status === 'passed';
        }).length;
        html += '<div class="center-info">已停卖 ' + passed + '/4</div>';
        html += '<div class="center-info" style="color:#999;font-size:11px;">可卖: 猪(黑桃Q) 血(红桃A) 羊(方片J) 变压器(草花T)</div>';
    } else if (status === 3) {
        // 出牌阶段
        html += '<div class="center-info">第 ' + (gi.round || 1) + ' / 13 轮</div>';
        if (gi.suit) {
            const si = SUIT_INFO[gi.suit] || {};
            html += '<div class="center-suit" style="color:' + suitColor(gi.suit) + ';">' +
                    '本轮花色: ' + si.symbol + ' ' + esc(si.name || gi.suit) + '</div>';
        } else {
            html += '<div class="center-suit" style="color:#999;">等待领出</div>';
        }
        if (data.is_my_turn) {
            html += '<div class="center-info" style="color:#00FF00;font-weight:bold;">请出牌</div>';
        } else if (data.current_player) {
            const info = DIR_INFO[data.current_player];
            html += '<div class="center-info">等待 ' + esc(info ? info.label : data.current_player) + ' 出牌...</div>';
        }
        // 全收请求状态
        if (claim.claimer) {
            const info = DIR_INFO[claim.claimer];
            html += '<div class="center-info" style="color:#FF6347;margin-top:6px;">' +
                    esc(info ? info.label : claim.claimer) + ' 要求全收！</div>';
            let yesCount = DIRS.filter(function(d) { return claim[d] === 'yes'; }).length;
            html += '<div class="center-info" style="font-size:11px;">同意 ' + yesCount + '/4</div>';
        }
        // 猪主
        if (data.pig_owner) {
            const info = DIR_INFO[data.pig_owner];
            html += '<div class="center-info" style="font-size:11px;color:#999;">猪主: ' +
                    esc(info ? info.label : data.pig_owner) + '</div>';
        }
    } else if (status === 4) {
        html += '<div class="center-info" style="font-weight:bold;color:#FFD700;">本局结算</div>';
        if (data.full_collector) {
            const info = DIR_INFO[data.full_collector];
            html += '<div class="center-info" style="color:#00FF00;">' +
                    esc(info ? info.label : data.full_collector) + ' 全收成功！</div>';
        }
        if (data.pig_owner) {
            const info = DIR_INFO[data.pig_owner];
            html += '<div class="center-info" style="color:#FF6666;">得猪: ' +
                    esc(info ? info.label : data.pig_owner) + '</div>';
        }
    }

    el.innerHTML = html;
}

/** 卖牌展示 */
function renderSold(data) {
    const el = document.getElementById('soldArea');
    const sold = data.sold_display || [];
    if (sold.length === 0) {
        el.innerHTML = '';
        return;
    }
    let html = '';
    sold.forEach(function(s) {
        const sellerInfo = DIR_INFO[s.seller] || { label: s.seller };
        const bg = s.flag === 'm' ? '#8B0000' : '#444';
        html += '<span class="sold-badge" style="background:' + bg + ';color:#FFD700;">' +
                esc(s.flag_text) + ' ' + esc(s.name) +
                ' (' + esc(sellerInfo.label) + ')</span>';
    });
    el.innerHTML = html;
}

/** 手牌区 */
function renderHand(data) {
    const el = document.getElementById('handArea');
    const cardsEl = document.getElementById('handCards');
    const hand = data.my_hand;
    const status = data.status;
    const mySeat = data.my_seat || '';

    if (!hand || hand.length === 0 || !mySeat) {
        el.style.display = 'none';
        return;
    }
    el.style.display = 'block';

    const soldMiscs = getSoldMiscs(data);
    let html = '';

    if (status === 3 && data.is_my_turn) {
        // 出牌阶段且轮到我: 可点击
        hand.forEach(function(card) {
            html += renderCardButton(card, true, "doPlay('" + card.short + "')");
        });
    } else if (status === 2) {
        // 卖牌阶段: 展示手牌, 可卖牌附卖出按钮
        hand.forEach(function(card) {
            let cardHtml = renderCardButton(card, false, '');
            if (card.sellable && !soldMiscs[card.misc]) {
                cardHtml += '<span class="card-sell-btns">' +
                    '<button onclick="doSell(\'' + card.short + '\',\'m\')">明卖</button>' +
                    '<button onclick="doSell(\'' + card.short + '\',\'a\')">暗卖</button>' +
                    '</span>';
            }
            html += '<span style="display:inline-block;">' + cardHtml + '</span>';
        });
    } else {
        // 其他状态: 只读展示
        hand.forEach(function(card) {
            html += renderCardButton(card, false, '');
        });
    }

    cardsEl.innerHTML = html;
}

/** 操作区 */
function renderActions(data) {
    const el = document.getElementById('actionArea');
    const status = data.status;
    const mySeat = data.my_seat || '';
    const seats = data.seats || {};
    const claim = data.claim || {};
    const gi = data.game_info || {};
    let html = '';

    if (status === 0) {
        // 等人: 入座 / 离开
        if (!mySeat) {
            html += '<div style="margin-bottom:4px;">选择座位入座 (入场费 50 文)</div>';
            DIRS.forEach(function(dir) {
                const s = seats[dir] || {};
                if (s.status === 'empty') {
                    const info = DIR_INFO[dir];
                    html += '<button class="action-btn green" onclick="doSit(\'' + dir + '\')">' +
                            '入座' + esc(info.label) + '</button>';
                }
            });
        } else {
            const info = DIR_INFO[mySeat];
            html += '<span style="margin-right:8px;">你坐在' + esc(info.label) + '</span>';
            html += '<button class="action-btn red" onclick="doLeave()">离开</button>';
        }
    } else if (status === 1) {
        // 等发牌: 准备发牌
        if (mySeat && seats[mySeat] && seats[mySeat].status !== 'asked_for_deal') {
            html += '<button class="action-btn" onclick="doDeal()">准备发牌</button>';
        } else if (mySeat) {
            html += '<span style="color:#90EE90;">已准备，等待其他玩家...</span>';
        } else {
            html += '<span style="color:#999;">观战中</span>';
        }
    } else if (status === 2) {
        // 等卖牌: 停卖
        if (mySeat && seats[mySeat] && seats[mySeat].status !== 'passed') {
            html += '<button class="action-btn red" onclick="doPass()">停卖</button>';
            html += '<span style="font-size:12px;color:#999;margin-left:8px;">点击手牌下方的明卖/暗卖按钮卖牌</span>';
        } else if (mySeat) {
            html += '<span style="color:#90EE90;">已停卖，等待其他玩家...</span>';
        } else {
            html += '<span style="color:#999;">观战中</span>';
        }
    } else if (status === 3) {
        // 出牌: 全收 / 同意 / 反对
        if (mySeat) {
            if (!claim.claimer) {
                // 没有全收请求
                if (data.is_my_turn && (gi.round || 0) > 8) {
                    html += '<button class="action-btn" onclick="doClaim(\'all\')">要求全收</button>';
                }
                if (data.is_my_turn) {
                    html += '<span style="font-size:12px;color:#999;margin-left:8px;">点击手牌出牌</span>';
                }
            } else {
                // 有全收请求
                if (claim.claimer === mySeat) {
                    html += '<span style="color:#FFD700;">你已要求全收，等待回应...</span>';
                } else if (claim[mySeat] === 'yes') {
                    html += '<span style="color:#90EE90;">你已同意全收</span>';
                } else {
                    html += '<button class="action-btn green" onclick="doClaim(\'yes\')">同意全收</button>';
                    html += '<button class="action-btn red" onclick="doClaim(\'no\')">反对</button>';
                }
            }
        } else {
            html += '<span style="color:#999;">观战中</span>';
        }
    } else if (status === 4) {
        html += '<span style="color:#FFD700;">本局结束，等待下一局...</span>';
    }

    el.innerHTML = html;
}

/** 积分表 */
function renderScoring(data) {
    const el = document.getElementById('scoringArea');
    const scoring = data.scoring;
    if (!scoring || !scoring.sitting) {
        el.innerHTML = '';
        return;
    }
    const sitting = scoring.sitting;
    const hand = scoring.hand || {};

    let html = '<table class="score-table"><thead><tr>' +
               '<th>座位</th><th>玩家</th><th>本手</th><th>累计</th></tr></thead><tbody>';
    DIRS.forEach(function(dir) {
        const s = (data.seats || {})[dir] || {};
        const info = DIR_INFO[dir];
        const handScore = hand[dir];
        const sitScore = sitting[dir] || 0;
        let handCell = (handScore !== undefined && handScore !== null) ? String(handScore) : '—';
        if (handScore > 0) handCell = '<span style="color:#00FF00;">+' + handScore + '</span>';
        else if (handScore < 0) handCell = '<span style="color:#FF6666;">' + handScore + '</span>';
        let sitCell = String(sitScore);
        if (sitScore > 0) sitCell = '<span style="color:#00FF00;">+' + sitScore + '</span>';
        else if (sitScore < 0) sitCell = '<span style="color:#FF6666;">' + sitScore + '</span>';
        html += '<tr>' +
                '<td>' + esc(info.label) + '</td>' +
                '<td>' + esc(s.name || '—') + '</td>' +
                '<td>' + handCell + '</td>' +
                '<td>' + sitCell + '</td>' +
                '</tr>';
    });
    html += '</tbody></table>';
    el.innerHTML = html;
}

/** 结果区 (status 4) */
function renderResult(data) {
    const el = document.getElementById('resultArea');
    if (data.status !== 4) {
        el.style.display = 'none';
        el.innerHTML = '';
        return;
    }
    el.style.display = 'block';
    let html = '';

    // 结果摘要
    if (data.result_summary) {
        html += '<div style="font-weight:bold;margin-bottom:6px;">本局结果</div>';
        html += '<div style="white-space:pre-wrap;">' + esc(data.result_summary) + '</div>';
    }

    // 收牌详情
    if (data.collected) {
        html += '<div style="font-weight:bold;margin-top:8px;margin-bottom:4px;">收牌详情</div>';
        DIRS.forEach(function(dir) {
            const cards = data.collected[dir] || [];
            if (cards.length === 0) return;
            const info = DIR_INFO[dir];
            const seat = (data.seats || {})[dir] || {};
            html += '<div class="collected-list">' +
                    '<span class="col-dir">' + esc(info.label) + ' ' + esc(seat.name || '') + ':</span> ';
            cards.forEach(function(name, i) {
                if (i > 0) html += ', ';
                html += '<span style="color:' + cardNameColor(name) + ';">' + esc(name) + '</span>';
            });
            html += '</div>';
        });
    }

    el.innerHTML = html;
}

// ═══════════════════════════════════════════════════════
// 轮询启动
// ═══════════════════════════════════════════════════════
function startPolling() {
    if (pollTimer) clearInterval(pollTimer);
    fetchStatus();
    pollTimer = setInterval(fetchStatus, POLL_INTERVAL);
}

startPolling();
</script>
</body>
</html>
