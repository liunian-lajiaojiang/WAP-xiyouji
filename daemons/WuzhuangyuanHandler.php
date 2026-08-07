<?php
require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/MessageDaemon.php';
require_once __DIR__ . '/../config/game.php';

class WuzhuangyuanHandler extends ActionHandler {

    private const RANK_LEVELS = [
        'gold'   => ['name' => '金榜', 'min_daoxing' => 10000000, 'max_daoxing' => 20000000, 'title' => ['武状元', '武榜眼', '武探花']],
        'silver' => ['name' => '银榜', 'min_daoxing' => 5000000,  'max_daoxing' => 10000000, 'title' => ['第一', '第二', '第三']],
        'copper' => ['name' => '铜榜', 'min_daoxing' => 1000000,  'max_daoxing' => 5000000,  'title' => ['第一', '第二', '第三']],
        'iron'   => ['name' => '铁榜', 'min_daoxing' => 100000,   'max_daoxing' => 1000000,  'title' => ['第一', '第二', '第三']],
        'tin'    => ['name' => '锡榜', 'min_daoxing' => 0,        'max_daoxing' => 100000,   'title' => ['第一', '第二', '第三']],
    ];

    private const ROOM_YWC_PREFIX = 'huanggong/ywc';

    public function execute(int $charId, array $action, array $params = []): array {
        $cmd = $params['cmd'] ?? $action['action_cmd'] ?? '';
        
        switch ($cmd) {
            case 'apply':
                $result = self::handleApply($charId, $params);
                return ['success' => true, 'message' => $result];
            case 'challenge':
                return self::handleChallenge($charId, $params);
            case 'list':
                return self::handleList($charId, $params);
            case 'check':
                return self::handleCheck($charId, $params);
            default:
                return ['success' => false, 'message' => '未知命令'];
        }
    }

    public static function handleInquiry(array $npc, array $char, string $topic, $extraParam = null): ?string {
        $npcName = $npc['name'] ?? '房玄龄';
        $charId = intval($char['id'] ?? 0);
        $charName = $char['name'] ?? '你';
        $daoxing = intval($char['daoxing'] ?? 0);

        $topic = strtolower($topic);
        
        if ($topic === '武状元' || $topic === '武榜' || $topic === '比武') {
            return self::handleAskWuzhuangyuan($npcName, $charName, $daoxing);
        }
        
        if ($topic === '投状' || $topic === '报名') {
            return self::handleApply($charId, ['npc_name' => $npcName, 'char_name' => $charName, 'daoxing' => $daoxing]);
        }
        
        if ($topic === '榜' || $topic === '排名' || $topic === '榜单') {
            return self::handleRankList($npcName);
        }
        
        if ($topic === '挑战') {
            return self::handleAvatarChallenge($npcName, $charId, $charName, $daoxing, $extraParam);
        }
        
        return null;
    }

    private static function handleAskWuzhuangyuan(string $npcName, string $charName, int $daoxing): string {
        $rankInfo = self::getRankByDaoxing($daoxing);
        
        $msg = HTML_HICYN . "{$npcName}捋了捋胡须，微笑道：" . HTML_NOR;
        $msg .= "武状元比武大会乃是大唐盛世之盛事，五榜争雄，各显神通！\n\n";
        $msg .= HTML_HIYEL . "【五大榜次】\n" . HTML_NOR;
        $msg .= "  金榜：道行100-200年（需" . (self::RANK_LEVELS['gold']['min_daoxing']/100000) . "年以上）\n";
        $msg .= "  银榜：道行50-100年\n";
        $msg .= "  铜榜：道行10-50年\n";
        $msg .= "  铁榜：道行1-10年\n";
        $msg .= "  锡榜：道行1年以下\n\n";
        
        $msg .= HTML_HICYN . "{$npcName}说道：" . HTML_NOR;
        $msg .= "{$charName}，你的道行为" . HTML_HIYEL . self::formatDaoxing($daoxing) . HTML_NOR . "，";
        $msg .= "可参加" . HTML_HIGRN . "{$rankInfo['name']}" . HTML_NOR . "的角逐。\n";
        $msg .= "若想投状报名，只需对我说「投状」即可。";
        
        return $msg;
    }

