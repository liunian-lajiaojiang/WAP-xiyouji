<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 骰子房 AJAX 后端 API
 *
 * 完整庄家制骰子赌局 — 移植自 LPC: d/city/shaizi-room.c
 *
 * 接口:
 *   GET  shaizi_api.php?action=status    → 获取当前赌桌状态（含懒推进）
 *   POST shaizi_api.php?action=zuozhuang → 坐庄（交保证金，设赌注上限）
 *   POST shaizi_api.php?action=bet       → 下注（玩家或庄家）
 *   POST shaizi_api.php?action=cancel    → 取消下注（非庄家玩家）
 *   POST shaizi_api.php?action=retire    → 让庄（庄家退出，退还保证金）
 *
 * 状态机:
 *   0=等待庄家(30s→NPC自动坐庄) → 1=下注中(30s) → 2=掷骰中(动画) → 3=已结算(10s) → 0
 *
 * 双骰点数规则 (移植自 LPC show_shaizi):
 *   两骰相同(对子): 100+面值，如两个4=104(四对)
 *   两骰不同: (骰1+骰2)%10，如3+5=8(八点)
 *   模10为0: 蹩十(最小)
 *   对子 > 散点; 对子间比面值; 散点间比模10值
 *
 * 结算规则 (移植自 LPC game_result):
 *   玩家点数 > 庄家点数 → 玩家赢，获2倍赌注
 *   玩家点数 ≤ 庄家点数 → 玩家输，赌注归庄家
 *   庄家获剩余赌池
 */

