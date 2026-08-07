<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 拱猪房 AJAX 后端 API
 *
 * 完整四人拱猪对战 — 移植自 LPC: d/city/piggy.c (piggy_n.c 继承)
 *
 * 接口:
 *   GET  piggy_api.php?action=status              → 获取当前牌桌状态（含懒推进）
 *   POST piggy_api.php?action=sit&dir=east         → 入座（east/north/west/south）
 *   POST piggy_api.php?action=leave                → 离座
 *   POST piggy_api.php?action=deal                 → 要求发牌
 *   POST piggy_api.php?action=sell&card=SQ&flag=m  → 卖牌（flag=m明卖, a暗卖）
 *   POST piggy_api.php?action=pass                 → 停卖
 *   POST piggy_api.php?action=play&card=SQ         → 出牌
 *   POST piggy_api.php?action=claim&type=all       → 要求全收
 *   POST piggy_api.php?action=claim&type=yes/no    → 同意/反对全收
 *
 * 状态机:
 *   0=等人(60s→NPC填位) → 1=等发牌(30s→自动发牌) → 2=等卖牌(30s→自动停卖)
 *   → 3=出牌(30s/回合→自动出牌) → 4=算分(15s→新局) → 0
 *
 * 计分规则 (移植自 LPC score_player):
 *   红桃: A=-50, K=-40, Q=-30, J=-20, T~5=-10, 4~2=0; 收全红→+200, 猪变正
 *   猪(黑桃Q): -100; 羊(方片J): +100; 变压器(草花T): +50(独收)或倍率
 *   卖牌: 明卖×4, 暗卖×2; 血(红桃A)卖牌影响所有红桃分
 *   全收(红桃+猪+羊+变压器): 其余三人得猪头
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
const WAIT_PLAYERS_TIMEOUT = 60;
const DEAL_TIMEOUT         = 30;
const SELL_TIMEOUT         = 30;
const PLAY_TURN_TIMEOUT    = 30;
const SCORE_DISPLAY_TIME   = 15;
const NUM_PLAYERS          = 4;
const SCORE_LIMIT          = 2000;
const PIG_PENALTY          = 3;
const ANTE_AMOUNT          = 50; // 入场费(铜钱)

// ─── 方向 ──────────────────────────────────────────────
$DIR  = ['east', 'north', 'west', 'south'];
$CDIR = ['east' => '东', 'north' => '北', 'west' => '西', 'south' => '南'];

// ─── 搭档映射 (双人拱猪用) ────────────────────────────
// 移植自 LPC piggy_two.c: 东西一队, 南北一队
$PARTNER = ['east' => 'west', 'west' => 'east', 'south' => 'north', 'north' => 'south'];
$PART_INDEX = ['east' => 2, 'north' => 3, 'west' => 0, 'south' => 1]; // DIR 索引映射

// ─── 房间模式映射 ──────────────────────────────────────
// piggy_n/piggy_s = 普通拱猪(个人计分); piggy_e/piggy_w = 双人拱猪(搭档计分)
$ROOM_MODE = [
    'city/piggy_n' => 'normal',
    'city/piggy_s' => 'normal',
    'city/piggy_e' => 'partner',
    'city/piggy_w' => 'partner',
];

// 从请求中获取房间ID和游戏模式
$roomId = $_REQUEST['room_id'] ?? 'city/piggy_n';
$gameMode = $ROOM_MODE[$roomId] ?? 'normal';

// ─── 扑克牌定义 (索引0为空, 1-52为52张牌) ──────────────
// 花色: spade=黑桃, heart=红桃, diamond=方片, club=草花
// rank: 2-14 (14=A, 13=K, 12=Q, 11=J, 10=T)
// worth: 原始分值(最终分=worth/100)
// misc: pig=猪, blood=血, sheep=羊, doubler=变压器

function buildCardDeck(): array {
    $cards = [[]]; // 索引0为空
    $suitInfo = [
        'spade'   => ['base' => 1,  'color' => 'spade'],
        'heart'   => ['base' => 14, 'color' => 'heart'],
        'diamond' => ['base' => 27, 'color' => 'diamond'],
        'club'    => ['base' => 40, 'color' => 'club'],
    ];
    $heartWorth = [14 => -5000, 13 => -4000, 12 => -3000, 11 => -2000,
                   10 => -1000, 9 => -1000, 8 => -1000, 7 => -1000,
                   6 => -1000, 5 => -1000, 4 => -1, 3 => -1, 2 => -1];
    foreach ($suitInfo as $suit => $info) {
        for ($rank = 14; $rank >= 2; $rank--) {
            $index = $info['base'] + (14 - $rank);
            $worth = 0;
            $misc = '';
            if ($suit === 'heart') {
                $worth = $heartWorth[$rank];
            }
            // 特殊牌
            if ($suit === 'spade' && $rank === 12) { $worth = -10000; $misc = 'pig'; }
            if ($suit === 'heart' && $rank === 14) { $misc = 'blood'; }
            if ($suit === 'diamond' && $rank === 11) { $worth = 10000; $misc = 'sheep'; }
            if ($suit === 'club' && $rank === 10) { $worth = 5000; $misc = 'doubler'; }
            $cards[$index] = [
                'suit' => $suit, 'rank' => $rank, 'worth' => $worth, 'misc' => $misc,
            ];
        }
    }
    return $cards;
}

$CARDS = buildCardDeck();
$CARDNO = 52;

// 可卖牌索引: 3=猪(SQ), 14=血(HA), 30=羊(DJ), 44=变压器(CT)
$SELLABLE = [3, 14, 30, 44];
$MISC_CARDS = ['pig' => 3, 'blood' => 14, 'sheep' => 30, 'doubler' => 44];

// 全角数字
$CNUM = ['？', '１', '２', '３', '４', '５', '６', '７', '８', '９', 'Ｔ', 'Ｊ', 'Ｑ', 'Ｋ', 'Ａ'];

// ─── 自动建表 ──────────────────────────────────────────
ensureTablesExist();

// ─── 辅助函数 ──────────────────────────────────────────

