<?php
/**
 * 治疗技能系统助手类
 * 处理佛法治疗和医疗技能
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/../models/Skill.php';

class HealHelper {
    
    /**
     * 使用佛法治疗技能
     * 
     * @param int $healerId 治疗者ID
     * @param int $targetId 目标ID（可选，默认为自己）
     * @param string $skillType 技能类型：mahayana(大乘佛法) / hinayana(小乘佛法)
     * @return array 治疗结果
     */
    public static function useBuddhismHeal(int $healerId, int $targetId = null, string $skillType = 'mahayana'): array {
        try {
            $healer = CharacterModel::find($healerId);
            if (!$healer) {
                return ['success' => false, 'message' => '治疗者不存在'];
            }
            
            // 默认治疗自己
            if ($targetId === null) {
                $targetId = $healerId;
            }
            
            $target = CharacterModel::find($targetId);
            if (!$target) {
                return ['success' => false, 'message' => '目标不存在'];
            }
            
            // 检查杀气限制
            if ($healer['bellicosity'] > 100) {
                return ['success' => false, 'message' => '你杀气太重，无法使用佛法！'];
            }
            
            // 获取技能等级
            $skillLevel = SkillModel::getSkillLevel($healerId, $skillType);
            if ($skillLevel <= 0) {
                return ['success' => false, 'message' => '你还没有学会这项技能！'];
            }
            
            // 检查内力
            $manaCost = $skillLevel * 10;
            $currentMana = $healer['mana'] ?? 0;
            if ($currentMana < $manaCost) {
                return ['success' => false, 'message' => '内力不足，无法使用治疗技能！'];
            }
            
            // 计算治疗效果
            $healBase = 20;
            $healBonus = $skillLevel * 5;
            $healAmount = $healBase + $healBonus;
            
            // 恢复气血
            $newKee = min($target['max_kee'], $target['kee'] + $healAmount);
            $actualHeal = $newKee - $target['kee'];
            
            if ($actualHeal <= 0) {
                return ['success' => false, 'message' => $target['name'] . '已经处于最佳状态，不需要治疗。'];
            }
            
            // 消耗内力
            Database::execute('UPDATE characters SET mana = mana - ? WHERE id = ?', [$manaCost, $healerId]);
            
            // 恢复气血
            Database::execute('UPDATE characters SET kee = ? WHERE id = ?', [$newKee, $targetId]);
            
            // 记录日志
            log_game('HEAL', "{$healer['name']} 使用{$skillType}治疗 {$target['name']}，恢复 {$actualHeal} 气血");
            
            return [
                'success' => true,
                'message' => "你运起{$skillType === 'mahayana' ? '大乘佛法' : '小乘佛法'}，一道金光笼罩" . 
                             ($targetId == $healerId ? '自己' : $target['name']) . "。\n" .
                             "恢复气血 {$actualHeal} 点。\n消耗内力 {$manaCost} 点。",
                'heal_amount' => $actualHeal,
                'mana_cost' => $manaCost
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * 使用医疗技能治疗
     * 
     * @param int $healerId 治疗者ID
     * @param int $targetId 目标ID
     * @return array 治疗结果
     */
    public static function useMedicineSkill(int $healerId, int $targetId): array {
        try {
            $healer = CharacterModel::find($healerId);
            if (!$healer) {
                return ['success' => false, 'message' => '治疗者不存在'];
            }
            
            $target = CharacterModel::find($targetId);
            if (!$target) {
                return ['success' => false, 'message' => '目标不存在'];
            }
            
            // 检查是否在同一房间
            if ($healer['current_room'] !== $target['current_room'] || 
                $healer['current_area'] !== $target['current_area']) {
                return ['success' => false, 'message' => '目标不在同一房间！'];
            }
            
            // 获取医疗技能等级
            $skillLevel = SkillModel::getSkillLevel($healerId, 'medicine');
            if ($skillLevel <= 0) {
                return ['success' => false, 'message' => '你还没有学会医疗技能！'];
            }
            
            // 检查是否有绷带等治疗物品
            $hasBandage = self::hasHealItem($healerId);
            if (!$hasBandage) {
                return ['success' => false, 'message' => '你没有携带治疗物品（如绷带）！'];
            }
            
            // 计算治疗效果
            $healBase = 15;
            $healBonus = $skillLevel * 3;
            $healAmount = $healBase + $healBonus;
            
            // 恢复气血
            $newKee = min($target['max_kee'], $target['kee'] + $healAmount);
            $actualHeal = $newKee - $target['kee'];
            
            if ($actualHeal <= 0) {
                return ['success' => false, 'message' => $target['name'] . '已经处于最佳状态，不需要治疗。'];
            }
            
            // 消耗治疗物品
            self::consumeHealItem($healerId);
            
            // 恢复气血
            Database::execute('UPDATE characters SET kee = ? WHERE id = ?', [$newKee, $targetId]);
            
            return [
                'success' => true,
                'message' => "你使用医疗技能为{$target['name']}进行治疗。\n" .
                             "恢复气血 {$actualHeal} 点。",
                'heal_amount' => $actualHeal
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * 检查是否有治疗物品
     */
    private static function hasHealItem(int $charId): bool {
        $result = Database::queryOne(
            'SELECT COUNT(*) as count FROM character_inventory 
             WHERE char_id = ? AND item_id = ? AND quantity > 0',
            [$charId, 'bandage']
        );
        return $result['count'] > 0;
    }
    
    /**
     * 消耗治疗物品
     */
    private static function consumeHealItem(int $charId): void {
        Database::execute(
            'UPDATE character_inventory SET quantity = quantity - 1 
             WHERE char_id = ? AND item_id = ? AND quantity > 0 LIMIT 1',
            [$charId, 'bandage']
        );
        Database::execute(
            'DELETE FROM character_inventory 
             WHERE char_id = ? AND item_id = ? AND quantity <= 0',
            [$charId, 'bandage']
        );
    }
}
?>