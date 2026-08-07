<?php
/**
 * Arena Daemon - 擂台比武守护进程
 * 
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 负责：
 * 1. 擂台比武的战斗结算（模拟多回合PK）
 * 2. 比武前后状态保存/恢复（HP/内力/法力/BUFF不真实变化）
 * 3. 积分更新（参考scoresheet.c的update_score公式）
 * 4. 排名维护
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../helpers/CombatSystemHelper.php';

class ArenaDaemon {

    /**
     * 获取针对某玩家的待处理挑战
     */
    public static function getPendingChallengeFor(int $defenderId): ?array {
        return Database::queryOne(
            "SELECT ac.*, c1.name AS challenger_name, c2.name AS defender_name
             FROM arena_challenges ac
             JOIN characters c1 ON c1.id = ac.challenger_id
             JOIN characters c2 ON c2.id = ac.defender_id
             WHERE ac.defender_id = ? AND ac.status = 'pending'
             ORDER BY ac.created_at DESC LIMIT 1",
            [$defenderId]
        );
    }

    /**
     * 获取某玩家发起的待处理挑战
     */
    public static function getPendingChallengeFrom(int $challengerId): ?array {
        return Database::queryOne(
            "SELECT ac.*, c1.name AS challenger_name, c2.name AS defender_name
             FROM arena_challenges ac
             JOIN characters c1 ON c1.id = ac.challenger_id
             JOIN characters c2 ON c2.id = ac.defender_id
             WHERE ac.challenger_id = ? AND ac.status = 'pending'
             ORDER BY ac.created_at DESC LIMIT 1",
            [$challengerId]
        );
    }

    /**
     * 检查擂台是否空闲（同一时间只有一场比武）
     */
    public static function isArenaBusy(): bool {
        $busy = Database::queryOne(
            "SELECT COUNT(*) AS cnt FROM arena_challenges WHERE status IN ('accepted', 'fighting')"
        );
        return ($busy['cnt'] ?? 0) > 0;
    }

    /**
     * 执行擂台比武
     * 
     * 模拟多回合战斗，根据双方属性计算胜负
     * 参考: leitai.c 的 start() + combat 机制
     * 
     * @param int $challengeId 挑战记录ID
     * @return array 比武结果
     */
    public static function executeCombat(int $challengeId): array {
        $challenge = Database::queryOne(
            "SELECT * FROM arena_challenges WHERE id = ? AND status = 'pending'",
            [$challengeId]
        );
        if (!$challenge) {
            return ['success' => false, 'message' => '挑战不存在或已被处理。'];
        }

        $challengerId = (int)$challenge['challenger_id'];
        $defenderId = (int)$challenge['defender_id'];

        // 获取双方数据
        $challenger = CharacterModel::find($challengerId);
        $defender = CharacterModel::find($defenderId);

        if (!$challenger || !$defender) {
            Database::execute("UPDATE arena_challenges SET status = 'cancelled' WHERE id = ?", [$challengeId]);
            return ['success' => false, 'message' => '挑战者或被挑战者不存在。'];
        }

        // === 保存双方战前状态（参考 leitai.c pre_status） ===
        $challengerState = self::saveState($challenger);
        $defenderState = self::saveState($defender);

        // === 更新挑战状态为 accepted ===
        Database::execute(
            "UPDATE arena_challenges SET status = 'accepted', started_at = NOW() WHERE id = ?",
            [$challengeId]
        );

        // === 模拟战斗（参考 leitai.c 的 300 秒限时 PK） ===
        $result = self::simulateCombat($challenger, $defender);

        // === 恢复双方状态（参考 leitai.c fullup） ===
        self::restoreState($challengerId, $challengerState);
        self::restoreState($defenderId, $defenderState);

        // === 更新挑战结果 ===
        $winnerId = $result['winner_id']; // 0 表示平局
        Database::execute(
            "UPDATE arena_challenges SET status = 'finished', winner_id = ?, 
             bonus_points = ?, rounds = ?, 
             challenger_hp_left = ?, defender_hp_left = ?,
             finished_at = NOW() 
             WHERE id = ?",
            [$winnerId ?: null, $result['bonus'], $result['rounds'],
             $result['challenger_hp'], $result['defender_hp'], $challengeId]
        );

        // === 更新积分排名（参考 scoresheet.c update_score） ===
        if ($winnerId > 0) {
            $loserId = ($winnerId === $challengerId) ? $defenderId : $challengerId;
            self::updateScore($winnerId, $loserId, $result['bonus']);
        }

        // === 更新 combat_stats ===
        self::updateCombatStats($challengerId, $defenderId, $winnerId);

        // === 移动双方到擂台 ===
        self::moveToArena($challengerId);
        self::moveToArena($defenderId);

        // === 通知双方 ===
        $combatReport = self::buildCombatReport($challenger, $defender, $result, $winnerId);
        MessageDaemon::queueMessageToSelf($challengerId, $combatReport, 'self_event');
        MessageDaemon::queueMessageToSelf($defenderId, $combatReport, 'self_event');

        return [
            'success' => true,
            'winner_id' => $winnerId,
            'winner_name' => $winnerId ? (($winnerId === $challengerId) ? $challenger['name'] : $defender['name']) : '平局',
            'challenger_name' => $challenger['name'],
            'defender_name' => $defender['name'],
            'bonus' => $result['bonus'],
            'rounds' => $result['rounds'],
            'report' => $combatReport
        ];
    }

    /**
     * 保存角色战前状态（参考 leitai.c pre_status）
     */
    private static function saveState(array $char): array {
        return [
            'kee' => (int)($char['kee'] ?? 0),
            'max_kee' => (int)($char['max_kee'] ?? 0),
            'eff_kee' => (int)($char['eff_kee'] ?? 0),
            'sen' => (int)($char['sen'] ?? 0),
            'max_sen' => (int)($char['max_sen'] ?? 0),
            'eff_sen' => (int)($char['eff_sen'] ?? 0),
            'force' => (int)($char['force'] ?? 0),
            'max_force' => (int)($char['max_force'] ?? 0),
            'mana' => (int)($char['mana'] ?? 0),
            'max_mana' => (int)($char['max_mana'] ?? 0),
            'food' => (int)($char['food'] ?? 0),
            'water' => (int)($char['water'] ?? 0),
            'current_area' => $char['current_area'] ?? '',
            'current_room' => $char['current_room'] ?? '',
        ];
    }

    /**
     * 恢复角色战后状态（参考 leitai.c fullup）
     */
    private static function restoreState(int $charId, array $state): void {
        Database::execute(
            "UPDATE characters SET 
             kee = ?, max_kee = ?, eff_kee = ?,
             sen = ?, max_sen = ?, eff_sen = ?,
             `force` = ?, max_force = ?, mana = ?, max_mana = ?,
             food = ?, water = ?
             WHERE id = ?",
            [
                $state['kee'], $state['max_kee'], $state['eff_kee'],
                $state['sen'], $state['max_sen'], $state['eff_sen'],
                $state['force'], $state['max_force'], $state['mana'], $state['max_mana'],
                $state['food'], $state['water'],
                $charId
            ]
        );
    }

    /**
     * 模拟擂台比武（多回合制）
     * 
     * 参考原始项目：限时300秒，双方互相攻击直到一方倒下
     * 简化实现：模拟最多30回合，每回合约10秒
     */
    private static function simulateCombat(array $p1, array $p2): array {
        $maxRounds = 30;

        // 计算双方战斗力
        $p1Power = self::calculateCombatPower($p1);
        $p2Power = self::calculateCombatPower($p2);

        // 双方当前HP（使用 kee 作为HP）
        $p1Hp = max(100, (int)($p1['max_kee'] ?? 100));
        $p2Hp = max(100, (int)($p2['max_kee'] ?? 100));

        $rounds = 0;
        $winnerId = 0; // 0=平局

        for ($i = 1; $i <= $maxRounds; $i++) {
            $rounds = $i;

            // P1攻击P2
            $p1Dmg = self::calculateRoundDamage($p1Power, $p2Power);
            $p2Hp -= $p1Dmg;

            if ($p2Hp <= 0) {
                $winnerId = (int)$p1['id'];
                $p2Hp = 0;
                break;
            }

            // P2攻击P1
            $p2Dmg = self::calculateRoundDamage($p2Power, $p1Power);
            $p1Hp -= $p2Dmg;

            if ($p1Hp <= 0) {
                $winnerId = (int)$p2['id'];
                $p1Hp = 0;
                break;
            }
        }

        // 计算积分奖励（参考 scoresheet.c: bonus = (loser_combat_exp + loser_daoxing) / 10000 + 1）
        $bonus = 0;
        if ($winnerId > 0) {
            $loserId = ($winnerId === (int)$p1['id']) ? (int)$p2['id'] : (int)$p1['id'];
            $loser = ($winnerId === (int)$p1['id']) ? $p2 : $p1;
            $loserCombatExp = (int)($loser['combat_exp'] ?? 0);
            $loserDaoxing = (int)($loser['daoxing'] ?? 0);
            $bonus = intdiv($loserCombatExp + $loserDaoxing, 10000) + 1;

            // 检查是否已经赢过该对手（同段对手只计一次分）
            $prevBonus = Database::queryOne(
                "SELECT bonus_points FROM arena_challenges 
                 WHERE winner_id = ? AND (challenger_id = ? OR defender_id = ?) 
                 AND status = 'finished' LIMIT 1",
                [$winnerId, $loserId, $loserId]
            );
            if ($prevBonus) {
                // 已经赢过，不再加分
                $bonus = 0;
            }
        }

        return [
            'winner_id' => $winnerId,
            'rounds' => $rounds,
            'bonus' => $bonus,
            'challenger_hp' => max(0, $p1Hp),
            'defender_hp' => max(0, $p2Hp),
            'p1_power' => $p1Power,
            'p2_power' => $p2Power,
        ];
    }

    /**
     * 计算角色战斗力
     */
    private static function calculateCombatPower(array $char): float {
        $str = (int)($char['str'] ?? 10);
        $con = (int)($char['con'] ?? 10);
        $dex = (int)($char['dex'] ?? 10);
        $int = (int)($char['int'] ?? 10);
        $wis = (int)($char['wis'] ?? 10);
        $combatExp = (int)($char['combat_exp'] ?? 0);
        $level = (int)($char['level'] ?? 1);
        $maxKee = (int)($char['max_kee'] ?? 100);
        $maxForce = (int)($char['max_force'] ?? 0);
        $maxMana = (int)($char['max_mana'] ?? 0);

        // 综合战斗力公式
        $power = ($str * 2 + $con * 1.5 + $dex * 1.5 + $int + $wis) * 0.5;
        $power += $combatExp * 0.01;
        $power += $level * 5;
        $power += $maxKee * 0.1;
        $power += $maxForce * 0.05;
        $power += $maxMana * 0.05;

        // 加入随机波动（±15%）
        $power *= (0.85 + mt_rand(0, 30) / 100);

        return max(1, $power);
    }

    /**
     * 计算单回合伤害
     */
    private static function calculateRoundDamage(float $attackerPower, float $defenderPower): int {
        $ratio = $attackerPower / max(1, $defenderPower);
        $baseDamage = max(5, (int)($attackerPower * 0.15 * $ratio));
        // 随机波动 ±30%
        $damage = (int)($baseDamage * (0.7 + mt_rand(0, 60) / 100));
        return max(1, $damage);
    }

    /**
     * 更新积分排名（参考 scoresheet.c update_winner_rank / update_loser_rank）
     */
    private static function updateScore(int $winnerId, int $loserId, int $bonus): void {
        if ($bonus <= 0) {
            return;
        }

        // 确保双方在 combat_stats 中有记录
        self::ensureCombatStats($winnerId);
        self::ensureCombatStats($loserId);

        // 胜者加分
        Database::execute(
            "UPDATE combat_stats SET rating = rating + ?, wins = wins + 1, 
             total_fights = total_fights + 1, last_fight_time = NOW() 
             WHERE char_id = ?",
            [$bonus, $winnerId]
        );

        // 败者减分（如果有分可减）
        $loserStats = Database::queryOne(
            "SELECT rating FROM combat_stats WHERE char_id = ?", [$loserId]
        );
        $loserRating = (int)($loserStats['rating'] ?? 0);
        $loss = min($loserRating, $bonus); // 不能减到负数
        if ($loss > 0) {
            Database::execute(
                "UPDATE combat_stats SET rating = GREATEST(0, rating - ?), losses = losses + 1,
                 total_fights = total_fights + 1, last_fight_time = NOW()
                 WHERE char_id = ?",
                [$loss, $loserId]
            );
        } else {
            Database::execute(
                "UPDATE combat_stats SET losses = losses + 1, 
                 total_fights = total_fights + 1, last_fight_time = NOW()
                 WHERE char_id = ?",
                [$loserId]
            );
        }
    }

    /**
     * 更新 combat_stats（确保有记录）
     */
    private static function ensureCombatStats(int $charId): void {
        $exists = Database::queryOne("SELECT char_id FROM combat_stats WHERE char_id = ?", [$charId]);
        if (!$exists) {
            Database::execute(
                "INSERT INTO combat_stats (char_id, total_fights, wins, losses, draws, rating) 
                 VALUES (?, 0, 0, 0, 0, 1000)",
                [$charId]
            );
        }
    }

    /**
     * 更新战斗统计
     */
    private static function updateCombatStats(int $challengerId, int $defenderId, int $winnerId): void {
        self::ensureCombatStats($challengerId);
        self::ensureCombatStats($defenderId);

        if ($winnerId === 0) {
            // 平局
            Database::execute(
                "UPDATE combat_stats SET draws = draws + 1, total_fights = total_fights + 1, 
                 last_fight_time = NOW() WHERE char_id IN (?, ?)",
                [$challengerId, $defenderId]
            );
        }
    }

    /**
     * 移动角色到擂台
     */
    private static function moveToArena(int $charId): void {
        Database::execute(
            "UPDATE characters SET current_area = 'city', current_room = 'city/misc/leitai' WHERE id = ?",
            [$charId]
        );
    }

    /**
     * 移动角色回观礼台
     */
    public static function moveToKantai(int $charId): void {
        Database::execute(
            "UPDATE characters SET current_area = 'city', current_room = 'city/misc/kantai' WHERE id = ?",
            [$charId]
        );
    }

    /**
     * 构建比武战报
     */
    private static function buildCombatReport(array $challenger, array $defender, array $result, int $winnerId): string {
        $challengerName = $challenger['name'] ?? '???';
        $defenderName = $defender['name'] ?? '???';

        $report = HTML_HIYEL . "========== 擂台比武 ==========" . HTML_NOR . "\n";
        $report .= HTML_HICYN . $challengerName . HTML_NOR . " VS " . HTML_HICYN . $defenderName . HTML_NOR . "\n";
        $report .= str_repeat("-", 30) . "\n";

        // 逐回合描述
        $rounds = $result['rounds'] ?? 1;
        if ($winnerId > 0) {
            $winnerName = ($winnerId === (int)$challenger['id']) ? $challengerName : $defenderName;
            $loserName = ($winnerId === (int)$challenger['id']) ? $defenderName : $challengerName;
            $report .= "经过 {$rounds} 回合激战...\n";
            $report .= HTML_HIRED . $winnerName . HTML_NOR . "把" . $loserName . "打翻在地，飞起一脚，将" . $loserName . "踢下了擂台。\n";
            $report .= HTML_HIGRN . $winnerName . " 获胜！" . HTML_NOR . "\n";
        } else {
            $report .= "大战 {$rounds} 回合，不分胜负，各自离场，改日再战！\n";
            $report .= HTML_HIYEL . "平局！" . HTML_NOR . "\n";
        }

        // 积分变化
        $bonus = $result['bonus'] ?? 0;
        if ($bonus > 0 && $winnerId > 0) {
            $winnerName = ($winnerId === (int)$challenger['id']) ? $challengerName : $defenderName;
            $report .= $winnerName . "的等级分增加了 {$bonus} 点！\n";
        }

        // 剩余HP
        $report .= sprintf("\n%s 剩余气血: %d\n", $challengerName, $result['challenger_hp'] ?? 0);
        $report .= sprintf("%s 剩余气血: %d\n", $defenderName, $result['defender_hp'] ?? 0);
        $report .= HTML_HIYEL . "==============================" . HTML_NOR;

        return $report;
    }
}
