<?php
/**
 * 法术与法力辅助类
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 核心概念
 * - 法力（mana）：施法所需的能量，由spells技能决定上限
 * - 最大法力（max_mana）：法力上限 = (spells技能等级/ 2 + 映射技能等级 × 10)
 * - 冥想（meditate）：恢复法力的命令
 */
require_once HELPER_PATH . 'SkillManager.php';
require_once HELPER_PATH . 'FabaoHelper.php';

class SpellHelper {
    
    /**
     * 查询最大法力
     * 公式: max_mana = (spells/2 + skill_map[spells]) × 10
     * 
     * @param array $char 角色数据
     * @return int 最大法力值
     */
    public static function queryMaxMana(array $char): int {
        $charId = $char['id'] ?? 0;
        if ($charId <= 0) {
            return 0;
        }
        
        // 使用SkillManager查询
        return SkillManager::queryMaxMana($charId);
    }
    
    /**
     * 添加最大法力
     * 用于技能升级时增加法力上限
     * 
     * @param int $charId 角色ID
      * @param int $amount 增量（正数增加，负数减少）
     * @return bool 是否成功
     */
    public static function addMaximumMana(int $charId, int $amount): bool {
        $char = CharacterModel::find($charId);
        if (!$char) {
            return false;
        }
        
        // 获取当前maximum_mana（存储的最大法力基数）
        $currentMaxManaBase = intval($char['maximum_mana'] ?? 0);
        $currentMaxManaCalculated = self::queryMaxMana($char);
        
        // 计算新的基数
        $newMaxManaBase = $currentMaxManaBase + $amount;
        
        // 检查边界条件
        if ($newMaxManaBase > $currentMaxManaCalculated) {
            if ($amount > 0) {
                // 不能超过计算出的最大法力
                return false;
            }
        } elseif ($newMaxManaBase < 0) {
            $newMaxManaBase = 0;
        }
        
        // 更新数据
        $sql = "UPDATE characters SET maximum_mana = ?, max_mana = ?, mana = 0 WHERE id = ?";
        $actualMaxMana = min($newMaxManaBase, $currentMaxManaCalculated);
        Database::execute($sql, [$newMaxManaBase, $actualMaxMana, $charId]);
        
        return true;
    }
    
    /**
     * 恢复法力（冥想）
     * 参考 cmds/std/Meditate.php
     * 
     * @param int $charId 角色ID
      * @param int $duration 冥想时长（秒），默认60秒
     * @return array ['success' => bool, 'message' => string, 'mana_recovered' => int]
     */
    public static function meditate(int $charId, int $duration = 60): array {
        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        $currentMana = intval($char['mana'] ?? 0);
        $maxMana = self::queryMaxMana($char);
        
        // 检查是否已经达到最大法力
        if ($currentMana >= $maxMana) {
            return [
                'success' => false,
                'message' => '你的法力已经满了，不需要冥想。',
                'mana_recovered' => 0
            ];
        }
        
        // 计算恢复量：基于灵性和spells技能
        $spi = AttributeHelper::querySpi($char);
        $spellsSkill = SkillManager::querySkill($charId, 'spells', false); // 使用最终等级（包含映射技能）
        
        // 恢复公式：每秒钟恢复 (spi + spells/10) 点法力
        $recoveryRate = intval($spi + $spellsSkill / 10);
        $manaRecovered = $recoveryRate * $duration;
        
        // 不能超过最大法力
        $newMana = min($currentMana + $manaRecovered, $maxMana);
        $actualRecovered = $newMana - $currentMana;
        
        // 更新数据
        $sql = "UPDATE characters SET mana = ? WHERE id = ?";
        Database::execute($sql, [$newMana, $charId]);
        
        return [
            'success' => true,
            'message' => "你冥想了{$duration}秒，恢复了{$actualRecovered}点法力。（当前：{$newMana}/{$maxMana}）",
            'mana_recovered' => $actualRecovered,
            'current_mana' => $newMana,
            'max_mana' => $maxMana
        ];
    }
    
    /**
     * 消耗法力（施法时调用）
     * 
     * @param int $charId 角色ID
      * @param int $cost 消耗的法力值
     * @return array ['success' => bool, 'message' => string, 'remaining_mana' => int]
     */
    public static function consumeMana(int $charId, int $cost): array {
        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在', 'remaining_mana' => 0];
        }
        
