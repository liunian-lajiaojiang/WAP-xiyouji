<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 骨骰房 AJAX 后端 API
 *
 * 多人共享轮次状态机 + 统一铜钱体系
 * 移植自 LPC: d/city/duchang2.c
 *
 * 接口:
 *   GET  gutou_api.php?action=status  → 获取当前轮次状态（含懒推进）
 *   POST gutou_api.php?action=bet     → 下注
 *
 * 状态机:
 *   0=空闲 → 1=押注中(24s,预告头彩号) → 2=开骰中(18s,逐枚开骰) → 3=已结算(6s) → 0=空闲 → ...
 *
 * 押注类型:
 *   tc=头彩(1赢36)  sd=双对(1赢12)  qx=七星(1赢6)  sx=散星(1赢3)
 *
 * 头彩预告: 押注阶段开始时掷两枚玉骰确定头彩号 big[0]/big[1]（两数不同）
 * 逐枚开骰: 开骰阶段第6秒开出第一枚 res[0]，第12秒开出第二枚 res[1]，第18秒结算
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
const BETTING_DURATION   = 24;   // 押注阶段时长(秒) — LPC: call_out("gamble_start",24)
const REVEAL_DURATION    = 18;   // 开骰阶段总时长(秒) — LPC: call_out("gamble_finish",18)
const DICE1_REVEAL_TIME  = 6;    // 第一枚骰子开出的时间(秒) — LPC: call_out("gamble_perform",6,0)
const DICE2_REVEAL_TIME  = 12;   // 第二枚骰子开出的时间(秒) — LPC: call_out("gamble_perform",12,1)
const SETTLE_DURATION    = 6;    // 结算展示时长(秒)
const COMMISSION_RATE    = 0.05; // 赢钱手续费 5%

// 四种押注类型及赔率 (移植自 LPC gutous mapping)
$GUTOU_TYPES = [
    'tc' => ['name' => '头彩', 'odds' => 36, 'color' => '#FF4444'],
    'sd' => ['name' => '双对', 'odds' => 12, 'color' => '#FFA500'],
    'qx' => ['name' => '七星', 'odds' => 6,  'color' => '#66BBFF'],
    'sx' => ['name' => '散星', 'odds' => 3,  'color' => '#90EE90'],
];

// ─── 自动建表 ──────────────────────────────────────────
ensureTablesExist();

// ─── 辅助函数 ──────────────────────────────────────────

/**
 * 确保 gutou_rounds 和 gutou_bets 表存在
 */
