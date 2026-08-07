<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 斗鸡房 AJAX 后端 API
 *
 * 多人共享轮次状态机 + 统一铜钱体系
 * 移植自 LPC: d/city/duchang3.c
 *
 * 接口:
 *   GET  douji_api.php?action=status  → 获取当前轮次状态（含懒推进）
 *   POST douji_api.php?action=bet     → 下注
 *
 * 状态机:
 *   0=空闲 → 1=押注中(20s) → 2=斗鸡中(逐帧文字动画,变长) → 3=已结算(6s) → 0=空闲 → ...
 *
 * 押注类型:
 *   hg=红冠鸡(1赢2)  lw=绿尾鸡(1赢2)
 *
 * 特殊机制:
 *   双败赔本: 若两鸡同归于尽，所有玩家都输（LPC: total=0, win="none of them"）
 *
 * 模拟战斗: 一次性模拟整场斗鸡并记录所有帧
 *   鸡的HP: 400-500 (移植自 LPC douji.c: max_kee = 400 + random2(2)*100)
 *   每帧双方同时攻击: 伤害 = mt_rand(10, 30)
 *   死亡线: HP < 15 (移植自 LPC gamble_perform: ji->query("kee")<15)
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
const BETTING_DURATION    = 20;  // 押注阶段时长(秒) — LPC: call_out("gamble_start",20)
const FIGHT_FRAME_INTERVAL = 1;  // 斗鸡每帧间隔(秒) — LPC: call_out("gamble_perform",1)
const FIGHT_END_BUFFER     = 2;  // 最后一帧后等待秒数再结算
const SETTLE_DURATION      = 6;  // 结算展示时长(秒)
const COMMISSION_RATE      = 0.05; // 赢钱手续费 5%
const ODDS                 = 2;    // 赔率: 一赢二
const DEATH_THRESHOLD      = 15;   // 死亡线 HP<15 — LPC: ji->query("kee")<15
const MAX_FIGHT_ROUNDS     = 60;   // 最大战斗轮数(安全限制)
const MIN_HP               = 400;  // 鸡的初始HP下限 — LPC: max_kee = 400
const MAX_HP               = 500;  // 鸡的初始HP上限 — LPC: max_kee = 400 + 100

// 两种鸡 (移植自 LPC jis mapping)
$JI_TYPES = [
    'hg' => ['name' => '红冠鸡', 'color' => '#FF4444'],
    'lw' => ['name' => '绿尾鸡', 'color' => '#44BB44'],
];

// ─── 自动建表 ──────────────────────────────────────────
ensureTablesExist();

// ─── 辅助函数 ──────────────────────────────────────────

/**
 * 确保 douji_rounds 和 douji_bets 表存在
 */
