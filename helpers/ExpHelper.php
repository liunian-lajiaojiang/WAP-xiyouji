<?php
/**
 * 经验与道行辅助类
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 *
 * 核心概念：
 * - 道行（daoxing）：修为的主要指标，按n³增长
 * - 实战经验（combat_exp）：战斗经验，也按n³增长
 * - 没有传统"等级"，而是用描述显示修为层次
 * 
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */
class ExpHelper {
    
    /**
     * 加载 rank 配置文件（带缓存）
     */
    private static ?array $rankConfig = null;
    
    private static function loadRankConfig(): array {
        if (self::$rankConfig === null) {
            $configFile = __DIR__ . '/../config/rank.php';
            if (file_exists($configFile)) {
                self::$rankConfig = require $configFile;
            } else {
                self::$rankConfig = [];
            }
        }
        return self::$rankConfig;
    }
    
    /**
     * 种族经验倍率映射表（fallback：从 config/rank.php 读取）
     */
    private static function getRaceMultiplier(): array {
        $config = self::loadRankConfig();
        return $config['race_multiplier'] ?? [
            '妖魔' => 1.2,
            '野兽' => 0.8,
            '人类' => 1.0,
        ];
    }
    
    /**
     * 职业经验倍率映射表（fallback：从 config/rank.php 读取）
     */
    private static function getClassMultiplier(): array {
        $config = self::loadRankConfig();
        return $config['class_multiplier'] ?? [
            'dragon' => 1.5,
            'xian'   => 2.0,
            'yaomo'  => 1.3,
        ];
    }
    
    /**
     * 门派正邪映射（fallback：从 config/rank.php 读取）
     * 正数代表正派，负数代表邪派，0代表中立
     */
    private static function getFamilyAlignmentMap(): array {
        $config = self::loadRankConfig();
        return $config['family_alignment'] ?? [
            'fangcun' => 1,
            'putuo' => 1,
            'jjf' => 1,
            'wudidong' => -1,
            'moon' => -1,
            'longgong' => 0,
            'xueshan' => -1,
            'wzg' => 1,
            'hell' => -1,
        ];
    }
    
    /**
     * 道行等级描述（从 config/rank.php 读取，带 fallback）
     * 每级需要的道行 = (grade+1)³ × 2000
     */
    private static function getDxLevelDesc(): array {
        $config = self::loadRankConfig();
        return $config['dx_levels'] ?? [
            '新入道途', '闻道则喜', '初领妙道', '略通道术',
            '渐入佳境', '元神初具', '道心稳固', '一日千里',
            '道高德隆', '脱胎换骨', '霞举飞升', '道满根归',
            '不堕轮回', '已证大道', '反璞归真', '天人合一',
        ];
    }
    
    /**
     * 实战经验等级描述（从 config/rank.php 读取，带 fallback）
     * 计算公式：lvl = combat_exp × 2 / 675
     */
    private static function getExpLevelDesc(): array {
        $config = self::loadRankConfig();
        return $config['exp_levels'] ?? [
            '初学乍练', '初窥门径', '粗通皮毛', '略知一二',
            '半生不熟', '马马虎虎', '已有小成', '渐入佳境',
            '驾轻就熟', '了然于胸', '出类拔萃', '心领神会',
            '神乎其技', '出神入化', '豁然贯通', '登峰造极',
            '举世无双', '一代宗师', '震古铄今', '深不可测',
        ];
    }
    
    /**
     * 获取道行计算参数
     */
    private static function getDxCalc(): array {
        $config = self::loadRankConfig();
        return $config['dx_calc'] ?? [
            'year_divisor'  => 2000,
            'year_per_unit' => 1000,
            'day_per_remain'=> 4,
            'hour_mult'     => 3,
        ];
    }
    
    /**
     * 获取实战经验计算参数
     */
    private static function getExpCalc(): array {
        $config = self::loadRankConfig();
        return $config['exp_calc'] ?? [
            'numerator'   => 2,
            'denominator' => 675,
        ];
    }
    
    /**
     * 根据道行值获取等级描述
     * 公式：two_year = daoxing / 2000
     * 找到最大的grade使得 (grade+1)³ <= two_year
     */
    public static function describeDx(int $daoxing): string {
        $dxCalc = self::getDxCalc();
        $twoYear = intval($daoxing / $dxCalc['year_divisor']);
        $dxLevelDesc = self::getDxLevelDesc();
        $grade = 0;
        
        for ($i = 0; $i < count($dxLevelDesc); $i++) {
            $n = pow($i + 1, 3);
            if ($twoYear < $n) {
                break;
            }
            $grade = $i;
        }
        
        if ($grade >= count($dxLevelDesc)) {
            $grade = count($dxLevelDesc) - 1;
        }
        
        return $dxLevelDesc[$grade];
    }
    
