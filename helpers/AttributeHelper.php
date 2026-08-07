<?php
/**
 * 属性计算辅助类
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */
class AttributeHelper {
    
    /**
     * 查询力量 (str)
     * 公式: str + force_factor + apply/strength + gift_modify/str
     */
    public static function queryStr(array $char): int {
        $base = intval($char['str'] ?? 10);
        $forceFactor = intval($char['force_factor'] ?? 0);
        $giftModify = self::getGiftModify($char, 'str');
        $applyStrength = self::getApplyBonus($char, 'strength');
        
        $final = $base + $forceFactor + $applyStrength + $giftModify;
        // if($final>40) $final=40;  // 注释掉上限
        
        return $final;
    }
    
    /**
     * 查询胆识 (cor)
     * 公式: cor + bellicosity/50 + apply/courage + gift_modify/cor
     */
    public static function queryCor(array $char): int {
        $base = intval($char['cor'] ?? 10);
        $bellicosity = intval($char['bellicosity'] ?? 0);
        $giftModify = self::getGiftModify($char, 'cor');
        $applyCourage = self::getApplyBonus($char, 'courage');
        
        $final = $base + intval($bellicosity / 50) + $applyCourage + $giftModify;
        // if($final>40) $final=40;  // 注释掉上限
        
        return $final;
    }
    
    /**
     * 查询悟性 (int)
     * 公式: int + apply/intelligence + gift_modify/int + (literate-20)/10 (if literate>20)
     * 上限: 40
     */
    public static function queryInt(array $char): int {
        $base = intval($char['int'] ?? 10);
        $giftModify = self::getGiftModify($char, 'int');
        $applyIntelligence = self::getApplyBonus($char, 'intelligence');
        
        $final = $base + $giftModify;
        
        // 读书识字技能加成
        $literate = self::getSkillLevel($char, 'literate');
        if ($literate > 20) {
            $final += intval(($literate - 20) / 10);
        }
        
        // ★ 上限仅对基础+天赋+技能生效，装备加成不受上限限制
        if ($final > 40) {
            $final = 40;
        }
        
        // 装备加成叠加（不受上限限制）
        $final += $applyIntelligence;
        
        return $final;
    }
    
    /**
     * 查询灵性 (spi)
     * 公式: spi + apply/spirituality + gift_modify/spi + (spells-20)/10 (if spells>20)
     * 上限: 40
     */
    public static function querySpi(array $char): int {
        $base = intval($char['spi'] ?? 10);
        $giftModify = self::getGiftModify($char, 'spi');
        $applySpirituality = self::getApplyBonus($char, 'spirituality');
        
        $final = $base + $giftModify;
        
        // 法术技能加成
        $spells = self::getSkillLevel($char, 'spells');
        if ($spells > 20) {
            $final += intval(($spells - 20) / 10);
        }
        
        // ★ 上限仅对基础+天赋+技能生效，装备加成不受上限限制
        if ($final > 40) {
            $final = 40;
        }
        
        // 装备加成叠加（不受上限限制）
        $final += $applySpirituality;
        
        return $final;
    }
    
    /**
     * 查询身法 (dex)
     * 公式: dex + apply/dex + gift_modify/dex + (dodge-20)/10 (if dodge>20)
     */
    public static function queryDex(array $char): int {
        $base = intval($char['dex'] ?? 10);
        $giftModify = self::getGiftModify($char, 'dex');
        $applyDex = self::getApplyBonus($char, 'dex');
        
        $final = $base + $applyDex + $giftModify;
        
        // 闪避技能加成
        $dodge = self::getSkillLevel($char, 'dodge');
        if ($dodge > 20) {
            $final += intval(($dodge - 20) / 10);
        }
        
        // 身法无上限
        
        return $final;
    }
    
    /**
     * 查询定力 (cps) - 气势系统核心属性
     * 公式: cps + force_factor/2 + apply/composure + gift_modify/cps
     */
    public static function queryCps(array $char): int {
        $base = intval($char['cps'] ?? 10);
        $forceFactor = intval($char['force_factor'] ?? 0);
        $giftModify = self::getGiftModify($char, 'cps');
        $applyComposure = self::getApplyBonus($char, 'composure');
        
        $final = $base + intval($forceFactor / 2) + $applyComposure + $giftModify;
        // if($final>40) $final=40;  // 注释掉上限
        
        return $final;
    }
    
    /**
     * 查询容貌 (per)
     * 公式: per + gift_modify/per + apply/personality
     */
    public static function queryPer(array $char): int {
        $base = intval($char['per'] ?? 10);
        $giftModify = self::getGiftModify($char, 'per');
        $applyPersonality = self::getApplyBonus($char, 'personality');
        
        $final = $base + $giftModify + $applyPersonality;
        // if($final>40) $final=40;  // 注释掉上限
        
        return $final;
    }
    