    private static function handleApply(int $charId, array $params): string {
        $npcName = $params['npc_name'] ?? '房玄龄';
        $charName = $params['char_name'] ?? '玩家';
        $daoxing = intval($params['daoxing'] ?? 0);

        if ($daoxing < 0) {
            return HTML_HIRED . "{$npcName}摇了摇头，说道：你的道行数据异常，请联系管理员。" . HTML_NOR;
        }

        $rankInfo = self::getRankByDaoxing($daoxing);
        $rankLevel = $rankInfo['key'];
        $rankName = $rankInfo['name'];

        $existing = Database::queryOne(
            "SELECT * FROM wuzhuangyuan_ranks WHERE char_id = ? LIMIT 1",
            [$charId]
        );

        if ($existing) {
            $existingRank = self::RANK_LEVELS[$existing['rank_level']]['name'];
            return HTML_HIYEL . "{$npcName}说道：你已经在{$existingRank}上榜了，无需重复投状。" . HTML_NOR;
        }

        $currentRank = Database::queryOne(
            "SELECT * FROM wuzhuangyuan_ranks WHERE rank_level = ? ORDER BY rank_position ASC LIMIT 1",
            [$rankLevel]
        );

        if (!$currentRank) {
            return HTML_HIRED . "{$npcName}叹了口气，说道：当前{$rankName}暂无排名，系统异常。" . HTML_NOR;
        }

        $msg = HTML_HICYN . "{$npcName}郑重道：" . HTML_NOR;
        $msg .= "好！{$charName}投状{$rankName}，勇气可嘉！\n";
        $msg .= "请前往演武场挑战现任" . HTML_HIYEL . "{$currentRank['char_name']}" . HTML_NOR . "，";
        $msg .= "若能取胜，便可取而代之！\n\n";
        $msg .= HTML_HIGRN . "提示：使用 'challenge' 命令挑战榜上前三名。" . HTML_NOR;

        return $msg;
    }

    private static function handleRankList(string $npcName): string {
        $msg = HTML_HICYN . "{$npcName}展开榜单，高声念道：" . HTML_NOR . "\n\n";
        
        foreach (array_reverse(self::RANK_LEVELS) as $key => $level) {
            $msg .= HTML_HIYEL . "【{$level['name']}】\n" . HTML_NOR;
            
            $ranks = Database::queryAll(
                "SELECT * FROM wuzhuangyuan_ranks WHERE rank_level = ? ORDER BY rank_position ASC",
                [$key]
            );
            
            foreach ($ranks as $rank) {
                $position = intval($rank['rank_position']);
                $title = $level['title'][$position - 1] ?? "第{$position}名";
                $name = $rank['char_name'] ?? '空缺';
                $dao = $rank['daoxing'] > 0 ? '（' . self::formatDaoxing($rank['daoxing']) . '）' : '';
                $msg .= "  {$title}：" . HTML_HIGRN . "{$name}" . HTML_NOR . "{$dao}\n";
            }
            $msg .= "\n";
        }
        
        return $msg;
    }

    private static function handleList(int $charId, array $params): array {
        $rankLevel = $params['rank_level'] ?? '';
        
        if (!empty($rankLevel) && !isset(self::RANK_LEVELS[$rankLevel])) {
            return ['success' => false, 'message' => '无效的榜次级别'];
        }
        
        $where = !empty($rankLevel) ? "WHERE rank_level = ?" : "";
        $ranks = Database::queryAll(
            "SELECT * FROM wuzhuangyuan_ranks {$where} ORDER BY FIELD(rank_level, 'gold','silver','copper','iron','tin'), rank_position ASC",
            !empty($rankLevel) ? [$rankLevel] : []
        );
        
        $msg = "【武状元排行榜】\n\n";
        foreach ($ranks as $rank) {
            $levelInfo = self::RANK_LEVELS[$rank['rank_level']];
            $position = intval($rank['rank_position']);
            $title = $levelInfo['title'][$position - 1] ?? "第{$position}名";
            $msg .= "{$levelInfo['name']} - {$title}：{$rank['char_name']}（" . self::formatDaoxing($rank['daoxing']) . "）\n";
        }
        
        return ['success' => true, 'message' => $msg];
    }

    private static function handleCheck(int $charId, array $params): array {
        $char = Database::queryOne("SELECT name, daoxing FROM characters WHERE id = ?", [$charId]);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        $daoxing = intval($char['daoxing'] ?? 0);
        $rankInfo = self::getRankByDaoxing($daoxing);
        
        $existing = Database::queryOne(
            "SELECT * FROM wuzhuangyuan_ranks WHERE char_id = ? LIMIT 1",
            [$charId]
        );
        
        $msg = "你的道行为：" . self::formatDaoxing($daoxing) . "\n";
        $msg .= "可参加的榜次：{$rankInfo['name']}\n";
        
        if ($existing) {
            $levelInfo = self::RANK_LEVELS[$existing['rank_level']];
            $position = intval($existing['rank_position']);
            $title = $levelInfo['title'][$position - 1] ?? "第{$position}名";
            $msg .= "当前排名：{$levelInfo['name']} - {$title}\n";
        } else {
            $msg .= "当前排名：未上榜\n";
        }
        
        return ['success' => true, 'message' => $msg];
    }

