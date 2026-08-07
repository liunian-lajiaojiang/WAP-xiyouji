<?php
require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/MessageDaemon.php';
require_once __DIR__ . '/../config/game.php';

class XingxiuHandler extends ActionHandler {

    private const RANK_LEVELS = [
        'jiao'   => ['name' => '角木蛟星君', 'title' => '角木蛟', 'group' => 'qinglong', 'min_daoxing' => 5000000, 'desc' => '东方青龙七宿之首，掌苍龙之角'],
        'kang'   => ['name' => '亢金龙星君', 'title' => '亢金龙', 'group' => 'qinglong', 'min_daoxing' => 5000000, 'desc' => '东方青龙七宿之二，掌苍龙咽喉'],
        'di'     => ['name' => '氐土貉星君', 'title' => '氐土貉', 'group' => 'qinglong', 'min_daoxing' => 5000000, 'desc' => '东方青龙七宿之三，掌苍龙胸臆'],
        'fang'   => ['name' => '房日兔星君', 'title' => '房日兔', 'group' => 'qinglong', 'min_daoxing' => 5000000, 'desc' => '东方青龙七宿之四，掌苍龙腹房'],
        'xin'    => ['name' => '心月狐星君', 'title' => '心月狐', 'group' => 'qinglong', 'min_daoxing' => 5000000, 'desc' => '东方青龙七宿之五，掌苍龙心脏'],
        'wei'    => ['name' => '尾火虎星君', 'title' => '尾火虎', 'group' => 'qinglong', 'min_daoxing' => 5000000, 'desc' => '东方青龙七宿之六，掌苍龙尾巴'],
        'ji'     => ['name' => '箕水豹星君', 'title' => '箕水豹', 'group' => 'qinglong', 'min_daoxing' => 5000000, 'desc' => '东方青龙七宿之七，掌苍龙尾箕'],
        'dou'    => ['name' => '斗木獬星君', 'title' => '斗木獬', 'group' => 'xuanwu', 'min_daoxing' => 4500000, 'desc' => '北方玄武七宿之首，掌北方斗柄'],
        'niu'    => ['name' => '牛金牛星君', 'title' => '牛金牛', 'group' => 'xuanwu', 'min_daoxing' => 4500000, 'desc' => '北方玄武七宿之二，掌北方牛宿'],
        'nv'     => ['name' => '女土蝠星君', 'title' => '女土蝠', 'group' => 'xuanwu', 'min_daoxing' => 4500000, 'desc' => '北方玄武七宿之三，掌北方女宿'],
        'xu'     => ['name' => '虚日鼠星君', 'title' => '虚日鼠', 'group' => 'xuanwu', 'min_daoxing' => 4500000, 'desc' => '北方玄武七宿之四，掌北方虚宿'],
        'wei2'   => ['name' => '危月燕星君', 'title' => '危月燕', 'group' => 'xuanwu', 'min_daoxing' => 4500000, 'desc' => '北方玄武七宿之五，掌北方危宿'],
        'shi'    => ['name' => '室火猪星君', 'title' => '室火猪', 'group' => 'xuanwu', 'min_daoxing' => 4500000, 'desc' => '北方玄武七宿之六，掌北方室宿'],
        'bi'     => ['name' => '壁水貐星君', 'title' => '壁水貐', 'group' => 'xuanwu', 'min_daoxing' => 4500000, 'desc' => '北方玄武七宿之七，掌北方壁宿'],
        'kui'    => ['name' => '奎木狼星君', 'title' => '奎木狼', 'group' => 'baihu', 'min_daoxing' => 4000000, 'desc' => '西方白虎七宿之首，掌西方奎宿'],
        'lou'    => ['name' => '娄金狗星君', 'title' => '娄金狗', 'group' => 'baihu', 'min_daoxing' => 4000000, 'desc' => '西方白虎七宿之二，掌西方娄宿'],
        'wei3'   => ['name' => '胃土雉星君', 'title' => '胃土雉', 'group' => 'baihu', 'min_daoxing' => 4000000, 'desc' => '西方白虎七宿之三，掌西方胃宿'],
        'mao'    => ['name' => '昴日鸡星君', 'title' => '昴日鸡', 'group' => 'baihu', 'min_daoxing' => 4000000, 'desc' => '西方白虎七宿之四，掌西方昴宿'],
        'bi2'    => ['name' => '毕月乌星君', 'title' => '毕月乌', 'group' => 'baihu', 'min_daoxing' => 4000000, 'desc' => '西方白虎七宿之五，掌西方毕宿'],
        'zi'     => ['name' => '觜火猴星君', 'title' => '觜火猴', 'group' => 'baihu', 'min_daoxing' => 4000000, 'desc' => '西方白虎七宿之六，掌西方觜宿'],
        'shen'   => ['name' => '参水猿星君', 'title' => '参水猿', 'group' => 'baihu', 'min_daoxing' => 4000000, 'desc' => '西方白虎七宿之七，掌西方参宿'],
        'jing'   => ['name' => '井木犴星君', 'title' => '井木犴', 'group' => 'zhuque', 'min_daoxing' => 3500000, 'desc' => '南方朱雀七宿之首，掌南方井宿'],
        'gui'    => ['name' => '鬼金羊星君', 'title' => '鬼金羊', 'group' => 'zhuque', 'min_daoxing' => 3500000, 'desc' => '南方朱雀七宿之二，掌南方鬼宿'],
        'liu'    => ['name' => '柳土獐星君', 'title' => '柳土獐', 'group' => 'zhuque', 'min_daoxing' => 3500000, 'desc' => '南方朱雀七宿之三，掌南方柳宿'],
        'xing'   => ['name' => '星日马星君', 'title' => '星日马', 'group' => 'zhuque', 'min_daoxing' => 3500000, 'desc' => '南方朱雀七宿之四，掌南方星宿'],
        'zhang'  => ['name' => '张月鹿星君', 'title' => '张月鹿', 'group' => 'zhuque', 'min_daoxing' => 3500000, 'desc' => '南方朱雀七宿之五，掌南方张宿'],
        'yi'     => ['name' => '翼火蛇星君', 'title' => '翼火蛇', 'group' => 'zhuque', 'min_daoxing' => 3500000, 'desc' => '南方朱雀七宿之六，掌南方翼宿'],
        'zhen'   => ['name' => '轸水蚓星君', 'title' => '轸水蚓', 'group' => 'zhuque', 'min_daoxing' => 3500000, 'desc' => '南方朱雀七宿之七，掌南方轸宿'],
    ];

