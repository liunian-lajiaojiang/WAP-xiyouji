<?php
require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/MessageDaemon.php';
require_once __DIR__ . '/../config/game.php';

class PantaohuiHandler extends ActionHandler {

    private const RANK_LEVELS = [
        'sun'   => ['name' => '日曜神位', 'title' => '日神', 'min_daoxing' => 50000000, 'desc' => '执掌太阳，光芒万丈'],
        'moon'  => ['name' => '月曜神位', 'title' => '月神', 'min_daoxing' => 45000000, 'desc' => '执掌太阴，清辉普照'],
        'metal' => ['name' => '金曜神位', 'title' => '金神', 'min_daoxing' => 40000000, 'desc' => '执掌金星，锐利无双'],
        'wood'  => ['name' => '木曜神位', 'title' => '木神', 'min_daoxing' => 35000000, 'desc' => '执掌木星，生机盎然'],
        'water' => ['name' => '水曜神位', 'title' => '水神', 'min_daoxing' => 30000000, 'desc' => '执掌水星，灵动万变'],
        'fire'  => ['name' => '火曜神位', 'title' => '火神', 'min_daoxing' => 25000000, 'desc' => '执掌火星，烈焰焚天'],
        'earth' => ['name' => '土曜神位', 'title' => '土神', 'min_daoxing' => 20000000, 'desc' => '执掌土星，厚德载物'],
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
            case 'accept':
                return self::handleAccept($charId, $params);
            default:
                return ['success' => false, 'message' => '未知命令'];
        }
    }

    public static function handleInquiry(array $npc, array $char, string $topic, $extraParam = null): mixed {
        $npcName = $npc['name'] ?? '太白金星';
        $charId = intval($char['id'] ?? 0);
        $charName = $char['name'] ?? '你';
        $daoxing = intval($char['daoxing'] ?? 0);

        $topic = strtolower($topic);
        
        if ($topic === '蟠桃' || $topic === '蟠桃会' || $topic === '蟠桃宴') {
            return self::handleAskPantaohui($npcName, $charName, $daoxing);
        }
        
        if ($topic === '申请' || $topic === '御批') {
            return self::handleApplyInquiry($npcName, $charId, $charName, $daoxing);
        }
        
        if ($topic === '封神榜') {
            return self::handleRankList($npcName);
        }
        
        if ($topic === '挑战') {
            return self::handleChallengeInquiry($npcName, $charId, $charName, $daoxing);
        }
        
        if ($topic === 'accept tai bai' || $topic === 'accept') {
            return self::handleAcceptInquiry($npcName, $charId);
        }
        
        return null;
    }

    private static function handleAskPantaohui(string $npcName, string $charName, int $daoxing): string {
        $msg = HTML_HICYN . "{$npcName}微微一笑，说道：" . HTML_NOR;
        $msg .= "蟠桃会乃是天庭盛事，七曜神位，各归其主！";
        return $msg;
    }

    private static function handleApplyInquiry(string $npcName, int $charId, string $charName, int $daoxing): string {
        if ($daoxing < self::RANK_LEVELS['earth']['min_daoxing']) {
            $req = self::formatDaoxing(self::RANK_LEVELS['earth']['min_daoxing']);
            return HTML_HIRED . "{$npcName}摇了摇头，说道：你的道行尚浅，需{$req}以上方能申请神位。" . HTML_NOR;
        }

        $existing = Database::queryOne(
            "SELECT * FROM pantaohui_applications WHERE char_id = ? ORDER BY apply_time DESC LIMIT 1",
            [$charId]
        );
        
        if ($existing) {
            if ($existing['status'] === 'approved') {
                $levelInfo = self::RANK_LEVELS[$existing['rank_level']];
                $avatar = Database::queryOne(
                    "SELECT npc_name FROM pantaohui_avatars WHERE rank_level = ?",
                    [$existing['rank_level']]
                );
                
                $msg = HTML_HICYN . "{$npcName}面露喜色，说道：" . HTML_NOR;
                $msg .= "恭喜{$charName}！玉皇大帝御批已下！\n";
                $msg .= HTML_HIGRN . "{$levelInfo['name']}申请批准！\n" . HTML_NOR;
                $msg .= "接下来，你需要挑战" . HTML_HIYEL . "{$avatar['npc_name']}" . HTML_NOR . "，\n";
                $msg .= "战胜他方能获得{$levelInfo['title']}的封号！\n\n";
                $msg .= HTML_HIGRN . "提示：对我说「挑战」即可发起挑战！" . HTML_NOR;
                return $msg;
            }
            
            if ($existing['status'] === 'completed') {
                $levelInfo = self::RANK_LEVELS[$existing['rank_level']];
                return HTML_HIGRN . "{$npcName}说道：恭喜{$charName}！你已获得{$levelInfo['title']}封号！随我来吧！" . HTML_NOR;
            }
            
            if ($existing['status'] === 'rejected') {
                return HTML_HIRED . "{$npcName}叹了口气，说道：你的申请已被玉皇大帝驳回，请再接再厉。" . HTML_NOR;
            }
            $applyTime = strtotime($existing['apply_time']);
            $now = time();
            $waitSeconds = $now - $applyTime;
            $approvalMinutes = 5;
            $approvalSeconds = $approvalMinutes * 60;
            
            if ($waitSeconds >= $approvalSeconds) {
                Database::execute(
                    "UPDATE pantaohui_applications SET status = 'approved', approve_time = NOW() WHERE id = ?",
                    [$existing['id']]
                );
                
                $levelInfo = self::RANK_LEVELS[$existing['rank_level']];
                $avatar = Database::queryOne(
                    "SELECT npc_name FROM pantaohui_avatars WHERE rank_level = ?",
                    [$existing['rank_level']]
                );
                
                $msg = HTML_HICYN . "{$npcName}面露喜色，说道：" . HTML_NOR;
                $msg .= "恭喜{$charName}！玉皇大帝御批已下！\n";
                $msg .= HTML_HIGRN . "{$levelInfo['name']}申请批准！\n" . HTML_NOR;
                $msg .= "接下来，你需要挑战" . HTML_HIYEL . "{$avatar['npc_name']}" . HTML_NOR . "，\n";
                $msg .= "战胜他方能获得{$levelInfo['title']}的封号！\n\n";
                $msg .= HTML_HIGRN . "提示：对我说「挑战」即可发起挑战！" . HTML_NOR;
                return $msg;
            }
            
            $remainingMinutes = ceil(($approvalSeconds - $waitSeconds) / 60);
            $remainingSeconds = ($approvalSeconds - $waitSeconds) % 60;
            
            if ($remainingMinutes > 0) {
                return HTML_HIYEL . "{$npcName}说道：你的申请正在审批中，请耐心等候玉皇大帝御批。（还需{$remainingMinutes}分钟{$remainingSeconds}秒）" . HTML_NOR;
            } else {
                return HTML_HIYEL . "{$npcName}说道：你的申请正在审批中，请耐心等候玉皇大帝御批。（即将批准）" . HTML_NOR;
            }
        }

        $rankLevel = self::getHighestRank($daoxing);
        $levelInfo = self::RANK_LEVELS[$rankLevel];
        
        Database::execute(
            "INSERT INTO pantaohui_applications (char_id, char_name, rank_level, status) VALUES (?, ?, ?, 'pending')",
            [$charId, $charName, $rankLevel]
        );

        $msg = HTML_HICYN . "{$npcName}郑重道：" . HTML_NOR;
        $msg .= "好！{$charName}申请{$levelInfo['name']}，勇气可嘉！\n";
        $msg .= "我这就前往凌霄宝殿，向玉皇大帝请示御批。\n";
        $msg .= HTML_HIYEL . "请稍候，御批下来我自会通知你。" . HTML_NOR;

        return $msg;
    }

    public static function handleChallengeInquiry(string $npcName, int $charId, string $charName, int $daoxing, ?string $rankLevel = null): mixed {
        if ($rankLevel && !isset(self::RANK_LEVELS[$rankLevel])) {
            return HTML_HIRED . "{$npcName}皱眉道：无效的神位。" . HTML_NOR;
        }

        $roomNames = [
            'sun' => '天乾殿',
            'moon' => '地坤殿',
            'metal' => '云象殿',
            'wood' => '旦寰殿',
            'water' => '夕寅殿',
            'fire' => '星冕殿',
            'earth' => '辰亘殿',
        ];

        if (!$rankLevel) {
            $application = Database::queryOne(
                "SELECT * FROM pantaohui_applications WHERE char_id = ? AND status = 'approved' LIMIT 1",
                [$charId]
            );
            
            if (!$application) {
                return HTML_HIYEL . "{$npcName}摇了摇头，说道：你尚未获得御批，或是申请已被驳回。" . HTML_NOR;
            }
            $rankLevel = $application['rank_level'];
            $levelInfo = self::RANK_LEVELS[$rankLevel];
            $roomName = $roomNames[$rankLevel] ?? '大殿';
            
            return HTML_HICYN . "{$npcName}一指前方，说道：" . HTML_NOR . "\n\n" .
                   "你已获得{$levelInfo['name']}御批！\n" .
                   "封神演礼在" . HTML_HIGRN . "{$roomName}" . HTML_NOR . "举行，\n" .
                   "请前往" . HTML_HIYEL . "{$roomName}" . HTML_NOR . "挑战" . HTML_HIRED . "{$levelInfo['title']}" . HTML_NOR . "分身！";
        }

        $application = Database::queryOne(
            "SELECT * FROM pantaohui_applications WHERE char_id = ? AND rank_level = ? AND status = 'approved' LIMIT 1",
            [$charId, $rankLevel]
        );
        
        if (!$application) {
            $levelInfo = self::RANK_LEVELS[$rankLevel];
            return HTML_HIYEL . "{$npcName}摇了摇头，说道：你尚未获得{$levelInfo['name']}的御批。" . HTML_NOR;
        }

        $levelInfo = self::RANK_LEVELS[$rankLevel];
        $avatar = Database::queryOne(
            "SELECT * FROM pantaohui_avatars WHERE rank_level = ?",
            [$rankLevel]
        );
        
        if (!$avatar) {
            return HTML_HIRED . "{$npcName}皱眉道：神位替身信息缺失，请稍后再试。" . HTML_NOR;
        }

        $existingRank = Database::queryOne(
            "SELECT * FROM pantaohui_ranks WHERE char_id = ?",
            [$charId]
        );
        
        if ($existingRank && $existingRank['rank_level'] === $rankLevel) {
            return HTML_HIYEL . "{$npcName}说道：你已经是{$levelInfo['title']}了，无需再挑战。" . HTML_NOR;
        }

        $msg = HTML_HICYN . "{$npcName}高声道：" . HTML_NOR;
        $msg .= "封神演礼开始！\n";
        $msg .= HTML_HIYEL . "{$charName}" . HTML_NOR . "挑战" . HTML_HIRED . "{$avatar['npc_name']}" . HTML_NOR . "！\n";
        $msg .= "胜者将获得" . HTML_HIGRN . "{$levelInfo['name']}" . HTML_NOR . "！\n\n";
        $msg .= "战斗即将开始，请做好准备！";

        require_once DAEMON_PATH . 'CombatDaemon.php';
        $combatResult = CombatDaemon::startFight($charId, $avatar['npc_id'], 'npc', 'pantaohui', $rankLevel);
        
        if (!$combatResult['success']) {
            return $combatResult['message'];
        }

        return [
            'success' => true,
            'type' => 'combat_start',
            'output' => $msg,
            'target_id' => $avatar['npc_id'],
            'target_type' => 'npc',
            'target_name' => $avatar['npc_name'],
            'rank_level' => $rankLevel,
            'system' => 'pantaohui',
        ];
    }

    private static function handleRankList(string $npcName): string {
        $msg = HTML_HICYN . "{$npcName}展开封神榜，高声念道：" . HTML_NOR . "\n\n";
        
        foreach (self::RANK_LEVELS as $key => $level) {
            $rank = Database::queryOne(
                "SELECT * FROM pantaohui_ranks WHERE rank_level = ?",
                [$key]
            );
            
            $avatar = Database::queryOne(
                "SELECT npc_name FROM pantaohui_avatars WHERE rank_level = ?",
                [$key]
            );
            
            $name = $rank['char_name'] ?? '空缺';
            $status = $rank['status'] ?? 'occupied';
            $statusText = $status === 'occupied' ? '在位' : ($status === 'challenging' ? '挑战中' : '空缺');
            
            $msg .= HTML_HIYEL . "【{$level['name']}】" . HTML_NOR . "\n";
            $msg .= "  神位：" . HTML_HIGRN . "{$level['title']}" . HTML_NOR . "\n";
            $msg .= "  执掌：{$level['desc']}\n";
            $msg .= "  现任：{$name}（{$statusText}）\n";
            $msg .= "  守护替身：" . ($avatar['npc_name'] ?? '未知') . "\n";
            
            if ($rank['daoxing'] > 0) {
                $msg .= "  道行：" . self::formatDaoxing($rank['daoxing']) . "\n";
            }
            $msg .= "\n";
        }
        
        return $msg;
    }

    private static function handleAcceptInquiry(string $npcName, int $charId): string {
        $rank = Database::queryOne(
            "SELECT * FROM pantaohui_ranks WHERE char_id = ?",
            [$charId]
        );
        
        if (!$rank) {
            return HTML_HIYEL . "{$npcName}摇了摇头，说道：你尚未封神，不能前往天庭。" . HTML_NOR;
        }

        $levelInfo = self::RANK_LEVELS[$rank['rank_level']];
        
        $msg = HTML_HICYN . "{$npcName}大笑道：" . HTML_NOR;
        $msg .= "好！{$rank['char_name']}，你已获得{$levelInfo['title']}封号！\n";
        $msg .= "随我来，我送你上九天云霄，共赴瑶池盛宴！";

        return $msg;
    }

    private static function handleApply(int $charId, array $params): array {
        $char = Database::queryOne("SELECT name, daoxing FROM characters WHERE id = ?", [$charId]);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        $daoxing = intval($char['daoxing'] ?? 0);
        $rankLevel = $params['rank_level'] ?? '';
        
        if (empty($rankLevel) || !isset(self::RANK_LEVELS[$rankLevel])) {
            return ['success' => false, 'message' => '请指定有效的神位（sun/moon/metal/wood/water/fire/earth）'];
        }
        
        $levelInfo = self::RANK_LEVELS[$rankLevel];
        if ($daoxing < $levelInfo['min_daoxing']) {
            $req = self::formatDaoxing($levelInfo['min_daoxing']);
            $cur = self::formatDaoxing($daoxing);
            return ['success' => false, 'message' => "你的道行{$cur}不足，{$levelInfo['name']}需要{$req}以上"];
        }
        
        Database::execute(
            "INSERT INTO pantaohui_applications (char_id, char_name, rank_level, status) VALUES (?, ?, ?, 'pending')",
            [$charId, $char['name'], $rankLevel]
        );
        
        return ['success' => true, 'message' => "申请{$levelInfo['name']}已提交，等待玉皇大帝御批"];
    }

    private static function handleChallenge(int $charId, array $params): array {
        $rankLevel = $params['rank_level'] ?? '';
        
        if (empty($rankLevel) || !isset(self::RANK_LEVELS[$rankLevel])) {
            return ['success' => false, 'message' => '请指定有效的神位'];
        }
        
        $char = Database::queryOne("SELECT name, daoxing FROM characters WHERE id = ?", [$charId]);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        $daoxing = intval($char['daoxing'] ?? 0);
        $levelInfo = self::RANK_LEVELS[$rankLevel];
        
        if ($daoxing < $levelInfo['min_daoxing']) {
            $req = self::formatDaoxing($levelInfo['min_daoxing']);
            $cur = self::formatDaoxing($daoxing);
            return ['success' => false, 'message' => "你的道行{$cur}不足，{$levelInfo['name']}需要{$req}以上"];
        }
        
        $application = Database::queryOne(
            "SELECT * FROM pantaohui_applications WHERE char_id = ? AND rank_level = ? AND status = 'approved' LIMIT 1",
            [$charId, $rankLevel]
        );
        
        if (!$application) {
            return ['success' => false, 'message' => '你尚未获得该神位的御批'];
        }
        
        $avatar = Database::queryOne(
            "SELECT * FROM pantaohui_avatars WHERE rank_level = ?",
            [$rankLevel]
        );
        
        if (!$avatar) {
            return ['success' => false, 'message' => '神位替身信息缺失'];
        }
        
        $rank = Database::queryOne(
            "SELECT * FROM pantaohui_ranks WHERE rank_level = ?",
            [$rankLevel]
        );
        
        if ($rank && $rank['char_id'] == $charId) {
            return ['success' => false, 'message' => '你已是该神位的执掌者'];
        }
        
        Database::execute(
            "UPDATE pantaohui_ranks SET status = 'challenging' WHERE rank_level = ?",
            [$rankLevel]
        );
        
        $msg = HTML_HICYN . "你向{$levelInfo['name']}守护替身【{$avatar['npc_name']}】发起挑战！" . HTML_NOR . "\n";
        $msg .= HTML_HIYEL . "封神演礼即将开始，请做好准备！" . HTML_NOR;
        
        return ['success' => true, 'message' => $msg, 'data' => [
            'rank_level' => $rankLevel,
            'avatar_name' => $avatar['npc_name'],
        ]];
    }

    private static function handleList(int $charId, array $params): array {
        $ranks = Database::queryAll("SELECT * FROM pantaohui_ranks ORDER BY FIELD(rank_level, 'sun','moon','metal','wood','water','fire','earth')");
        
        $msg = "【蟠桃会封神榜】\n\n";
        foreach ($ranks as $rank) {
            $levelInfo = self::RANK_LEVELS[$rank['rank_level']];
            $status = $rank['status'] === 'occupied' ? '在位' : ($rank['status'] === 'challenging' ? '挑战中' : '空缺');
            $msg .= "{$levelInfo['name']} - {$levelInfo['title']}：{$rank['char_name']}（{$status}）\n";
        }
        
        return ['success' => true, 'message' => $msg];
    }

    private static function handleCheck(int $charId, array $params): array {
        $char = Database::queryOne("SELECT name, daoxing FROM characters WHERE id = ?", [$charId]);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        $daoxing = intval($char['daoxing'] ?? 0);
        
        $application = Database::queryOne(
            "SELECT * FROM pantaohui_applications WHERE char_id = ? ORDER BY apply_time DESC LIMIT 1",
            [$charId]
        );
        
        $rank = Database::queryOne(
            "SELECT * FROM pantaohui_ranks WHERE char_id = ?",
            [$charId]
        );
        
        $msg = "你的道行为：" . self::formatDaoxing($daoxing) . "\n";
        
        if ($application) {
            $levelInfo = self::RANK_LEVELS[$application['rank_level']];
            $statusText = self::getStatusText($application['status']);
            $msg .= "当前申请：{$levelInfo['name']}（{$statusText}）\n";
            
            if ($application['status'] === 'approved') {
                $avatar = Database::queryOne(
                    "SELECT npc_name FROM pantaohui_avatars WHERE rank_level = ?",
                    [$application['rank_level']]
                );
                $msg .= "待挑战替身：{$avatar['npc_name']}\n";
            }
        }
        
        if ($rank) {
            $levelInfo = self::RANK_LEVELS[$rank['rank_level']];
            $msg .= "现任神位：{$levelInfo['name']} - {$levelInfo['title']}\n";
        } else {
            $msg .= "现任神位：无\n";
        }
        
        return ['success' => true, 'message' => $msg];
    }

    private static function handleAccept(int $charId, array $params): array {
        $rank = Database::queryOne(
            "SELECT * FROM pantaohui_ranks WHERE char_id = ?",
            [$charId]
        );
        
        if (!$rank) {
            return ['success' => false, 'message' => '你尚未封神，不能前往天庭'];
        }
        
        $levelInfo = self::RANK_LEVELS[$rank['rank_level']];
        
        return ['success' => true, 'message' => "恭喜！你已获得{$levelInfo['title']}封号，可跟随太白金星上天参加蟠桃会"];
    }

    public static function onCombatResult(int $winnerId, int $loserId, string $rankLevel, ?array $winnerInfo = null): void {
        log_game('PANTAOHUI_CALLBACK', "onCombatResult called: winnerId={$winnerId}, loserId={$loserId}, rankLevel={$rankLevel}");
        
        $winnerChar = Database::queryOne("SELECT name, daoxing, per, age, combat_exp, force, max_force, mana, max_mana, atman, max_atman, str, int, con, dex, cor, cps, spi, kar, gender, race, family_name FROM characters WHERE id = ?", [$winnerId]);
        
        if (!$winnerChar) {
            log_game('PANTAOHUI_CALLBACK', "onCombatResult failed: winner not found");
            return;
        }
        
        $winner = $winnerInfo ?: $winnerChar;
        $winner['per'] = $winnerChar['per'];
        $winner['age'] = $winnerChar['age'];
        
        $loser = Database::queryOne("SELECT name FROM characters WHERE id = ?", [$loserId]);
        
        $equipments = Database::queryAll(
            "SELECT ci.equip_slot, i.name, i.type FROM character_inventory ci " .
            "LEFT JOIN items i ON ci.item_id = i.item_id " .
            "WHERE ci.char_id = ? AND ci.equipped = 1",
            [$winnerId]
        );
        
        $levelInfo = self::RANK_LEVELS[$rankLevel];
        
        $existingRank = Database::queryOne(
            "SELECT * FROM pantaohui_ranks WHERE rank_level = ?",
            [$rankLevel]
        );
        
        if ($existingRank) {
            Database::execute(
                "UPDATE pantaohui_ranks SET char_id = ?, char_name = ?, daoxing = ?, status = 'occupied', updated_at = NOW() WHERE rank_level = ?",
                [$winnerId, $winner['name'], $winner['daoxing'], $rankLevel]
            );
        } else {
            Database::execute(
                "INSERT INTO pantaohui_ranks (rank_level, char_id, char_name, daoxing, status) VALUES (?, ?, ?, ?, 'occupied')",
                [$rankLevel, $winnerId, $winner['name'], $winner['daoxing']]
            );
        }
        
        Database::execute(
            "UPDATE pantaohui_avatars SET char_id = ?, char_name = ?, status = 'active' WHERE rank_level = ?",
            [$winnerId, $winner['name'], $rankLevel]
        );
        
        $avatar = Database::queryOne(
            "SELECT npc_id FROM pantaohui_avatars WHERE rank_level = ?",
            [$rankLevel]
        );
        
        if ($avatar && $avatar['npc_id']) {
            $newNpcTitle = $levelInfo['title'];
            $newNpcAlias = "pantaohui_avatar_{$rankLevel}";
            
            Database::execute(
                "UPDATE npcs SET name = ?, title = ?, alias = ?, per = ?, age = ?, daoxing = ?, combat_exp = ?, force = ?, max_force = ?, mana = ?, max_mana = ?, atman = ?, max_atman = ?, str = ?, int = ?, con = ?, dex = ?, cor = ?, cps = ?, spi = ?, kar = ?, gender = ?, race = ?, family_name = ?, attitude = 'friendly', can_talk = 1 WHERE id = ?",
                [
                    $winner['name'],
                    $newNpcTitle,
                    $newNpcAlias,
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
                "UPDATE pantaohui_avatars SET char_id = ?, char_name = ?, npc_name = ?, status = 'active' WHERE npc_id = ?",
                [$winnerId, $winner['name'], $winner['name'], $avatar['npc_id']]
            );
        }
        
        Database::execute(
            "UPDATE pantaohui_applications SET status = 'completed' WHERE char_id = ? AND rank_level = ?",
            [$winnerId, $rankLevel]
        );
        
        $msg = HTML_HICYN . "【封神捷报】" . HTML_NOR . "\n";
        $msg .= HTML_HIGRN . "{$winner['name']}" . HTML_NOR . "在封神演礼中击败守护替身，\n";
        $msg .= "夺得" . HTML_HIYEL . "{$levelInfo['name']}" . HTML_NOR . "！\n";
        $msg .= "恭喜新的" . HTML_HIGRN . "{$levelInfo['title']}" . HTML_NOR . "诞生了！";
        
        MessageDaemon::broadcastToAll($msg);
        
        $followMsg = "\n" . HTML_HICYN . "系统提示：" . HTML_NOR;
        $followMsg .= "封神成功！你已获得" . HTML_HIGRN . "{$levelInfo['title']}" . HTML_NOR . "封号。\n";
        $followMsg .= "太白金星微微一笑，说道：恭喜{$winner['name']}！你已获得{$levelInfo['title']}封号！随我来吧！\n";
        $followMsg .= "霎时间，一道金光笼罩你的全身，腾云驾雾，直冲九霄！";
        
        MessageDaemon::queueMessageToSelf($winnerId, $followMsg, 'self_event');
        
        $expReward = 50000;
        $potReward = 10000;
        $daoxingReward = 5000;
        
        Database::execute(
            "UPDATE characters SET combat_exp = combat_exp + ?, potential = potential + ?, daoxing = daoxing + ? WHERE id = ?",
            [$expReward, $potReward, $daoxingReward, $winnerId]
        );
        
        $rewardMsg = "\n" . HTML_HIGRN . "【蟠桃会奖励】" . HTML_NOR . "\n";
        $rewardMsg .= "获得" . HTML_HIYEL . "经验 +{$expReward}" . HTML_NOR . "\n";
        $rewardMsg .= "获得" . HTML_HIYEL . "潜能 +{$potReward}" . HTML_NOR . "\n";
        $rewardMsg .= "获得" . HTML_HIYEL . "道行 +{$daoxingReward}" . HTML_NOR;
        
        MessageDaemon::queueMessageToSelf($winnerId, $rewardMsg, 'self_event');
        
        $winnerChar = Database::queryOne("SELECT * FROM characters WHERE id = ?", [$winnerId]);
        if ($winnerChar) {
            $followResult = self::handleFollowTaibai($winnerId, $winnerChar);
            if ($followResult['success']) {
                MessageDaemon::queueMessageToSelf($winnerId, $followResult['message'], 'self_event');
            }
        }
    }

    public static function handleFollowTaibai(int $charId, array $char): array {
        $charName = $char['name'] ?? '玩家';
        $currentRoom = $char['current_room'] ?? '';

        $rank = Database::queryOne(
            "SELECT * FROM pantaohui_ranks WHERE char_id = ?",
            [$charId]
        );
        
        if (!$rank) {
            return ['success' => false, 'message' => HTML_HIYEL . '太白金星摇了摇头，说道：你尚未封神，不能前往天庭。请先获得神位封号。' . HTML_NOR];
        }

        $targetRoomId = 'pantao/fengb';
        $targetArea = 'pantao';

        Database::execute(
            "UPDATE characters SET current_area = ?, current_room = ? WHERE id = ?",
            [$targetArea, $targetRoomId, $charId]
        );
        
        if (isset($_SESSION['user_char'])) {
            $_SESSION['user_char']['current_room'] = $targetRoomId;
            $_SESSION['user_char']['current_area'] = $targetArea;
        }
        $_SESSION["char_{$charId}_room"] = $targetRoomId;
        $_SESSION["char_{$charId}_area"] = $targetArea;

        $levelInfo = self::RANK_LEVELS[$rank['rank_level']];

        $msg = HTML_HICYN . "太白金星微微一笑，念动咒语，一道金光笼罩你的全身！" . HTML_NOR . "\n";
        $msg .= "霎时间，你感觉身体变得轻飘飘的，腾云驾雾，直冲九霄！\n";
        $msg .= "穿过南天门，越过灵霄宝殿，转眼间来到了" . HTML_HIYEL . "瑶池蟠桃园" . HTML_NOR . "！\n\n";
        $msg .= HTML_HIGRN . "【封神台】" . HTML_NOR . "\n";
        $msg .= "封神灵台巍峨雄伟，金璧辉煌。\n";
        $msg .= "只见琉璃碧沉沉，宝玉明幌幌，天神、天圣、天尊、天王等两边伫立。\n";
        $msg .= "龙旗鸾辂祥光蔼，宝节幢幡瑞气飘。\n";
        $msg .= "恭喜" . HTML_HIGRN . "{$charName}" . HTML_NOR . "获得" . HTML_HIYEL . "{$levelInfo['title']}" . HTML_NOR . "封号，共赴瑶池盛宴！";

        MessageDaemon::broadcastToRoom(
            $currentRoom,
            HTML_HIYEL . "{$charName}跟着太白金星驾云而去，消失在天际……" . HTML_NOR,
            $charId, 'room'
        );

        return ['success' => true, 'message' => $msg];
    }

    public static function approveApplication(int $applicationId): bool {
        $application = Database::queryOne(
            "SELECT * FROM pantaohui_applications WHERE id = ? AND status = 'pending'",
            [$applicationId]
        );
        
        if (!$application) return false;
        
        Database::execute(
            "UPDATE pantaohui_applications SET status = 'approved', approve_time = NOW() WHERE id = ?",
            [$applicationId]
        );
        
        return true;
    }

    private static function getHighestRank(int $daoxing): string {
        foreach (self::RANK_LEVELS as $key => $level) {
            if ($daoxing >= $level['min_daoxing']) {
                return $key;
            }
        }
        return 'earth';
    }

    private static function getStatusText(string $status): string {
        $map = [
            'pending' => '审批中',
            'approved' => '已批准（待挑战）',
            'rejected' => '已驳回',
            'completed' => '已封神',
        ];
        return $map[$status] ?? $status;
    }

    private static function formatDaoxing(int $daoxing): string {
        if ($daoxing >= 100000) {
            return number_format($daoxing / 100000, 1) . '年';
        }
        return number_format($daoxing) . '天';
    }
    
    private static function getPerText(int $per): string {
        if ($per < 10) {
            return '奇丑无比';
        } elseif ($per < 15) {
            return '相貌丑陋';
        } elseif ($per < 20) {
            return '相貌普通';
        } elseif ($per < 25) {
            return '相貌清秀';
        } elseif ($per < 30) {
            return '相貌俊美';
        } elseif ($per < 35) {
            return '容貌出众';
        } elseif ($per < 40) {
            return '绝世容颜';
        } else {
            return '天人之姿';
        }
    }
}