require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Character.php';
require_once HELPER_PATH . 'MoneyHelper.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
$charId = get_char_id();
$char = CharacterModel::getFullInfo($charId);
if (!$char) {
    echo json_encode(['success' => false, 'message' => '角色不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── 常量 ──────────────────────────────────────────────
const WAIT_DEALER_TIMEOUT  = 30;   // 等待庄家超时(秒) → NPC自动坐庄
const BETTING_DURATION     = 30;   // 下注阶段时长(秒)
const NPC_AUTO_BET_TIME    = 25;   // NPC庄家自动下注时间(秒)
const ROLL_INTERVAL        = 4;    // 每人掷骰间隔(秒)
const ROLL_BUFFER          = 4;    // 掷骰动画缓冲(秒)
const SETTLE_DISPLAY       = 10;   // 结算展示时间(秒)
const DEALER_DEPOSIT       = 1000; // 庄家保证金(铜钱)
const MIN_BET_LIMIT        = 500;  // 最小赌注上限
const MAX_BET_LIMIT        = 10000;// 最大赌注上限
const DEFAULT_NPC_LIMIT    = 2000; // NPC庄家默认赌注上限
const MIN_BET              = 50;   // 最小下注金额
const MAX_PLAYERS          = 10;   // 最大玩家数

// ─── 自动建表 ──────────────────────────────────────────
ensureTablesExist();

// ─── ASCII 骰面艺术 (移植自 LPC shaizi_msg) ────────────
$diceArt = [
    0 => "┌───┐\n│　　　│\n│　　　│\n│　　　│\n└───┘",
    1 => "┌───┐\n│　　　│\n│　●　│\n│　　　│\n└───┘",
    2 => "┌───┐\n│　　　│\n│●　●│\n│　　　│\n└───┘",
    3 => "┌───┐\n│●　　│\n│　●　│\n│　　●│\n└───┘",
    4 => "┌───┐\n│●　●│\n│　　　│\n│●　●│\n└───┘",
    5 => "┌───┐\n│●　●│\n│　●　│\n│●　●│\n└───┘",
    6 => "┌───┐\n│●　●│\n│●　●│\n│●　●│\n└───┘",
];

// ─── 掷骰动作描写 (移植自 LPC sha_msg) ──────────────────
$shaMsgs = [
    '瞪着一对红眼，大喝一声：杀！手中的两粒骰子往桌子上一摔！',
    '往手上吹了口气，两粒骰子轻轻一抛．．．',
    '微微一笑，两粒骰子往桌子上一滚．．．',
    '望空作了个揖：菩萨保佑！两粒骰子战战噤噤地往桌上一投．．．',
    '拿着两粒骰子，抖足精神：娶老婆生孩子在此一举！',
    '衣袖一卷，大声叫道：看我的！',
    '咬牙切齿，两粒骰子往桌子上狠狠地一砸．．．',
    '满头大汗，自言自语道：六对，六对，该上我家了吧．．．',
    '潇洒地作了个四方揖：这把该我赢，看好了．．．',
];

// ─── 辅助函数 ──────────────────────────────────────────

function ensureTablesExist(): void {
    $exists = Database::queryOne(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shaizi_rounds'"
    );
    if (!$exists) {
        Database::execute("CREATE TABLE `shaizi_rounds` (
            `id` int NOT NULL AUTO_INCREMENT,
            `dealer_char_id` int NOT NULL DEFAULT 0 COMMENT '庄家角色ID(0=NPC)',
            `dealer_name` varchar(100) NULL COMMENT '庄家名称',
            `dealer_deposit` int NOT NULL DEFAULT 0 COMMENT '庄家保证金(铜钱)',
            `max_bet` int NOT NULL DEFAULT 2000 COMMENT '赌注上限(铜钱)',
            `total_bet` int NOT NULL DEFAULT 0 COMMENT '总下注额(铜钱)',
            `dealer_bet` int NOT NULL DEFAULT 0 COMMENT '庄家下注额',
            `status` int NOT NULL DEFAULT 0 COMMENT '0=等待庄家 1=下注中 2=掷骰中 3=已结算',
            `status_changed_at` datetime NULL DEFAULT CURRENT_TIMESTAMP,
            `roll_results` text NULL COMMENT '掷骰结果JSON(动画用)',
            `dealer_point1` int NULL COMMENT '庄家骰子1',
            `dealer_point2` int NULL COMMENT '庄家骰子2',
            `dealer_point` int NULL COMMENT '庄家点数',
            `dealer_point_name` varchar(20) NULL COMMENT '庄家点数名称',
            `created_at` datetime NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='骰子房轮次'");
    }

    $exists2 = Database::queryOne(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shaizi_bets'"
    );
    if (!$exists2) {
        Database::execute("CREATE TABLE `shaizi_bets` (
            `id` int NOT NULL AUTO_INCREMENT,
            `round_id` int NOT NULL,
            `char_id` int NOT NULL COMMENT '角色ID(0=NPC)',
            `char_name` varchar(100) NOT NULL,
            `bet_amount` int NOT NULL DEFAULT 0 COMMENT '下注金额(铜钱)',
            `is_dealer` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否庄家',
            `point1` int NULL COMMENT '骰子1',
            `point2` int NULL COMMENT '骰子2',
            `point` int NULL COMMENT '点数(100+面值=对子, 模10=散点)',
            `point_name` varchar(20) NULL COMMENT '点数名称',
            `is_win` tinyint(1) NULL COMMENT '是否赢(庄家为NULL)',
            `win_amount` int NOT NULL DEFAULT 0 COMMENT '赢取金额',
            `roll_order` int NOT NULL DEFAULT 0 COMMENT '掷骰顺序',
            `created_at` datetime NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_round` (`round_id`),
            INDEX `idx_char` (`char_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='骰子房下注记录'");
    }
}

/**
 * 双骰点数计算 (移植自 LPC show_shaizi 点数逻辑)
 *
 * @param int $d1 骰子1 (1-6)
 * @param int $d2 骰子2 (1-6)
 * @return array ['point' => int, 'name' => string]
 */
function calculatePoint(int $d1, int $d2): array {
    $cnNum = ['', '一', '二', '三', '四', '五', '六'];
    if ($d1 == $d2) {
        // 对子: 100 + 面值
        return ['point' => 100 + $d2, 'name' => $cnNum[$d2] . '对'];
    }
    // 散点: (d1+d2) % 10
    $point = ($d1 + $d2) % 10;
    if ($point == 0) {
        return ['point' => 0, 'name' => '蹩十'];
    }
    $cnNum10 = ['', '一', '二', '三', '四', '五', '六', '七', '八', '九'];
    return ['point' => $point, 'name' => $cnNum10[$point] . '点'];
}

/**
 * 获取当前轮次
 */
function getCurrentRound(): ?array {
    return Database::queryOne("SELECT * FROM shaizi_rounds ORDER BY id DESC LIMIT 1");
}

/**
 * 创建新轮次
 */
function createNewRound(): array {
    Database::execute("INSERT INTO shaizi_rounds (status, status_changed_at, created_at) VALUES (0, NOW(), NOW())");
    $id = Database::lastInsertId();
    return Database::queryOne("SELECT * FROM shaizi_rounds WHERE id = ?", [$id]);
}

/**
 * 获取轮次的下注列表
 */
function getRoundBets(int $roundId): array {
    return Database::queryAll("SELECT * FROM shaizi_bets WHERE round_id = ? ORDER BY is_dealer DESC, roll_order ASC", [$roundId]);
}

/**
 * 懒推进状态机
 *
 * 移植自 LPC 的 call_out 机制，改为轮询驱动:
 *   - call_out("do_test",60) → advanceRound 检查时间
 *   - call_out("check_finish",600) → 超时取消
 *   - call_out("game_process",j*4) → 前端动画
 */
function advanceRound(): void {
    $round = getCurrentRound();
    if (!$round) {
        createNewRound();
        return;
    }

    $now = time();
    $elapsed = $now - strtotime($round['status_changed_at']);

    switch ((int)$round['status']) {
        case 0: // 等待庄家
            if (!empty($round['dealer_name'])) {
                // 异常恢复：状态0但有残留庄家信息（超时取消未清干净的遗留）
                // 保证金已在超时处理路径中退还/没收，此处仅清除残留数据
                Database::execute(
                    "UPDATE shaizi_rounds SET dealer_char_id = 0, dealer_name = NULL, dealer_deposit = 0, max_bet = 2000, dealer_bet = 0, status_changed_at = NOW() WHERE id = ?",
                    [$round['id']]
                );
            } elseif ($elapsed >= WAIT_DEALER_TIMEOUT) {
                // 超时且无庄家 → NPC 公孙大娘自动坐庄
                Database::execute(
                    "UPDATE shaizi_rounds SET dealer_char_id = 0, dealer_name = ?, dealer_deposit = 0, max_bet = ?, status = 1, status_changed_at = NOW() WHERE id = ?",
                    ['公孙大娘', DEFAULT_NPC_LIMIT, $round['id']]
                );
            }
            break;

        case 1: // 下注中
            $bets = getRoundBets((int)$round['id']);
            $playerBets = array_filter($bets, fn($b) => !$b['is_dealer']);
            $dealerBet = null;
            foreach ($bets as $b) {
                if ($b['is_dealer']) { $dealerBet = $b; break; }
            }
            $totalPlayerBet = array_sum(array_column($playerBets, 'bet_amount'));

            if ($elapsed >= BETTING_DURATION) {
                // 超时处理
                if (empty($playerBets)) {
                    // 无人下注 → 取消本轮，清除庄家信息
                    if ((int)$round['dealer_char_id'] > 0 && $round['dealer_deposit'] > 0) {
                        MoneyHelper::addMoney((int)$round['dealer_char_id'], (int)$round['dealer_deposit']);
                    }
                    Database::execute(
                        "UPDATE shaizi_rounds SET dealer_char_id = 0, dealer_name = NULL, dealer_deposit = 0, max_bet = 2000, total_bet = 0, dealer_bet = 0, status = 0, status_changed_at = NOW() WHERE id = ?",
                        [$round['id']]
                    );
                } elseif ((int)$round['dealer_char_id'] == 0 && !$dealerBet) {
                    // NPC庄家自动下注
                    triggerRolling($round, $totalPlayerBet);
                } else {
                    // 玩家庄家未下注 → 取消，没收保证金，退还玩家
                    foreach ($playerBets as $pb) {
                        if ((int)$pb['char_id'] > 0) {
                            MoneyHelper::addMoney((int)$pb['char_id'], (int)$pb['bet_amount']);
                        }
                    }
                    Database::execute("DELETE FROM shaizi_bets WHERE round_id = ?", [$round['id']]);
                    Database::execute(
                        "UPDATE shaizi_rounds SET dealer_char_id = 0, dealer_name = NULL, dealer_deposit = 0, max_bet = 2000, total_bet = 0, dealer_bet = 0, status = 0, status_changed_at = NOW() WHERE id = ?",
                        [$round['id']]
                    );
                }
            } elseif ((int)$round['dealer_char_id'] == 0 && !$dealerBet && $elapsed >= NPC_AUTO_BET_TIME && !empty($playerBets)) {
                // NPC庄家提前自动下注（有玩家下注且到达25秒）
                triggerRolling($round, $totalPlayerBet);
            }
            break;

        case 2: // 掷骰中
            $bets = getRoundBets((int)$round['id']);
            $numBettors = count($bets);
            $animTime = ($numBettors + 1) * ROLL_INTERVAL + ROLL_BUFFER;
            if ($elapsed >= $animTime) {
                settleRound($round);
            }
            break;

        case 3: // 已结算
            if ($elapsed >= SETTLE_DISPLAY) {
                // 退还庄家保证金
                if ((int)$round['dealer_char_id'] > 0 && $round['dealer_deposit'] > 0) {
                    MoneyHelper::addMoney((int)$round['dealer_char_id'], (int)$round['dealer_deposit']);
                }
                // 创建新轮次
                createNewRound();
            }
            break;
    }
}

/**
 * 触发掷骰阶段 (庄家下注 → 进入掷骰)
 *
 * 移植自 LPC game_process + show_shaizi
 */
function triggerRolling(array $round, int $dealerBetAmount): void {
    global $diceArt, $shaMsgs;

    $roundId = (int)$round['id'];
    $isNPC = ((int)$round['dealer_char_id'] == 0);

    // NPC庄家不扣钱，玩家庄家扣钱
    if (!$isNPC) {
        $money = MoneyHelper::getMoneyInventory((int)$round['dealer_char_id']);
        if (intval($money['coin']) < $dealerBetAmount) {
            // 庄家钱不够，自动调整
            $dealerBetAmount = intval($money['coin']);
        }
        if ($dealerBetAmount > 0) {
            MoneyHelper::deductMoney((int)$round['dealer_char_id'], $dealerBetAmount);
        }
    }

    // 添加庄家下注记录
    Database::execute(
        "INSERT INTO shaizi_bets (round_id, char_id, char_name, bet_amount, is_dealer, roll_order, created_at)
         VALUES (?, ?, ?, ?, 1, 0, NOW())",
        [$roundId, (int)$round['dealer_char_id'], $round['dealer_name'], $dealerBetAmount]
    );

    // 更新总下注额
    $newTotal = (int)$round['total_bet'] + $dealerBetAmount;
    Database::execute("UPDATE shaizi_rounds SET dealer_bet = ?, total_bet = ?, status = 2, status_changed_at = NOW() WHERE id = ?", [$dealerBetAmount, $newTotal, $roundId]);

    // 掷所有骰子 (服务端一次性完成，前端逐帧播放)
    $bets = getRoundBets($roundId);
    $rollResults = [];
    $dealerPoint = null;

    // 庄家排在最后（增加悬念）
    $dealerBetIdx = null;
    $playerBetsList = [];
    foreach ($bets as $idx => $b) {
        if ($b['is_dealer']) {
            $dealerBetIdx = $idx;
        } else {
            $playerBetsList[] = $b;
        }
    }

    $order = 0;
    // 先掷玩家
    foreach ($playerBetsList as $b) {
        $d1 = mt_rand(1, 6);
        $d2 = mt_rand(1, 6);
        $pt = calculatePoint($d1, $d2);
        $msg = $shaMsgs[array_rand($shaMsgs)];

        Database::execute(
            "UPDATE shaizi_bets SET point1 = ?, point2 = ?, point = ?, point_name = ?, roll_order = ? WHERE id = ?",
            [$d1, $d2, $pt['point'], $pt['name'], $order, $b['id']]
        );

        $rollResults[] = [
            'char_name' => $b['char_name'],
            'is_dealer' => false,
            'point1' => $d1,
            'point2' => $d2,
            'point' => $pt['point'],
            'point_name' => $pt['name'],
            'action_msg' => $msg,
            'dice1_art' => $diceArt[$d1],
            'dice2_art' => $diceArt[$d2],
            'roll_order' => $order,
        ];
        $order++;
    }

    // 最后掷庄家
    if ($dealerBetIdx !== null) {
        $dealerBetRecord = $bets[$dealerBetIdx];
        $d1 = mt_rand(1, 6);
        $d2 = mt_rand(1, 6);
        $pt = calculatePoint($d1, $d2);
        $msg = $shaMsgs[array_rand($shaMsgs)];

        Database::execute(
            "UPDATE shaizi_bets SET point1 = ?, point2 = ?, point = ?, point_name = ?, roll_order = ? WHERE id = ?",
            [$d1, $d2, $pt['point'], $pt['name'], $order, $dealerBetRecord['id']]
        );

        $rollResults[] = [
            'char_name' => $round['dealer_name'],
            'is_dealer' => true,
            'point1' => $d1,
            'point2' => $d2,
            'point' => $pt['point'],
            'point_name' => $pt['name'],
            'action_msg' => $msg,
            'dice1_art' => $diceArt[$d1],
            'dice2_art' => $diceArt[$d2],
            'roll_order' => $order,
        ];

        $dealerPoint = $pt['point'];
        $dealerPointName = $pt['name'];
        $dealerP1 = $d1;
        $dealerP2 = $d2;
    }

    // 存储掷骰结果
    Database::execute(
        "UPDATE shaizi_rounds SET roll_results = ?, dealer_point1 = ?, dealer_point2 = ?, dealer_point = ?, dealer_point_name = ? WHERE id = ?",
        [json_encode($rollResults, JSON_UNESCAPED_UNICODE), $dealerP1 ?? 0, $dealerP2 ?? 0, $dealerPoint ?? 0, $dealerPointName ?? '', $roundId]
    );
}

/**
 * 结算 (移植自 LPC game_result)
 *
 * 规则:
 *   玩家点数 > 庄家点数 → 玩家赢，获2倍赌注
 *   玩家点数 ≤ 庄家点数 → 玩家输，赌注归庄家
 *   庄家获剩余赌池
 */
function settleRound(array $round): void {
    $roundId = (int)$round['id'];
    $bets = getRoundBets($roundId);

    $dealerBet = null;
    $playerBets = [];
    foreach ($bets as $b) {
        if ($b['is_dealer']) { $dealerBet = $b; }
        else { $playerBets[] = $b; }
    }

    if (!$dealerBet) {
        // 庄家不在，所有人都赢
        foreach ($playerBets as $pb) {
            $payout = 2 * (int)$pb['bet_amount'];
            if ((int)$pb['char_id'] > 0) {
                MoneyHelper::addMoney((int)$pb['char_id'], $payout);
            }
            Database::execute("UPDATE shaizi_bets SET is_win = 1, win_amount = ? WHERE id = ?", [$payout, $pb['id']]);
        }
        Database::execute("UPDATE shaizi_rounds SET status = 3, status_changed_at = NOW() WHERE id = ?", [$roundId]);
        return;
    }

    $zhuangPoint = (int)$dealerBet['point'];
    $pot = (int)$round['total_bet'];

    // 逐个比较玩家与庄家点数
    foreach ($playerBets as $pb) {
        $playerPoint = (int)$pb['point'];
        if ($playerPoint > $zhuangPoint) {
            // 玩家赢
            $payout = 2 * (int)$pb['bet_amount'];
            $pot -= $payout;
            if ((int)$pb['char_id'] > 0) {
                MoneyHelper::addMoney((int)$pb['char_id'], $payout);
            }
            Database::execute("UPDATE shaizi_bets SET is_win = 1, win_amount = ? WHERE id = ?", [$payout, $pb['id']]);
        } else {
            // 玩家输
            Database::execute("UPDATE shaizi_bets SET is_win = 0, win_amount = 0 WHERE id = ?", [$pb['id']]);
        }
    }

    // 庄家获剩余赌池
    if ($pot > 0 && (int)$dealerBet['char_id'] > 0) {
        MoneyHelper::addMoney((int)$dealerBet['char_id'], $pot);
    }
    // 记录庄家输赢
    $dealerNet = $pot - (int)$dealerBet['bet_amount'];
    Database::execute("UPDATE shaizi_bets SET is_win = NULL, win_amount = ? WHERE id = ?", [$pot, $dealerBet['id']]);

    Database::execute("UPDATE shaizi_rounds SET status = 3, status_changed_at = NOW() WHERE id = ?", [$roundId]);
}

// ─── 主逻辑 ────────────────────────────────────────────

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// === 获取状态 ===
if ($action === 'status') {
    advanceRound();
    $round = getCurrentRound();
    if (!$round) {
        createNewRound();
        $round = getCurrentRound();
    }

    $bets = getRoundBets((int)$round['id']);
    $now = time();
    $elapsed = $now - strtotime($round['status_changed_at']);

    $statusText = ['等待庄家', '下注中', '掷骰中', '已结算'][(int)$round['status']] ?? '未知';

    $remaining = 0;
    switch ((int)$round['status']) {
        case 0: $remaining = max(0, WAIT_DEALER_TIMEOUT - $elapsed); break;
        case 1: $remaining = max(0, BETTING_DURATION - $elapsed); break;
        case 2:
            $numBettors = count($bets);
            $remaining = max(0, ($numBettors + 1) * ROLL_INTERVAL + ROLL_BUFFER - $elapsed);
            break;
        case 3: $remaining = max(0, SETTLE_DISPLAY - $elapsed); break;
    }

    // 判断当前玩家是否是庄家
    $isDealer = ((int)$round['dealer_char_id'] === $charId);
    $isNPCDealer = ((int)$round['dealer_char_id'] === 0 && !empty($round['dealer_name']));

    // 查找当前玩家的下注
    $myBet = null;
    foreach ($bets as $b) {
        if ((int)$b['char_id'] === $charId) { $myBet = $b; break; }
    }

    // 非庄家玩家的下注列表
    $playerBetsList = [];
    $dealerBetInfo = null;
    foreach ($bets as $b) {
        if ($b['is_dealer']) {
            $dealerBetInfo = $b;
        } else {
            $playerBetsList[] = [
                'charName' => $b['char_name'],
                'betAmount' => (int)$b['bet_amount'],
            ];
        }
    }

    $money = MoneyHelper::getMoneyInventory($charId);
    $coinBalance = intval($money['coin']);

    $response = [
        'success' => true,
        'status' => (int)$round['status'],
        'statusText' => $statusText,
        'remaining' => $remaining,
        'roundId' => (int)$round['id'],
        'dealer' => $round['dealer_name'] ? [
            'name' => $round['dealer_name'],
            'isNPC' => $isNPCDealer,
            'deposit' => (int)$round['dealer_deposit'],
        ] : null,
        'maxBet' => (int)$round['max_bet'],
        'totalBet' => (int)$round['total_bet'],
        'dealerBet' => $dealerBetInfo ? (int)$dealerBetInfo['bet_amount'] : 0,
        'playerBets' => $playerBetsList,
        'isDealer' => $isDealer,
        'myBet' => $myBet ? [
            'amount' => (int)$myBet['bet_amount'],
            'point1' => $myBet['point1'] !== null ? (int)$myBet['point1'] : null,
            'point2' => $myBet['point2'] !== null ? (int)$myBet['point2'] : null,
            'point' => $myBet['point'] !== null ? (int)$myBet['point'] : null,
            'pointName' => $myBet['point_name'],
            'isWin' => $myBet['is_win'] !== null ? (bool)$myBet['is_win'] : null,
            'winAmount' => (int)$myBet['win_amount'],
        ] : null,
        'coinBalance' => $coinBalance,
        'charName' => $char['name'],
    ];

    // 掷骰中：返回动画数据
    if ((int)$round['status'] === 2 && $round['roll_results']) {
        $response['rollResults'] = json_decode($round['roll_results'], true);
        $response['animationTime'] = (count($bets) + 1) * ROLL_INTERVAL + ROLL_BUFFER;
    }

    // 已结算：返回完整结果
    if ((int)$round['status'] === 3) {
        $results = [];
        foreach ($bets as $b) {
            $results[] = [
                'charName' => $b['char_name'],
                'isDealer' => (bool)$b['is_dealer'],
                'betAmount' => (int)$b['bet_amount'],
                'point1' => $b['point1'] !== null ? (int)$b['point1'] : 0,
                'point2' => $b['point2'] !== null ? (int)$b['point2'] : 0,
                'point' => $b['point'] !== null ? (int)$b['point'] : 0,
                'pointName' => $b['point_name'] ?? '',
                'isWin' => $b['is_win'] !== null ? (bool)$b['is_win'] : null,
                'winAmount' => (int)$b['win_amount'],
            ];
        }
        $response['results'] = $results;
        $response['dealerPoint'] = (int)$round['dealer_point'];
        $response['dealerPointName'] = $round['dealer_point_name'];
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// === 坐庄 ===
if ($action === 'zuozhuang') {
    advanceRound();
    $round = getCurrentRound();
    if (!$round) {
        echo json_encode(['success' => false, 'message' => '系统错误'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((int)$round['status'] !== 0) {
        echo json_encode(['success' => false, 'message' => '现在不能坐庄。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!empty($round['dealer_name'])) {
        echo json_encode(['success' => false, 'message' => '已经有庄家了，叫' . $round['dealer_name'] . '让庄吧。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $betLimit = intval($_POST['bet_limit'] ?? DEFAULT_NPC_LIMIT);
    if ($betLimit < MIN_BET_LIMIT) $betLimit = MIN_BET_LIMIT;
    if ($betLimit > MAX_BET_LIMIT) $betLimit = MAX_BET_LIMIT;

    // 检查保证金
    $money = MoneyHelper::getMoneyInventory($charId);
    if (intval($money['coin']) < DEALER_DEPOSIT) {
        echo json_encode(['success' => false, 'message' => '你没有足够的钱交坐庄保证金（需' . DEALER_DEPOSIT . '文铜钱）。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 扣除保证金
    MoneyHelper::deductMoney($charId, DEALER_DEPOSIT);

    Database::execute(
        "UPDATE shaizi_rounds SET dealer_char_id = ?, dealer_name = ?, dealer_deposit = ?, max_bet = ?, status = 1, status_changed_at = NOW() WHERE id = ?",
        [$charId, $char['name'], DEALER_DEPOSIT, $betLimit, $round['id']]
    );

    echo json_encode([
        'success' => true,
        'message' => $char['name'] . '拿出一锭金子往桌上一拍，在庄家的位子上坐了下来。赌注上限：' . $betLimit . '文铜钱。',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// === 下注 ===
if ($action === 'bet') {
    advanceRound();
    $round = getCurrentRound();
    if (!$round) {
        echo json_encode(['success' => false, 'message' => '系统错误'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((int)$round['status'] !== 1) {
        echo json_encode(['success' => false, 'message' => '还没到下注的时候，听庄家吩咐。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $betAmount = intval($_POST['bet_amount'] ?? 0);
    if ($betAmount < MIN_BET) {
        echo json_encode(['success' => false, 'message' => '下注至少' . MIN_BET . '文铜钱。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $isDealer = ((int)$round['dealer_char_id'] === $charId);

    if ($isDealer) {
        // 庄家下注
        $bets = getRoundBets((int)$round['id']);
        $playerBets = array_filter($bets, fn($b) => !$b['is_dealer']);
        $totalPlayerBet = array_sum(array_column($playerBets, 'bet_amount'));

        if ($totalPlayerBet == 0) {
            echo json_encode(['success' => false, 'message' => '还没人下注呢。等大家都下完了你再下吧。'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($betAmount < $totalPlayerBet) {
            echo json_encode(['success' => false, 'message' => '这一轮共下注' . $totalPlayerBet . '文铜钱，庄家所押不能少于这个数目。'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 检查余额
        $money = MoneyHelper::getMoneyInventory($charId);
        if (intval($money['coin']) < $betAmount) {
            echo json_encode(['success' => false, 'message' => '你没这么多钱。'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 庄家下注 → 触发掷骰
        triggerRolling($round, $betAmount);

        echo json_encode([
            'success' => true,
            'message' => $char['name'] . '拿出' . $betAmount . '文铜钱，押在桌子上。手一压：好！现在开掷，大家一个一个来。',
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } else {
        // 非庄家玩家下注
        $bets = getRoundBets((int)$round['id']);
        foreach ($bets as $b) {
            if ((int)$b['char_id'] === $charId && !$b['is_dealer']) {
                echo json_encode(['success' => false, 'message' => '你已经下过注了。'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        if (count(array_filter($bets, fn($b) => !$b['is_dealer'])) >= MAX_PLAYERS) {
            echo json_encode(['success' => false, 'message' => '桌上人满了，等下一轮吧。'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 检查赌注上限
        $myTotalBet = $betAmount;
        if ($myTotalBet > (int)$round['max_bet']) {
            echo json_encode(['success' => false, 'message' => '庄家太穷了，赌注别超过' . $round['max_bet'] . '文铜钱。'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 检查余额
        $money = MoneyHelper::getMoneyInventory($charId);
        if (intval($money['coin']) < $betAmount) {
            echo json_encode(['success' => false, 'message' => '你没这么多钱。'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 扣除赌注
        MoneyHelper::deductMoney($charId, $betAmount);

        // 记录下注
        Database::execute(
            "INSERT INTO shaizi_bets (round_id, char_id, char_name, bet_amount, is_dealer, created_at) VALUES (?, ?, ?, ?, 0, NOW())",
            [(int)$round['id'], $charId, $char['name'], $betAmount]
        );

        // 更新总下注额
        Database::execute("UPDATE shaizi_rounds SET total_bet = total_bet + ? WHERE id = ?", [$betAmount, $round['id']]);

        echo json_encode([
            'success' => true,
            'message' => $char['name'] . '拿出' . $betAmount . '文铜钱，押在桌子上。',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// === 取消下注 (非庄家玩家) ===
if ($action === 'cancel') {
    advanceRound();
    $round = getCurrentRound();
    if (!$round) {
        echo json_encode(['success' => false, 'message' => '系统错误'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((int)$round['status'] !== 1) {
        echo json_encode(['success' => false, 'message' => '现在没有什么需要取消的。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $bets = getRoundBets((int)$round['id']);
    $myBet = null;
    foreach ($bets as $b) {
        if ((int)$b['char_id'] === $charId && !$b['is_dealer']) { $myBet = $b; break; }
    }

    if (!$myBet) {
        echo json_encode(['success' => false, 'message' => '你又没下注，在这里起什么哄？'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 退还赌注
    MoneyHelper::addMoney($charId, (int)$myBet['bet_amount']);

    // 删除下注记录
    Database::execute("DELETE FROM shaizi_bets WHERE id = ?", [$myBet['id']]);

    // 更新总下注额
    Database::execute("UPDATE shaizi_rounds SET total_bet = total_bet - ? WHERE id = ?", [(int)$myBet['bet_amount'], $round['id']]);

    echo json_encode([
        'success' => true,
        'message' => $char['name'] . '起身把放在桌子上的赌注拿了回来。',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// === 让庄 (庄家退出) ===
if ($action === 'retire') {
    advanceRound();
    $round = getCurrentRound();
    if (!$round) {
        echo json_encode(['success' => false, 'message' => '系统错误'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((int)$round['dealer_char_id'] !== $charId) {
        echo json_encode(['success' => false, 'message' => '你又不是庄家，让什么让？'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ((int)$round['status'] !== 1) {
        echo json_encode(['success' => false, 'message' => '想逃？好歹得赌完这一把。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 检查庄家是否已下注
    $bets = getRoundBets((int)$round['id']);
    $dealerHasBet = false;
    foreach ($bets as $b) {
        if ($b['is_dealer']) { $dealerHasBet = true; break; }
    }

    if ($dealerHasBet) {
        echo json_encode(['success' => false, 'message' => '已经开掷了，不能让庄。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 退还保证金
    MoneyHelper::addMoney($charId, (int)$round['dealer_deposit']);

    // 退还所有玩家赌注
    foreach ($bets as $b) {
        if (!$b['is_dealer'] && (int)$b['char_id'] > 0) {
            MoneyHelper::addMoney((int)$b['char_id'], (int)$b['bet_amount']);
        }
    }
    Database::execute("DELETE FROM shaizi_bets WHERE round_id = ?", [$round['id']]);

    // 重置轮次
    Database::execute(
        "UPDATE shaizi_rounds SET dealer_char_id = 0, dealer_name = NULL, dealer_deposit = 0, max_bet = 2000, total_bet = 0, dealer_bet = 0, status = 0, status_changed_at = NOW() WHERE id = ?",
        [$round['id']]
    );

    echo json_encode([
        'success' => true,
        'message' => $char['name'] . '站起来嚷道：这个霉庄我可不坐了！说罢顺手将桌上的保证金揣在怀里。',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 未知操作
echo json_encode(['success' => false, 'message' => '无效的操作'], JSON_UNESCAPED_UNICODE);
