<?php
/**
 * 战斗系统辅助类 - 完整实现原始项目的战斗逻辑
 * 
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */

require_once __DIR__ . '/SkillManager.php';

class CombatSystemHelper
{
    /** @var array|null 战斗系统配置缓存 */
    private static ?array $sysConfig = null;

    /**
     * 加载战斗系统配置
     */
    private static function loadConfig(): array {
        if (self::$sysConfig !== null) {
            return self::$sysConfig;
        }
        self::$sysConfig = require __DIR__ . '/../config/combat_system.php';
        return self::$sysConfig;
    }

    // 技能使用类型常量
    const SKILL_USAGE_ATTACK = 1;
    const SKILL_USAGE_DEFENSE = 2;
    const SKILL_USAGE_SPELL = 3;
    const SKILL_USAGE_DODGE = 4;
    const SKILL_USAGE_PARRY = 5;

    // 攻击类型
    const TYPE_REGULAR = 0;
    const TYPE_RIPOSTE = 1;
    const TYPE_QUICK = 2;

    // 闪避/招架结果
    const RESULT_DODGE = -1;
    const RESULT_PARRY = -2;

    /**
     * 计算技能威力 (skill_power)
     * 公式：(level^3)/3 + combat_exp，考虑精神状态
     * 
     * @param array $character 角色数据
     * @param string $skill 技能名称
     * @param int $usage 技能使用类型
     * @return int 技能威力
     */
    public static function skillPower(array $character, string $skill, int $usage = self::SKILL_USAGE_ATTACK): int
    {
        if (!isset($character['id'])) {
            return 0;
        }

        $charId = $character['id'];
        
        // 获取技能等级
        $level = SkillManager::querySkill($charId, $skill);
        if ($level < 1) {
            $level = 1;
        }

        // 职业加成（从配置读取参数）
        $sp = self::loadConfig()['skill_power'];
        $guild = $character['guild'] ?? '';
        $calcBonus = function(int $lvl) use ($sp): int {
            if ($lvl > $sp['tier3_threshold']) {
                return $sp['tier3_base_bonus'] + (int)(($lvl - $sp['tier3_threshold']) * $sp['tier3_ratio']);
            } elseif ($lvl > $sp['tier2_threshold']) {
                return $sp['tier2_base_bonus'] + (int)(($lvl - $sp['tier2_threshold']) * $sp['tier2_ratio']);
            } elseif ($lvl > $sp['tier1_threshold']) {
                return $sp['tier1_base_bonus'] + (int)(($lvl - $sp['tier1_threshold']) * $sp['tier1_ratio']);
            }
            return (int)($lvl * $sp['base_ratio']);
        };

        if ($guild === 'fighter') {
            if ($usage === self::SKILL_USAGE_ATTACK || $usage === self::SKILL_USAGE_DEFENSE) {
                $level += $calcBonus($level);
            } elseif ($usage === self::SKILL_USAGE_SPELL) {
                $level -= (int)($level / $sp['warrior_spell_div']);
            }
        } elseif ($guild === 'magician') {
            if ($usage === self::SKILL_USAGE_ATTACK || $usage === self::SKILL_USAGE_DEFENSE) {
                $level -= (int)($level / $sp['mage_penalty_div']);
            } elseif ($usage === self::SKILL_USAGE_SPELL) {
                $level += $calcBonus($level);
            }
        }

        // 应用临时加成
        $tempApply = 0;
        if ($usage === self::SKILL_USAGE_ATTACK) {
            $tempApply = $character['temp_attack'] ?? 0;
        } elseif ($usage === self::SKILL_USAGE_DEFENSE) {
            $tempApply = $character['temp_defense'] ?? 0;
        }
        $level += $tempApply;

        // 计算基础威力（从配置读取除数）
        $power = (int)(($level * $level * $level) / $sp['level_cube_div']);

        // 考虑精神状态（sen）
        $maxSen = $character['max_sen'] ?? $sp['default_max_sen'];
        $sen = $character['sen'] ?? $maxSen;
        if ($maxSen > 0) {
            if ($power > $sp['precision_threshold']) {
                $power = (int)($power / $maxSen * $sen);
            } else {
                $power = (int)($power * $sen / $maxSen);
            }
        }

        // 加上战斗经验
        $combatExp = $character['combat_exp'] ?? 0;
        $power += $combatExp;

        return max(1, $power);
    }

