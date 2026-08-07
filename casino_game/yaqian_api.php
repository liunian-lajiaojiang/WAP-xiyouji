<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 押签房 AJAX 后端 API
 *
 * 多人共享轮次状态机 + 统一铜钱体系
 *
 * 接口:
 *   GET  yaqian_api.php?action=status  → 获取当前轮次状态（含懒推进）
 *   POST yaqian_api.php?action=bet     → 下注
 *
 * 状态机:
 *   0=空闲 → 1=押注中(25s) → 2=开奖中(8s,逐根揭示) → 3=已结算(5s) → 0=空闲 → ...
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
const BETTING_DURATION = 25;  // 押注阶段时长(秒)
const DRAW_DURATION    = 8;   // 开奖阶段时长(秒)
const SETTLE_DURATION  = 5;   // 结算展示时长(秒)
const SIGN_INTERVAL    = 1.5; // 每根签揭示间隔(秒)
const COMMISSION_RATE  = 0.05; // 赢钱手续费 5%

$SIGN_TYPES = [
    'dqq' => ['name' => '大乾签', 'odds' => 32],
    'dkq' => ['name' => '大坤签', 'odds' => 32],
    'xqq' => ['name' => '小乾签', 'odds' => 16],
    'xkq' => ['name' => '小坤签', 'odds' => 16],
    'qq'  => ['name' => '乾签',   'odds' => 2],
    'kq'  => ['name' => '坤签',   'odds' => 2],
];

// ─── 自动建表 ──────────────────────────────────────────
ensureTablesExist();

// ─── 辅助函数 ──────────────────────────────────────────

/**
 * 确保 yaqian_rounds 和 yaqian_bets 表存在
 */