    private static function handleChallenge(int $charId, array $params): array {
        $rankLevel = $params['rank_level'] ?? '';
        $position = intval($params['position'] ?? 0);
        
        if (empty($rankLevel) || !isset(self::RANK_LEVELS[$rankLevel])) {
            return ['success' => false, 'message' => '请指定有效的榜次级别（gold/silver/copper/iron/tin）'];
        }
        
        if ($position < 1 || $position > 3) {
            return ['success' => false, 'message' => '请指定排名（1-3）'];
        }
        
        $char = Database::queryOne("SELECT name, daoxing FROM characters WHERE id = ?", [$charId]);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        $charName = $char['name'];
        $charDaoxing = intval($char['daoxing'] ?? 0);
        
        $target = Database::queryOne(
            "SELECT * FROM wuzhuangyuan_ranks WHERE rank_level = ? AND rank_position = ?",
            [$rankLevel, $position]
        );
        
        if (!$target) {
            return ['success' => false, 'message' => '该排名暂无选手'];
        }
        
        $levelInfo = self::RANK_LEVELS[$rankLevel];
        $requiredDaoxing = $levelInfo['min_daoxing'];
        
        if ($charDaoxing < $requiredDaoxing) {
            $requiredStr = self::formatDaoxing($requiredDaoxing);
            $currentStr = self::formatDaoxing($charDaoxing);
            return ['success' => false, 'message' => "你的道行{$currentStr}不足，{$levelInfo['name']}需要{$requiredStr}以上"];
        }
        
        if ($target['char_id'] == $charId) {
            return ['success' => false, 'message' => '你不能挑战自己'];
        }
        
        $title = $levelInfo['title'][$position - 1] ?? "第{$position}名";
        $msg = HTML_HICYN . "你向{$levelInfo['name']}{$title}【{$target['char_name']}】发起挑战！" . HTML_NOR . "\n";
        $msg .= HTML_HIYEL . "战斗即将开始，请做好准备！" . HTML_NOR;
        
        self::broadcastChallenge($rankLevel, $position, $charName, $target['char_name']);
        
        return ['success' => true, 'message' => $msg, 'data' => [
            'rank_level' => $rankLevel,
            'position' => $position,
            'target_char_id' => $target['char_id'],
            'target_name' => $target['char_name'],
        ]];
    }

    private static function handleAvatarChallenge(string $npcName, int $charId, string $charName, int $daoxing, $extraParam): ?string {
        if (!$extraParam || !str_starts_with($extraParam, 'challenge_')) {
            return HTML_HIRED . "{$npcName}皱眉道：你想挑战什么？" . HTML_NOR;
        }
        
        $parts = explode('_', $extraParam);
        if (count($parts) < 3) {
            return HTML_HIRED . "{$npcName}皱眉道：挑战目标不明确！" . HTML_NOR;
        }
        
        $rankLevel = $parts[1];
        $position = intval($parts[2]);
        
        if (!isset(self::RANK_LEVELS[$rankLevel])) {
            return HTML_HIRED . "{$npcName}皱眉道：无效的榜次级别！" . HTML_NOR;
        }
        
        $levelInfo = self::RANK_LEVELS[$rankLevel];
        $requiredDaoxing = $levelInfo['min_daoxing'];
        
        if ($daoxing < $requiredDaoxing) {
            $requiredStr = self::formatDaoxing($requiredDaoxing);
            $currentStr = self::formatDaoxing($daoxing);
            return HTML_HIRED . "{$npcName}摇了摇头，说道：你的道行{$currentStr}不足，{$levelInfo['name']}需要{$requiredStr}以上！" . HTML_NOR;
        }
        
        $avatar = Database::queryOne(
            "SELECT npc_id, char_name FROM wuzhuangyuan_avatars WHERE rank_level = ? AND rank_position = ? AND status = 'active'",
            [$rankLevel, $position]
        );
        
        if (!$avatar) {
            return HTML_HIRED . "{$npcName}皱眉道：当前榜位暂无挑战者！" . HTML_NOR;
        }
        
        $title = $levelInfo['title'][$position - 1] ?? "第{$position}名";
        
        require_once DAEMON_PATH . 'CombatDaemon.php';
        $result = CombatDaemon::startFight($charId, $avatar['npc_id'], 'npc', 'wuzhuangyuan', "{$rankLevel}_{$position}");
        
        if ($result['success']) {
            $msg = HTML_HICYN . "{$npcName}高声道：比武开始！\n" . HTML_NOR;
            $msg .= HTML_HIGRN . "{$charName}" . HTML_NOR . "挑战" . HTML_HIYEL . "{$levelInfo['name']}{$title}【{$avatar['char_name']}】" . HTML_NOR . "！\n";
            $msg .= HTML_HIYEL . "战斗即将开始，请做好准备！" . HTML_NOR;
            return $msg;
        } else {
            return HTML_HIRED . "{$result['message']}" . HTML_NOR;
        }
    }

