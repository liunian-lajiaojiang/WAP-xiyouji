<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 赛龟房 AJAX 后端 API
 *
 * 多人共享轮次状态机 + 统一铜钱体系
 * 移植自 LPC: d/city/duchang4.c
 *
 * 接口:
 *   GET  saigui_api.php?action=status  → 获取当前轮次状态（含懒推进）
 *   POST saigui_api.php?action=bet     → 下注
 *
 * 状态机 (文字动画版，一次性模拟整场赛跑并记录所有帧):
 *   0=空闲 → 1=押注中(20s) → 2=赛跑中(逐帧文字动画,变长) → 3=已结算(6s) → 0=空闲 → ...
 *
 * 赛龟: 长寿龟(cs) / 千年龟(qn) / 不老龟(bl)
 * 赛道: 30格，一次性模拟整场赛跑，记录每一帧位置供前端文字动画
 * 赔率: 一赢三 (3倍)
 * 特殊: 二龟/三龟同胜则无赢家
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
const BETTING_DURATION   = 20;   // 押注阶段时长(秒)
const RACE_FRAME_INTERVAL = 1;   // 赛跑每帧间隔(秒)，对应 LPC call_out("gamble_perform",1)
const RACE_END_BUFFER     = 2;   // 最后一帧后等待秒数再结算
const SETTLE_DURATION     = 6;   // 结算展示时长(秒)
const COMMISSION_RATE     = 0.05; // 赢钱手续费 5%
const ODDS                = 3;    // 赔率: 一赢三
const RACE_FINISH_LINE    = 30;   // 赛道终点格数

$GUI_TYPES = [
    'cs' => ['name' => '长寿龟', 'color' => '#FFD700'],
    'qn' => ['name' => '千年龟', 'color' => '#87CEEB'],
    'bl' => ['name' => '不老龟', 'color' => '#90EE90'],
];

// ─── 自动建表 ──────────────────────────────────────────
ensureTablesExist();

// ─── 辅助函数 ──────────────────────────────────────────

/**
 * 确保 saigui_rounds 和 saigui_bets 表存在
 * 兼容旧版表结构迁移
 */