function ensureTablesExist(): void {
    $exists = Database::queryOne(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'piggy_rounds'"
    );
    if (!$exists) {
        Database::execute("CREATE TABLE `piggy_rounds` (
            `id` int NOT NULL AUTO_INCREMENT,
            `room_id` varchar(30) NOT NULL DEFAULT 'city/piggy_n' COMMENT '房间ID',
            `game_mode` varchar(10) NOT NULL DEFAULT 'normal' COMMENT 'normal=普通 partner=搭档',
            `status` int NOT NULL DEFAULT 0 COMMENT '0=等人 1=等发牌 2=等卖牌 3=出牌 4=算分',
            `status_changed_at` datetime NULL DEFAULT CURRENT_TIMESTAMP,
            `seats` json NULL COMMENT '四个座位信息',
            `game_state` json NULL COMMENT '完整游戏状态(手牌/桌面/卖牌等)',
            `scoring` json NULL COMMENT '积分信息',
            `pig_owner` varchar(10) NULL DEFAULT '',
            `full_collector` varchar(10) NULL DEFAULT '',
            `result_summary` text NULL COMMENT '本局结果摘要',
            `created_at` datetime NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_room_status` (`room_id`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='拱猪房轮次'");
    } else {
        // 兼容旧表: 添加 room_id 和 game_mode 列
        $cols = Database::queryAll("SHOW COLUMNS FROM piggy_rounds LIKE 'room_id'");
        if (empty($cols)) {
            Database::execute("ALTER TABLE piggy_rounds ADD COLUMN `room_id` varchar(30) NOT NULL DEFAULT 'city/piggy_n' COMMENT '房间ID' AFTER `id`");
        }
        $cols2 = Database::queryAll("SHOW COLUMNS FROM piggy_rounds LIKE 'game_mode'");
        if (empty($cols2)) {
            Database::execute("ALTER TABLE piggy_rounds ADD COLUMN `game_mode` varchar(10) NOT NULL DEFAULT 'normal' COMMENT 'normal=普通 partner=搭档' AFTER `room_id`");
        }
    }

    $exists2 = Database::queryOne(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'piggy_bets'"
    );
    if (!$exists2) {
        Database::execute("CREATE TABLE `piggy_bets` (
            `id` int NOT NULL AUTO_INCREMENT,
            `round_id` int NOT NULL,
            `char_id` int NOT NULL COMMENT '角色ID(0=NPC)',
            `char_name` varchar(100) NOT NULL,
            `seat` varchar(10) NOT NULL COMMENT 'east/north/west/south',
            `is_npc` tinyint(1) NOT NULL DEFAULT 0,
            `hand_score` int NOT NULL DEFAULT 0 COMMENT '本手牌得分',
            `is_pighead` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否得猪头',
            `collected_cards` text NULL COMMENT '收的牌(JSON)',
            `sold_cards` text NULL COMMENT '卖出的牌(JSON)',
            `rank_before` int NOT NULL DEFAULT 100,
            `rank_after` int NOT NULL DEFAULT 100,
            `coin_change` int NOT NULL DEFAULT 0 COMMENT '铜钱变化',
            `created_at` datetime NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_round` (`round_id`),
            INDEX `idx_char` (`char_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='拱猪房参与记录'");
    }

    $exists3 = Database::queryOne(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'piggy_rankings'"
    );
    if (!$exists3) {
        Database::execute("CREATE TABLE `piggy_rankings` (
            `id` int NOT NULL AUTO_INCREMENT,
            `char_id` int NOT NULL UNIQUE,
            `char_name` varchar(100) NOT NULL,
            `rank_points` int NOT NULL DEFAULT 100 COMMENT '等级分',
            `hands_played` int NOT NULL DEFAULT 0 COMMENT '手数',
            `heads_received` int NOT NULL DEFAULT 0 COMMENT '猪头数',
            `heads_given` int NOT NULL DEFAULT 0 COMMENT '给他人猪头数',
            `updated_at` datetime NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_rank` (`rank_points` DESC)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='拱猪排行榜'");
    }
}

/** 获取牌名 */
function getCardName(int $idx): string {
    global $CARDS, $CNUM;
    if (!isset($CARDS[$idx])) return '？';
    $c = $CARDS[$idx];
    $suitNames = ['spade' => '黑桃', 'heart' => '红桃', 'diamond' => '方片', 'club' => '草花'];
    return $suitNames[$c['suit']] . $CNUM[$c['rank']];
}

/** 获取牌的短名(用于前端,如 SQ, HA, DJ, CT) */
function getCardShort(int $idx): string {
    global $CARDS;
    if (!isset($CARDS[$idx])) return '??';
    $c = $CARDS[$idx];
    $suitChar = ['spade' => 'S', 'heart' => 'H', 'diamond' => 'D', 'club' => 'C'];
    $rankChar = [10 => 'T', 11 => 'J', 12 => 'Q', 13 => 'K', 14 => 'A'];
    $r = $rankChar[$c['rank']] ?? (string)$c['rank'];
    return $suitChar[$c['suit']] . $r;
}

/** 从短名解析牌索引 */
function parseCardShort(string $short): int {
    global $CARDS;
    if (strlen($short) < 2) return 0;
    $suitChar = strtoupper($short[0]);
    $rankStr = substr($short, 1);
    $suitMap = ['S' => 'spade', 'H' => 'heart', 'D' => 'diamond', 'C' => 'club'];
    if (!isset($suitMap[$suitChar])) return 0;
    $suit = $suitMap[$suitChar];
    $rankMap = ['T' => 10, 'J' => 11, 'Q' => 12, 'K' => 13, 'A' => 14];
    $rank = $rankMap[strtoupper($rankStr)] ?? intval($rankStr);
    if ($rank < 2 || $rank > 14) return 0;
    $base = ['spade' => 1, 'heart' => 14, 'diamond' => 27, 'club' => 40][$suit];
    return $base + (14 - $rank);
}

/** 是否可卖 */
function isSellable(int $idx): bool {
    global $SELLABLE;
    return in_array($idx, $SELLABLE);
}

/** 获取当前轮次 */
function getCurrentRound(): ?array {
    global $roomId;
    return Database::queryOne("SELECT * FROM piggy_rounds WHERE room_id = ? ORDER BY id DESC LIMIT 1", [$roomId]);
}

/** 创建新轮次 */
function createNewRound(): array {
    global $roomId, $gameMode;
    $seats = json_encode([
        'east'  => ['char_id' => 0, 'char_name' => '「空」', 'status' => 'empty', 'is_npc' => false],
        'north' => ['char_id' => 0, 'char_name' => '「空」', 'status' => 'empty', 'is_npc' => false],
        'west'  => ['char_id' => 0, 'char_name' => '「空」', 'status' => 'empty', 'is_npc' => false],
        'south' => ['char_id' => 0, 'char_name' => '「空」', 'status' => 'empty', 'is_npc' => false],
    ]);
    Database::execute("INSERT INTO piggy_rounds (room_id, game_mode, status, seats, status_changed_at, created_at) VALUES (?, ?, 0, ?, NOW(), NOW())",
        [$roomId, $gameMode, $seats]);
    $id = Database::lastInsertId();
    return Database::queryOne("SELECT * FROM piggy_rounds WHERE id = ?", [$id]);
}

/** 初始化游戏状态 */
function initGameState(): string {
    return json_encode([
        'hands' => ['east' => [], 'north' => [], 'west' => [], 'south' => []],
        'collected' => ['east' => [], 'north' => [], 'west' => [], 'south' => []],
        'table_cards' => ['east' => 0, 'north' => 0, 'west' => 0, 'south' => 0],
        'game_info' => [
            'round' => 0, 'rlead' => '', 'next' => '', 'suit' => '',
            'spade' => 0, 'heart' => 0, 'diamond' => 0, 'club' => 0,
        ],
        'sold' => [
            'pig' => ['not'], 'blood' => ['not'],
            'sheep' => ['not'], 'doubler' => ['not'],
        ],
        'claim' => [
            'claimer' => '',
            'east' => 'no', 'north' => 'no', 'west' => 'no', 'south' => 'no',
        ],
        'played_cards' => '',
    ]);
}

/** 发牌 */
function dealCards(array &$gameState, array $seats): void {
    $deck = range(1, 52);
    shuffle($deck);
    $dirs = ['east', 'north', 'west', 'south'];
    $idx = 0;
    foreach ($dirs as $dir) {
        $hand = [];
        for ($i = 0; $i < 13; $i++) {
            $hand[] = $deck[$idx++];
        }
        sort($hand);
        $gameState['hands'][$dir] = $hand;
        $gameState['collected'][$dir] = [];
        $gameState['table_cards'][$dir] = 0;
    }
    $gameState['game_info']['round'] = 1;
    $gameState['game_info']['suit'] = '';
    $gameState['sold'] = [
        'pig' => ['not'], 'blood' => ['not'],
        'sheep' => ['not'], 'doubler' => ['not'],
    ];
    $gameState['claim'] = [
        'claimer' => '',
        'east' => 'no', 'north' => 'no', 'west' => 'no', 'south' => 'no',
    ];
    $gameState['played_cards'] = '';
}

/** 搜索某方向某花色的牌数量 */
function searchSuit(array $hand, string $suit): int {
    global $CARDS;
    $count = 0;
    foreach ($hand as $idx) {
        if (isset($CARDS[$idx]) && $CARDS[$idx]['suit'] === $suit) $count++;
    }
    return $count;
}

/** 检查牌是否可出 (移植自 LPC is_playable) */
function isPlayableCard(array $hand, int $cardIdx, array $gameInfo, array $sold): array {
    global $CARDS;
    if (!in_array($cardIdx, $hand)) {
        return ['ok' => false, 'msg' => '这张牌不在你手里。'];
    }
    $card = $CARDS[$cardIdx];
    $suit = $card['suit'];
    $ledSuit = $gameInfo['suit'];

    // 必须跟花色
    if (!empty($ledSuit) && $suit !== $ledSuit && searchSuit($hand, $ledSuit) > 0) {
        return ['ok' => false, 'msg' => '你手中还有' . getSuitName($ledSuit) . '，不能出' . getCardName($cardIdx) . '。'];
    }

    // 明卖牌不能在本花色第一轮出
    if ($card['misc'] && isset($sold[$card['misc']]) && $sold[$card['misc']][0] === 'm') {
        $suitCount = $gameInfo[$suit] ?? 0;
        if ($suitCount === 0) {
            $checkSuit = empty($ledSuit) ? $suit : $ledSuit;
            if (searchSuit($hand, $checkSuit) > 1) {
                return ['ok' => false, 'msg' => '明卖了的牌不能在本花色第一轮出。'];
            }
        }
    }
    return ['ok' => true];
}

function getSuitName(string $suit): string {
    return ['spade' => '黑桃', 'heart' => '红桃', 'diamond' => '方片', 'club' => '草花'][$suit] ?? '未知';
}

/** 下一玩家 (顺时针: east→north→west→south→east) */
function nextPlayer(string $dir): string {
    return ['east' => 'north', 'north' => 'west', 'west' => 'south', 'south' => 'east'][$dir] ?? '';
}

/** 找出本轮最大牌的方向 (移植自 LPC find_large) */
function findTrickWinner(array $tableCards, string $ledSuit): string {
    global $CARDS, $DIR;
    $winner = '';
    $maxRank = 0;
    foreach ($DIR as $dir) {
        $idx = $tableCards[$dir];
        if ($idx === 0) continue;
        $card = $CARDS[$idx];
        if ($card['suit'] === $ledSuit && $card['rank'] > $maxRank) {
            $maxRank = $card['rank'];
            $winner = $dir;
        }
    }
    return $winner;
}

/** 计算玩家得分 (移植自 LPC score_player)
 * @return array ['score' => int, 'full' => bool]
 */
function calculateScore(array $collected, array $sold): array {
    global $CARDS;
    $score = 0;
    $tscore = 0;
    $full = false;
    $ctonly = true;
    $remaining = $collected;

    // 1. 红桃分
    for ($i = 12; $i >= 0; $i--) {
        $j = $i + 14; // 红桃索引 14-26
        $key = array_search($j, $remaining);
        if ($key === false) continue;
        $score += $CARDS[$j]['worth'];
        $ctonly = false;
        array_splice($remaining, $key, 1);
    }
    // 检查收全红
    if ($score == -20003) {
        $score = 20000;
        $full = true;
    }
    // 血(红桃A)卖牌倍率
    if ($sold['blood'][0] === 'm') $score *= 4;
    elseif ($sold['blood'][0] === 'a') $score *= 2;

    // 2. 猪分
    $pigKey = array_search(3, $remaining);
    if ($pigKey !== false) {
        $ctonly = false;
        if ($sold['pig'][0] === 'm') $tscore = 4 * $CARDS[3]['worth'];
        elseif ($sold['pig'][0] === 'a') $tscore = 2 * $CARDS[3]['worth'];
        else $tscore = $CARDS[3]['worth'];
        array_splice($remaining, $pigKey, 1);
        if ($full) $score -= $tscore; // 全收时猪变正
        else $score += $tscore;
    } else {
        $full = false;
    }

    // 3. 羊分
    $sheepKey = array_search(30, $remaining);
    if ($sheepKey !== false) {
        $ctonly = false;
        $score = intdiv($score, 100) * 100;
        if ($sold['sheep'][0] === 'm') $tscore = 4 * $CARDS[30]['worth'];
        elseif ($sold['sheep'][0] === 'a') $tscore = 2 * $CARDS[30]['worth'];
        else $tscore = $CARDS[30]['worth'];
        array_splice($remaining, $sheepKey, 1);
        $score += $tscore;
    } else {
        $full = false;
    }

    // 4. 变压器
    $ctKey = array_search(44, $remaining);
    if ($ctKey !== false) {
        if ($sold['doubler'][0] === 'm') $tscore = 8;
        elseif ($sold['doubler'][0] === 'a') $tscore = 4;
        else $tscore = 2;
        array_splice($remaining, $ctKey, 1);
        if ($score == 0 && $ctonly) {
            $score = $tscore * $CARDS[44]['worth'] / 2; // 独收变压器
        } else {
            $score = intdiv($score, 1000) * 1000 * $tscore;
        }
    } else {
        $full = false;
    }

    return ['score' => intdiv($score, 100), 'full' => $full];
}

/** 阶梯计分 (移植自 LPC update_ranking) */
function calculateRankChanges(array $scores, array $pigheads): array {
    $num = count($scores);
    $penalty = PIG_PENALTY;
    $limit = SCORE_LIMIT;
    $changes = array_fill(0, $num, 0);

    if (!empty($pigheads)) {
        $k = count($pigheads);
        $noHeadBonus = $k < $num ? intdiv($k * $penalty, $num - $k) : 0;
        for ($i = 0; $i < $num; $i++) {
            $changes[$i] = in_array($i, $pigheads) ? -$penalty : $noHeadBonus;
        }
        return $changes;
    }

    $sum = 0; $max = $scores[0]; $min = $scores[0];
    foreach ($scores as $s) {
        $sum += $s;
        if ($s > $max) $max = $s;
        if ($s < $min) $min = $s;
    }
    $range = $max - $min;
    $factor = intdiv($range * $penalty * 6, 3 * $limit);
    if ($factor > $penalty) $factor = $penalty;
    $totalPts = 100 * $factor;

    $avg = intdiv($sum, $num);
    $m = 0;
    foreach ($scores as $s) {
        if ($s > $avg) $m += ($s - $avg);
    }
    if ($m == 0) $m = 10;

    $totalPts = intdiv($totalPts, 10);
    $m *= 10;
    $avg5 = intdiv($avg, 5);
    $m5 = intdiv($m, 5);

    for ($i = 0; $i < $num; $i++) {
        $k = intdiv($scores[$i], 5) - $avg5;
        if ($k > 0) $k = $totalPts * $k + intdiv($m5 * 2, 3);
        else $k = $totalPts * $k - intdiv($m5, 2);
        $changes[$i] = $m5 > 0 ? intdiv($k, $m5) : 0;
    }
    return $changes;
}

/** 获取或初始化排名 */
function getRank(int $charId): int {
    $row = Database::queryOne("SELECT rank_points FROM piggy_rankings WHERE char_id = ?", [$charId]);
    if ($row) return (int)$row['rank_points'];
    return 100;
}

/** 更新排名 */
function updateRank(int $charId, string $charName, int $rankChange, int $handPlayed, int $headsReceived): void {
    $row = Database::queryOne("SELECT * FROM piggy_rankings WHERE char_id = ?", [$charId]);
    if ($row) {
        $newRank = max(1, (int)$row['rank_points'] + $rankChange);
        Database::execute(
            "UPDATE piggy_rankings SET rank_points = ?, hands_played = ?, heads_received = ?, char_name = ?, updated_at = NOW() WHERE char_id = ?",
            [$newRank, (int)$row['hands_played'] + $handPlayed, (int)$row['heads_received'] + $headsReceived, $charName, $charId]
        );
    } else {
        $newRank = max(1, 100 + $rankChange);
        Database::execute(
            "INSERT INTO piggy_rankings (char_id, char_name, rank_points, hands_played, heads_received, updated_at) VALUES (?, ?, ?, ?, ?, NOW())",
            [$charId, $charName, $newRank, $handPlayed, $headsReceived]
        );
    }
}

// ─── NPC AI ────────────────────────────────────────────

/** NPC自动卖牌决策 */
function npcAutoSell(array $hand, string $seat, array $sold = []): array {
    global $SELLABLE, $CARDS;
    $soldCards = [];
    $sellableInHand = array_filter($hand, fn($idx) => in_array($idx, $SELLABLE));
    foreach ($sellableInHand as $idx) {
        $misc = $CARDS[$idx]['misc'];
        // 跳过已被其他人卖出的牌
        if (isset($sold[$misc]) && $sold[$misc][0] !== 'not') continue;
        // NPC 20%概率卖牌, 70%暗卖 30%明卖
        if (mt_rand(1, 100) <= 20) {
            $flag = (mt_rand(1, 100) <= 30) ? 'm' : 'a';
            $soldCards[] = ['card_idx' => $idx, 'misc' => $misc, 'flag' => $flag];
        }
    }
    return $soldCards;
}

/** NPC自动出牌 */
function npcAutoPlay(array $hand, array $gameInfo, array $sold): int {
    global $CARDS;
    if (empty($hand)) return 0;

    $ledSuit = $gameInfo['suit'];
    $isLeading = empty($ledSuit);

    // 跟花色: 出最低的同花色牌
    if (!$isLeading) {
        $suitCards = [];
        foreach ($hand as $idx) {
            if ($CARDS[$idx]['suit'] === $ledSuit) $suitCards[] = $idx;
        }
        if (!empty($suitCards)) {
            // 按rank排序,出最低
            usort($suitCards, fn($a, $b) => $CARDS[$a]['rank'] - $CARDS[$b]['rank']);
            return $suitCards[0];
        }
    }

    // 领出或无同花色: 优先出非特殊牌的最低牌
    $nonSpecial = [];
    $special = [];
    foreach ($hand as $idx) {
        if (!empty($CARDS[$idx]['misc'])) {
            $special[] = $idx;
        } else {
            $nonSpecial[] = $idx;
        }
    }

    if ($isLeading) {
        // 领出: 优先非红桃非特殊牌
        $safeCards = array_filter($nonSpecial, fn($idx) => $CARDS[$idx]['suit'] !== 'heart');
        if (!empty($safeCards)) {
            usort($safeCards, fn($a, $b) => $CARDS[$a]['rank'] - $CARDS[$b]['rank']);
            return $safeCards[0];
        }
        if (!empty($nonSpecial)) {
            usort($nonSpecial, fn($a, $b) => $CARDS[$a]['rank'] - $CARDS[$b]['rank']);
            return $nonSpecial[0];
        }
        // 只有特殊牌了
        usort($special, fn($a, $b) => $CARDS[$a]['rank'] - $CARDS[$b]['rank']);
        return $special[0];
    }

    // 垫牌: 出最高的非特殊牌(避免吃牌)
    if (!empty($nonSpecial)) {
        usort($nonSpecial, fn($a, $b) => $CARDS[$b]['rank'] - $CARDS[$a]['rank']);
        return $nonSpecial[0];
    }
    // 只有特殊牌,出最低的
    usort($special, fn($a, $b) => $CARDS[$a]['rank'] - $CARDS[$b]['rank']);
    return $special[0];
}

// ─── 懒推进状态机 ──────────────────────────────────────

function advanceRound(): void {
    global $DIR, $CDIR;
    $round = getCurrentRound();
    if (!$round) {
        createNewRound();
        return;
    }

    $now = time();
    $elapsed = $now - strtotime($round['status_changed_at']);
    $status = (int)$round['status'];
    $seats = json_decode($round['seats'], true) ?? [];
    $gameState = $status >= 2 ? (json_decode($round['game_state'], true) ?? []) : [];

    switch ($status) {
        case 0: // 等人
            $filledCount = 0;
            foreach ($DIR as $dir) {
                if ($seats[$dir]['status'] !== 'empty') $filledCount++;
            }
            if ($filledCount === NUM_PLAYERS) {
                // 四人到齐 → 等发牌
                foreach ($DIR as $dir) {
                    $seats[$dir]['status'] = 'filled';
                }
                Database::execute("UPDATE piggy_rounds SET seats = ?, status = 1, status_changed_at = NOW() WHERE id = ?",
                    [json_encode($seats), $round['id']]);
            } elseif ($elapsed >= WAIT_PLAYERS_TIMEOUT) {
                // 超时 → NPC填位
                $npcNames = ['电脑玩家甲', '电脑玩家乙', '电脑玩家丙', '电脑玩家丁'];
                $npcIdx = 0;
                foreach ($DIR as $dir) {
                    if ($seats[$dir]['status'] === 'empty') {
                        $seats[$dir] = [
                            'char_id' => 0, 'char_name' => $npcNames[$npcIdx++],
                            'status' => 'filled', 'is_npc' => true,
                        ];
                    }
                }
                Database::execute("UPDATE piggy_rounds SET seats = ?, status = 1, status_changed_at = NOW() WHERE id = ?",
                    [json_encode($seats), $round['id']]);
            }
            break;

        case 1: // 等发牌
            $allReady = true;
            foreach ($DIR as $dir) {
                if ($seats[$dir]['status'] !== 'asked_for_deal') {
                    $allReady = false;
                    break;
                }
            }
            // NPC自动要求发牌(2秒后)
            if (!$allReady && $elapsed >= 2) {
                $changed = false;
                foreach ($DIR as $dir) {
                    if (!empty($seats[$dir]['is_npc']) && $seats[$dir]['status'] !== 'asked_for_deal') {
                        $seats[$dir]['status'] = 'asked_for_deal';
                        $changed = true;
                    }
                }
                if ($changed) {
                    Database::execute("UPDATE piggy_rounds SET seats = ? WHERE id = ?",
                        [json_encode($seats), $round['id']]);
                    // 重新检查
                    $allReady = true;
                    foreach ($DIR as $dir) {
                        if ($seats[$dir]['status'] !== 'asked_for_deal') { $allReady = false; break; }
                    }
                }
            }
            if ($allReady || $elapsed >= DEAL_TIMEOUT) {
                // 发牌
                $gs = json_decode(initGameState(), true);
                dealCards($gs, $seats);
                // 随机选先手
                $rlead = $DIR[array_rand($DIR)];
                $gs['game_info']['rlead'] = $rlead;
                $gs['game_info']['next'] = $rlead;
                foreach ($DIR as $dir) {
                    $seats[$dir]['status'] = 'selling';
                }
                Database::execute("UPDATE piggy_rounds SET seats = ?, game_state = ?, status = 2, status_changed_at = NOW() WHERE id = ?",
                    [json_encode($seats), json_encode($gs), $round['id']]);
            }
            break;

        case 2: // 等卖牌
            $allPassed = true;
            foreach ($DIR as $dir) {
                if ($seats[$dir]['status'] !== 'passed') {
                    $allPassed = false;
                    break;
                }
            }
            if ($allPassed) {
                // 进入出牌
                $seats[$DIR[0]]['status'] = 'playing'; // mark first player
                foreach ($DIR as $dir) {
                    $seats[$dir]['status'] = 'playing';
                }
                Database::execute("UPDATE piggy_rounds SET seats = ?, status = 3, status_changed_at = NOW() WHERE id = ?",
                    [json_encode($seats), $round['id']]);
            } else {
                // 检查每个NPC是否需要自动停卖
                $changed = false;
                foreach ($DIR as $dir) {
                    if (!empty($seats[$dir]['is_npc']) && $seats[$dir]['status'] === 'selling') {
                        // NPC自动卖牌+停卖
                        $hand = $gameState['hands'][$dir] ?? [];
                        $soldCards = npcAutoSell($hand, $dir, $gameState['sold']);
                        foreach ($soldCards as $sc) {
                            $gameState['sold'][$sc['misc']] = [$sc['flag'], $dir];
                        }
                        $seats[$dir]['status'] = 'passed';
                        $changed = true;
                    }
                }
                // 检查玩家超时自动停卖
                foreach ($DIR as $dir) {
                    if (empty($seats[$dir]['is_npc']) && $seats[$dir]['status'] === 'selling' && $elapsed >= SELL_TIMEOUT) {
                        $seats[$dir]['status'] = 'passed';
                        $changed = true;
                    }
                }
                if ($changed) {
                    Database::execute("UPDATE piggy_rounds SET seats = ?, game_state = ? WHERE id = ?",
                        [json_encode($seats), json_encode($gameState), $round['id']]);
                }
                // 重新检查是否全部停卖
                $allPassed = true;
                foreach ($DIR as $dir) {
                    if ($seats[$dir]['status'] !== 'passed') { $allPassed = false; break; }
                }
                if ($allPassed) {
                    foreach ($DIR as $dir) $seats[$dir]['status'] = 'playing';
                    Database::execute("UPDATE piggy_rounds SET seats = ?, status = 3, status_changed_at = NOW() WHERE id = ?",
                        [json_encode($seats), $round['id']]);
                }
            }
            break;

        case 3: // 出牌
            $next = $gameState['game_info']['next'] ?? '';
            $gi = $gameState['game_info'];
            if (empty($next)) break;

            // 检查当前轮次是否完成(4张牌都出了)
            $allPlayed = true;
            foreach ($DIR as $dir) {
                if (($gameState['table_cards'][$dir] ?? 0) === 0) { $allPlayed = false; break; }
            }

            if ($allPlayed) {
                // 本轮结束 → 判定赢家,收牌
                finishTrick($round, $gameState, $seats);
                return;
            }

            $currentSeat = $seats[$next] ?? null;
            if (!$currentSeat) break;

            // NPC出牌: 3秒后自动出牌(给玩家观察时间)
            $npcDelay = 3;
            if (($currentSeat['is_npc'] ?? false) && $elapsed >= $npcDelay) {
                $hand = $gameState['hands'][$next] ?? [];
                $cardIdx = npcAutoPlay($hand, $gi, $gameState['sold']);
                if ($cardIdx > 0) {
                    playCardInternal($round, $gameState, $seats, $next, $cardIdx);
                }
            } elseif (!$currentSeat['is_npc'] && $elapsed >= PLAY_TURN_TIMEOUT) {
                // 玩家超时自动出牌
                $hand = $gameState['hands'][$next] ?? [];
                $cardIdx = npcAutoPlay($hand, $gi, $gameState['sold']);
                if ($cardIdx > 0) {
                    playCardInternal($round, $gameState, $seats, $next, $cardIdx);
                }
            }
            break;

        case 4: // 算分
            if ($elapsed >= SCORE_DISPLAY_TIME) {
                // 清理座位,开始新局
                foreach ($DIR as $dir) {
                    $seats[$dir] = [
                        'char_id' => 0, 'char_name' => '「空」',
                        'status' => 'empty', 'is_npc' => false,
                    ];
                }
                Database::execute("UPDATE piggy_rounds SET seats = ?, game_state = NULL, status = 0, status_changed_at = NOW(), pig_owner = '', full_collector = '', result_summary = NULL WHERE id = ?",
                    [json_encode($seats), $round['id']]);
            }
            break;
    }
}

/** 完成一轮出牌(4张牌都出了) */
function finishTrick(array $round, array &$gameState, array &$seats): void {
    global $DIR, $CARDS;
    $gi = $gameState['game_info'];
    $ledSuit = $gi['suit'];
    
    // 如果 suit 为空或 null，从领出者的牌推断花色
    if (empty($ledSuit)) {
        $rlead = $gi['rlead'] ?? '';
        $rleadCard = $gameState['table_cards'][$rlead] ?? 0;
        if ($rleadCard > 0 && isset($CARDS[$rleadCard])) {
            $ledSuit = $CARDS[$rleadCard]['suit'];
            $gi['suit'] = $ledSuit;
        }
    }
    
    $winner = findTrickWinner($gameState['table_cards'], $ledSuit);

    if (empty($winner)) return;

    // 收牌
    foreach ($DIR as $dir) {
        $idx = $gameState['table_cards'][$dir];
        if ($idx > 0) {
            $gameState['collected'][$winner][] = $idx;
            // 检查猪
            if ($idx === 3) {
                Database::execute("UPDATE piggy_rounds SET pig_owner = ? WHERE id = ?", [$winner, $round['id']]);
            }
        }
        $gameState['table_cards'][$dir] = 0;
    }

    $gi['round']++;
    $gi['rlead'] = $winner;
    $gi['next'] = $winner;

    // 检查是否13轮打完
    if ($gi['round'] > 13) {
        // 进入算分
        $gameState['game_info'] = $gi;
        finishHand($round, $gameState, $seats);
        return;
    }

    // 更新花色计数
    if (!empty($ledSuit)) {
        $gi[$ledSuit] = ($gi[$ledSuit] ?? 0) + 1;
    }
    $gi['suit'] = '';
    $gameState['played_cards'] = '';
    $gameState['game_info'] = $gi;

    Database::execute("UPDATE piggy_rounds SET game_state = ?, status_changed_at = NOW() WHERE id = ?",
        [json_encode($gameState), $round['id']]);
}

/** 完成一局牌(13轮打完) */
function finishHand(array $round, array &$gameState, array &$seats): void {
    global $DIR, $CDIR, $PARTNER, $PART_INDEX;
    $sold = $gameState['sold'];
    $scores = [];       // 每人原始得分
    $finalScores = [];  // 最终得分(搭档模式可能不同)
    $fullCollector = '';
    $pigOwner = '';
    $isPartner = ($round['game_mode'] ?? 'normal') === 'partner';

    // 先算猪主人的分(检查全收)
    $pigOwnerFromDb = Database::queryOne("SELECT pig_owner FROM piggy_rounds WHERE id = ?", [$round['id']]);
    if ($pigOwnerFromDb && !empty($pigOwnerFromDb['pig_owner'])) {
        $pigOwner = $pigOwnerFromDb['pig_owner'];
    }

    // 计算每个人的原始分
    foreach ($DIR as $i => $dir) {
        $result = calculateScore($gameState['collected'][$dir], $sold);
        $scores[$i] = $result['score'];
        if ($result['full']) {
            $fullCollector = $dir;
        }
    }

    // ─── 全收逻辑 ──────────────────────────────────
    $pigheads = [];
    if (!empty($fullCollector)) {
        Database::execute("UPDATE piggy_rounds SET full_collector = ? WHERE id = ?", [$fullCollector, $round['id']]);

        if ($isPartner) {
            // 双人拱猪全收: 全收者和搭档各得全额/2, 对方队伍得猪头
            $fullScore = $scores[array_search($fullCollector, $DIR)] / 2;
            foreach ($DIR as $i => $dir) {
                if ($dir !== $fullCollector && $dir !== $PARTNER[$fullCollector]) {
                    $pigheads[] = $i;
                    $finalScores[$i] = $scores[$i];
                } else {
                    $finalScores[$i] = $fullScore;
                }
            }
        } else {
            // 普通拱猪全收: 全收者得分, 其余3人得猪头
            foreach ($DIR as $i => $dir) {
                if ($dir !== $fullCollector) {
                    $pigheads[] = $i;
                }
                $finalScores[$i] = $scores[$i];
            }
        }
    } elseif ($isPartner) {
        // ─── 双人拱猪正常计分: 搭档分数合并均摊 ──────
        // 移植自 LPC piggy_two.c finish_round()
        // result_sc[i] += score; result_sc[part_index[i]] += score;
        // score = result_sc[i] / 2;
        $resultSc = [0, 0, 0, 0];
        foreach ($DIR as $i => $dir) {
            $resultSc[$i] += $scores[$i];
            $resultSc[$PART_INDEX[$dir]] += $scores[$i];
        }
        foreach ($DIR as $i => $dir) {
            $finalScores[$i] = intdiv($resultSc[$i], 2);
        }
        // 检查猪头(按合并后总分)
        $scoring = json_decode($round['scoring'] ?? '{}', true) ?: ['sitting' => array_fill_keys($DIR, 0)];
        foreach ($DIR as $i => $dir) {
            $sitting = ($scoring['sitting'][$dir] ?? 0) + $finalScores[$i];
            if ($sitting <= -SCORE_LIMIT) {
                $pigheads[] = $i;
            }
        }
    } else {
        // ─── 普通拱猪正常计分 ──────────────────────
        foreach ($DIR as $i => $dir) {
            $finalScores[$i] = $scores[$i];
        }
        $scoring = json_decode($round['scoring'] ?? '{}', true) ?: ['sitting' => array_fill_keys($DIR, 0)];
        foreach ($DIR as $i => $dir) {
            $sitting = ($scoring['sitting'][$dir] ?? 0) + $finalScores[$i];
            if ($sitting <= -SCORE_LIMIT) {
                $pigheads[] = $i;
            }
        }
    }

    // 阶梯计分 (用最终得分)
    $rankChanges = calculateRankChanges($finalScores, $pigheads);

    // 更新积分和排名
    $scoring = json_decode($round['scoring'] ?? '{}', true) ?: [
        'sitting' => array_fill_keys($DIR, 0),
        'hand' => array_fill_keys($DIR, 0),
    ];
    $resultLines = [];
    $antePot = ANTE_AMOUNT * NUM_PLAYERS;

    if ($isPartner) {
        $resultLines[] = '【搭档模式】东西一队 · 南北一队';
    }

    foreach ($DIR as $i => $dir) {
        $seat = $seats[$dir];
        $handScore = $finalScores[$i];
        $scoring['hand'][$dir] = $handScore;
        $scoring['sitting'][$dir] = ($scoring['sitting'][$dir] ?? 0) + $handScore;
        $isPighead = in_array($i, $pigheads);

        $rankBefore = 100;
        $rankAfter = 100;
        $coinChange = 0;

        if (!$seat['is_npc'] && $seat['char_id'] > 0) {
            $rankBefore = getRank((int)$seat['char_id']);
            updateRank((int)$seat['char_id'], $seat['char_name'], $rankChanges[$i], 1, $isPighead ? 1 : 0);
            $rankAfter = getRank((int)$seat['char_id']);

            // 铜钱结算: 根据得分比例分配奖池
            if ($handScore > 0) {
                $coinChange = intdiv($antePot * $handScore, max(1, array_sum(array_filter($finalScores, fn($s) => $s > 0))));
            } elseif ($isPighead) {
                $coinChange = -ANTE_AMOUNT - 50; // 额外罚金
            } else {
                $coinChange = 0;
            }
            if ($coinChange > 0) {
                MoneyHelper::addMoney((int)$seat['char_id'], $coinChange);
            } elseif ($coinChange < 0) {
                MoneyHelper::deductMoney((int)$seat['char_id'], abs($coinChange));
            }
        }

        // 保存参与记录
        Database::execute(
            "INSERT INTO piggy_bets (round_id, char_id, char_name, seat, is_npc, hand_score, is_pighead, collected_cards, sold_cards, rank_before, rank_after, coin_change, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $round['id'], (int)$seat['char_id'], $seat['char_name'], $dir,
                $seat['is_npc'] ? 1 : 0, $handScore, $isPighead ? 1 : 0,
                json_encode($gameState['collected'][$dir]),
                json_encode(array_filter($gameState['sold'], fn($v) => $v[0] !== 'not')),
                $rankBefore, $rankAfter, $coinChange,
            ]
        );

        $partnerTag = '';
        if ($isPartner) {
            $pDir = $PARTNER[$dir];
            $partnerTag = ' (搭档: ' . ($seats[$pDir]['char_name'] ?? '?') . ')';
        }
        $resultLines[] = sprintf('%s家 %s%s: 得分 %+d, 等级分 %+d%s',
            $CDIR[$dir], $seat['char_name'], $partnerTag, $handScore, $rankChanges[$i],
            $isPighead ? ' [猪头!]' : '');
    }

    Database::execute("UPDATE piggy_rounds SET game_state = ?, scoring = ?, status = 4, status_changed_at = NOW(), result_summary = ? WHERE id = ?",
        [json_encode($gameState), json_encode($scoring), implode("\n", $resultLines), $round['id']]);
}

/** 内部出牌函数(玩家和NPC共用) */
function playCardInternal(array $round, array &$gameState, array &$seats, string $dir, int $cardIdx): void {
    global $CARDS;
    $hand = $gameState['hands'][$dir];
    $key = array_search($cardIdx, $hand);
    if ($key === false) return;
    array_splice($hand, $key, 1);
    $gameState['hands'][$dir] = $hand;
    $gameState['table_cards'][$dir] = $cardIdx;

    $gi = $gameState['game_info'];
    // 如果是领出,设置花色
    if ($dir === $gi['rlead'] && empty($gi['suit'])) {
        $gi['suit'] = $CARDS[$cardIdx]['suit'];
    }
    $gameState['played_cards'] .= getCardName($cardIdx) . ' ';

    // 检查4张牌是否都出了
    $allPlayed = true;
    foreach (['east', 'north', 'west', 'south'] as $d) {
        if (($gameState['table_cards'][$d] ?? 0) === 0) { $allPlayed = false; break; }
    }

    // 先保存 game_info (含花色设置)，再判断是否本轮结束
    if (!$allPlayed) {
        $gi['next'] = nextPlayer($dir);
    }
    $gameState['game_info'] = $gi;

    if ($allPlayed) {
        // 本轮结束 → 判定赢家
        finishTrick($round, $gameState, $seats);
    } else {
        // 下一玩家
        Database::execute("UPDATE piggy_rounds SET game_state = ?, status_changed_at = NOW() WHERE id = ?",
            [json_encode($gameState), $round['id']]);
    }
}

// ─── 动作处理 ──────────────────────────────────────────

$action = $_REQUEST['action'] ?? 'status';

// 先执行懒推进
advanceRound();

// 重新获取最新状态
$round = getCurrentRound();
if (!$round) {
    $round = createNewRound();
}

$seats = json_decode($round['seats'], true) ?? [];
$gameState = ($round['game_state']) ? (json_decode($round['game_state'], true) ?? []) : [];
$status = (int)$round['status'];

switch ($action) {
    case 'status':
        echo json_encode(buildStatusResponse($charId, $round, $seats, $gameState), JSON_UNESCAPED_UNICODE);
        break;

    case 'sit':
        $dir = $_REQUEST['dir'] ?? '';
        echo json_encode(actionSit($charId, $char, $dir, $round, $seats), JSON_UNESCAPED_UNICODE);
        break;

    case 'leave':
        echo json_encode(actionLeave($charId, $round, $seats), JSON_UNESCAPED_UNICODE);
        break;

    case 'deal':
        echo json_encode(actionDeal($charId, $round, $seats), JSON_UNESCAPED_UNICODE);
        break;

    case 'sell':
        $cardShort = $_REQUEST['card'] ?? '';
        $flag = $_REQUEST['flag'] ?? 'a';
        echo json_encode(actionSell($charId, $cardShort, $flag, $round, $seats, $gameState), JSON_UNESCAPED_UNICODE);
        break;

    case 'pass':
        echo json_encode(actionPass($charId, $round, $seats), JSON_UNESCAPED_UNICODE);
        break;

    case 'play':
        $cardShort = $_REQUEST['card'] ?? '';
        echo json_encode(actionPlay($charId, $cardShort, $round, $seats, $gameState), JSON_UNESCAPED_UNICODE);
        break;

    case 'claim':
        $type = $_REQUEST['type'] ?? '';
        echo json_encode(actionClaim($charId, $type, $round, $seats, $gameState), JSON_UNESCAPED_UNICODE);
        break;

    default:
        echo json_encode(['success' => false, 'message' => '未知操作'], JSON_UNESCAPED_UNICODE);
}

// ─── 构建状态响应 ──────────────────────────────────────

function buildStatusResponse(int $charId, array $round, array $seats, array $gameState): array {
    global $DIR, $CDIR, $CARDS, $CNUM, $PARTNER;
    $status = (int)$round['status'];
    $statusText = ['等人', '等发牌', '等卖牌', '出牌', '算分'][$status] ?? '未知';
    $elapsed = time() - strtotime($round['status_changed_at']);
    $gameMode = $round['game_mode'] ?? 'normal';
    $isPartner = $gameMode === 'partner';

    // 找到当前玩家的座位
    $mySeat = '';
    foreach ($DIR as $dir) {
        if ((int)$seats[$dir]['char_id'] === $charId) {
            $mySeat = $dir;
            break;
        }
    }

    $resp = [
        'success' => true,
        'status' => $status,
        'status_text' => $statusText,
        'elapsed' => $elapsed,
        'my_seat' => $mySeat,
        'game_mode' => $gameMode,
        'is_partner' => $isPartner,
        'partner' => $isPartner && $mySeat ? $PARTNER[$mySeat] : '',
        'partner_name' => '',
        'seats' => [],
        'game_info' => $gameState['game_info'] ?? null,
        'table_cards' => [],
        'sold' => $gameState['sold'] ?? null,
        'scoring' => json_decode($round['scoring'] ?? '{}', true),
        'result_summary' => $round['result_summary'] ?? null,
        'pig_owner' => $round['pig_owner'] ?? '',
        'full_collector' => $round['full_collector'] ?? '',
    ];

    // 搭档名
    if ($isPartner && $mySeat && isset($seats[$PARTNER[$mySeat]])) {
        $resp['partner_name'] = $seats[$PARTNER[$mySeat]]['char_name'] ?? '';
    }

    // 座位信息
    foreach ($DIR as $dir) {
        $s = $seats[$dir];
        $resp['seats'][$dir] = [
            'name' => $s['char_name'],
            'is_npc' => $s['is_npc'] ?? false,
            'status' => $s['status'],
            'is_me' => (int)$s['char_id'] === $charId,
        ];
    }

    // 桌面牌
    if (isset($gameState['table_cards'])) {
        foreach ($DIR as $dir) {
            $idx = $gameState['table_cards'][$dir] ?? 0;
            $resp['table_cards'][$dir] = $idx > 0 ? [
                'idx' => $idx,
                'name' => getCardName($idx),
                'short' => getCardShort($idx),
                'suit' => $CARDS[$idx]['suit'] ?? '',
                'rank' => $CARDS[$idx]['rank'] ?? 0,
                'misc' => $CARDS[$idx]['misc'] ?? '',
            ] : null;
        }
    }

    // 我的手牌
    if ($mySeat && isset($gameState['hands'][$mySeat])) {
        $resp['my_hand'] = [];
        foreach ($gameState['hands'][$mySeat] as $idx) {
            $resp['my_hand'][] = [
                'idx' => $idx,
                'name' => getCardName($idx),
                'short' => getCardShort($idx),
                'suit' => $CARDS[$idx]['suit'] ?? '',
                'rank' => $CARDS[$idx]['rank'] ?? 0,
                'misc' => $CARDS[$idx]['misc'] ?? '',
                'sellable' => isSellable($idx),
            ];
        }
    }

    // 卖牌信息(明卖才显示)
    if (isset($gameState['sold'])) {
        $resp['sold_display'] = [];
        $cardNames = ['pig' => '猪', 'blood' => '血', 'sheep' => '羊', 'doubler' => '变压器'];
        foreach ($gameState['sold'] as $misc => $info) {
            if ($info[0] !== 'not') {
                $resp['sold_display'][] = [
                    'misc' => $misc,
                    'name' => $cardNames[$misc],
                    'flag' => $info[0],
                    'flag_text' => $info[0] === 'm' ? '明卖' : '暗卖',
                    'seller' => $info[1] ?? '',
                ];
            }
        }
    }

    // 已收的牌(在算分阶段显示)
    if ($status === 4 && isset($gameState['collected'])) {
        $resp['collected'] = [];
        foreach ($DIR as $dir) {
            $cards = [];
            foreach ($gameState['collected'][$dir] as $idx) {
                $cards[] = getCardName($idx);
            }
            $resp['collected'][$dir] = $cards;
        }
    }

    // 当前出牌玩家
    if ($status === 3 && isset($gameState['game_info']['next'])) {
        $resp['current_player'] = $gameState['game_info']['next'];
        $resp['is_my_turn'] = $gameState['game_info']['next'] === $mySeat;
    }

    // 全收请求
    if (isset($gameState['claim'])) {
        $resp['claim'] = $gameState['claim'];
    }

    // 积分表
    $scoring = json_decode($round['scoring'] ?? '{}', true);
    if ($scoring) {
        $resp['scoring'] = $scoring;
    }

    return $resp;
}

// ─── 动作实现 ──────────────────────────────────────────

function actionSit(int $charId, array $char, string $dir, array $round, array &$seats): array {
    global $DIR;
    if (!in_array($dir, $DIR)) {
        return ['success' => false, 'message' => '请选择 east、north、west 或 south。'];
    }
    if ((int)$round['status'] !== 0) {
        return ['success' => false, 'message' => '现在不能入座，请等下一局。'];
    }
    // 检查是否已在座
    foreach ($DIR as $d) {
        if ((int)$seats[$d]['char_id'] === $charId) {
            return ['success' => false, 'message' => '你已经在座了，请用 leave 站起来。'];
        }
    }
    if ($seats[$dir]['status'] !== 'empty') {
        return ['success' => false, 'message' => $seats[$dir]['char_name'] . '正在' . ($GLOBALS['CDIR'][$dir] ?? '') . '边坐着呢。'];
    }

    // 扣入场费
    $money = MoneyHelper::getMoneyInventory($charId);
    if (intval($money['coin']) < ANTE_AMOUNT) {
        return ['success' => false, 'message' => '入场需要' . ANTE_AMOUNT . '文铜钱，你的铜钱不足。'];
    }
    MoneyHelper::deductMoney($charId, ANTE_AMOUNT);

    $seats[$dir] = [
        'char_id' => $charId,
        'char_name' => $char['name'],
        'status' => 'filled',
        'is_npc' => false,
    ];
    Database::execute("UPDATE piggy_rounds SET seats = ? WHERE id = ?",
        [json_encode($seats), $round['id']]);

    return ['success' => true, 'message' => '你坐入了' . ($GLOBALS['CDIR'][$dir] ?? '') . '边的位置。'];
}

function actionLeave(int $charId, array $round, array &$seats): array {
    global $DIR;
    if ((int)$round['status'] !== 0 && (int)$round['status'] !== 1) {
        return ['success' => false, 'message' => '拱猪进行中，不能退出牌局。'];
    }
    $myDir = '';
    foreach ($DIR as $d) {
        if ((int)$seats[$d]['char_id'] === $charId) { $myDir = $d; break; }
    }
    if (empty($myDir)) {
        return ['success' => false, 'message' => '你并没在拱猪桌边坐着。'];
    }
    // 退还入场费
    MoneyHelper::addMoney($charId, ANTE_AMOUNT);
    $seats[$myDir] = ['char_id' => 0, 'char_name' => '「空」', 'status' => 'empty', 'is_npc' => false];
    Database::execute("UPDATE piggy_rounds SET seats = ? WHERE id = ?",
        [json_encode($seats), $round['id']]);
    return ['success' => true, 'message' => '你让出了' . ($GLOBALS['CDIR'][$myDir] ?? '') . '边的位置。'];
}

function actionDeal(int $charId, array $round, array &$seats): array {
    global $DIR;
    if ((int)$round['status'] !== 1) {
        return ['success' => false, 'message' => '现在不是发牌的时候。'];
    }
    $myDir = '';
    foreach ($DIR as $d) {
        if ((int)$seats[$d]['char_id'] === $charId) { $myDir = $d; break; }
    }
    if (empty($myDir)) {
        return ['success' => false, 'message' => '你没有入座，无法要求发牌。'];
    }
    if ($seats[$myDir]['status'] === 'asked_for_deal') {
        return ['success' => false, 'message' => '你已经准备好了。'];
    }
    $seats[$myDir]['status'] = 'asked_for_deal';
    Database::execute("UPDATE piggy_rounds SET seats = ? WHERE id = ?",
        [json_encode($seats), $round['id']]);
    return ['success' => true, 'message' => '你说道：我准备好了，发牌吧。'];
}

function actionSell(int $charId, string $cardShort, string $flag, array $round, array &$seats, array &$gameState): array {
    global $DIR, $CARDS, $MISC_CARDS;
    if ((int)$round['status'] !== 2) {
        return ['success' => false, 'message' => '现在不能卖牌。'];
    }
    $myDir = '';
    foreach ($DIR as $d) {
        if ((int)$seats[$d]['char_id'] === $charId) { $myDir = $d; break; }
    }
    if (empty($myDir)) {
        return ['success' => false, 'message' => '你并不在拱猪。'];
    }
    if ($seats[$myDir]['status'] === 'passed') {
        return ['success' => false, 'message' => '你已经决定停卖了。'];
    }
    $cardIdx = parseCardShort($cardShort);
    if ($cardIdx === 0) {
        return ['success' => false, 'message' => '无法识别的牌：' . $cardShort];
    }
    if (!in_array($cardIdx, $gameState['hands'][$myDir])) {
        return ['success' => false, 'message' => getCardName($cardIdx) . '不在你手里。'];
    }
    if (!isSellable($cardIdx)) {
        return ['success' => false, 'message' => '只能卖猪(黑桃Q)、羊(方片J)、变压器(草花T)或血(红桃A)。'];
    }
    $misc = $CARDS[$cardIdx]['misc'];
    if ($gameState['sold'][$misc][0] !== 'not') {
        return ['success' => false, 'message' => '这张牌已经被卖了。'];
    }
    if ($flag !== 'm' && $flag !== 'a') $flag = 'a';
    $gameState['sold'][$misc] = [$flag, $myDir];
    Database::execute("UPDATE piggy_rounds SET game_state = ? WHERE id = ?",
        [json_encode($gameState), $round['id']]);

    $flagText = $flag === 'm' ? '明卖' : '暗卖';
    $cardNames = ['pig' => '猪', 'blood' => '血', 'sheep' => '羊', 'doubler' => '变压器'];
    return ['success' => true, 'message' => '你决定' . $flagText . getCardName($cardIdx) . '。'];
}

function actionPass(int $charId, array $round, array &$seats): array {
    global $DIR;
    if ((int)$round['status'] !== 2) {
        return ['success' => false, 'message' => '现在不能停卖。'];
    }
    $myDir = '';
    foreach ($DIR as $d) {
        if ((int)$seats[$d]['char_id'] === $charId) { $myDir = $d; break; }
    }
    if (empty($myDir)) {
        return ['success' => false, 'message' => '你并不在拱猪。'];
    }
    if ($seats[$myDir]['status'] === 'passed') {
        return ['success' => false, 'message' => '你已经决定停卖了。'];
    }
    $seats[$myDir]['status'] = 'passed';
    Database::execute("UPDATE piggy_rounds SET seats = ? WHERE id = ?",
        [json_encode($seats), $round['id']]);
    return ['success' => true, 'message' => '你说道：我停卖。'];
}

function actionPlay(int $charId, string $cardShort, array $round, array &$seats, array &$gameState): array {
    global $DIR, $CARDS;
    if ((int)$round['status'] !== 3) {
        return ['success' => false, 'message' => '现在不能出牌。'];
    }
    $myDir = '';
    foreach ($DIR as $d) {
        if ((int)$seats[$d]['char_id'] === $charId) { $myDir = $d; break; }
    }
    if (empty($myDir)) {
        return ['success' => false, 'message' => '你并不在拱猪。'];
    }
    $next = $gameState['game_info']['next'] ?? '';
    if ($next !== $myDir) {
        return ['success' => false, 'message' => '现在不该你出牌。'];
    }
    // 全收请求等待中
    if (!empty($gameState['claim']['claimer'])) {
        return ['success' => false, 'message' => '请等到全收要求有结果后再出牌。'];
    }
    $cardIdx = parseCardShort($cardShort);
    if ($cardIdx === 0) {
        return ['success' => false, 'message' => '无法识别的牌：' . $cardShort];
    }
    // 检查是否可出
    $check = isPlayableCard($gameState['hands'][$myDir], $cardIdx, $gameState['game_info'], $gameState['sold']);
    if (!$check['ok']) {
        return ['success' => false, 'message' => $check['msg']];
    }

    playCardInternal($round, $gameState, $seats, $myDir, $cardIdx);

    $card = $CARDS[$cardIdx];
    $ledSuit = $gameState['game_info']['suit'] ?? '';
    $action = $card['suit'] === $ledSuit ? '出' : '垫';
    $misc = $card['misc'];
    if ($misc && ($gameState['sold'][$misc][0] ?? 'not') !== 'not') {
        $flagText = $gameState['sold'][$misc][0] === 'm' ? '明卖' : '暗卖';
        return ['success' => true, 'message' => '你出' . $flagText . '的' . getCardName($cardIdx) . '！'];
    }
    return ['success' => true, 'message' => '你' . $action . '了一张' . getCardName($cardIdx) . '。'];
}

function actionClaim(int $charId, string $type, array $round, array &$seats, array &$gameState): array {
    global $DIR;
    if ((int)$round['status'] !== 3) {
        return ['success' => false, 'message' => '现在并不在出牌。'];
    }
    $myDir = '';
    foreach ($DIR as $d) {
        if ((int)$seats[$d]['char_id'] === $charId) { $myDir = $d; break; }
    }
    if (empty($myDir)) {
        return ['success' => false, 'message' => '你并不在拱猪。'];
    }
    $gi = $gameState['game_info'];
    $claim = $gameState['claim'];

    if ($type === 'all') {
        if ($gi['round'] <= 8) {
            return ['success' => false, 'message' => '前八轮不能全收。'];
        }
        if (!empty($claim['claimer'])) {
            return ['success' => false, 'message' => '已经有人发出要求了，请先否决。'];
        }
        if ($claim[$myDir] === 'yes') {
            return ['success' => false, 'message' => '你已经同意了。'];
        }
        $claim['claimer'] = $myDir;
        $claim[$myDir] = 'yes';
        $gameState['claim'] = $claim;
        Database::execute("UPDATE piggy_rounds SET game_state = ? WHERE id = ?",
            [json_encode($gameState), $round['id']]);
        return ['success' => true, 'message' => '你认为手中的牌都大了，要求全收。请其他人用 claim yes/no 回应。'];
    }

    if ($type === 'yes') {
        if (empty($claim['claimer'])) {
            return ['success' => false, 'message' => '没人要求摊牌。'];
        }
        if ($claim[$myDir] === 'yes') {
            return ['success' => false, 'message' => '你已经同意了。'];
        }
        $claim[$myDir] = 'yes';
        $gameState['claim'] = $claim;

        // 检查是否全部同意
        $allAgreed = true;
        foreach ($DIR as $d) {
            if ($claim[$d] !== 'yes') { $allAgreed = false; break; }
        }
        if ($allAgreed) {
            // 全收成功: 所有剩余手牌归全收者
            $claimer = $claim['claimer'];
            foreach ($DIR as $d) {
                // 桌上的牌
                if (($gameState['table_cards'][$d] ?? 0) > 0) {
                    $gameState['collected'][$claimer][] = $gameState['table_cards'][$d];
                    if ($gameState['table_cards'][$d] === 3) {
                        Database::execute("UPDATE piggy_rounds SET pig_owner = ? WHERE id = ?", [$claimer, $round['id']]);
                    }
                    $gameState['table_cards'][$d] = 0;
                }
                // 手中的牌
                foreach ($gameState['hands'][$d] as $idx) {
                    $gameState['collected'][$claimer][] = $idx;
                    if ($idx === 3) {
                        Database::execute("UPDATE piggy_rounds SET pig_owner = ? WHERE id = ?", [$claimer, $round['id']]);
                    }
                }
                $gameState['hands'][$d] = [];
            }
            $gameState['game_info']['round'] = 14; // 强制结束
            Database::execute("UPDATE piggy_rounds SET game_state = ? WHERE id = ?",
                [json_encode($gameState), $round['id']]);
            // 直接结算
            finishHand($round, $gameState, $seats);
            return ['success' => true, 'message' => '全收成功！所有人摊牌。'];
        }
        Database::execute("UPDATE piggy_rounds SET game_state = ? WHERE id = ?",
            [json_encode($gameState), $round['id']]);
        return ['success' => true, 'message' => '你同意全收。'];
    }

    if ($type === 'no') {
        if (empty($claim['claimer'])) {
            return ['success' => false, 'message' => '没有人要求摊牌。'];
        }
        // 重置全收请求
        foreach ($DIR as $d) {
            $claim[$d] = 'no';
        }
        $claim['claimer'] = '';
        $gameState['claim'] = $claim;
        Database::execute("UPDATE piggy_rounds SET game_state = ?, status_changed_at = NOW() WHERE id = ?",
            [json_encode($gameState), $round['id']]);
        return ['success' => true, 'message' => '你不同意，继续打下去。'];
    }

    return ['success' => false, 'message' => '请用 claim all/yes/no。'];
}