    public static function onCombatResult(int $winnerId, int $loserId, string $rankLevel, int $position): void {
        $winner = Database::queryOne("SELECT name, daoxing FROM characters WHERE id = ?", [$winnerId]);
        $loser = Database::queryOne("SELECT name FROM characters WHERE id = ?", [$loserId]);
        
        if (!$winner) return;
        
        $levelInfo = self::RANK_LEVELS[$rankLevel];
        $title = $levelInfo['title'][$position - 1] ?? "第{$position}名";
        
        Database::execute(
            "UPDATE wuzhuangyuan_ranks SET char_id = ?, char_name = ?, daoxing = ?, updated_at = NOW() WHERE rank_level = ? AND rank_position = ?",
            [$winnerId, $winner['name'], $winner['daoxing'], $rankLevel, $position]
        );
        
        $avatar = Database::queryOne(
            "SELECT npc_id, npc_name FROM wuzhuangyuan_avatars WHERE rank_level = ? AND rank_position = ? AND status = 'active'",
            [$rankLevel, $position]
        );
        
        if ($avatar && $avatar['npc_id']) {
            $fullTitle = "{$levelInfo['name']}{$title}";
            
            Database::execute(
                "UPDATE npcs SET name = ?, title = ? WHERE id = ?",
                [$winner['name'], $fullTitle, $avatar['npc_id']]
            );
            
            Database::execute(
                "UPDATE wuzhuangyuan_avatars SET char_id = ?, char_name = ?, npc_name = ? WHERE npc_id = ?",
                [$winnerId, $winner['name'], $winner['name'], $avatar['npc_id']]
            );
        }
        
        $winnerName = $winner['name'];
        $loserName = $loser['name'] ?? '无名之辈';
        
        $msg = HTML_HICYN . "【武状元捷报】" . HTML_NOR . "\n";
        $msg .= HTML_HIGRN . "{$winnerName}" . HTML_NOR . "在演武场击败" . HTML_HIRED . "{$loserName}" . HTML_NOR . "，\n";
        $msg .= "夺得" . HTML_HIYEL . "{$levelInfo['name']}{$title}" . HTML_NOR . "！\n";
        $msg .= "恭喜！新的{$title}诞生了！";
        
        MessageDaemon::broadcastToAll($msg);
        
        $roomId = 'huanggong/fst';
        MessageDaemon::broadcastToRoom($roomId, $msg);
    }

    private static function broadcastChallenge(string $rankLevel, int $position, string $challenger, string $defender): void {
        $levelInfo = self::RANK_LEVELS[$rankLevel];
        $title = $levelInfo['title'][$position - 1] ?? "第{$position}名";
        
        $msg = HTML_HICYN . "【比武公告】" . HTML_NOR . "\n";
        $msg .= HTML_HIGRN . "{$challenger}" . HTML_NOR . "向" . HTML_HIYEL . "{$levelInfo['name']}{$title}【{$defender}】" . HTML_NOR . "发起挑战！\n";
        $msg .= "请各位前往演武场观战！";
        
        $roomId = 'huanggong/fst';
        MessageDaemon::broadcastToRoom($roomId, $msg);
    }

    private static function getRankByDaoxing(int $daoxing): array {
        foreach (self::RANK_LEVELS as $key => $level) {
            if ($daoxing >= $level['min_daoxing'] && $daoxing <= $level['max_daoxing']) {
                return ['key' => $key, 'name' => $level['name'], 'min_daoxing' => $level['min_daoxing'], 'max_daoxing' => $level['max_daoxing']];
            }
        }
        return ['key' => 'tin', 'name' => '锡榜', 'min_daoxing' => 0, 'max_daoxing' => 100000];
    }

    private static function formatDaoxing(int $daoxing): string {
        if ($daoxing >= 100000) {
            return number_format($daoxing / 100000, 1) . '年';
        }
        return number_format($daoxing) . '天';
    }
}