    /**
     * 查询根骨 (con)
     * 公式: con + apply/constitution + gift_modify/con + (force-20)/10 (if force>20)
     * 上限: 40
     */
    public static function queryCon(array $char): int {
        $base = intval($char['con'] ?? 10);
        $giftModify = self::getGiftModify($char, 'con');
        $applyConstitution = self::getApplyBonus($char, 'constitution');
        
        $final = $base + $giftModify;
        
        // 内功技能加成
        $force = self::getSkillLevel($char, 'force');
        if ($force > 20) {
            $final += intval(($force - 20) / 10);
        }
        
        // ★ 上限仅对基础+天赋+技能生效，装备加成不受上限限制
        if ($final > 40) {
            $final = 40;
        }
        
        // 装备加成叠加（不受上限限制）
        $final += $applyConstitution;
        
        return $final;
    }
    
    /**
     * 查询运气 (kar)
     * 公式: kar + apply/karma + gift_modify/kar + donation/1000000
     * 上限: 40
     */
    public static function queryKar(array $char): int {
        $base = intval($char['kar'] ?? 10);
        $giftModify = self::getGiftModify($char, 'kar');
        $applyKarma = self::getApplyBonus($char, 'karma');
        $donation = intval($char['donation'] ?? 0);
        
        $final = $base + $applyKarma + $giftModify + intval($donation / 1000000);
        
        if ($final > 40) {
            $final = 40;
        }
        
        return $final;
    }
    
    /**
     * 获取天赋修正值
     */
    private static function getGiftModify(array $char, string $attr): int {
        if (empty($char['gift_modify'])) {
            return 0;
        }
        
        $giftModify = json_decode($char['gift_modify'], true);
        if (is_array($giftModify) && isset($giftModify[$attr])) {
            return intval($giftModify[$attr]);
        }
        
        return 0;
    }
    
    /**
     * 获取装备/状态加成（apply）
     * 从 session 中读取装备系统写入的临时属性加成
     * 
     * 属性映射关系（原始项目 -> 当前项目）：
     * - strength -> str
     * - courage -> cor
     * - intelligence -> int
     * - spirituality -> spi
     * - composure -> cps
     * - personality -> per
     * - constitution -> con
     * - karma -> kar
     */
    private static function getApplyBonus(array $char, string $type): int {
        $charId = intval($char['id'] ?? 0);
        if ($charId <= 0) {
            return 0;
        }
        
        // 从 session 中读取装备加成
        $applyData = $_SESSION["char_apply_{$charId}"] ?? [];
        
        // 属性名映射（apply 名称 -> session 中的 key）
        $attrMap = [
            'strength'     => 'str',
            'courage'      => 'cor',
            'intelligence' => 'int',
            'spirituality' => 'spi',
            'composure'    => 'cps',
            'personality'  => 'per',
            'constitution' => 'con',
            'karma'        => 'kar',
            'dex'          => 'dex',
        ];
        
        $sessionKey = $attrMap[$type] ?? $type;
        $bonus = intval($applyData[$sessionKey] ?? 0);
        
        // 也检查直接使用 apply 名称的情况
        if ($bonus === 0) {
            $bonus = intval($applyData[$type] ?? 0);
        }
        
        // 检查状态效果加成（如闪避、攻击、防御等）
        if ($type === 'dodge' || $type === 'attack' || $type === 'defense') {
            $bonus += intval($applyData[$type] ?? 0);
        }
        
        return $bonus;
    }
    
    /**
     * 获取技能等级
     * 优先从角色数据中的 skills 数组读取，否则查询数据库
     */
    private static function getSkillLevel(array $char, string $skillId): int {
        // 优先从角色数据中的 skills 数组读取（避免重复查询）
        if (isset($char['skills']) && is_array($char['skills'])) {
            foreach ($char['skills'] as $skill) {
                if (($skill['skill_id'] ?? '') === $skillId) {
                    return intval($skill['level'] ?? 0);
                }
            }
        }
        
        // 从数据库查询技能等级
        $charId = intval($char['id'] ?? 0);
        if ($charId <= 0) {
            return 0;
        }
        
        $skill = Database::queryOne(
            "SELECT level FROM character_skills WHERE char_id = ? AND skill_id = ?",
            [$charId, $skillId]
        );
        
        return $skill ? intval($skill['level']) : 0;
    }
    
    /**
     * 查询气防 (qi_defense)
     * 从装备中累积的气防加成
     */
    public static function queryQiDefense(array $char): int {
        $charId = intval($char['id'] ?? 0);
        if ($charId <= 0) return 0;
        $applyData = $_SESSION["char_apply_{$charId}"] ?? [];
        return intval($applyData['qi_defense'] ?? 0);
    }
    
    /**
     * 查询神防 (shen_defense)
     * 从装备中累积的神防加成
     */
    public static function queryShenDefense(array $char): int {
        $charId = intval($char['id'] ?? 0);
        if ($charId <= 0) return 0;
        $applyData = $_SESSION["char_apply_{$charId}"] ?? [];
        return intval($applyData['shen_defense'] ?? 0);
    }
    
    /**
     * 获取所有属性的最终值
     */
    public static function getAllAttributes(array $char): array {
        return [
            'str' => self::queryStr($char),
            'cor' => self::queryCor($char),
            'int' => self::queryInt($char),
            'spi' => self::querySpi($char),
            'cps' => self::queryCps($char),
            'per' => self::queryPer($char),
            'con' => self::queryCon($char),
            'kar' => self::queryKar($char),
            'dex' => self::queryDex($char),
            'qi_defense' => self::queryQiDefense($char),
            'shen_defense' => self::queryShenDefense($char),
        ];
    }
}

