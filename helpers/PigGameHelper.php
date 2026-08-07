<?php
/**
 * 拱猪游戏辅助类 - 实现完整的拱猪纸牌游戏逻辑
 * 玩家(0号位) 对战 3个AI(1,2,3号位)
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config/game.php';

class PigGameHelper {
    // 花色常量
    const SUIT_SPADE = 0;   // 黑桃
    const SUIT_HEART = 1;   // 红心
    const SUIT_CLUB = 2;    // 梅花
    const SUIT_DIAMOND = 3; // 方块

    // 花色符号
    private static $suitSymbols = ['♠', '♥', '♣', '♦'];

    // 特殊牌 [suit, rank]
    const PIG = [0, 12];         // 黑桃Q
    const SHEEP = [3, 11];       // 方块J
    const TRANSFORMER = [2, 10]; // 梅花10

    // ELO参数
    const ELO_INITIAL = 1000;
    const ELO_K = 32;
    const AI_RATING = 1000;

    // AI名称
    private static $aiNames = ['东家(AI)', '南家(AI)', '西家(AI)'];
    private static $playerNames = ['你', '东家(AI)', '南家(AI)', '西家(AI)'];

    // ==================== 公开API ====================

    /**
     * 开始新游戏
     */
    public static function startGame(int $charId): array {
        self::ensureStateTable();

        $existing = self::loadGameState($charId);
        if ($existing && $existing['phase'] !== 'finished') {
            return [
                'success' => false,
                'message' => '你已经有进行中的拱猪游戏了，请先完成或输入 pig quit 退出。'
            ];
        }

        $deck = self::initDeck();
        $hands = self::dealCards($deck);
        $starter = self::findStarter($hands);

        $state = [
            'hands' => $hands,
            'current_player' => $starter,
            'lead_suit' => -1,
            'current_round_cards' => [],
            'scores' => [0, 0, 0, 0],
            'hearts_collected' => [0, 0, 0, 0],
            'round' => 1,
            'tricks_won' => [[], [], [], []],
            'phase' => 'play',
            'char_id' => $charId,
            'history' => []
        ];

        self::saveGameState($charId, $state);

        $output = self::buildGameDisplay($state, true);

        if ($starter !== 0) {
            $state = self::processAiTurns($state);
            self::saveGameState($charId, $state);
            $output .= "\n" . self::buildGameDisplay($state, false);
        }

        return ['success' => true, 'message' => $output];
    }

    /**
     * 玩家出牌
     */
    public static function playCard(int $charId, int $cardIndex): array {
        self::ensureStateTable();
        $state = self::loadGameState($charId);

        if (!$state || $state['phase'] === 'finished') {
            return [
                'success' => false,
                'message' => '你没有进行中的拱猪游戏，请输入 pig start 开始新游戏。'
            ];
        }

        if ($state['current_player'] !== 0) {
            return [
                'success' => false,
                'message' => '还没轮到你出牌，请等待其他玩家。'
            ];
        }

        $hand = $state['hands'][0];
        if ($cardIndex < 1 || $cardIndex > count($hand)) {
            return [
                'success' => false,
                'message' => '无效的牌编号，请输入 1-' . count($hand) . ' 之间的数字。'
            ];
        }

        $realIndex = $cardIndex - 1;
        $card = $hand[$realIndex];
        $leadSuit = $state['lead_suit'];

        // 验证出牌规则
        if ($leadSuit !== -1) {
            $hasSuit = false;
            foreach ($hand as $c) {
                if ($c[0] === $leadSuit) {
                    $hasSuit = true;
                    break;
                }
            }
            if ($hasSuit && $card[0] !== $leadSuit) {
                $suitName = self::$suitSymbols[$leadSuit] . self::suitName($leadSuit);
                return [
                    'success' => false,
                    'message' => '你必须跟花色（' . $suitName . '），请选择正确的牌。'
                ];
            }
        }

        $state = self::doPlayCard($state, 0, $card);
        self::saveGameState($charId, $state);

        if ($state['phase'] !== 'finished' && $state['current_player'] !== 0) {
            $state = self::processAiTurns($state);
            self::saveGameState($charId, $state);
        }

        $output = self::buildGameDisplay($state, false);

        if ($state['phase'] === 'finished') {
            $result = self::finalizeGame($charId, $state);
            $output .= "\n" . $result['message'];
        }

        return ['success' => true, 'message' => $output];
    }

    /**
     * 获取游戏状态
     */
    public static function getGameStatus(int $charId): array {
        $state = self::loadGameState($charId);
        if (!$state || $state['phase'] === 'finished') {
            return [
                'success' => false,
                'message' => '你没有进行中的拱猪游戏。'
            ];
        }
        return [
            'success' => true,
            'message' => self::buildGameDisplay($state, false)
        ];
    }

    /**
     * 退出当前游戏
     */
    public static function quitGame(int $charId): array {
        $state = self::loadGameState($charId);
        if (!$state || $state['phase'] === 'finished') {
            return [
                'success' => false,
                'message' => '你没有进行中的拱猪游戏。'
            ];
        }
        self::deleteGameState($charId);
        return [
            'success' => true,
            'message' => '你已退出拱猪游戏。'
        ];
    }

    /**
     * 获取排行榜（原始数据）
     */
    public static function getRankings(int $limit = 10): array {
        $sql = "SELECT pgr.*, gc.name as player_name
                FROM pig_game_rankings pgr
                LEFT JOIN characters gc ON pgr.char_id = gc.id
                ORDER BY pgr.elo_rating DESC, pgr.wins DESC
                LIMIT {$limit}";
        return Database::queryAll($sql);
    }

    /**
     * 获取排行榜显示文本
     */
    public static function getRankingsDisplay(int $charId): array {
        $rankings = self::getRankings(10);
        $output = [];

        $output[] = HTML_HICYN . '╔══════════════════════════════════════╗' . HTML_NOR;
        $output[] = HTML_HICYN . '║          ' . HTML_HIWHT . '拱 猪 排 行 榜' . HTML_HICYN . '          ║' . HTML_NOR;
        $output[] = HTML_HICYN . '╚══════════════════════════════════════╝' . HTML_NOR;
        $output[] = '';

        if (empty($rankings)) {
            $output[] = '  暂无排名数据。';
        } else {
            $output[] = sprintf('  %-4s %-10s %-6s %-6s %-8s', '排名', '玩家', '场次', '胜场', '等级分');
            $output[] = '  ' . str_repeat('-', 40);
            foreach ($rankings as $i => $r) {
                $name = $r['player_name'] ?? '未知';
                $rank = $i + 1;
                $medal = '';
                if ($rank === 1) $medal = HTML_HIYEL . '★' . HTML_NOR;
                elseif ($rank === 2) $medal = HTML_HIWHT . '★' . HTML_NOR;
                elseif ($rank === 3) $medal = HTML_HIRED . '★' . HTML_NOR;
                $output[] = sprintf(
                    '  %-4d %-10s %-6d %-6d %-8d %s',
                    $rank,
                    self::truncateName($name, 10),
                    $r['total_games'],
                    $r['wins'],
                    $r['elo_rating'],
                    $medal
                );
            }
        }

        $myStats = self::getPlayerStats($charId);
        if ($myStats && $myStats['total_games'] > 0) {
            $output[] = '';
            $output[] = HTML_HIGRN . '【你的战绩】' . HTML_NOR;
            $output[] = "  场次: {$myStats['total_games']} | 胜场: {$myStats['wins']} | 等级分: {$myStats['elo_rating']}";
        }

        return [
            'success' => true,
            'message' => implode("\n", $output)
        ];
    }

    /**
     * 获取玩家统计
     */
    public static function getPlayerStats(int $charId): ?array {
        $sql = "SELECT * FROM pig_game_rankings WHERE char_id = ?";
        $stats = Database::queryOne($sql, [$charId]);
        if (!$stats) {
            return [
                'char_id' => $charId,
                'total_games' => 0,
                'wins' => 0,
                'elo_rating' => self::ELO_INITIAL,
                'last_played_at' => null
            ];
        }
        return $stats;
    }

    // ==================== 核心游戏逻辑 ====================

    /**
     * 初始化一副52张牌
     */
    private static function initDeck(): array {
        $deck = [];
        for ($suit = 0; $suit < 4; $suit++) {
            for ($rank = 2; $rank <= 14; $rank++) {
                $deck[] = [$suit, $rank];
            }
        }
        return $deck;
    }

    /**
     * 洗牌并发牌
     */
    private static function dealCards(array $deck): array {
        for ($i = count($deck) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            $tmp = $deck[$i];
            $deck[$i] = $deck[$j];
            $deck[$j] = $tmp;
        }

        $hands = [[], [], [], []];
        for ($i = 0; $i < 52; $i++) {
            $hands[$i % 4][] = $deck[$i];
        }

        foreach ($hands as &$hand) {
            usort($hand, function($a, $b) {
                if ($a[0] !== $b[0]) return $a[0] - $b[0];
                return $a[1] - $b[1];
            });
        }
        unset($hand);

        return $hands;
    }

    /**
     * 找到首轮出牌者（持梅花2者）
     */
    private static function findStarter(array $hands): int {
        for ($p = 0; $p < 4; $p++) {
            foreach ($hands[$p] as $card) {
                if ($card[0] === self::SUIT_CLUB && $card[1] === 2) {
                    return $p;
                }
            }
        }
        $minClub = 15;
        $starter = 0;
        for ($p = 0; $p < 4; $p++) {
            foreach ($hands[$p] as $card) {
                if ($card[0] === self::SUIT_CLUB && $card[1] < $minClub) {
                    $minClub = $card[1];
                    $starter = $p;
                }
            }
        }
        return $starter;
    }

    /**
     * 执行出牌并更新状态
     */
    private static function doPlayCard(array $state, int $player, array $card): array {
        $hand = &$state['hands'][$player];
        $found = false;
        foreach ($hand as $i => $c) {
            if ($c[0] === $card[0] && $c[1] === $card[1]) {
                array_splice($hand, $i, 1);
                $found = true;
                break;
            }
        }
        if (!$found) {
            return $state;
        }

        if ($state['lead_suit'] === -1) {
            $state['lead_suit'] = $card[0];
        }

        $state['current_round_cards'][] = [
            'player' => $player,
            'card' => $card
        ];

        if (count($state['current_round_cards']) === 4) {
            $result = self::calculateRoundScore($state['current_round_cards']);
            $winner = $result['winner'];
            $score = $result['score'];
            $hearts = $result['hearts'];

            foreach ($state['current_round_cards'] as $play) {
                $state['tricks_won'][$winner][] = $play['card'];
            }

            $state['scores'][$winner] += $score[$winner];
            if ($hearts > 0) {
                $state['hearts_collected'][$winner] += $hearts;
            }

            $state['history'][] = [
                'round' => $state['round'],
                'winner' => $winner,
                'cards' => $state['current_round_cards']
            ];

            $state['round']++;
            $state['current_round_cards'] = [];
            $state['lead_suit'] = -1;
            $state['current_player'] = $winner;

            if (count($state['hands'][0]) === 0) {
                $state['phase'] = 'finished';
            }
        } else {
            $state['current_player'] = ($player + 1) % 4;
        }

        return $state;
    }

    /**
     * 计算一轮得分
     */
    private static function calculateRoundScore(array $cards): array {
        $leadSuit = $cards[0]['card'][0];
        $winner = $cards[0]['player'];
        $maxRank = $cards[0]['card'][1];

        foreach ($cards as $play) {
            $card = $play['card'];
            if ($card[0] === $leadSuit && $card[1] > $maxRank) {
                $maxRank = $card[1];
                $winner = $play['player'];
            }
        }

        $hasTransformer = false;
        $hearts = 0;
        $winnerScore = 0;

        foreach ($cards as $play) {
            $card = $play['card'];
            if ($card[0] === self::TRANSFORMER[0] && $card[1] === self::TRANSFORMER[1]) {
                $hasTransformer = true;
            }
            if ($card[0] === self::SUIT_HEART) {
                $hearts++;
                $winnerScore -= 10;
            }
            if ($card[0] === self::PIG[0] && $card[1] === self::PIG[1]) {
                $winnerScore -= 100;
            }
            if ($card[0] === self::SHEEP[0] && $card[1] === self::SHEEP[1]) {
                $winnerScore += 100;
            }
        }

        if ($hasTransformer) {
            $winnerScore *= 2;
        }

        $score = [0, 0, 0, 0];
        $score[$winner] = $winnerScore;

        return [
            'winner' => $winner,
            'score' => $score,
            'hearts' => $hearts
        ];
    }

    /**
     * 计算最终得分（含全红奖励）
     */
    private static function calculateFinalScore(array $scores, array $heartsCollected): array {
        $final = [0, 0, 0, 0];
        for ($p = 0; $p < 4; $p++) {
            $final[$p] = $scores[$p];
            if ($heartsCollected[$p] >= 13) {
                $final[$p] += 330; // -130 变 +200，净增 330
            }
        }
        return $final;
    }

    /**
     * AI出牌逻辑
     */
    private static function aiPlayCard(array $hand, int $leadSuit): int {
        if (empty($hand)) return 0;

        // 首出
        if ($leadSuit === -1) {
            $bestIdx = -1;
            $bestRank = 99;
            foreach ($hand as $i => $card) {
                if (self::isSpecialCard($card)) continue;
                if ($card[1] < $bestRank) {
                    $bestRank = $card[1];
                    $bestIdx = $i;
                }
            }
            if ($bestIdx !== -1) return $bestIdx;
            // 没有非特殊牌，出最小的
            return self::findMinRankIndex($hand);
        }

        // 跟牌 - 有该花色则出最小的
        $suitCards = [];
        foreach ($hand as $i => $card) {
            if ($card[0] === $leadSuit) {
                $suitCards[] = ['idx' => $i, 'card' => $card];
            }
        }

        if (!empty($suitCards)) {
            usort($suitCards, function($a, $b) {
                return $a['card'][1] - $b['card'][1];
            });
            return $suitCards[0]['idx'];
        }

        // 无该花色 - 优先甩猪，其次甩红心，再甩大牌
        foreach ($hand as $i => $card) {
            if ($card[0] === self::PIG[0] && $card[1] === self::PIG[1]) {
                return $i;
            }
        }

        $heartCards = [];
        foreach ($hand as $i => $card) {
            if ($card[0] === self::SUIT_HEART) {
                $heartCards[] = ['idx' => $i, 'rank' => $card[1]];
            }
        }
        if (!empty($heartCards)) {
            usort($heartCards, function($a, $b) {
                return $a['rank'] - $b['rank'];
            });
            return $heartCards[0]['idx'];
        }

        // 甩最大的非羊牌
        $bestIdx = 0;
        $bestRank = -1;
        foreach ($hand as $i => $card) {
            if ($card[0] === self::SHEEP[0] && $card[1] === self::SHEEP[1]) continue;
            if ($card[1] > $bestRank) {
                $bestRank = $card[1];
                $bestIdx = $i;
            }
        }
        if ($bestRank !== -1) return $bestIdx;

        return 0;
    }

    /**
     * 处理AI连续出牌
     */
    private static function processAiTurns(array $state): array {
        while ($state['phase'] !== 'finished' && $state['current_player'] !== 0) {
            $player = $state['current_player'];
            $hand = $state['hands'][$player];
            if (empty($hand)) break;
            $cardIndex = self::aiPlayCard($hand, $state['lead_suit']);
            $card = $hand[$cardIndex];
            $state = self::doPlayCard($state, $player, $card);
        }
        return $state;
    }

    /**
     * 判断是否为特殊牌
     */
    private static function isSpecialCard(array $card): bool {
        if ($card[0] === self::PIG[0] && $card[1] === self::PIG[1]) return true;
        if ($card[0] === self::SHEEP[0] && $card[1] === self::SHEEP[1]) return true;
        if ($card[0] === self::TRANSFORMER[0] && $card[1] === self::TRANSFORMER[1]) return true;
        if ($card[0] === self::SUIT_HEART) return true;
        return false;
    }

    /**
     * 手牌中rank最小的索引
     */
    private static function findMinRankIndex(array $hand): int {
        $idx = 0;
        $min = $hand[0][1];
        for ($i = 1; $i < count($hand); $i++) {
            if ($hand[$i][1] < $min) {
                $min = $hand[$i][1];
                $idx = $i;
            }
        }
        return $idx;
    }

    // ==================== ELO与结算 ====================

    /**
     * 更新ELO评分
     */
    private static function updateElo(int $charId, bool $won): void {
        $stats = self::getPlayerStats($charId);
        $rating = $stats ? intval($stats['elo_rating']) : self::ELO_INITIAL;
        $totalGames = $stats ? intval($stats['total_games']) : 0;
        $wins = $stats ? intval($stats['wins']) : 0;

        $expected = 1.0 / (1.0 + pow(10, (self::AI_RATING - $rating) / 400.0));
        $actual = $won ? 1.0 : 0.0;
        $newRating = intval(round($rating + self::ELO_K * ($actual - $expected)));

        $totalGames++;
        if ($won) $wins++;

        $sql = "INSERT INTO pig_game_rankings (char_id, total_games, wins, elo_rating, last_played_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                total_games = VALUES(total_games),
                wins = VALUES(wins),
                elo_rating = VALUES(elo_rating),
                last_played_at = VALUES(last_played_at)";
        Database::execute($sql, [$charId, $totalGames, $wins, $newRating]);
    }

    /**
     * 结束游戏并保存记录
     */
    private static function finalizeGame(int $charId, array $state): array {
        $finalScores = self::calculateFinalScore($state['scores'], $state['hearts_collected']);
        $playerScore = $finalScores[0];
        $aiScores = [$finalScores[1], $finalScores[2], $finalScores[3]];
        $maxAiScore = max($aiScores);

        $won = $playerScore >= $maxAiScore;
        self::updateElo($charId, $won);

        $stats = self::getPlayerStats($charId);
        $currentRating = $stats ? intval($stats['elo_rating']) : self::ELO_INITIAL;
        $expected = 1.0 / (1.0 + pow(10, (self::AI_RATING - $currentRating) / 400.0));
        $eloChange = $won
            ? intval(round(self::ELO_K * (1 - $expected)))
            : intval(round(self::ELO_K * (0 - $expected)));

        $players = json_encode([
            ['name' => '你', 'type' => 'player'],
            ['name' => '东家(AI)', 'type' => 'ai'],
            ['name' => '南家(AI)', 'type' => 'ai'],
            ['name' => '西家(AI)', 'type' => 'ai']
        ]);
        $scores = json_encode($finalScores);

        $sql = "INSERT INTO pig_game_records (char_id, players, scores, result, elo_change, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())";
        Database::execute($sql, [
            $charId, $players, $scores,
            $won ? 'win' : 'lose', $eloChange
        ]);

        self::deleteGameState($charId);

        $output = [];
        $output[] = '';
        $output[] = HTML_HICYN . '══════════ 游戏结束 ══════════' . HTML_NOR;
        $output[] = '最终得分：';
        $names = ['你', '东家(AI)', '南家(AI)', '西家(AI)'];
        for ($p = 0; $p < 4; $p++) {
            $s = $finalScores[$p];
            $scoreStr = $s > 0 ? '+' . $s : (string)$s;
            $output[] = "  {$names[$p]}: {$scoreStr}分";
        }
        $output[] = '';
        if ($won) {
            $output[] = HTML_HIGRN . '恭喜你赢了！' . HTML_NOR;
        } else {
            $output[] = HTML_HIRED . '很遗憾，你输了。' . HTML_NOR;
        }
        $output[] = '等级分变化: ' . ($eloChange >= 0 ? '+' : '') . $eloChange;

        return [
            'success' => true,
            'message' => implode("\n", $output)
        ];
    }

    // ==================== 显示相关 ====================

    /**
     * 构建游戏显示文本
     */
    private static function buildGameDisplay(array $state, bool $isNewGame): string {
        $output = [];
        $names = self::$playerNames;

        $output[] = HICYN . '═══ 拱猪游戏 第' . $state['round'] . '轮 ═══' . NOR;

        for ($p = 1; $p < 4; $p++) {
            $cnt = count($state['hands'][$p]);
            $output[] = "  {$names[$p]}: {$cnt}张";
        }
        $output[] = '';

        $scoreLine = '当前得分: ';
        for ($p = 0; $p < 4; $p++) {
            $s = $state['scores'][$p];
            $scoreLine .= $names[$p] . ($s > 0 ? '+' : '') . $s . ' ';
        }
        $output[] = $scoreLine;

        $output[] = '';
        $output[] = HIGRN . '你的手牌：' . NOR;
        $hand = $state['hands'][0];
        $cardStrs = [];
        foreach ($hand as $i => $card) {
            $cardStrs[] = ($i + 1) . '.' . self::formatCard($card);
        }
        if (empty($cardStrs)) {
            $output[] = '  (无)';
        } else {
            $output[] = '  ' . implode('  ', $cardStrs);
        }

        if (!empty($state['current_round_cards'])) {
            $output[] = '';
            $output[] = '本轮已出：';
            foreach ($state['current_round_cards'] as $play) {
                $pName = $names[$play['player']];
                $output[] = "  {$pName}: " . self::formatCard($play['card']);
            }
        }

        if ($state['phase'] === 'finished') {
            // 已结束，在finalizeGame中处理详细结果
        } elseif ($state['current_player'] === 0) {
            $output[] = '';
            $output[] = HIYEL . '轮到你出牌，请输入: pig play <编号>' . NOR;
        } else {
            $output[] = '';
            $output[] = "等待 {$names[$state['current_player']]} 出牌...";
        }

        return implode("\n", $output);
    }

    /**
     * 格式化单张牌（带HTML颜色高亮）
     */
    private static function formatCard(array $card): string {
        $suit = $card[0];
        $rank = $card[1];
        $sym = self::$suitSymbols[$suit];
        $name = self::cardName($rank);

        if ($suit === self::SUIT_HEART) {
            return HTML_HIMAG . $sym . $name . HTML_NOR;
        }
        if ($suit === self::PIG[0] && $rank === self::PIG[1]) {
            return HTML_HIRED . $sym . $name . HTML_NOR;
        }
        if ($suit === self::SHEEP[0] && $rank === self::SHEEP[1]) {
            return HTML_HIGRN . $sym . $name . HTML_NOR;
        }
        if ($suit === self::TRANSFORMER[0] && $rank === self::TRANSFORMER[1]) {
            return HTML_HIYEL . $sym . $name . HTML_NOR;
        }
        return $sym . $name;
    }

    /**
     * 牌面显示名
     */
    private static function cardName(int $rank): string {
        if ($rank === 14) return 'A';
        if ($rank === 13) return 'K';
        if ($rank === 12) return 'Q';
        if ($rank === 11) return 'J';
        return (string)$rank;
    }

    /**
     * 花色名称
     */
    private static function suitName(int $suit): string {
        $names = ['黑桃', '红心', '梅花', '方块'];
        return $names[$suit] ?? '未知';
    }

    /**
     * 截断名称
     */
    private static function truncateName(string $name, int $len): string {
        if (function_exists('mb_strlen') && mb_strlen($name) > $len) {
            return mb_substr($name, 0, $len - 1) . '..';
        }
        if (strlen($name) > $len * 2) {
            return substr($name, 0, $len * 2 - 2) . '..';
        }
        return $name;
    }

    // ==================== 数据持久化 ====================

    /**
     * 确保状态表存在
     */
    private static function ensureStateTable(): void {
        $db = Database::getInstance();
        $db->exec("CREATE TABLE IF NOT EXISTS pig_game_state (
            char_id INT PRIMARY KEY,
            game_state JSON NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
    }

    /**
     * 加载游戏状态
     */
    private static function loadGameState(int $charId): ?array {
        $sql = "SELECT game_state FROM pig_game_state WHERE char_id = ?";
        $result = Database::queryOne($sql, [$charId]);
        if ($result) {
            return json_decode($result['game_state'], true);
        }
        return null;
    }

    /**
     * 保存游戏状态
     */
    private static function saveGameState(int $charId, array $state): void {
        $json = json_encode($state);
        $sql = "INSERT INTO pig_game_state (char_id, game_state, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                game_state = VALUES(game_state),
                updated_at = VALUES(updated_at)";
        Database::execute($sql, [$charId, $json]);
    }

    /**
     * 删除游戏状态
     */
    private static function deleteGameState(int $charId): void {
        $sql = "DELETE FROM pig_game_state WHERE char_id = ?";
        Database::execute($sql, [$charId]);
    }
}