        $currentMana = intval($char['mana'] ?? 0);
        
        // 检查法力是否足够
        if ($currentMana < $cost) {
            return [
                'success' => false,
                'message' => "你的法力不足！需要{$cost}点，当前只有{$currentMana}点。",
                'remaining_mana' => $currentMana
            ];
        }
        
        // 扣除法力
        $newMana = $currentMana - $cost;
        $sql = "UPDATE characters SET mana = ? WHERE id = ?";
        Database::execute($sql, [$newMana, $charId]);
        
        return [
            'success' => true,
            'message' => "消耗了{$cost}点法力。（剩余：{$newMana}）",
            'remaining_mana' => $newMana
        ];
    }
    
    /**
     * 初始化角色法力值
     * 在角色创建或登录时调用
     * 
     * @param int $charId 角色ID
     * @return bool 是否成功
     */
    public static function initializeMana(int $charId): bool {
        $char = CharacterModel::find($charId);
        if (!$char) {
            return false;
        }
        
        // 计算最大法力值
        $maxMana = self::queryMaxMana($char);
        
        // 设置初始法力为最大值的一半（或者保持当前值）
        $currentMana = intval($char['mana'] ?? 0);
        if ($currentMana == 0 && $maxMana > 0) {
            $currentMana = intval($maxMana / 2);
        }
        
        // 更新数据
        $sql = "UPDATE characters SET max_mana = ?, mana = ? WHERE id = ?";
        Database::execute($sql, [$maxMana, $currentMana, $charId]);
        
        return true;
    }
    
    /**
     * 法术攻击
     * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
     * 
     * @param int $attackerCharId 攻击者角色ID
     * @param int $targetCharId 目标角色ID
     * @param string $spellName 法术名称
     * @return array ['success' => bool, 'message' => string, 'damage_qi' => int, 'damage_shen' => int]
     */
    public static function castSpell(int $attackerCharId, int $targetCharId, string $spellName): array {
        $attacker = CharacterModel::find($attackerCharId);
        $target = CharacterModel::find($targetCharId);
        
        if (!$attacker || !$target) {
            return ['success' => false, 'message' => '角色不存在', 'damage_qi' => 0, 'damage_shen' => 0];
        }
        
        // 检查攻击者的spells技能是否学会
        $spellsSkill = SkillManager::getSkillLevel($attackerCharId, 'spells');
        if ($spellsSkill < 1) {
            return ['success' => false, 'message' => '你没有学会任何法术', 'damage_qi' => 0, 'damage_shen' => 0];
        }
        
        // 计算基础伤害(基于spells技能等级)
        $baseDamage = intval($spellsSkill * 1.5) + mt_rand(1, 10);
        
        // 分配气和神伤
        $damageQi = intval($baseDamage / 2);
        $damageShen = $baseDamage - $damageQi;
        
        // 应用法宝防御
        $defendResult = FabaoHelper::applyFabaoDefense($targetCharId, $damageQi, $damageShen);
        $defendCount = $defendResult['defendCount'];
        
        // 生成消息
        $messages = [];
        
        if ($defendCount > 0) {
            $messages = array_merge($messages, $defendResult['messages']);
        }
        
        if ($damageQi <= 0 && $damageShen <= 0) {
            $messages[] = "结果{$attacker['name']}的攻击完全被{$target['name']}的法宝挡住！";
            return [
                'success' => true,
                'message' => implode("\n", $messages),
                'damage_qi' => 0,
                'damage_shen' => 0
            ];
        }
        
        // 应用伤害到目标
        $totalDamage = $damageQi + $damageShen;
        
        Database::execute(
            "UPDATE characters SET 
             gin = GREATEST(0, gin - ?),
             kee = GREATEST(0, kee - ?)
             WHERE id = ?",
            [intval($damageQi / 2), intval($damageShen / 2), $targetCharId]
        );
        
        $messages[] = "{$attacker['name']}对{$target['name']}施放{$spellName}，造成{$totalDamage}点伤害！";
        
        return [
            'success' => true,
            'message' => implode("\n", $messages),
            'damage_qi' => $damageQi,
            'damage_shen' => $damageShen
        ];
    }
}