    /**
     * 根据实战经验获取等级描述
     * 公式：lvl = combat_exp × 2 / 675
     * 找到最大的grade使得 (grade+1)³ <= lvl
     */
    public static function describeExp(int $combatExp): string {
        $expCalc = self::getExpCalc();
        $lvl = intval(($combatExp * $expCalc['numerator']) / $expCalc['denominator']);
        $expLevelDesc = self::getExpLevelDesc();
        $grade = 0;
        
        for ($i = 0; $i < count($expLevelDesc); $i++) {
            $n = pow($i + 1, 3);
            if ($lvl < $n) {
                break;
            }
            $grade = $i;
        }
        
        if ($grade >= count($expLevelDesc)) {
            $grade = count($expLevelDesc) - 1;
        }
        
        return $expLevelDesc[$grade];
    }
    
    /**
     * 计算击败NPC获得的道行奖励
     * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
     *
     * @param array $attacker 攻击者信息
     * @param array $npc NPC信息
     * @return int 获得的道行值
     */
    public static function calculateDxGain(array $attacker, array $npc): int {
        // 1. 检查NPC是否无奖励
        if (isset($npc['no_nk_reward']) && $npc['no_nk_reward'] == 1) {
            return 0;
        }
        
        // 2. 获取玩家门派
        $attackerFamily = $attacker['family_name'] ?? '';
        
        // 3. 玩家必须有门派才能获得奖励（简化版：原始项目要求）
        // 如果没有门派，仍然可以获得奖励但会减少（模拟cla==0的情况）
        $attackerAlignment = self::getFamilyAlignment($attackerFamily);
        
        // 4. 获取NPC的门派和有效道行
        $npcFamily = $npc['family_name'] ?? '';
        $effDx = intval($npc['eff_dx'] ?? 0);
        $nkgain = intval($npc['nkgain'] ?? 0);
        
        // 5. 检查同门派NPC，无奖励
        if (!empty($npcFamily) && $npcFamily === $attackerFamily) {
            return 0;
        }
        
        // 6. 检查正邪对立，如果同正邪则无奖励
        $npcAlignment = self::getFamilyAlignment($npcFamily);
        if ($effDx != 0 && $attackerAlignment * $npcAlignment > 0) {
            return 0;
        }
        
        // 7. 如果NPC没有设置eff_dx，计算默认值
        if ($effDx == 0) {
            $npcDx = intval($npc['daoxing'] ?? 0);
            $npcExp = intval($npc['combat_exp'] ?? 0);
            $effDx = intval(($npcDx + $npcExp) / 2);
            
            if ($effDx > 20000) {
                $effDx = intval($effDx / 3);
            } elseif ($effDx > 5000) {
                $effDx = intval($effDx / 2);
            }
            // 否则保持原值，新手更容易获得奖励
        }
        
        // 8. 如果没有设置nkgain，根据eff_dx计算默认值
        if ($nkgain == 0) {
            $absEffDx = abs($effDx);
            
            if ($absEffDx > 667000) {
                $nkgain = 600;
            } elseif ($absEffDx > 333000) {
                $nkgain = 500;
            } elseif ($absEffDx > 100000) {
                $nkgain = 400;
            } elseif ($absEffDx > 33000) {
                $nkgain = 300;
            } elseif ($absEffDx > 17000) {
                $nkgain = 200;
            } elseif ($absEffDx > 5000) {
                $nkgain = 150;
            } elseif ($absEffDx > 2000) {
                $nkgain = 100;
            } elseif ($absEffDx > 500) {
                $nkgain = 50;
            } else {
                $nkgain = 25;
            }
            
            $nkgain += 20;
        }
        
        // 9. 计算奖励衰减
        $absEffDx = abs($effDx);
        $eff1 = intval($absEffDx / 8);
        $eff2 = intval($absEffDx / 4);
        $attackerDx = intval($attacker['daoxing'] ?? 0);
        $attackerExp = intval($attacker['combat_exp'] ?? 0);
        $attackerCombined = intval(($attackerDx + $attackerExp) / 2);
        
        $reward = 0;
        if ($attackerCombined > $absEffDx) {
            $reward = 0;
        } elseif ($attackerCombined > $eff2) {
            $reward = $nkgain;
        } elseif ($attackerCombined > $eff1) {
            // 按比例计算奖励
            $reward = intval(10 * ($attackerCombined - $eff1) / ($eff2 - $eff1) * $nkgain / 10);
        } else {
            $reward = 0;
        }
        
        // 10. 如果玩家无门派或NPC无门派，奖励减少2/3
        if ($attackerAlignment == 0 || ($attackerAlignment != 0 && empty($npcFamily))) {
            $reward = intval($reward * 2 / 3);
        }
        
        return max(0, $reward);
    }
    