function ensureTablesExist(): void {
    $exists = Database::queryOne(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'yaqian_rounds'"
    );
    if (!$exists) {
        Database::execute("CREATE TABLE `yaqian_rounds` (
            `id` int NOT NULL AUTO_INCREMENT,
            `status` tinyint NOT NULL DEFAULT 0 COMMENT '0=空闲 1=押注中 2=开奖中 3=已结算',
            `signs` varchar(5) NULL COMMENT '5签结果 0=坤 1=乾',
            `win_kind` varchar(10) NULL COMMENT '中奖种类',
            `betting_start` datetime NULL,
            `draw_start` datetime NULL,
            `settle_time` datetime NULL,
            `created_at` datetime NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='押签房轮次状态机'");
    }

    $exists = Database::queryOne(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'yaqian_bets'"
    );
    if (!$exists) {
        Database::execute("CREATE TABLE `yaqian_bets` (
            `id` int NOT NULL AUTO_INCREMENT,
            `round_id` int NOT NULL,
            `char_id` int NOT NULL,
            `bet_kind` varchar(10) NOT NULL,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='押签房下注记录'");
    }
}

/**
 * 生成5根随机签 (0=坤, 1=乾)
 */
function generateSigns(): string {
    $signs = '';
    for ($i = 0; $i < 5; $i++) {
        $signs .= mt_rand(0, 1);
    }
    return $signs;
}

/**
 * 判定中奖种类 (移植自 duchang1.c:229-267)
 * @param string $signs 5位字符串, 如 "10110"
 * @return string 种类代码 dqq/dkq/xqq/xkq/qq/kq
 */
function determineWinKind(string $signs): string {
    $s = array_map('intval', str_split($signs));
    // 5乾
    if ($s[0] === 1 && $s[1] === 1 && $s[2] === 1 && $s[3] === 1 && $s[4] === 1) {
        return 'dqq';
    }
    // 5坤
    if ($s[0] === 0 && $s[1] === 0 && $s[2] === 0 && $s[3] === 0 && $s[4] === 0) {
        return 'dkq';
    }
    // 连续4乾
    if (($s[0] === 1 && $s[1] === 1 && $s[2] === 1 && $s[3] === 1) ||
        ($s[1] === 1 && $s[2] === 1 && $s[3] === 1 && $s[4] === 1)) {
        return 'xqq';
    }
    // 连续4坤
    if (($s[0] === 0 && $s[1] === 0 && $s[2] === 0 && $s[3] === 0) ||
        ($s[1] === 0 && $s[2] === 0 && $s[3] === 0 && $s[4] === 0)) {
        return 'xkq';
    }
    // 统计乾签数量
    $qianCount = array_sum($s);
    return $qianCount >= 3 ? 'qq' : 'kq';
}

/**
 * 获取最新轮次
 */
function getLatestRound(): ?array {
    return Database::queryOne("SELECT * FROM yaqian_rounds ORDER BY id DESC LIMIT 1");
}

/**
 * 创建新轮次 (进入押注阶段)
 */
function createNewRound(): array {
    Database::execute(
        "INSERT INTO yaqian_rounds (status, betting_start, created_at) VALUES (1, NOW(), NOW())"
    );
    $id = Database::lastInsertId();
    return Database::queryOne("SELECT * FROM yaqian_rounds WHERE id = ?", [$id]);
}

/**
 * 懒推进状态机
 * 每次被轮询时检查当前轮次是否超时，超时则推进状态
 */
function advanceRound(?array &$round): void {
    global $SIGN_TYPES;

    // 无轮次 → 创建新轮次
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

    // 状态 1=押注中 → 检查是否到时开奖
    if ((int)$round['status'] === 1) {
        $elapsed = $now - strtotime($round['betting_start']);
        if ($elapsed >= BETTING_DURATION) {
            $signs = generateSigns();
            // 原子性推进: 只有一个请求能成功
            $affected = Database::execute(
                "UPDATE yaqian_rounds SET status = 2, signs = ?, draw_start = NOW() WHERE id = ? AND status = 1",
                [$signs, $round['id']]
            );
            if ($affected > 0) {
                $round = Database::queryOne("SELECT * FROM yaqian_rounds WHERE id = ?", [$round['id']]);
            } else {
                // 另一个请求已推进，重新读取
                $round = Database::queryOne("SELECT * FROM yaqian_rounds WHERE id = ?", [$round['id']]);
            }
        }
        return;
    }

    // 状态 2=开奖中 → 检查是否到时结算
    if ((int)$round['status'] === 2) {
        $elapsed = $now - strtotime($round['draw_start']);
        if ($elapsed >= DRAW_DURATION) {
            // 事务结算，防止并发重复结算
            Database::beginTransaction();
            try {
                $locked = Database::queryOne(
                    "SELECT * FROM yaqian_rounds WHERE id = ? FOR UPDATE",
                    [$round['id']]
                );
                if ($locked && (int)$locked['status'] === 2) {
                    $winKind = determineWinKind($locked['signs']);
                    $winOdds = $SIGN_TYPES[$winKind]['odds'];

                    // 结算所有未结算的注
                    $bets = Database::queryAll(
                        "SELECT * FROM yaqian_bets WHERE round_id = ? AND is_settled = 0",
                        [$round['id']]
                    );
                    foreach ($bets as $bet) {
                        $isWin = ($bet['bet_kind'] === $winKind);
                        $winAmount = 0;
                        $commission = 0;
                        if ($isWin) {
                            $winAmount = $bet['bet_amount'] * $winOdds;
                            $commission = (int)($winAmount * COMMISSION_RATE);
                            $netWin = $winAmount - $commission;
                            MoneyHelper::addMoney($bet['char_id'], $netWin);
                        }
                        $money = MoneyHelper::getMoneyInventory($bet['char_id']);
                        $coinAfter = $money['coin'];

                        Database::execute(
                            "UPDATE yaqian_bets SET is_settled = 1, is_win = ?, win_amount = ?, commission = ?, coin_after = ? WHERE id = ?",
                            [$isWin ? 1 : 0, $winAmount, $commission, $coinAfter, $bet['id']]
                        );
                    }

                    Database::execute(
                        "UPDATE yaqian_rounds SET status = 3, win_kind = ?, settle_time = NOW() WHERE id = ?",
                        [$winKind, $round['id']]
                    );
                }
                Database::commit();
            } catch (Exception $e) {
                Database::rollBack();
                error_log('押签房结算失败: ' . $e->getMessage());
            }
            $round = Database::queryOne("SELECT * FROM yaqian_rounds WHERE id = ?", [$round['id']]);
        }
        return;
    }

    // 状态 3=已结算 → 检查是否到时开新轮
    if ((int)$round['status'] === 3) {
        $elapsed = $now - strtotime($round['settle_time']);
        if ($elapsed >= SETTLE_DURATION) {
            Database::execute(
                "UPDATE yaqian_rounds SET status = 0 WHERE id = ? AND status = 3",
                [$round['id']]
            );
            $round = createNewRound();
        }
        return;
    }
}

/**
 * 计算当前应揭示的签数量
 */
function getRevealedCount(array $round): int {
    if ((int)$round['status'] === 2 && $round['signs']) {
        $elapsed = time() - strtotime($round['draw_start']);
        return min(5, (int)floor($elapsed / SIGN_INTERVAL) + 1);
    }
    if ((int)$round['status'] >= 3 && $round['signs']) {
        return 5;
    }
    return 0;
}

// ─── 主逻辑 ────────────────────────────────────────────

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// === 获取状态 ===
if ($action === 'status') {
    $round = getLatestRound();
    advanceRound($round);

    // 获取玩家本轮下注
    $myBet = null;
    if ($round && (int)$round['status'] >= 1) {
        $myBet = Database::queryOne(
            "SELECT * FROM yaqian_bets WHERE round_id = ? AND char_id = ?",
            [$round['id'], $charId]
        );
    }

    // 计算揭示的签
    $revealedCount = getRevealedCount($round);
    $visibleSigns = '';
    if ($revealedCount > 0 && $round['signs']) {
        $visibleSigns = substr($round['signs'], 0, $revealedCount);
    }

    // 计算倒计时
    $bettingRemaining = 0;
    $drawRemaining = 0;
    $settleRemaining = 0;
    if ((int)$round['status'] === 1) {
        $bettingRemaining = max(0, BETTING_DURATION - (time() - strtotime($round['betting_start'])));
    } elseif ((int)$round['status'] === 2) {
        $drawRemaining = max(0, DRAW_DURATION - (time() - strtotime($round['draw_start'])));
    } elseif ((int)$round['status'] === 3) {
        $settleRemaining = max(0, SETTLE_DURATION - (time() - strtotime($round['settle_time'])));
    }

    // 获取本轮所有下注（多人氛围）
    $allBets = [];
    if ($round && (int)$round['status'] >= 1) {
        $allBets = Database::queryAll(
            "SELECT b.*, c.name as char_name
             FROM yaqian_bets b
             LEFT JOIN characters c ON b.char_id = c.id
             WHERE b.round_id = ?
             ORDER BY b.created_at DESC",
            [$round['id']]
        );
    }

    // 中奖种类名称
    $winKindName = null;
    if ($round && $round['win_kind']) {
        $winKindName = $SIGN_TYPES[$round['win_kind']]['name'] ?? null;
    }

    $money = MoneyHelper::getMoneyInventory($charId);

    $response = [
        'success' => true,
        'round' => [
            'id' => (int)$round['id'],
            'status' => (int)$round['status'],
            'statusText' => ['空闲', '押注中', '开奖中', '已结算'][$round['status']] ?? '未知',
            'visibleSigns' => $visibleSigns,
            'revealedCount' => $revealedCount,
            'allSigns' => ((int)$round['status'] >= 3) ? $round['signs'] : '',
            'winKind' => $round['win_kind'] ?? '',
            'winKindName' => $winKindName,
            'bettingRemaining' => $bettingRemaining,
            'drawRemaining' => $drawRemaining,
            'settleRemaining' => $settleRemaining,
        ],
        'myBet' => $myBet ? [
            'kind' => $myBet['bet_kind'],
            'kindName' => $SIGN_TYPES[$myBet['bet_kind']]['name'] ?? $myBet['bet_kind'],
            'amount' => (int)$myBet['bet_amount'],
            'isSettled' => (bool)$myBet['is_settled'],
            'isWin' => $myBet['is_win'] !== null ? (bool)$myBet['is_win'] : null,
            'winAmount' => (int)$myBet['win_amount'],
            'commission' => (int)$myBet['commission'],
            'netWin' => $myBet['is_win'] ? (int)($myBet['win_amount'] - $myBet['commission']) : 0,
        ] : null,
        'allBets' => array_map(function($b) use ($SIGN_TYPES) {
            return [
                'charName' => $b['char_name'] ?? '未知',
                'kindName' => $SIGN_TYPES[$b['bet_kind']]['name'] ?? $b['bet_kind'],
                'amount' => (int)$b['bet_amount'],
            ];
        }, $allBets),
        'coinBalance' => $money['coin'],
        'signTypes' => $SIGN_TYPES,
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// === 下注 ===
if ($action === 'bet') {
    $betKind = $_POST['kind'] ?? '';
    $betAmount = intval($_POST['amount'] ?? 0);

    // 验证押签种类
    if (!isset($SIGN_TYPES[$betKind])) {
        echo json_encode(['success' => false, 'message' => '无效的押签种类'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 验证金额
    if ($betAmount <= 0) {
        echo json_encode(['success' => false, 'message' => '请输入有效的下注金额'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 推进轮次并检查状态
    $round = getLatestRound();
    advanceRound($round);

    if (!$round || (int)$round['status'] !== 1) {
        echo json_encode(['success' => false, 'message' => '当前不在押注阶段，请等待下一轮'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 检查是否已下注
    $existing = Database::queryOne(
        "SELECT id FROM yaqian_bets WHERE round_id = ? AND char_id = ?",
        [$round['id'], $charId]
    );
    if ($existing) {
        echo json_encode(['success' => false, 'message' => '本轮已押注，每人每轮只能押一次'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 检查余额
    $money = MoneyHelper::getMoneyInventory($charId);
    if ($betAmount > $money['coin']) {
        echo json_encode(['success' => false, 'message' => '铜钱不足，当前余额: ' . $money['coin'] . '文'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 事务下注: 锁定轮次 → 扣款 → 插入记录
    Database::beginTransaction();
    try {
        $lockedRound = Database::queryOne(
            "SELECT * FROM yaqian_rounds WHERE id = ? FOR UPDATE",
            [$round['id']]
        );
        if (!$lockedRound || (int)$lockedRound['status'] !== 1) {
            Database::rollBack();
            echo json_encode(['success' => false, 'message' => '押注时间已过，请等待下一轮'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 扣除铜钱
        if (!MoneyHelper::deductMoney($charId, $betAmount)) {
            Database::rollBack();
            echo json_encode(['success' => false, 'message' => '扣款失败，铜钱不足'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 插入下注记录
        Database::execute(
            "INSERT INTO yaqian_bets (round_id, char_id, bet_kind, bet_amount, created_at) VALUES (?, ?, ?, ?, NOW())",
            [$round['id'], $charId, $betKind, $betAmount]
        );

        Database::commit();
    } catch (Exception $e) {
        Database::rollBack();
        error_log('押签房下注失败: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '系统错误，请重试'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $newMoney = MoneyHelper::getMoneyInventory($charId);
    echo json_encode([
        'success' => true,
        'message' => '押注成功！押' . $SIGN_TYPES[$betKind]['name'] . ' ' . $betAmount . '文铜钱',
        'coinBalance' => $newMoney['coin'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 未知操作
echo json_encode(['success' => false, 'message' => '无效的操作'], JSON_UNESCAPED_UNICODE);