function ensureTablesExist(): void {
    // saigui_rounds 表
    $exists = Database::queryOne(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'saigui_rounds'"
    );
    if (!$exists) {
        Database::execute("CREATE TABLE `saigui_rounds` (
            `id` int NOT NULL AUTO_INCREMENT,
            `status` tinyint NOT NULL DEFAULT 0 COMMENT '0=空闲 1=押注中 2=赛跑中 3=已结算',
            `positions` varchar(20) NULL COMMENT '三龟最终格数 JSON [0,0,0]',
            `race_frames` text NULL COMMENT '赛跑所有帧位置 JSON [[cs,qn,bl],...]',
            `winner` varchar(10) NULL COMMENT '获胜龟代码 cs/qn/bl/null(无赢家)',
            `winner_reason` varchar(50) NULL COMMENT '无赢家原因',
            `betting_start` datetime NULL,
            `race_start` datetime NULL,
            `settle_time` datetime NULL,
            `created_at` datetime NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='赛龟房轮次状态机'");
    } else {
        // 迁移: 添加 race_frames 列
        $col = Database::queryOne(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'saigui_rounds'
               AND COLUMN_NAME = 'race_frames'"
        );
        if (!$col) {
            Database::execute(
                "ALTER TABLE saigui_rounds ADD COLUMN race_frames text NULL COMMENT '赛跑所有帧位置JSON' AFTER positions"
            );
        }
        // 迁移: 添加 winner_reason 列
        $col = Database::queryOne(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'saigui_rounds'
               AND COLUMN_NAME = 'winner_reason'"
        );
        if (!$col) {
            Database::execute(
                "ALTER TABLE saigui_rounds ADD COLUMN winner_reason varchar(50) NULL COMMENT '无赢家原因' AFTER winner"
            );
        }
    }

    // saigui_bets 表
    $exists = Database::queryOne(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'saigui_bets'"
    );
    if (!$exists) {
        Database::execute("CREATE TABLE `saigui_bets` (
            `id` int NOT NULL AUTO_INCREMENT,
            `round_id` int NOT NULL,
            `char_id` int NOT NULL,
            `bet_kind` varchar(10) NOT NULL COMMENT 'cs/qn/bl',
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='赛龟房下注记录'");
    }
}

/**
 * 生成随机移动步数 (移植自 LPC move_on: random2(7))
 * @return int 0-6
 */
function randomStep(): int {
    return mt_rand(0, 6);
}

/**
 * boost_guis: 当某龟接近终点时，有概率直接推到终点
 * 移植自 LPC boost_guis
 * @param array $positions [cs, qn, bl] 当前格数
 * @return array 推进后的格数
 */
function boostGuis(array $positions): array {
    for ($i = 0; $i < 10; $i++) {
        $j = mt_rand(0, 2);
        if ($positions[$j] >= 28) {
            $positions[$j] = RACE_FINISH_LINE;
            break;
        }
    }
    return $positions;
}

/**
 * 推进所有龟一步 (移植自 LPC gamble_perform 中的 move_on 循环)
 * @param array $positions 当前格数 [cs, qn, bl]
 * @return array 新格数
 */
function moveOnAll(array $positions): array {
    for ($i = 0; $i < 3; $i++) {
        $positions[$i] += randomStep();
        if ($positions[$i] > RACE_FINISH_LINE) {
            $positions[$i] = RACE_FINISH_LINE;
        }
    }
    return boostGuis($positions);
}

/**
 * 一次性模拟整场赛跑并记录所有帧 (移植自 LPC gamble_perform 循环)
 *
 * LPC 逻辑: 循环推进三龟位置，直到至少一只龟到达终点(30格)
 * 每轮: move_on(各龟) → boost_guis → 检查是否有人到30
 * 本函数额外记录每一帧的位置，供前端文字动画播放
 *
 * @return array ['frames' => [[cs,qn,bl],...], 'final' => [cs,qn,bl]]
 */
function simulateRace(): array {
    $positions = [0, 0, 0];
    $frames = [$positions]; // 第0帧：起始位置
    while ($positions[0] < RACE_FINISH_LINE &&
           $positions[1] < RACE_FINISH_LINE &&
           $positions[2] < RACE_FINISH_LINE) {
        $positions = moveOnAll($positions);
        $frames[] = $positions;
    }
    return ['frames' => $frames, 'final' => $positions];
}

/**
 * 判定获胜者 (移植自 LPC gamble_finish:285-322)
 * @param array $positions 最终格数 [cs, qn, bl]
 * @return array ['winner' => string|null, 'reason' => string]
 */
function determineWinner(array $positions): array {
    $cs = $positions[0];
    $qn = $positions[1];
    $bl = $positions[2];

    // 三龟同胜
    if ($cs >= RACE_FINISH_LINE && $qn >= RACE_FINISH_LINE && $bl >= RACE_FINISH_LINE) {
        return ['winner' => null, 'reason' => '三龟同胜无赢家'];
    }
    // 二龟同胜
    if (($cs >= RACE_FINISH_LINE && $qn >= RACE_FINISH_LINE) ||
        ($qn >= RACE_FINISH_LINE && $bl >= RACE_FINISH_LINE) ||
        ($bl >= RACE_FINISH_LINE && $cs >= RACE_FINISH_LINE)) {
        return ['winner' => null, 'reason' => '二龟同胜无赢家'];
    }
    // 单龟获胜
    if ($cs >= RACE_FINISH_LINE) {
        return ['winner' => 'cs', 'reason' => '长寿龟获胜'];
    }
    if ($qn >= RACE_FINISH_LINE) {
        return ['winner' => 'qn', 'reason' => '千年龟获胜'];
    }
    return ['winner' => 'bl', 'reason' => '不老龟获胜'];
}

/**
 * 获取最新轮次
 */
function getLatestRound(): ?array {
    return Database::queryOne("SELECT * FROM saigui_rounds ORDER BY id DESC LIMIT 1");
}

/**
 * 创建新轮次 (进入押注阶段)
 */
function createNewRound(): array {
    Database::execute(
        "INSERT INTO saigui_rounds (status, betting_start, created_at) VALUES (1, NOW(), NOW())"
    );
    $id = Database::lastInsertId();
    return Database::queryOne("SELECT * FROM saigui_rounds WHERE id = ?", [$id]);
}

/**
 * 懒推进状态机
 *
 * 状态流转:
 *   null/0 → 1(创建新轮次)
 *   1(押注中,20s) → 2(一次性模拟赛跑,记录所有帧)
 *   2(赛跑动画,总帧数*间隔+缓冲) → 3(结算所有注)
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

    // 状态 1=押注中 → 到时一次性模拟整场赛跑
    if ((int)$round['status'] === 1) {
        $elapsed = $now - strtotime($round['betting_start']);
        if ($elapsed >= BETTING_DURATION) {
            // 模拟赛跑，记录所有帧
            $race = simulateRace();
            $result = determineWinner($race['final']);
            $positionsJson = json_encode($race['final']);
            $framesJson = json_encode($race['frames']);

            // 原子性推进: 只有一个请求能成功
            Database::execute(
                "UPDATE saigui_rounds
                 SET status = 2, race_start = NOW(),
                     positions = ?, race_frames = ?, winner = ?, winner_reason = ?
                 WHERE id = ? AND status = 1",
                [$positionsJson, $framesJson, $result['winner'], $result['reason'], $round['id']]
            );
            $round = Database::queryOne("SELECT * FROM saigui_rounds WHERE id = ?", [$round['id']]);
        }
        return;
    }

    // 状态 2=赛跑动画 → 所有帧播放完+缓冲后结算
    if ((int)$round['status'] === 2) {
        $frames = json_decode($round['race_frames'] ?? '[]', true) ?: [];
        $totalFrames = max(1, count($frames));
        $raceTotalDuration = $totalFrames * RACE_FRAME_INTERVAL + RACE_END_BUFFER;
        $elapsed = $now - strtotime($round['race_start']);

        if ($elapsed >= $raceTotalDuration) {
            // 事务结算
            Database::beginTransaction();
            try {
                $locked = Database::queryOne(
                    "SELECT * FROM saigui_rounds WHERE id = ? FOR UPDATE",
                    [$round['id']]
                );
                if ($locked && (int)$locked['status'] === 2) {
                    $winner = $locked['winner'];
                    $winnerReason = $locked['winner_reason'];

                    // 兼容旧版: 无 winner 数据则重新模拟
                    if (empty($winner) && empty($winnerReason)) {
                        $positions = json_decode($locked['positions'] ?? 'null', true);
                        if ($positions === null) {
                            $race = simulateRace();
                            $positions = $race['final'];
                        }
                        $result = determineWinner($positions);
                        $winner = $result['winner'];
                        $winnerReason = $result['reason'];
                        Database::execute(
                            "UPDATE saigui_rounds SET positions = ?, winner = ?, winner_reason = ? WHERE id = ?",
                            [json_encode($positions), $winner, $winnerReason, $locked['id']]
                        );
                    }

                    // 结算所有未结算的注
                    $bets = Database::queryAll(
                        "SELECT * FROM saigui_bets WHERE round_id = ? AND is_settled = 0",
                        [$locked['id']]
                    );
                    foreach ($bets as $bet) {
                        $isWin = ($winner !== null && $winner !== '' && $bet['bet_kind'] === $winner);
                        $winAmount = 0;
                        $commission = 0;
                        if ($isWin) {
                            $winAmount = $bet['bet_amount'] * ODDS;
                            $commission = (int)($winAmount * COMMISSION_RATE);
                            $netWin = $winAmount - $commission;
                            MoneyHelper::addMoney($bet['char_id'], $netWin);
                        }
                        $money = MoneyHelper::getMoneyInventory($bet['char_id']);
                        $coinAfter = $money['coin'];

                        Database::execute(
                            "UPDATE saigui_bets SET is_settled = 1, is_win = ?, win_amount = ?, commission = ?, coin_after = ? WHERE id = ?",
                            [$isWin ? 1 : 0, $winAmount, $commission, $coinAfter, $bet['id']]
                        );
                    }

                    Database::execute(
                        "UPDATE saigui_rounds SET status = 3, settle_time = NOW() WHERE id = ?",
                        [$locked['id']]
                    );
                }
                Database::commit();
            } catch (Exception $e) {
                Database::rollBack();
                error_log('赛龟房结算失败: ' . $e->getMessage());
            }
            $round = Database::queryOne("SELECT * FROM saigui_rounds WHERE id = ?", [$round['id']]);
        }
        return;
    }

    // 状态 3=已结算 → 到时开新轮
    if ((int)$round['status'] === 3) {
        $elapsed = $now - strtotime($round['settle_time']);
        if ($elapsed >= SETTLE_DURATION) {
            Database::execute(
                "UPDATE saigui_rounds SET status = 0 WHERE id = ? AND status = 3",
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
            "SELECT * FROM saigui_bets WHERE round_id = ? AND char_id = ?",
            [$round['id'], $charId]
        );
    }

    // 计算各阶段倒计时
    $bettingRemaining = 0;
    $settleRemaining = 0;
    if ((int)$round['status'] === 1) {
        $bettingRemaining = max(0, BETTING_DURATION - (time() - strtotime($round['betting_start'])));
    } elseif ((int)$round['status'] === 3) {
        $settleRemaining = max(0, SETTLE_DURATION - (time() - strtotime($round['settle_time'])));
    }

    // 获取本轮所有下注
    $allBets = [];
    if ((int)$round['status'] >= 1) {
        $allBets = Database::queryAll(
            "SELECT b.*, c.name as char_name
             FROM saigui_bets b
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
        'statusText' => ['空闲', '押注中', '赛跑中', '已结算'][$round['status']] ?? '未知',
        'finishLine' => RACE_FINISH_LINE,
        'bettingRemaining' => $bettingRemaining,
        'settleRemaining' => $settleRemaining,
    ];

    // 状态2: 返回帧数据供前端文字动画 (不返回 winner)
    if ((int)$round['status'] === 2) {
        $frames = json_decode($round['race_frames'] ?? '[]', true) ?: [];
        $totalFrames = max(1, count($frames));
        $elapsed = time() - strtotime($round['race_start']);
        $frameIndex = min($totalFrames - 1, max(0, (int)floor($elapsed / RACE_FRAME_INTERVAL)));
        $currentPositions = $frames[$frameIndex] ?? [0, 0, 0];

        $guiInfo = [];
        foreach (['cs', 'qn', 'bl'] as $i => $code) {
            $guiInfo[] = [
                'code' => $code,
                'name' => $GUI_TYPES[$code]['name'],
                'color' => $GUI_TYPES[$code]['color'],
                'position' => $currentPositions[$i],
            ];
        }
        $roundData['guis'] = $guiInfo;
        $roundData['raceFrames'] = $frames;
        $roundData['frameIndex'] = $frameIndex;
        $roundData['totalFrames'] = $totalFrames;
        $roundData['raceFrameInterval'] = RACE_FRAME_INTERVAL;
    }

    // 状态3: 揭示最终位置和结果
    if ((int)$round['status'] === 3) {
        $positions = json_decode($round['positions'] ?? '[0,0,0]', true) ?: [0, 0, 0];
        $guiInfo = [];
        foreach (['cs', 'qn', 'bl'] as $i => $code) {
            $guiInfo[] = [
                'code' => $code,
                'name' => $GUI_TYPES[$code]['name'],
                'color' => $GUI_TYPES[$code]['color'],
                'position' => $positions[$i],
            ];
        }
        $roundData['guis'] = $guiInfo;
        $roundData['winner'] = $round['winner'] ?? '';
        $roundData['winnerName'] = (!empty($round['winner']) && isset($GUI_TYPES[$round['winner']]))
            ? $GUI_TYPES[$round['winner']]['name'] : null;
        $roundData['winnerReason'] = $round['winner_reason'] ?? null;
    }

    $response = [
        'success' => true,
        'round' => $roundData,
        'myBet' => $myBet ? [
            'kind' => $myBet['bet_kind'],
            'kindName' => $GUI_TYPES[$myBet['bet_kind']]['name'] ?? $myBet['bet_kind'],
            'amount' => (int)$myBet['bet_amount'],
            'isSettled' => (bool)$myBet['is_settled'],
            'isWin' => $myBet['is_win'] !== null ? (bool)$myBet['is_win'] : null,
            'winAmount' => (int)$myBet['win_amount'],
            'commission' => (int)$myBet['commission'],
            'netWin' => $myBet['is_win'] ? (int)($myBet['win_amount'] - $myBet['commission']) : 0,
        ] : null,
        'allBets' => array_map(function($b) use ($GUI_TYPES) {
            return [
                'charName' => $b['char_name'] ?? '未知',
                'kindName' => $GUI_TYPES[$b['bet_kind']]['name'] ?? $b['bet_kind'],
                'amount' => (int)$b['bet_amount'],
            ];
        }, $allBets),
        'coinBalance' => $money['coin'],
        'guiTypes' => $GUI_TYPES,
        'odds' => ODDS,
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// === 下注 ===
if ($action === 'bet') {
    $betKind = $_POST['kind'] ?? '';
    $betAmount = intval($_POST['amount'] ?? 0);

    if (!isset($GUI_TYPES[$betKind])) {
        echo json_encode(['success' => false, 'message' => '无效的押龟种类'], JSON_UNESCAPED_UNICODE);
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
        "SELECT id FROM saigui_bets WHERE round_id = ? AND char_id = ?",
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
            "SELECT * FROM saigui_rounds WHERE id = ? FOR UPDATE",
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
            "INSERT INTO saigui_bets (round_id, char_id, bet_kind, bet_amount, created_at) VALUES (?, ?, ?, ?, NOW())",
            [$round['id'], $charId, $betKind, $betAmount]
        );

        Database::commit();
    } catch (Exception $e) {
        Database::rollBack();
        error_log('赛龟房下注失败: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '系统错误，请重试'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $newMoney = MoneyHelper::getMoneyInventory($charId);
    echo json_encode([
        'success' => true,
        'message' => '押注成功！押' . $GUI_TYPES[$betKind]['name'] . ' ' . $betAmount . '文铜钱',
        'coinBalance' => $newMoney['coin'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 未知操作
echo json_encode(['success' => false, 'message' => '无效的操作'], JSON_UNESCAPED_UNICODE);