    /**
     * 计算击败NPC获得的实战经验奖励
     * 使用与道行奖励类似的逻辑
     *
     * @param array $attacker 攻击者信息
     * @param array $npc NPC信息
     * @return int 获得的实战经验
     */
    public static function calculateCombatExpGain(array $attacker, array $npc): int {
        // 实战经验奖励基于道行奖励的一定比例
        $dxGain = self::calculateDxGain($attacker, $npc);
        
        // 实战经验奖励 = 道行奖励 × 2
        $baseExp = intval($dxGain * 2);
        
        // 应用种族倍率
        $npcRace = $npc['race'] ?? '人类';
        $raceMap = self::getRaceMultiplier();
        $raceMultiplier = $raceMap[$npcRace] ?? 1.0;
        
        // 应用职业倍率
        $npcClass = $npc['class'] ?? '';
        $classMap = self::getClassMultiplier();
        $classMultiplier = $classMap[$npcClass] ?? 1.0;
        
        // 最终经验 = 基础经验 * 种族倍率 * 职业倍率
        $finalExp = intval($baseExp * $raceMultiplier * $classMultiplier);
        
        return max(0, $finalExp);
    }
    
    /**
     * 计算击败NPC获得的总奖励（道行+实战经验）
     *
     * @param array $attacker 攻击者信息
     * @param array $npc NPC信息
     * @return array ['daoxing' => int, 'combat_exp' => int]
     */
    public static function calculateRewards(array $attacker, array $npc): array {
        return [
            'daoxing' => self::calculateDxGain($attacker, $npc),
            'combat_exp' => self::calculateCombatExpGain($attacker, $npc),
        ];
    }
    
    /**
     * 获取门派的正邪属性
     *
     * @param string $family 门派名称
     * @return int 1=正派, -1=邪派, 0=中立/无门派
     */
    private static function getFamilyAlignment(string $family): int {
        if (empty($family)) {
            return 0;
        }
        
        $alignmentMap = self::getFamilyAlignmentMap();
        return $alignmentMap[$family] ?? 0;
    }
    
    /**
     * 获取所有道行等级描述列表（用于help命令）
     */
    public static function getDxLevelList(): array {
        $dxCalc = self::getDxCalc();
        $dxLevelDesc = self::getDxLevelDesc();
        $list = [];
        foreach ($dxLevelDesc as $grade => $desc) {
            $minDx = pow($grade + 1, 3) * $dxCalc['year_divisor'];
            $list[] = [
                'grade' => $grade,
                'desc' => $desc,
                'min_daoxing' => $minDx,
            ];
        }
        return $list;
    }
    
    /**
     * 获取所有实战经验等级描述列表（用于help命令）
     */
    public static function getExpLevelList(): array {
        $expCalc = self::getExpCalc();
        $expLevelDesc = self::getExpLevelDesc();
        $list = [];
        foreach ($expLevelDesc as $grade => $desc) {
            $minExp = intval(pow($grade + 1, 3) * $expCalc['denominator'] / $expCalc['numerator']);
            $list[] = [
                'grade' => $grade,
                'desc' => $desc,
                'min_combat_exp' => $minExp,
            ];
        }
        return $list;
    }
    
    /**
     * 将道行数值转换为中文描述
     * 参考原始项目的chinese_daoxing函数
     *
     * @param int $daoxing 道行值
     * @return string 中文描述
     */
    public static function chineseDaoxing(int $daoxing): string {
        $dxCalc = self::getDxCalc();
        $year = intval($daoxing / $dxCalc['year_per_unit']);
        $remain = $daoxing % $dxCalc['year_per_unit'];
        $day = intval($remain / $dxCalc['day_per_remain']);
        $hour = ($remain % $dxCalc['day_per_remain']) * $dxCalc['hour_mult'];
        
        $str = '';
        if ($year > 0) {
            $str .= $year . '年';
        }
        if ($day > 0) {
            $str .= $day . '天';
        }
        if ($hour > 0) {
            $str .= $hour . '时辰';
        }
        
        return $str;
    }
}