function ensureTablesExist(): void {
    // gutou_rounds 表
    $exists = Database::queryOne(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gutou_rounds'"
    );
    if (!$exists) {
        Database::execute("CREATE TABLE `gutou_rounds` (
            `id` int NOT NULL AUTO_INCREMENT,
            `status` tinyint NOT NULL DEFAULT 0 COMMENT '0=空闲 1=押注中 2=开骰中 3=已结算',
            `big_dice` varchar(20) NULL COMMENT '头彩预告骰号 JSON [big0,big1]',
            `res_dice` varchar(20) NULL COMMENT '实际开出骰号 JSON [res0,res1]',
            `winner` varchar(10) NULL COMMENT '中奖类型 tc/sd/qx/sx/null(空盘)',
            `betting_start` datetime NULL,
            `reveal_start` datetime NULL COMMENT '开骰阶段开始时间',
            `settle_time` datetime NULL,
            `created_at` datetime NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='骨骰房轮次状态机'");
    }

    // gutou_bets 表
    $exists = Database::queryOne(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gutou_bets'"
    );
    if (!$exists) {
        Database::execute("CREATE TABLE `gutou_bets` (
            `id` int NOT NULL AUTO_INCREMENT,
            `round_id` int NOT NULL,
            `char_id` int NOT NULL,
            `bet_kind` varchar(10) NOT NULL COMMENT 'tc/sd/qx/sx',
            `bet_amount` int NOT NULL DEFAULT 0,
            `is_settled` tinyint(1) NOT NULL DEFAULT 0,
            `is_win` tinyint(1) NULL,
            `win_amount` int NOT NULL DEFAULT 0,
            `commission` int NOT NULL DEFAULT 0,
            `coin_after` int NULL,
            `created_at` datetime NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_round` (`round_id`),
            INDEX `idx_char` (`char_id`),
            INDEX `idx_settled` (`is_settled`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='骨骰房下注记录'");
    }
}

/**
 * 掷一枚骰子 (移植自 LPC rdm: random(6)+1)
 * @return int 1-6
 */
function rdm(): int {
    return mt_rand(1, 6);
}

/**
 * 生成头彩预告骰号 (移植自 LPC gamble_prepare)
 * 掷两枚玉骰，确保两数不同（概率 1/36）
 * @return array [big0, big1]
 */
function rollHeadPrize(): array {
    $big0 = rdm();
    $big1 = rdm();
    // LPC: while (big[0] == big[1]) big[1] = rdm();
    while ($big0 === $big1) {
        $big1 = rdm();
    }
    return [$big0, $big1];
}

/**
 * 判定中奖类型 (移植自 LPC gamble_finish:285-319)
 *
 * LPC 逻辑:
 *   if (res[0]==big[0] && res[1]==big[1]) → tc (头彩, 36倍)
 *   else if (res[0]==res[1] && 偶数) → sd (双对, 12倍)
 *   else if (和==7) → qx (七星, 6倍)
 *   else if (和==3||5||9||11) → sx (散星, 3倍)
 *   else → 空盘
 *
 * @param int $res0 第一枚骰子
 * @param int $res1 第二枚骰子
 * @param int $big0 头彩预告号1
 * @param int $big1 头彩预告号2
 * @return array ['winner' => string|null, 'odds' => int]
 */
function determineWinner(int $res0, int $res1, int $big0, int $big1): array {
    // 头彩: 两骰与预告号完全一致
    if ($res0 === $big0 && $res1 === $big1) {
        return ['winner' => 'tc', 'odds' => 36];
    }
    // 双对: 两骰相同且为偶数
    if ($res0 === $res1 && ($res0 / 2 * 2 === $res0)) {
        return ['winner' => 'sd', 'odds' => 12];
    }
    // 七星: 两骰之和为7
    $sum = $res0 + $res1;
    if ($sum === 7) {
        return ['winner' => 'qx', 'odds' => 6];
    }
    // 散星: 两骰之和为3,5,9,11
    if ($sum === 3 || $sum === 5 || $sum === 9 || $sum === 11) {
        return ['winner' => 'sx', 'odds' => 3];
    }
    // 空盘
    return ['winner' => null, 'odds' => 0];
}

/**
 * 获取最新轮次
 */
function getLatestRound(): ?array {
    return Database::queryOne("SELECT * FROM gutou_rounds ORDER BY id DESC LIMIT 1");
}

/**
 * 创建新轮次 (进入押注阶段，预告头彩号)
 * 移植自 LPC gamble_prepare: 掷两枚玉骰确定头彩号
 */
function createNewRound(): array {
    $big = rollHeadPrize();
    $bigJson = json_encode($big);
    Database::execute(
        "INSERT INTO gutou_rounds (status, big_dice, betting_start, created_at) VALUES (1, ?, NOW(), NOW())",
        [$bigJson]
    );
    $id = Database::lastInsertId();
    return Database::queryOne("SELECT * FROM gutou_rounds WHERE id = ?", [$id]);
}

/**
 * 懒推进状态机
 *
 * 状态流转:
 *   null/0 → 1(创建新轮次,预告头彩号)
 *   1(押注中,24s) → 2(掷两枚骰,进入逐枚开骰)
 *   2(开骰中,18s) → 3(结算所有注)
 *   3(已结算,6s) → 0 → 1(新轮次)
 */
function advanceRound(?array &$round): void {
    if (!$round) {
        $round = createNewRound();
        return;
    }

    $now = time();

    // 状态 0=空闲 → 创建新轮次
    if ((int)$round['status'] === 0) {
        $round = createNewRound();
        return;
    }

    // 状态 1=押注中 → 到时进入开骰阶段
    if ((int)$round['status'] === 1) {
        $elapsed = $now - strtotime($round['betting_start']);
        if ($elapsed >= BETTING_DURATION) {
            // 掷两枚骰子作为结果 (移植自 LPC gamble_perform: res[i] = rdm())
            $res = [rdm(), rdm()];

            // 原子性推进: 只有一个请求能成功
            Database::execute(
                "UPDATE gutou_rounds
                 SET status = 2, reveal_start = NOW(), res_dice = ?
                 WHERE id = ? AND status = 1",
                [json_encode($res), $round['id']]
            );
            $round = Database::queryOne("SELECT * FROM gutou_rounds WHERE id = ?", [$round['id']]);
        }
        return;
    }

    // 状态 2=开骰中 → 18秒后结算
    if ((int)$round['status'] === 2) {
        $elapsed = $now - strtotime($round['reveal_start']);
        if ($elapsed >= REVEAL_DURATION) {
            // 事务结算
            Database::beginTransaction();
            try {
                $locked = Database::queryOne(
                    "SELECT * FROM gutou_rounds WHERE id = ? FOR UPDATE",
                    [$round['id']]
                );
                if ($locked && (int)$locked['status'] === 2) {
                    $big = json_decode($locked['big_dice'] ?? '[0,0]', true) ?: [0, 0];
                    $res = json_decode($locked['res_dice'] ?? '[0,0]', true) ?: [0, 0];
                    $result = determineWinner($res[0], $res[1], $big[0], $big[1]);
                    $winner = $result['winner'];
                    $odds = $result['odds'];

                    // 结算所有未结算的注
                    $bets = Database::queryAll(
                        "SELECT * FROM gutou_bets WHERE round_id = ? AND is_settled = 0",
                        [$locked['id']]
                    );
                    foreach ($bets as $bet) {
                        $isWin = ($winner !== null && $bet['bet_kind'] === $winner);
                        $winAmount = 0;
                        $commission = 0;
                        if ($isWin) {
                            $winAmount = $bet['bet_amount'] * $odds;
                            $commission = (int)($winAmount * COMMISSION_RATE);
                            $netWin = $winAmount - $commission;
                            MoneyHelper::addMoney($bet['char_id'], $netWin);
                        }
                        $money = MoneyHelper::getMoneyInventory($bet['char_id']);
                        $coinAfter = $money['coin'];

                        Database::execute(
                            "UPDATE gutou_bets SET is_settled = 1, is_win = ?, win_amount = ?, commission = ?, coin_after = ? WHERE id = ?",
                            [$isWin ? 1 : 0, $winAmount, $commission, $coinAfter, $bet['id']]
                        );
                    }

                    Database::execute(
                        "UPDATE gutou_rounds SET status = 3, settle_time = NOW(), winner = ? WHERE id = ?",
                        [$winner, $locked['id']]
                    );
                }
                Database::commit();
            } catch (Exception $e) {
                Database::rollBack();
                error_log('骨骰房结算失败: ' . $e->getMessage());
            }
            $round = Database::queryOne("SELECT * FROM gutou_rounds WHERE id = ?", [$round['id']]);
        }
        return;
    }

    // 状态 3=已结算 → 到时开新轮
    if ((int)$round['status'] === 3) {
        $elapsed = $now - strtotime($round['settle_time']);
        if ($elapsed >= SETTLE_DURATION) {
            Database::execute(
                "UPDATE gutou_rounds SET status = 0 WHERE id = ? AND status = 3",
                [$round['id']]
            );
            $round = createNewRound();
        }
        return;
    }
}

// ─── 主逻辑 ────────────────────────────────────────────

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// === 获取状态 ===
if ($action === 'status') {
    $round = getLatestRound();
    advanceRound($round);

    if (!$round) {
        echo json_encode(['success' => false, 'message' => '初始化中，请稍候'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 获取玩家本轮下注
    $myBet = null;
    if ((int)$round['status'] >= 1) {
        $myBet = Database::queryOne(
            "SELECT * FROM gutou_bets WHERE round_id = ? AND char_id = ?",
            [$round['id'], $charId]
        );
    }

    // 计算各阶段倒计时
    $bettingRemaining = 0;
    $revealRemaining = 0;
    $settleRemaining = 0;
    if ((int)$round['status'] === 1) {
        $bettingRemaining = max(0, BETTING_DURATION - (time() - strtotime($round['betting_start'])));
    } elseif ((int)$round['status'] === 2) {
        $revealRemaining = max(0, REVEAL_DURATION - (time() - strtotime($round['reveal_start'])));
    } elseif ((int)$round['status'] === 3) {
        $settleRemaining = max(0, SETTLE_DURATION - (time() - strtotime($round['settle_time'])));
    }

    // 获取本轮所有下注
    $allBets = [];
    if ((int)$round['status'] >= 1) {
        $allBets = Database::queryAll(
            "SELECT b.*, c.name as char_name
             FROM gutou_bets b
             LEFT JOIN characters c ON b.char_id = c.id
             WHERE b.round_id = ?
             ORDER BY b.created_at DESC",
            [$round['id']]
        );
    }

    $money = MoneyHelper::getMoneyInventory($charId);

    // 构建轮次数据
    $roundData = [
        'id' => (int)$round['id'],
        'status' => (int)$round['status'],
        'statusText' => ['空闲', '押注中', '开骰中', '已结算'][$round['status']] ?? '未知',
        'bettingRemaining' => $bettingRemaining,
        'revealRemaining' => $revealRemaining,
        'settleRemaining' => $settleRemaining,
        'bettingDuration' => BETTING_DURATION,
        'revealDuration' => REVEAL_DURATION,
        'settleDuration' => SETTLE_DURATION,
        'dice1RevealTime' => DICE1_REVEAL_TIME,
        'dice2RevealTime' => DICE2_REVEAL_TIME,
    ];

    // 状态1: 返回头彩预告号
    if ((int)$round['status'] === 1) {
        $big = json_decode($round['big_dice'] ?? '[0,0]', true) ?: [0, 0];
        $roundData['bigDice'] = [$big[0], $big[1]];
        $roundData['bigDiceText'] = '头彩骰号' . chineseNumber($big[0]) . chineseNumber($big[1]);
    }

    // 状态2: 返回骰子结果（前端按时间逐枚展示）
    if ((int)$round['status'] === 2) {
        $res = json_decode($round['res_dice'] ?? '[0,0]', true) ?: [0, 0];
        $big = json_decode($round['big_dice'] ?? '[0,0]', true) ?: [0, 0];
        $elapsed = time() - strtotime($round['reveal_start']);

        $roundData['dice1'] = $res[0];
        $roundData['dice2'] = $res[1];
        $roundData['bigDice'] = [$big[0], $big[1]];
        $roundData['dice1Revealed'] = $elapsed >= DICE1_REVEAL_TIME;
        $roundData['dice2Revealed'] = $elapsed >= DICE2_REVEAL_TIME;
        $roundData['revealElapsed'] = $elapsed;
        // 不返回 winner，让前端 suspense
    }

    // 状态3: 揭示完整结果和中奖类型
    if ((int)$round['status'] === 3) {
        $res = json_decode($round['res_dice'] ?? '[0,0]', true) ?: [0, 0];
        $big = json_decode($round['big_dice'] ?? '[0,0]', true) ?: [0, 0];
        $roundData['dice1'] = $res[0];
        $roundData['dice2'] = $res[1];
        $roundData['bigDice'] = [$big[0], $big[1]];
        $roundData['diceSum'] = $res[0] + $res[1];
        $roundData['winner'] = $round['winner'] ?? '';
        $roundData['winnerName'] = (!empty($round['winner']) && isset($GUTOU_TYPES[$round['winner']]))
            ? $GUTOU_TYPES[$round['winner']]['name'] : '空盘';
    }

    $response = [
        'success' => true,
        'round' => $roundData,
        'myBet' => $myBet ? [
            'kind' => $myBet['bet_kind'],
            'kindName' => $GUTOU_TYPES[$myBet['bet_kind']]['name'] ?? $myBet['bet_kind'],
            'amount' => (int)$myBet['bet_amount'],
            'isSettled' => (bool)$myBet['is_settled'],
            'isWin' => $myBet['is_win'] !== null ? (bool)$myBet['is_win'] : null,
            'winAmount' => (int)$myBet['win_amount'],
            'commission' => (int)$myBet['commission'],
            'netWin' => $myBet['is_win'] ? (int)($myBet['win_amount'] - $myBet['commission']) : 0,
        ] : null,
        'allBets' => array_map(function($b) use ($GUTOU_TYPES) {
            return [
                'charName' => $b['char_name'] ?? '未知',
                'kindName' => $GUTOU_TYPES[$b['bet_kind']]['name'] ?? $b['bet_kind'],
                'amount' => (int)$b['bet_amount'],
            ];
        }, $allBets),
        'coinBalance' => $money['coin'],
        'gutouTypes' => $GUTOU_TYPES,
        'commissionRate' => COMMISSION_RATE,
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// === 下注 ===
if ($action === 'bet') {
    $betKind = $_POST['kind'] ?? '';
    $betAmount = intval($_POST['amount'] ?? 0);

    if (!isset($GUTOU_TYPES[$betKind])) {
        echo json_encode(['success' => false, 'message' => '无效的押骰种类'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($betAmount <= 0) {
        echo json_encode(['success' => false, 'message' => '请输入有效的下注金额'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $round = getLatestRound();
    advanceRound($round);

    if (!$round || (int)$round['status'] !== 1) {
        echo json_encode(['success' => false, 'message' => '当前不在押注阶段，请等待下一轮'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $existing = Database::queryOne(
        "SELECT id FROM gutou_bets WHERE round_id = ? AND char_id = ?",
        [$round['id'], $charId]
    );
    if ($existing) {
        echo json_encode(['success' => false, 'message' => '本轮已押注，每人每轮只能押一次'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $money = MoneyHelper::getMoneyInventory($charId);
    if ($betAmount > $money['coin']) {
        echo json_encode(['success' => false, 'message' => '铜钱不足，当前余额: ' . $money['coin'] . '文'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    Database::beginTransaction();
    try {
        $lockedRound = Database::queryOne(
            "SELECT * FROM gutou_rounds WHERE id = ? FOR UPDATE",
            [$round['id']]
        );
        if (!$lockedRound || (int)$lockedRound['status'] !== 1) {
            Database::rollBack();
            echo json_encode(['success' => false, 'message' => '押注时间已过，请等待下一轮'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!MoneyHelper::deductMoney($charId, $betAmount)) {
            Database::rollBack();
            echo json_encode(['success' => false, 'message' => '扣款失败，铜钱不足'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        Database::execute(
            "INSERT INTO gutou_bets (round_id, char_id, bet_kind, bet_amount, created_at) VALUES (?, ?, ?, ?, NOW())",
            [$round['id'], $charId, $betKind, $betAmount]
        );

        Database::commit();
    } catch (Exception $e) {
        Database::rollBack();
        error_log('骨骰房下注失败: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '系统错误，请重试'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $newMoney = MoneyHelper::getMoneyInventory($charId);
    echo json_encode([
        'success' => true,
        'message' => '押注成功！押' . $GUTOU_TYPES[$betKind]['name'] . ' ' . $betAmount . '文铜钱',
        'coinBalance' => $newMoney['coin'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 未知操作
echo json_encode(['success' => false, 'message' => '无效的操作'], JSON_UNESCAPED_UNICODE);

// ─── 本地辅助函数 ──────────────────────────────────────

/**
 * 数字转中文 (1-6)
 * 移植自 LPC chinese_number
 */
function chineseNumber(int $n): string {
    $map = [1 => '一', 2 => '二', 3 => '三', 4 => '四', 5 => '五', 6 => '六'];
    return $map[$n] ?? (string)$n;
}