    /**
     * 计算伤害
     * 基于原始项目的 do_attack 逻辑
     * 
     * @param array $attacker 攻击者数据
     * @param array $defender 防御者数据
     * @param array|null $weapon 武器数据
     * @param array|null $action 动作数据
     * @return int 伤害值
     */
    public static function calculateDamage(
        array $attacker, 
        array $defender, 
        ?array $weapon = null, 
        ?array $action = null
    ): int {
        // 基础伤害
        $dc = self::loadConfig()['damage_calc'];
        $damage = $attacker['temp_damage'] ?? 0;
        
        // 随机波动
        if ($damage > 0) {
            $damage = (int)(($damage + mt_rand(0, $damage)) / $dc['random_fluctuation_div']);
        }

        // 动作加成
        if ($action && isset($action['damage'])) {
            $damage += (int)($action['damage'] * $damage / $dc['action_damage_div']);
        }

        // 力量加成
        $damageBonus = $attacker['str'] ?? 0;

        // 内功加成
        $forceFactor = $attacker['force_factor'] ?? 0;
        $force = $attacker['force'] ?? 0;
        if ($forceFactor > 0 && $force > $forceFactor) {
            $mappedForceSkill = SkillManager::querySkillMapped($attacker['id'] ?? 0, 'force');
            if ($mappedForceSkill) {
                $forceBonus = (int)($forceFactor / $dc['force_bonus_div']);
                $damageBonus += $forceBonus;
            }
        }

        // 动作中的内力加成
        if ($action && isset($action['force'])) {
            $damageBonus += (int)($action['force'] * $damageBonus / $dc['force_percent_div']);
        }

        // 武器技能加成
        $weaponSkill = 'unarmed';
        if ($weapon) {
            $weaponSkill = $weapon['weapon_type'] ?? ($weapon['skill_type'] ?? 'unarmed');
            $mappedSkill = SkillManager::querySkillMapped($attacker['id'] ?? 0, $weaponSkill);
            if ($mappedSkill) {
                $skillDamageBonus = SkillManager::calculateHitBonus(
                    $attacker['id'] ?? 0, 
                    $mappedSkill, 
                    $damageBonus, 
                    false
                );
                $damageBonus += $skillDamageBonus;
            }
        } else {
            // 徒手技能加成
            $mappedSkill = SkillManager::querySkillMapped($attacker['id'] ?? 0, 'unarmed');
            if ($mappedSkill) {
                $skillDamageBonus = SkillManager::calculateHitBonus(
                    $attacker['id'] ?? 0, 
                    $mappedSkill, 
                    $damageBonus, 
                    false
                );
                $damageBonus += (int)($skillDamageBonus * $dc['unarmed_skill_mult']);
            }
        }

        // 武器特殊加成
        if ($weapon && isset($weapon['hit_ob']) && $weapon['hit_ob']) {
            // 这里简化处理，实际需要调用武器的 hit_ob
        }

        // 应用伤害加成
        if ($damageBonus > 0) {
            $damage += (int)(($damageBonus + mt_rand(0, $damageBonus)) / $dc['bonus_avg_div']);
        }

        // 防御减免已移至 CombatDaemon::doAttack() 中所有加成之后执行
        // 参见 applyDefenseReduction()

        // 确保伤害至少为 0
        return max(0, $damage);
    }

    /**
     * 防御减免循环（还原原始项目 combatd.c 的防御减免逻辑）
     * 在所有伤害加成（hit_ob、skill config、perform、combo、暴风连击、杀气衰减）完毕后执行
     * 
     * 循环条件：random(defense_factor) > attacker_exp
     * 每次减免：damage -= damage/3, defense_factor /= 2
     * 
     * @param int $damage 当前伤害值
     * @param int $attackerExp 攻击者的 combat_exp
     * @param int $defenderExp 防守方的 combat_exp
     * @return int 减免后的伤害值
     */
    public static function applyDefenseReduction(int $damage, int $attackerExp, int $defenderExp): int {
        if ($damage <= 0 || $defenderExp <= 0 || $attackerExp <= 0) {
            return max(0, $damage);
        }
        
        $defCfg = self::loadConfig()['defense'];
        $defenseFactor = $defenderExp;
        while (mt_rand(0, max(1, $defenseFactor) - 1) > $attackerExp) {
            $damage -= intval($damage / $defCfg['reduction_div']);
            $defenseFactor = intval($defenseFactor / $defCfg['factor_div']);
            if ($damage <= 0) {
                break;
            }
        }
        
        return max(0, $damage);
    }

