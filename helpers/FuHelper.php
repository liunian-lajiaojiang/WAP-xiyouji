<?php
namespace XYJ\Helpers;

use XYJ\Database;

/**
 * 符箓道具辅助类
 * 还原原始LPC机制：权限按技能级别判断，道具管理简单增减
 */
class FuHelper {
    
    // 支持画符的技能系配置
    const SCRIBE_SKILLS = [
        'baguazhou' => [
            'name' => '八卦咒',
            'seals' => ['thunder', 'light', 'wind', 'rain'],
        ],
    ];
    
    /**
     * 检查玩家是否可以画符
     * 原始LPC机制：按技能级别判断，不看门派
     * @param int $playerId 角色ID
     * @param string $spellName 要画的符名
     * @return bool
     */
    public static function canScribe(int $playerId, string $spellName): bool {
        // 检查是否启用了画符技能系
        $mappedSpell = self::getMappedSpellSkill($playerId);
        if (!$mappedSpell) {
            return false;
        }
        
        // 检查技能级别
        $skillLevel = self::getSpellSkillLevel($playerId, $mappedSpell);
        if ($skillLevel < 20) {
            return false;
        }
        
        // 检查该技能是否支持此符咒
        if (!isset(self::SCRIBE_SKILLS[$mappedSpell])) {
            return false;
        }
        
        $seals = self::SCRIBE_SKILLS[$mappedSpell]['seals'];
        return in_array($spellName, $seals);
    }
    
    /**
     * 获取玩家启用的法术技能系
     */
    private static function getMappedSpellSkill(int $playerId): ?string {
        $char = Database::queryOne(
            "SELECT mapped_skills FROM characters WHERE id = ?",
            [$playerId]
        );
        
        if (!$char || empty($char['mapped_skills'])) {
            return null;
        }
        
        $mappedSkills = json_decode($char['mapped_skills'], true);
        $mappedSpells = $mappedSkills['spells'] ?? null;
        
        // 返回启用的画符技能系名称
        if ($mappedSpells && isset(self::SCRIBE_SKILLS[$mappedSpells])) {
            return $mappedSpells;
        }
        
        return null;
    }
    
    /**
     * 获取玩家技能级别
     */
    private static function getSpellSkillLevel(int $playerId, string $skillName): int {
        $char = Database::queryOne(
            "SELECT skills FROM characters WHERE id = ?",
            [$playerId]
        );
        
        if (!$char || empty($char['skills'])) {
            return 0;
        }
        
        $skills = json_decode($char['skills'], true);
        return (int)($skills[$skillName] ?? 0);
    }
    
    /**
     * 绘制符箓（原始LPC机制：消耗桃符纸和精神）
     * @param int $playerId 角色ID
     * @param string $spellName 符咒名称
     * @return array
     */
    public static function scribeSpell(int $playerId, string $spellName): array {
        // 权限检查：按技能级别判断
        if (!self::canScribe($playerId, $spellName)) {
            return ['success' => false, 'message' => '你所学的法术没有这种符。'];
        }
        
        // 检查精神值
        $char = Database::queryOne(
            "SELECT sen, max_kee FROM characters WHERE id = ?",
            [$playerId]
        );
        
        if (!$char || ($char['sen'] ?? 0) < 30) {
            return ['success' => false, 'message' => '你的精神太差了，无法画符。'];
        }
        
        // 检查桃符纸
        $paper = Database::queryOne(
            "SELECT quantity FROM character_inventory WHERE char_id = ? AND item_id = 'paper_seal' AND COALESCE(category, '') = ''",
            [$playerId]
        );
        
        if (!$paper || ($paper['quantity'] ?? 0) < 1) {
            return ['success' => false, 'message' => '你只能将符咒画在桃符纸上。'];
        }
        
        // 消耗桃符纸和精神
        Database::execute(
            "UPDATE character_inventory SET quantity = quantity - 1 WHERE char_id = ? AND item_id = 'paper_seal' AND COALESCE(category, '') = ''",
            [$playerId]
        );
        Database::execute(
            "UPDATE characters SET sen = sen - 30, kee = kee - max_kee / 100 WHERE id = ?",
            [$playerId]
        );
        
        // 生成符箓道具（简单增加）
        $sealId = "{$spellName}_seal";
        $existing = Database::queryOne(
            "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = ? AND COALESCE(category, '') = ''",
            [$playerId, $sealId]
        );
        if ($existing) {
            Database::execute(
                "UPDATE character_inventory SET quantity = quantity + 1 WHERE id = ?",
                [$existing['id']]
            );
        } else {
            Database::execute(
                "INSERT INTO character_inventory (char_id, item_id, category, quantity) VALUES (?, ?, '', 1)",
                [$playerId, $sealId]
            );
        }
        
        return ['success' => true, 'message' => '画符成功！'];
    }
    
    /**
     * 使用符箓（原始LPC机制：简单消耗道具）
     * @param int $playerId 角色ID
     * @param string $sealId 符箓道具ID
     * @param int $targetId 目标ID
     * @return array
     */
    public static function useSeal(int $playerId, string $sealId, int $targetId): array {
        // 检查符箓是否存在
        $seal = Database::queryOne(
            "SELECT id, quantity FROM character_inventory WHERE char_id = ? AND item_id = ? AND COALESCE(category, '') = ''",
            [$playerId, $sealId]
        );
        
        if (!$seal || ($seal['quantity'] ?? 0) < 1) {
            return ['success' => false, 'message' => '你没有这张符箓。'];
        }
        
        // 消耗符箓（简单减少）
        Database::execute(
            "UPDATE character_inventory SET quantity = quantity - 1 WHERE id = ?",
            [$seal['id']]
        );
        
        // 删除数量为0的记录
        Database::execute(
            "DELETE FROM character_inventory WHERE id = ? AND quantity <= 0",
            [$seal['id']]
        );
        
        // 符箓效果由技能配置决定，这里只负责道具消耗
        return ['success' => true, 'message' => '祭符成功！'];
    }
}