function ensureTablesExist(): void {
    // douji_rounds 表
    $exists = Database::queryOne(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'douji_rounds'"
    );
    if (!$exists) {
        Database::execute("CREATE TABLE `douji_rounds` (
            `id` int NOT NULL AUTO_INCREMENT,
            `status` tinyint NOT NULL DEFAULT 0 COMMENT '0=空闲 1=押注中 2=斗鸡中 3=已结算',
            `hg_init_hp` int NULL COMMENT '红冠鸡初始HP',
            `lw_init_hp` int NULL COMMENT '绿尾鸡初始HP',
            `fight_frames` text NULL COMMENT '斗鸡所有帧HP JSON [[hg_hp,lw_hp],...]',
            `winner` varchar(10) NULL COMMENT '获胜鸡代码 hg/lw/null(双败)',
            `betting_start` datetime NULL,
            `fight_start` datetime NULL COMMENT '斗鸡阶段开始时间',
            `settle_time` datetime NULL,
            `created_at` datetime NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='斗鸡房轮次状态机'");
    }

    // douji_bets 表
    $exists = Database::queryOne(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'douji_bets'"
    );
    if (!$exists) {
        Database::execute("CREATE TABLE `douji_bets` (
            `id` int NOT NULL AUTO_INCREMENT,
            `round_id` int NOT NULL,
            `char_id` int NOT NULL,
            `bet_kind` varchar(10) NOT NULL COMMENT 'hg/lw',
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='斗鸡房下注记录'");
    }
}

/**
 * 生成鸡的初始HP (移植自 LPC douji.c: max_kee = 400 + random2(2)*100)
 * @return int 400 or 500
 */
function rollHp(): int {
    return MIN_HP + mt_rand(0, 1) * 100;
}

/**
 * 一次性模拟整场斗鸡并记录所有帧
 *
 * 移植自 LPC gamble_perform 循环:
 *   - 每秒检查两只鸡是否仍在战斗
 *   - 若鸡的 kee < 15 则强制死亡
 *   - 战斗结束后调用 gamble_finish
 *
 * PHP 模拟逻辑:
 *   - 每帧双方同时攻击，伤害 = mt_rand(10, 30)
 *   - 5%几率暴击(2倍伤害)，10%几率闪避(0伤害)
 *   - HP < 15 时死亡
 *   - 最多60轮(安全限制)
 *
 * @param int $hgHp 红冠鸡初始HP
 * @param int $lwHp 绿尾鸡初始HP
 * @return array ['frames' => [[hg_hp, lw_hp], ...], 'final' => [hg_hp, lw_hp], 'winner' => string|null]
 */
function simulateFight(int $hgHp, int $lwHp): array {
    $frames = [[$hgHp, $lwHp]]; // 第0帧：初始状态

    for ($round = 0; $round < MAX_FIGHT_ROUNDS; $round++) {
        // 检查是否已有鸡死亡
        if ($hgHp < DEATH_THRESHOLD || $lwHp < DEATH_THRESHOLD) {
            break;
        }

        // 红冠鸡攻击绿尾鸡
        $hgDmg = mt_rand(10, 30);
        if (mt_rand(1, 100) <= 5) {
            $hgDmg *= 2; // 5%暴击
        }
        if (mt_rand(1, 100) <= 10) {
            $hgDmg = 0; // 10%闪避
        }

        // 绿尾鸡攻击红冠鸡
        $lwDmg = mt_rand(10, 30);
        if (mt_rand(1, 100) <= 5) {
            $lwDmg *= 2;
        }
        if (mt_rand(1, 100) <= 10) {
            $lwDmg = 0;
        }

        // 双方同时受到伤害
        $hgHp = max(0, $hgHp - $lwDmg);
        $lwHp = max(0, $lwHp - $hgDmg);

        $frames[] = [$hgHp, $lwHp];
    }

    // 达到最大轮数仍未分出胜负，按HP高低判定
    if ($round >= MAX_FIGHT_ROUNDS - 1 && $hgHp >= DEATH_THRESHOLD && $lwHp >= DEATH_THRESHOLD) {
        if ($hgHp > $lwHp) {
            $lwHp = 0;
        } elseif ($lwHp > $hgHp) {
            $hgHp = 0;
        } else {
            $hgHp = 0;
            $lwHp = 0;
        }
        $frames[] = [$hgHp, $lwHp];
    }

    // 判定获胜者 (移植自 LPC gamble_finish:294-315)
    $winner = null;
    if ($hgHp < DEATH_THRESHOLD && $lwHp < DEATH_THRESHOLD) {
        // 双败 (LPC: total=0, win="none of them")
        $winner = null;
    } elseif ($hgHp < DEATH_THRESHOLD) {
        $winner = 'lw'; // 绿尾鸡获胜
    } elseif ($lwHp < DEATH_THRESHOLD) {
        $winner = 'hg'; // 红冠鸡获胜
    } else {
        // 不应该到这里，但以防万一
        $winner = $hgHp >= $lwHp ? 'hg' : 'lw';
    }

    return [
        'frames' => $frames,
        'final' => [$hgHp, $lwHp],
        'winner' => $winner,
    ];
}

/**
 * 获取最新轮次
 */
function getLatestRound(): ?array {
    return Database::queryOne("SELECT * FROM douji_rounds ORDER BY id DESC LIMIT 1");
}

/**
 * 创建新轮次 (进入押注阶段)
 * 移植自 LPC gamble_prepare: 鸡仙拿出两只鸡
 */
function createNewRound(): array {
    $hgHp = rollHp();
    $lwHp = rollHp();
    Database::execute(
        "INSERT INTO douji_rounds (status, hg_init_hp, lw_init_hp, betting_start, created_at)
         VALUES (1, ?, ?, NOW(), NOW())",
        [$hgHp, $lwHp]
    );
    $id = Database::lastInsertId();
    return Database::queryOne("SELECT * FROM douji_rounds WHERE id = ?", [$id]);
}

/**
 * 懒推进状态机
 *
 * 状态流转:
 *   null/0 → 1(创建新轮次)
 *   1(押注中,20s) → 2(一次性模拟斗鸡,记录所有帧)
 *   2(斗鸡动画,总帧数*间隔+缓冲) → 3(结算所有注)
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

    // 状态 1=押注中 → 到时一次性模拟整场斗鸡
    if ((int)$round['status'] === 1) {
        $elapsed = $now - strtotime($round['betting_start']);
        if ($elapsed >= BETTING_DURATION) {
            // 模拟斗鸡
            $hgHp = (int)$round['hg_init_hp'];
            $lwHp = (int)$round['lw_init_hp'];
            $fight = simulateFight($hgHp, $lwHp);
            $framesJson = json_encode($fight['frames']);

            // 原子性推进: 只有一个请求能成功
            Database::execute(
                "UPDATE douji_rounds
                 SET status = 2, fight_start = NOW(), fight_frames = ?, winner = ?
                 WHERE id = ? AND status = 1",
                [$framesJson, $fight['winner'], $round['id']]
            );
            $round = Database::queryOne("SELECT * FROM douji_rounds WHERE id = ?", [$round['id']]);
        }
        return;
    }

    // 状态 2=斗鸡动画 → 所有帧播放完+缓冲后结算
    if ((int)$round['status'] === 2) {
        $frames = json_decode($round['fight_frames'] ?? '[]', true) ?: [];
        $totalFrames = max(1, count($frames));
        $fightTotalDuration = $totalFrames * FIGHT_FRAME_INTERVAL + FIGHT_END_BUFFER;
        $elapsed = $now - strtotime($round['fight_start']);

        if ($elapsed >= $fightTotalDuration) {
            // 事务结算
            Database::beginTransaction();
            try {
                $locked = Database::queryOne(
                    "SELECT * FROM douji_rounds WHERE id = ? FOR UPDATE",
                    [$round['id']]
                );
                if ($locked && (int)$locked['status'] === 2) {
                    $winner = $locked['winner'];

                    // 兼容: 无 winner 数据则重新模拟
                    if ($winner === null && empty($locked['fight_frames'])) {
                        $fight = simulateFight((int)$locked['hg_init_hp'], (int)$locked['lw_init_hp']);
                        $winner = $fight['winner'];
                        Database::execute(
                            "UPDATE douji_rounds SET fight_frames = ?, winner = ? WHERE id = ?",
                            [json_encode($fight['frames']), $winner, $locked['id']]
                        );
                    }

                    // 结算所有未结算的注
                    $bets = Database::queryAll(
                        "SELECT * FROM douji_bets WHERE round_id = ? AND is_settled = 0",
                        [$locked['id']]
                    );
                    foreach ($bets as $bet) {
                        // 双败时所有玩家都输 (LPC: total=0 → player_loses for everyone)
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
                            "UPDATE douji_bets SET is_settled = 1, is_win = ?, win_amount = ?, commission = ?, coin_after = ? WHERE id = ?",
                            [$isWin ? 1 : 0, $winAmount, $commission, $coinAfter, $bet['id']]
                        );
                    }

                    Database::execute(
                        "UPDATE douji_rounds SET status = 3, settle_time = NOW() WHERE id = ?",
                        [$locked['id']]
                    );
                }
                Database::commit();
            } catch (Exception $e) {
                Database::rollBack();
                error_log('斗鸡房结算失败: ' . $e->getMessage());
            }
            $round = Database::queryOne("SELECT * FROM douji_rounds WHERE id = ?", [$round['id']]);
        }
        return;
    }

    // 状态 3=已结算 → 到时开新轮
    if ((int)$round['status'] === 3) {
        $elapsed = $now - strtotime($round['settle_time']);
        if ($elapsed >= SETTLE_DURATION) {
            Database::execute(
                "UPDATE douji_rounds SET status = 0 WHERE id = ? AND status = 3",
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
            "SELECT * FROM douji_bets WHERE round_id = ? AND char_id = ?",
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
             FROM douji_bets b
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
        'statusText' => ['空闲', '押注中', '斗鸡中', '已结算'][$round['status']] ?? '未知',
        'bettingRemaining' => $bettingRemaining,
        'settleRemaining' => $settleRemaining,
    ];

    // 状态1: 返回初始HP（展示两只鸡的状态）
    if ((int)$round['status'] === 1) {
        $roundData['hgInitHp'] = (int)$round['hg_init_hp'];
        $roundData['lwInitHp'] = (int)$round['lw_init_hp'];
    }

    // 状态2: 返回帧数据供前端文字动画 (不返回 winner)
    if ((int)$round['status'] === 2) {
        $frames = json_decode($round['fight_frames'] ?? '[]', true) ?: [];
        $totalFrames = max(1, count($frames));
        $elapsed = time() - strtotime($round['fight_start']);
        $frameIndex = min($totalFrames - 1, max(0, (int)floor($elapsed / FIGHT_FRAME_INTERVAL)));
        $currentHp = $frames[$frameIndex] ?? [(int)$round['hg_init_hp'], (int)$round['lw_init_hp']];

        $roundData['hgInitHp'] = (int)$round['hg_init_hp'];
        $roundData['lwInitHp'] = (int)$round['lw_init_hp'];
        $roundData['hgHp'] = $currentHp[0];
        $roundData['lwHp'] = $currentHp[1];
        $roundData['fightFrames'] = $frames;
        $roundData['frameIndex'] = $frameIndex;
        $roundData['totalFrames'] = $totalFrames;
        $roundData['fightFrameInterval'] = FIGHT_FRAME_INTERVAL;
        // 不返回 winner，让前端 suspense
    }

    // 状态3: 揭示最终结果
    if ((int)$round['status'] === 3) {
        $frames = json_decode($round['fight_frames'] ?? '[]', true) ?: [];
        $finalHp = end($frames) ?: [(int)$round['hg_init_hp'], (int)$round['lw_init_hp']];
        $roundData['hgInitHp'] = (int)$round['hg_init_hp'];
        $roundData['lwInitHp'] = (int)$round['lw_init_hp'];
        $roundData['hgFinalHp'] = $finalHp[0];
        $roundData['lwFinalHp'] = $finalHp[1];
        $roundData['winner'] = $round['winner'] ?? '';
        $roundData['winnerName'] = (!empty($round['winner']) && isset($JI_TYPES[$round['winner']]))
            ? $JI_TYPES[$round['winner']]['name'] : '双败赔本';
        $roundData['isDoubleLoss'] = empty($round['winner']);
    }

    $response = [
        'success' => true,
        'round' => $roundData,
        'myBet' => $myBet ? [
            'kind' => $myBet['bet_kind'],
            'kindName' => $JI_TYPES[$myBet['bet_kind']]['name'] ?? $myBet['bet_kind'],
            'amount' => (int)$myBet['bet_amount'],
            'isSettled' => (bool)$myBet['is_settled'],
            'isWin' => $myBet['is_win'] !== null ? (bool)$myBet['is_win'] : null,
            'winAmount' => (int)$myBet['win_amount'],
            'commission' => (int)$myBet['commission'],
            'netWin' => $myBet['is_win'] ? (int)($myBet['win_amount'] - $myBet['commission']) : 0,
        ] : null,
        'allBets' => array_map(function($b) use ($JI_TYPES) {
            return [
                'charName' => $b['char_name'] ?? '未知',
                'kindName' => $JI_TYPES[$b['bet_kind']]['name'] ?? $b['bet_kind'],
                'amount' => (int)$b['bet_amount'],
            ];
        }, $allBets),
        'coinBalance' => $money['coin'],
        'jiTypes' => $JI_TYPES,
        'odds' => ODDS,
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// === 下注 ===
if ($action === 'bet') {
    $betKind = $_POST['kind'] ?? '';
    $betAmount = intval($_POST['amount'] ?? 0);

    if (!isset($JI_TYPES[$betKind])) {
        echo json_encode(['success' => false, 'message' => '无效的押鸡种类'], JSON_UNESCAPED_UNICODE);
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
        "SELECT id FROM douji_bets WHERE round_id = ? AND char_id = ?",
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
            "SELECT * FROM douji_rounds WHERE id = ? FOR UPDATE",
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
            "INSERT INTO douji_bets (round_id, char_id, bet_kind, bet_amount, created_at) VALUES (?, ?, ?, ?, NOW())",
            [$round['id'], $charId, $betKind, $betAmount]
        );

        Database::commit();
    } catch (Exception $e) {
        Database::rollBack();
        error_log('斗鸡房下注失败: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '系统错误，请重试'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $newMoney = MoneyHelper::getMoneyInventory($charId);
    echo json_encode([
        'success' => true,
        'message' => '押注成功！押' . $JI_TYPES[$betKind]['name'] . ' ' . $betAmount . '文铜钱',
        'coinBalance' => $newMoney['coin'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 未知操作
echo json_encode(['success' => false, 'message' => '无效的操作'], JSON_UNESCAPED_UNICODE);