    /**
     * 检查是否命中（闪避判定）
     * 
     * @param array $attacker 攻击者数据
     * @param array $defender 防御者数据
     * @param int $attackPower 攻击威力 AP
     * @param int $dodgeMod 闪避修正（来自招式）
     * @return bool 是否被闪避
     */
    public static function checkDodge(
        array $attacker, 
        array $defender, 
        int $attackPower, 
        int $dodgeMod = 0
    ): bool {
        // 计算闪避威力 DP
        $dodgePower = self::skillPower($defender, 'dodge', self::SKILL_USAGE_DEFENSE);

        // 检查是否有坐骑闪避加成
        $dodgeCfg = self::loadConfig()['dodge'];
        $mountDodgeBonus = self::getMountDodgeBonus($defender['id'] ?? 0);
        if ($mountDodgeBonus > 0) {
            $dodgePower += (int)($dodgePower * $mountDodgeBonus / $dodgeCfg['mount_dodge_div']);
        }
        
        // 应用闪避修正
        $modValue = $dodgeMod;
        if ($dodgePower > $dodgeCfg['precision_threshold']) {
            $modValue = (int)($dodgePower / $dodgeCfg['high_precision_div'] * (100 + $modValue));
        } else {
            $modValue = (int)((100 + $modValue) * $dodgePower / $dodgeCfg['low_precision_div']);
        }
        $modValue = max(0, $modValue);

        // 闪避判定：随机(AP+DP) < 修正后的DP
        $total = $attackPower + $modValue;
        if ($total <= 0) {
            return false;
        }
        
        return mt_rand(0, $total - 1) < $modValue;
    }

    /**
     * 获取坐骑闪避加成
     * 
     * @param int $charId 角色ID
     * @return int 闪避加成百分比
     */
    public static function getMountDodgeBonus(int $charId): int {
        if ($charId <= 0) {
            return 0;
        }
        
        require_once __DIR__ . '/TempStateHelper.php';
        $mountData = TempStateHelper::get($charId, 'ride/mounted');
        
        if (!$mountData || !isset($mountData['dodge_bonus'])) {
            return 0;
        }
        
        return (int)$mountData['dodge_bonus'];
    }

    /**
     * 检查是否招架
     * 
     * @param array $attacker 攻击者数据
     * @param array $defender 防御者数据
     * @param int $attackPower 攻击威力 AP
     * @param int $parryMod 招架修正（来自招式）
     * @param array|null $attackerWeapon 攻击者武器
     * @return array ['success' => bool, 'reduction' => float]
     */
    public static function checkParry(
        array $attacker, 
        array $defender, 
        int $attackPower, 
        int $parryMod = 0, 
        ?array $attackerWeapon = null
    ): array {
        $parrySkill = 'parry';
        if (!$attackerWeapon) {
            $parrySkill = 'unarmed';
        }

        // 计算招架威力
        $parryCfg = self::loadConfig()['parry_calc'];
        $parryPower = self::skillPower($defender, $parrySkill, self::SKILL_USAGE_DEFENSE);

        // 没有武器时，招架徒手攻击的加成
        if (!$attackerWeapon) {
            $parryPower *= $parryCfg['unarmed_bonus_mult'];
        }

        // 应用招架修正
        $modValue = $parryMod;
        if ($parryPower > $parryCfg['precision_threshold']) {
            $modValue = (int)($parryPower / $parryCfg['high_precision_div'] * (100 + $modValue));
        } else {
            $modValue = (int)((100 + $modValue) * $parryPower / $parryCfg['low_precision_div']);
        }
        $modValue = max(0, $modValue);

        // 招架判定
        $total = $attackPower + $modValue;
        $success = false;
        $reduction = 0.0;

        if ($total > 0 && mt_rand(0, $total - 1) < $modValue) {
            $success = true;
            $reduction = mt_rand($parryCfg['reduce_min'], $parryCfg['reduce_max']) / 100.0;
        }

        return [
            'success' => $success,
            'reduction' => $reduction
        ];
    }

    /**
     * 检查是否暴击
     * 
     * @param array $attacker 攻击者数据
     * @return bool 是否暴击
     */
    public static function checkCritical(array $attacker): bool
    {
        $critCfg = self::loadConfig()['critical'];
        $critRate = $critCfg['base_rate'];
        
        $exp = $attacker['combat_exp'] ?? 0;
        if ($exp > 0) {
            $critRate += min((int)($exp / $critCfg['exp_div']), $critCfg['rate_cap']);
        }

        return mt_rand(1, 100) <= $critRate;
    }

