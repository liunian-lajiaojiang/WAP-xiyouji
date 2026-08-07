<?php
/**
 * 雪山炼制系统处理器
 * 
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 动作逻辑：
 * - 在特定雪山房间可以进行炼制
 * - 需要特定材料和技能等级
 * - 成功率基于相关技能等级
 */

require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/../models/Item.php';
require_once __DIR__ . '/MessageDaemon.php';

class XueshanCraftingHandler extends ActionHandler {
    /**
     * 执行炼制动作
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在', 'data' => null];
            }
            
            // 解析配置
            $recipe = $action['recipe'] ?? [];
            if (empty($recipe)) {
                return ['success' => false, 'message' => '无效的炼制配方', 'data' => null];
            }
            
            // 检查所需材料
            $requiredItems = $recipe['required_items'] ?? [];
            $hasAllMaterials = true;
            $missingItems = [];
            
            foreach ($requiredItems as $itemId => $quantity) {
                $itemCount = ItemModel::getItemCount($charId, $itemId);
                if ($itemCount < $quantity) {
                    $hasAllMaterials = false;
                    $missingItems[] = $itemId;
                }
            }
            
            if (!$hasAllMaterials) {
                return ['success' => false, 'message' => '你缺少炼制所需的材料', 'data' => null];
            }
            
            // 检查技能要求
            $requiredSkill = $recipe['required_skill'] ?? '';
            $requiredLevel = $recipe['required_level'] ?? 0;
            
            if (!empty($requiredSkill)) {
                $skillLevel = $this->getSkillLevel($charId, $requiredSkill);
                if ($skillLevel < $requiredLevel) {
                    return ['success' => false, 'message' => "你的{$requiredSkill}技能等级不够，需要达到{$requiredLevel}级", 'data' => null];
                }
            }
            
            // 计算成功率
            $baseSuccessRate = $recipe['success_rate'] ?? 50;
            $skillBonus = 0;
            
            if (!empty($requiredSkill)) {
                $skillLevel = $this->getSkillLevel($charId, $requiredSkill);
                $skillBonus = min(50, ($skillLevel - $requiredLevel) * 2);
            }
            
            $successRate = min(95, $baseSuccessRate + $skillBonus);
            $success = (rand(1, 100) <= $successRate);
            
            // 消耗材料
            foreach ($requiredItems as $itemId => $quantity) {
                ItemModel::removeFromInventory($charId, $itemId, $quantity);
            }
            
            if ($success) {
                // 获得产物
                $product = $recipe['product'] ?? '';
                $productQuantity = $recipe['product_quantity'] ?? 1;
                
                if (!empty($product)) {
                    ItemModel::addToInventory($charId, $product, $productQuantity);
                }
                
                // 技能增长
                if (!empty($requiredSkill)) {
                    $gain = min(5, $recipe['skill_gain'] ?? 1);
                    $newLevel = $this->getSkillLevel($charId, $requiredSkill) + $gain;
                    CharacterModel::updateSkill($charId, $requiredSkill, $newLevel);
                }
                
                $message = "炼制成功！你获得了" . ($productQuantity > 1 ? $productQuantity . "个" : "") . "物品。";
                if (!empty($requiredSkill)) {
                    $message .= "\n你的{$requiredSkill}技能有所提升。";
                }
                
                return ['success' => true, 'message' => $message, 'data' => null];
            } else {
                // 炼制失败
                $message = "炼制失败了，材料被浪费了。";
                if (rand(1, 100) <= 10) { // 10%概率获得垃圾
                    ItemModel::addToInventory($charId, 'trash', 1);
                    $message .= "\n不过你得到了一些奇怪的残渣。";
                }
                
                return ['success' => true, 'message' => $message, 'data' => null];
            }
            
        } catch (\Exception $e) {
            error_log("XueshanCraftingHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '炼制动作执行失败', 'data' => null];
        }
    }
    
    /**
     * 获取技能等级
     */
    private function getSkillLevel(int $charId, string $skill): int {
        require_once __DIR__ . '/../models/Skill.php';
        return SkillModel::getLevel($charId, $skill);
    }
}