    private const GROUP_NAMES = [
        'qinglong' => '东方青龙',
        'xuanwu'   => '北方玄武',
        'baihu'    => '西方白虎',
        'zhuque'   => '南方朱雀',
    ];

    public function execute(int $charId, array $action, array $params = []): array {
        $cmd = $params['cmd'] ?? $action['action_cmd'] ?? '';
        
        switch ($cmd) {
            case 'apply':
                return self::handleApply($charId, $params);
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
        $npcName = $npc['name'] ?? '星君';
        $charId = intval($char['id'] ?? 0);
        $charName = $char['name'] ?? '你';
        $daoxing = intval($char['daoxing'] ?? 0);

        $topic = strtolower($topic);
        
        if ($topic === '星宿' || $topic === '28星宿' || $topic === '二十八星宿') {
            return self::handleAskXingxiu($npcName, $charName, $daoxing);
        }
        
        if ($topic === '投名' || $topic === '报名') {
            return self::handleApplyInquiry($npcName, $charId, $charName, $daoxing);
        }
        
        if ($topic === '星榜' || $topic === '榜单') {
            return self::handleRankList($npcName);
        }
        
        if ($topic === '挑战') {
            return self::handleChallengeInquiry($npcName, $charId, $charName, $daoxing);
        }
        
        return null;
    }

    private static function handleAskXingxiu(string $npcName, string $charName, int $daoxing): string {
        $msg = HTML_HICYN . "{$npcName}缓缓说道：" . HTML_NOR;
        $msg .= "二十八星宿乃天庭镇守四方的神将，分属四象，各掌吉凶。\n\n";
        $msg .= HTML_HIYEL . "【四象七宿】\n" . HTML_NOR;
        $msg .= "  " . HTML_HIGRN . "东方青龙" . HTML_NOR . "：角木蛟、亢金龙、氐土貉、房日兔、心月狐、尾火虎、箕水豹\n";
        $msg .= "  " . HTML_HIYEL . "北方玄武" . HTML_NOR . "：斗木獬、牛金牛、女土蝠、虚日鼠、危月燕、室火猪、壁水貐\n";
        $msg .= "  " . HTML_HICYN . "西方白虎" . HTML_NOR . "：奎木狼、娄金狗、胃土雉、昴日鸡、毕月乌、觜火猴、参水猿\n";
        $msg .= "  " . HTML_HIRED . "南方朱雀" . HTML_NOR . "：井木犴、鬼金羊、柳土獐、星日马、张月鹿、翼火蛇、轸水蚓\n\n";
        $msg .= HTML_HICYN . "{$npcName}说道：" . HTML_NOR;
        $msg .= "{$charName}，你的道行为" . HTML_HIYEL . self::formatDaoxing($daoxing) . HTML_NOR . "，";
        $msg .= "若想争夺星君之位，需有足够实力击败现任星君。\n";
        $msg .= "对我说「投名」即可报名参加挑战。";
        
        return $msg;
    }

    private static function handleApplyInquiry(string $npcName, int $charId, string $charName, int $daoxing): string {
        $existing = Database::queryOne(
            "SELECT * FROM xingxiu_ranks WHERE char_id = ? LIMIT 1",
            [$charId]
        );
        
        if ($existing) {
            $levelInfo = self::RANK_LEVELS[$existing['rank_level']];
            return HTML_HIYEL . "{$npcName}说道：你已经是{$levelInfo['name']}了，无需重复报名。" . HTML_NOR;
        }

        $msg = HTML_HICYN . "{$npcName}郑重道：" . HTML_NOR;
        $msg .= "好！{$charName}愿投身星宿之争，勇气可嘉！\n";
        $msg .= "请选择你要挑战的星君之位，挑战成功即可取而代之！\n\n";
        $msg .= HTML_HIGRN . "提示：对我说「挑战」查看可挑战的星君。" . HTML_NOR;

        return $msg;
    }

    private static function handleApply(int $charId, array $params): array {
        $char = Database::queryOne("SELECT name, daoxing FROM characters WHERE id = ?", [$charId]);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        $charName = $char['name'];
        $daoxing = intval($char['daoxing'] ?? 0);

        $existing = Database::queryOne(
            "SELECT * FROM xingxiu_ranks WHERE char_id = ? LIMIT 1",
            [$charId]
        );
        
        if ($existing) {
            $levelInfo = self::RANK_LEVELS[$existing['rank_level']];
            return ['success' => false, 'message' => "你已经是{$levelInfo['name']}了"];
        }

        return ['success' => true, 'message' => "{$charName}已报名参加星宿挑战！请选择要挑战的星君。"];
    }

    private static function handleRankList(string $npcName): string {
        $msg = HTML_HICYN . "{$npcName}展开星榜，高声念道：" . HTML_NOR . "\n\n";
        
        foreach (self::GROUP_NAMES as $groupKey => $groupName) {
            $msg .= HTML_HIYEL . "【{$groupName}七宿】\n" . HTML_NOR;
            
            foreach (self::RANK_LEVELS as $key => $level) {
                if ($level['group'] !== $groupKey) continue;
                
                $rank = Database::queryOne(
                    "SELECT * FROM xingxiu_ranks WHERE rank_level = ?",
                    [$key]
                );
                
                $occupant = $rank && $rank['status'] === 'occupied' ? $rank['char_name'] : '空缺';
                $dao = $rank && $rank['daoxing'] > 0 ? '（' . self::formatDaoxing($rank['daoxing']) . '）' : '';
                $msg .= "  {$level['title']}：" . HTML_HIGRN . "{$occupant}" . HTML_NOR . "{$dao}\n";
            }
            $msg .= "\n";
        }
        
        return $msg;
    }

    private static function handleList(int $charId, array $params): array {
        $group = $params['group'] ?? '';
        
        $msg = "【二十八星宿榜】\n\n";
        
        foreach (self::GROUP_NAMES as $groupKey => $groupName) {
            if (!empty($group) && $group !== $groupKey) continue;
            
            $msg .= "【{$groupName}七宿】\n";
            
            foreach (self::RANK_LEVELS as $key => $level) {
                if ($level['group'] !== $groupKey) continue;
                
                $rank = Database::queryOne(
                    "SELECT * FROM xingxiu_ranks WHERE rank_level = ?",
                    [$key]
                );
                
                $occupant = $rank && $rank['status'] === 'occupied' ? $rank['char_name'] : '空缺';
                $dao = $rank && $rank['daoxing'] > 0 ? '（' . self::formatDaoxing($rank['daoxing']) . '）' : '';
                $msg .= "  {$level['title']}：{$occupant}{$dao}\n";
            }
            $msg .= "\n";
        }
        
        return ['success' => true, 'message' => $msg];
    }

    private static function handleCheck(int $charId, array $params): array {
        $char = Database::queryOne("SELECT name, daoxing FROM characters WHERE id = ?", [$charId]);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        $daoxing = intval($char['daoxing'] ?? 0);
        
        $rank = Database::queryOne(
            "SELECT * FROM xingxiu_ranks WHERE char_id = ?",
            [$charId]
        );
        
        $msg = "你的道行为：" . self::formatDaoxing($daoxing) . "\n";
        
        if ($rank) {
            $levelInfo = self::RANK_LEVELS[$rank['rank_level']];
            $msg .= "当前星君位：{$levelInfo['name']}\n";
        } else {
            $msg .= "当前星君位：未获得\n";
        }
        
        return ['success' => true, 'message' => $msg];
    }

    private static function handleChallenge(int $charId, array $params): array {
        $rankLevel = $params['rank_level'] ?? '';
        
        if (empty($rankLevel) || !isset(self::RANK_LEVELS[$rankLevel])) {
            return ['success' => false, 'message' => '请指定有效的星宿（如jiao/kang/di等）'];
        }
        
        $char = Database::queryOne("SELECT name, daoxing FROM characters WHERE id = ?", [$charId]);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        $charName = $char['name'];
        $charDaoxing = intval($char['daoxing'] ?? 0);
        
        $levelInfo = self::RANK_LEVELS[$rankLevel];
        $requiredDaoxing = $levelInfo['min_daoxing'];
        
        if ($charDaoxing < $requiredDaoxing) {
            $requiredStr = self::formatDaoxing($requiredDaoxing);
            $currentStr = self::formatDaoxing($charDaoxing);
            return ['success' => false, 'message' => "你的道行{$currentStr}不足，{$levelInfo['name']}需要{$requiredStr}以上"];
        }
        
        $target = Database::queryOne(
            "SELECT * FROM xingxiu_ranks WHERE rank_level = ?",
            [$rankLevel]
        );
        
        if ($target && $target['char_id'] == $charId) {
            return ['success' => false, 'message' => '你不能挑战自己'];
        }
        
        $targetName = $target && $target['status'] === 'occupied' ? $target['char_name'] : $levelInfo['name'];
        
        $msg = HTML_HICYN . "你向{$levelInfo['name']}【{$targetName}】发起挑战！" . HTML_NOR . "\n";
        $msg .= HTML_HIYEL . "战斗即将开始，请做好准备！" . HTML_NOR;
        
        self::broadcastChallenge($rankLevel, $charName, $targetName);
        
        return ['success' => true, 'message' => $msg, 'data' => [
            'rank_level' => $rankLevel,
            'combat_system' => 'xingxiu'
        ]];
    }

    private static function handleChallengeInquiry(string $npcName, int $charId, string $charName, int $daoxing): string {
        $msg = HTML_HICYN . "{$npcName}说道：" . HTML_NOR;
        $msg .= "以下是你可以挑战的星君：\n\n";
        
        $found = false;
        foreach (self::RANK_LEVELS as $key => $level) {
            if ($daoxing >= $level['min_daoxing']) {
                $rank = Database::queryOne(
                    "SELECT * FROM xingxiu_ranks WHERE rank_level = ?",
                    [$key]
                );
                
                $occupant = $rank && $rank['status'] === 'occupied' ? $rank['char_name'] : '（空缺）';
                $status = $rank && $rank['char_id'] == $charId ? '（现任）' : '';
                
                if ($rank && $rank['char_id'] == $charId) continue;
                
                $msg .= "  " . HTML_HIGRN . "{$level['title']}" . HTML_NOR . "：{$occupant}{$status}\n";
                $found = true;
            }
        }
        
        if (!$found) {
            $minDao = self::getMinDaoxing();
            return HTML_HIRED . "{$npcName}摇了摇头，说道：你的道行尚浅，需" . self::formatDaoxing($minDao) . "以上方能挑战星宿。" . HTML_NOR;
        }
        
        $msg .= "\n" . HTML_HIGRN . "提示：选择一个星君，使用 'challenge' 命令发起挑战。" . HTML_NOR;
        
        return $msg;
    }

    private static function broadcastChallenge(string $rankLevel, string $charName, string $targetName): void {
        $levelInfo = self::RANK_LEVELS[$rankLevel];
        $groupName = self::GROUP_NAMES[$levelInfo['group']];
        
        $msg = HTML_HICYN . "【星宿之争】" . HTML_NOR;
        $msg .= HTML_HIGRN . "{$charName}" . HTML_NOR;
        $msg .= "向{$groupName}之";
        $msg .= HTML_HIYEL . "{$levelInfo['title']}" . HTML_NOR;
        $msg .= "【{$targetName}】发起挑战！";
        
        MessageDaemon::broadcastSystem($msg);
    }

    public static function onCombatResult(int $winnerId, int $loserId, string $rankLevel, ?array $winnerInfo = null): void {
        log_game('XINGXIU_CALLBACK', "onCombatResult called: winnerId={$winnerId}, loserId={$loserId}, rankLevel={$rankLevel}");
        
        $winnerChar = Database::queryOne("SELECT name, daoxing, per, age, combat_exp, force, max_force, mana, max_mana, atman, max_atman, str, int, con, dex, cor, cps, spi, kar, gender, race, family_name FROM characters WHERE id = ?", [$winnerId]);
        
        if (!$winnerChar) {
            log_game('XINGXIU_CALLBACK', "onCombatResult failed: winner not found");
            return;
        }
        
        $winner = $winnerInfo ?: $winnerChar;
        $winner['per'] = $winnerChar['per'];
        $winner['age'] = $winnerChar['age'];
        
        $levelInfo = self::RANK_LEVELS[$rankLevel];
        
        Database::execute(
            "UPDATE xingxiu_ranks SET char_id = ?, char_name = ?, daoxing = ?, status = 'occupied', updated_at = NOW() WHERE rank_level = ?",
            [$winnerId, $winner['name'], $winner['daoxing'], $rankLevel]
        );
        
        $avatar = Database::queryOne(
            "SELECT npc_id FROM xingxiu_avatars WHERE rank_level = ?",
            [$rankLevel]
        );
        
        if ($avatar && $avatar['npc_id']) {
            Database::execute(
                "UPDATE npcs SET name = ?, title = ?, per = ?, age = ?, daoxing = ?, combat_exp = ?, `force` = ?, max_force = ?, mana = ?, max_mana = ?, atman = ?, max_atman = ?, str = ?, `int` = ?, con = ?, dex = ?, cor = ?, cps = ?, spi = ?, kar = ?, gender = ?, race = ?, family_name = ?, attitude = 'friendly', can_talk = 1 WHERE id = ?",
                [
                    $winner['name'],
                    $levelInfo['title'],
                    $winnerChar['per'],
                    $winnerChar['age'],
                    $winnerChar['daoxing'],
                    $winnerChar['combat_exp'] ?? 0,
                    $winnerChar['force'] ?? 0,
                    $winnerChar['max_force'] ?? 0,
                    $winnerChar['mana'] ?? 0,
                    $winnerChar['max_mana'] ?? 0,
                    $winnerChar['atman'] ?? 0,
                    $winnerChar['max_atman'] ?? 0,
                    $winnerChar['str'] ?? 10,
                    $winnerChar['int'] ?? 10,
                    $winnerChar['con'] ?? 10,
                    $winnerChar['dex'] ?? 10,
                    $winnerChar['cor'] ?? 20,
                    $winnerChar['cps'] ?? 20,
                    $winnerChar['spi'] ?? 10,
                    $winnerChar['kar'] ?? 20,
                    $winnerChar['gender'] ?? 'male',
                    $winnerChar['race'] ?? 'human',
                    $winnerChar['family_name'] ?? '',
                    $avatar['npc_id'],
                ]
            );
            
            Database::execute(
                "UPDATE xingxiu_avatars SET char_id = ?, char_name = ?, npc_name = ?, status = 'active' WHERE npc_id = ?",
                [$winnerId, $winner['name'], $winner['name'], $avatar['npc_id']]
            );
        }
        
        $groupName = self::GROUP_NAMES[$levelInfo['group']];
        
        $msg = HTML_HICYN . "【星宿捷报】" . HTML_NOR . "\n";
        $msg .= HTML_HIGRN . "{$winner['name']}" . HTML_NOR . "在星宿之争中击败守护星君，\n";
        $msg .= "夺得" . HTML_HIYEL . "{$levelInfo['name']}" . HTML_NOR . "！\n";
        $msg .= "恭喜新的" . HTML_HIGRN . "{$levelInfo['title']}" . HTML_NOR . "诞生了！";
        
        MessageDaemon::broadcastSystem($msg);
    }

    private static function getMinDaoxing(): int {
        $min = PHP_INT_MAX;
        foreach (self::RANK_LEVELS as $level) {
            if ($level['min_daoxing'] < $min) {
                $min = $level['min_daoxing'];
            }
        }
        return $min;
    }

    private static function formatDaoxing(int $daoxing): string {
        if ($daoxing >= 1000000) {
            return number_format($daoxing / 1000000, 1) . '年';
        } elseif ($daoxing >= 10000) {
            return number_format($daoxing / 10000, 1) . '月';
        } else {
            return $daoxing . '天';
        }
    }
}