    /**
     * 获取伤害类型
     * 
     * @param array|null $weapon 武器数据
     * @return string 伤害类型
     */
    public static function getDamageType(?array $weapon = null): string
    {
        if ($weapon) {
            $types = [
                'blade' => '砍伤',
                'sword' => '刺伤',
                'axe' => '砍伤',
                'spear' => '刺伤',
                'staff' => '挫伤',
                'whip' => '鞭伤',
                'stick' => '挫伤',
                'hammer' => '挫伤',
                'mace' => '挫伤',
                'dagger' => '刺伤',
                'rake' => '抓伤',
                'fork' => '刺伤',
                'throwing' => '刺伤',
                'archery' => '刺伤',
                'bow' => '刺伤',
            ];
            $weaponType = strtolower($weapon['skill_type'] ?? '');
            return $types[$weaponType] ?? '挫伤';
        }
        return '挫伤'; // 徒手为挫伤
    }

    /**
     * 获取随机部位
     * 
     * @return string 部位名称
     */
    public static function getRandomLimb(): string
    {
        $limbs = ['头部', '颈部', '胸口', '腹部', '腰部', '左臂', '右臂', '左腿', '右腿', '肩膀'];
        return $limbs[array_rand($limbs)];
    }

    /**
     * 生成伤害消息
     * 
     * @param int $damage 伤害值
     * @param string $type 伤害类型
     * @return string 伤害消息
     */
    public static function getDamageMessage(int $damage, string $type): string
    {
        if ($damage === 0) {
            return "结果没有造成任何伤害。\n";
        }

        $t = self::loadConfig()['damage_msg_thresholds'];
        // 阈值数组: [T1, T2, T3, T4, T5]

        switch ($type) {
            case '擦伤':
            case '抓伤':
            case '割伤':
                if ($damage < $t[0]) return "结果只是轻轻地划破\$p的皮肉。\n";
                elseif ($damage < $t[1]) return "结果在\$p\$l划出一道细长的血痕。\n";
                elseif ($damage < $t[2]) return "结果「嗤」地一声划出一道伤口！\n";
                elseif ($damage < $t[3]) return "结果「嗤」地一声划出一道血淋淋的伤口！\n";
                elseif ($damage < $t[4]) return "结果「嗤」地一声划出一道又长又深的伤口，溅得\$N满脸鲜血！\n";
                else return "结果只听见\$n一声惨嚎，\$p\$l被划出一道深及见骨的可怕伤口！\n";

            case '砍伤':
            case '劈伤':
                if ($damage < $t[0]) return "结果只是在\$n的皮肉上碰了碰，跟蚊子叮差不多。\n";
                elseif ($damage < $t[1]) return "结果在\$n\$l砍出一道细长的血痕。\n";
                elseif ($damage < $t[2]) return "结果「噗嗤」一声砍出一道血淋淋的伤口！\n";
                elseif ($damage < $t[3]) return "结果只听「噗」地一声，\$n的\$l被砍得血如泉涌，痛得\$p咬牙切齿！\n";
                elseif ($damage < $t[4]) return "结果「噗」地一声砍出一道又长又深的伤口，溅得\$N满脸鲜血！\n";
                else return "结果只听见\$n一声惨嚎，\$p\$l被砍开一道深及见骨的可怕伤口！\n";

            case '刺伤':
                if ($damage < $t[0]) return "结果只是轻轻地刺了\$p一下。\n";
                elseif ($damage < $t[1]) return "结果在\$p\$l刺出一道血痕。\n";
                elseif ($damage < $t[2]) return "结果「噗」地一声刺入\$p的\$l！\n";
                elseif ($damage < $t[3]) return "结果「噗」地一声刺入\$n的\$l，鲜血喷涌而出！\n";
                elseif ($damage < $t[4]) return "结果「噗噗」连刺数下，\$n的\$l鲜血直流！\n";
                else return "结果「噗」地一声，\$n的\$l被刺了一个血洞！\n";

            case '鞭伤':
            case '灼伤':
                if ($damage < $t[0]) return "结果只是在\$p身上留下一点红痕。\n";
                elseif ($damage < $t[1]) return "结果在\$p\$l留下一道鞭痕。\n";
                elseif ($damage < $t[2]) return "结果「啪」地一声抽了\$p一道！\n";
                elseif ($damage < $t[3]) return "结果「啪啪」数声，\$p被抽得皮开肉绽！\n";
                elseif ($damage < $t[4]) return "结果「啪啪啪」连抽数下，\$n痛得哇哇大叫！\n";
                else return "结果一鞭抽下，\$n被抽得鲜血淋漓，惨叫连连！\n";

            case '挫伤':
            case '内伤':
                if ($damage < $t[0]) return "结果只是在\$p身上轻轻碰了一下。\n";
                elseif ($damage < $t[1]) return "结果震得\$p气血翻涌。\n";
                elseif ($damage < $t[2]) return "结果震伤了\$p的经脉。\n";
                elseif ($damage < $t[3]) return "结果「砰」地一声，\$p被震得连退数步！\n";
                elseif ($damage < $t[4]) return "结果「轰轰」两下，\$p被震得狂吐鲜血！\n";
                else return "结果「轰轰轰」数声，\$n被震得五脏俱裂！\n";

            default:
                if ($damage < $t[0]) return "结果只是轻轻地碰了\$p一下。\n";
                elseif ($damage < $t[1]) return "结果对\$p造成一点小伤害。\n";
                elseif ($damage < $t[2]) return "结果对\$p造成了一定的伤害。\n";
                elseif ($damage < $t[3]) return "结果对\$p造成了不小的伤害！\n";
                elseif ($damage < $t[4]) return "结果对\$p造成了严重的伤害！\n";
                else return "结果对\$p造成了致命的伤害！！！\n";
        }
    }

    /**
     * 计算战斗经验奖励
     * 
     * @param int $levelDiff 等级差
     * @param int $baseExp 基础经验
     * @return int 奖励经验
     */
    public static function calculateExp(int $levelDiff, int $baseExp): int
    {
        $multiplier = 1 + $levelDiff * 0.1;
        return (int)($baseExp * $multiplier);
    }

    /**
     * 替换消息变量
     * 
     * @param string $msg 消息模板
     * @param string $attackerName 攻击者名称
     * @param string $defenderName 防御者名称
     * @param string $limb 部位
     * @param string $weaponName 武器名称
     * @return string 替换后的消息
     */
    public static function replaceVars(
        string $msg, 
        string $attackerName, 
        string $defenderName, 
        string $limb = '', 
        string $weaponName = ''
    ): string {
        $msg = str_replace('$N', $attackerName, $msg);
        $msg = str_replace('$n', $defenderName, $msg);
        $msg = str_replace('$p', $defenderName, $msg);
        
        if (!empty($limb)) {
            $msg = str_replace('$l', $limb, $msg);
        }
        
        if (!empty($weaponName)) {
            $msg = str_replace('$w', $weaponName, $msg);
        }

        return $msg;
    }

    /**
     * 获取角色比武战绩
     * 
     * @param int $charId 角色ID
     * @return array|false 战绩数据，无记录返回 false
     */
    public static function getStats(int $charId)
    {
        require_once __DIR__ . '/../includes/db.php';

        $stats = Database::queryOne(
            'SELECT cs.total_fights, cs.wins, cs.losses, cs.draws, cs.rating
             FROM combat_stats cs
             WHERE cs.char_id = ?',
            [$charId]
        );

        if (!$stats || (int)$stats['total_fights'] === 0) {
            return false;
        }

        $totalFights = (int)$stats['total_fights'];
        $wins = (int)$stats['wins'];
        $winRate = $totalFights > 0 ? round($wins / $totalFights * 100, 1) : 0;

        // 计算排名（按积分降序）
        $rankRow = Database::queryOne(
            'SELECT COUNT(*) + 1 AS `rank` FROM combat_stats WHERE rating > (SELECT COALESCE(rating,0) FROM combat_stats WHERE char_id = ?)',
            [$charId]
        );
        $rank = (int)($rankRow['rank'] ?? 1);

        return [
            'rank'         => $rank,
            'rating'       => (int)$stats['rating'],
            'total_fights' => $totalFights,
            'wins'         => $wins,
            'losses'       => (int)$stats['losses'],
            'draws'        => (int)$stats['draws'],
            'win_rate'     => $winRate,
        ];
    }

    /**
     * 获取比武排行榜
     * 
     * @param int $limit 显示数量
     * @return array 排行榜数据
     */
    public static function getRanking(int $limit = 10): array
    {
        require_once __DIR__ . '/../includes/db.php';

        $rows = Database::queryAll(
            'SELECT cs.char_id, cs.rating, cs.wins, cs.losses, cs.total_fights, c.name
             FROM combat_stats cs
             JOIN characters c ON c.id = cs.char_id
             WHERE cs.total_fights > 0
             ORDER BY cs.rating DESC, cs.wins DESC
             LIMIT {$limit}',
            []
        );

        $ranking = [];
        foreach ($rows as $row) {
            $total = (int)$row['total_fights'];
            $wins  = (int)$row['wins'];
            $ranking[] = [
                'name'       => $row['name'] ?? '未知',
                'rating'     => (int)$row['rating'],
                'wins'       => $wins,
                'losses'     => (int)$row['losses'],
                'win_rate'   => $total > 0 ? round($wins / $total * 100, 1) : 0,
                'total_fights' => $total,
            ];
        }

        return $ranking;
    }
}
