<?php
/**
 * 食物和饮水系统助手类
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../models/Character.php';

class FoodWaterHelper {
    
    /**
     * 消耗食物和饮水（基于时间）
     * 每次消耗1点食物和1点饮水
     * 
     * @param int $charId 角色ID
     * @return array 更新后的数据
     */
    public static function consumeResources(int $charId): array {
        try {
            $char = CharacterModel::find($charId);
            if (!$char) {
                return ['success' => false, 'message' => '角色不存在'];
            }
            
            // 计算新值（不低于0）
            $newFood = max(0, $char['food'] - 1);
            $newWater = max(0, $char['water'] - 1);
            
            // 更新数据库
            $sql = "UPDATE characters 
                    SET food = ?, water = ?
                    WHERE id = ?";
            Database::execute($sql, [$newFood, $newWater, $charId]);
            
            return [
                'success' => true,
                'food' => $newFood,
                'water' => $newWater,
                'max_food' => $char['max_food'],
                'max_water' => $char['max_water']
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * 吃食物
     * 
     * @param int $charId 角色ID
     * @param int $amount 恢复的食物量
     * @return array 结果
     */
    public static function eat(int $charId, int $amount = 50): array {
        try {
            $char = CharacterModel::find($charId);
            if (!$char) {
                return ['success' => false, 'message' => '角色不存在'];
            }
            
            // 计算新值（不超过最大值）
            $newFood = min($char['max_food'], $char['food'] + $amount);
            $actualGain = $newFood - $char['food'];
            
            if ($actualGain <= 0) {
                return ['success' => false, 'message' => '你已经吃饱了，吃不下了！'];
            }
            
            // 更新数据库
            $sql = "UPDATE characters SET food = ? WHERE id = ?";
            Database::execute($sql, [$newFood, $charId]);
            
            // 获取状态描述
            $statusText = self::foodStatusText($newFood, $char['max_food']);
            
            return [
                'success' => true,
                'message' => "你吃了一些食物，感觉好多了！（食物 +{$actualGain}）当前状态：{$statusText}",
                'food' => $newFood,
                'max_food' => $char['max_food'],
                'status' => $statusText
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * 喝水
     * 
     * @param int $charId 角色ID
     * @param int $amount 恢复的饮水量
     * @return array 结果
     */
    public static function drink(int $charId, int $amount = 50): array {
        try {
            $char = CharacterModel::find($charId);
            if (!$char) {
                return ['success' => false, 'message' => '角色不存在'];
            }
            
            // 计算新值（不超过最大值）
            $newWater = min($char['max_water'], $char['water'] + $amount);
            $actualGain = $newWater - $char['water'];
            
            if ($actualGain <= 0) {
                return ['success' => false, 'message' => '你已经喝饱了，喝不下了！'];
            }
            
            // 更新数据库
            $sql = "UPDATE characters SET water = ? WHERE id = ?";
            Database::execute($sql, [$newWater, $charId]);
            
            // 获取状态描述
            $statusText = self::waterStatusText($newWater, $char['max_water']);
            
            return [
                'success' => true,
                'message' => "你喝了一些水，感觉好多了！（饮水 +{$actualGain}）当前状态：{$statusText}",
                'water' => $newWater,
                'max_water' => $char['max_water'],
                'status' => $statusText
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * 恢复气血和精神（需要消耗食物和饮水）
     * 参考原始项目 sleep.c 的 wakeup1 函数逻辑
     * 
     * @param int $charId 角色ID
     * @return array 结果
     */
    public static function recover(int $charId): array {
        try {
            $char = CharacterModel::find($charId);
            if (!$char) {
                return ['success' => false, 'message' => '角色不存在'];
            }
            
            // 检查是否有足够的食物和饮水
            if ($char['food'] <= 0 && $char['water'] <= 0) {
                return ['success' => false, 'message' => '你又饿又渴，无法恢复！先吃点东西或喝点水吧。', 'skip_queue' => true];
            }

            if ($char['food'] <= 0) {
                return ['success' => false, 'message' => '你太饿了，无法恢复！先吃点东西吧。', 'skip_queue' => true];
            }

            if ($char['water'] <= 0) {
                return ['success' => false, 'message' => '你太渴了，无法恢复！先喝点水吧。', 'skip_queue' => true];
            }
            
            // 消耗食物和饮水
            $foodCost = min(5, $char['food']);
            $waterCost = min(3, $char['water']);
            
            // 参考原始项目：恢复精神到有效上限 eff_sen
            $effSen = intval($char['eff_sen'] ?? $char['sen'] ?? 0);
            $maxSen = intval($char['max_sen'] ?? $char['sen'] ?? 100);
            $newSen = max($effSen, $maxSen);
            
            // 参考原始项目：恢复法力到上限 max_mana
            $maxMana = intval($char['max_mana'] ?? $char['mana'] ?? 0);
            $newMana = $maxMana;
            
            // 参考原始项目：睡觉醒来不恢复气血！保持原值
            $newKee = intval($char['kee'] ?? 0);
            
            // 计算实际恢复量
            $actualSenRecovered = $newSen - intval($char['sen'] ?? 0);
            $actualManaRecovered = $newMana - intval($char['mana'] ?? 0);

            // 内力恢复（调息）：上限的 10%，最低 15 点
            $maxForce = intval($char['max_force'] ?? 0);
            $curForce = intval($char['force'] ?? 0);
            $actualForceRecovered = 0;
            if ($maxForce > 0 && $curForce < $maxForce) {
                $newForce = min($maxForce, $curForce + max(15, (int)($maxForce * 0.10)));
                $actualForceRecovered = $newForce - $curForce;
            } else {
                $newForce = $curForce;
            }
            
            // 更新数据库
            $sql = "UPDATE characters 
                    SET sen = ?, mana = ?, `force` = ?, food = food - ?, water = water - ?
                    WHERE id = ?";
            Database::execute($sql, [$newSen, $newMana, $newForce, $foodCost, $waterCost, $charId]);
            
            // 构建消息
            $output = [];
            $output[] = '你休息了一会儿，感觉好多了！';
            if ($actualSenRecovered > 0) {
                $output[] = '精神 +' . $actualSenRecovered;
            }
            if ($actualManaRecovered > 0) {
                $output[] = '法力 +' . $actualManaRecovered;
            }
            if ($actualForceRecovered > 0) {
                $output[] = '内力 +' . $actualForceRecovered;
            }
            $output[] = '消耗食物 ' . $foodCost . '，消耗饮水 ' . $waterCost;
            
            return [
                'success' => true,
                'message' => implode("\n", $output),
                'skip_queue' => true,
                'sen' => $newSen,
                'mana' => $newMana,
                'kee' => $newKee,
                'food' => $char['food'] - $foodCost,
                'water' => $char['water'] - $waterCost,
                'sen_recovered' => $actualSenRecovered,
                'mana_recovered' => $actualManaRecovered,
                'force_recovered' => $actualForceRecovered,
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * 食物状态文字
     */
    public static function foodStatusText(int $food, int $maxFood): string {
        if ($maxFood <= 0) return '正常';
        $pct = ($food / $maxFood) * 100;
        if ($pct >= 90) return '暴食';
        if ($pct >= 50) return '饱腹';
        if ($pct >= 20) return '有些饿';
        if ($pct > 0)   return '饥肠辘辘';
        return '饿极了';
    }
    
    /**
     * 饮水状态文字
     */
    public static function waterStatusText(int $water, int $maxWater): string {
        if ($maxWater <= 0) return '正常';
        $pct = ($water / $maxWater) * 100;
        if ($pct >= 90) return '喝撑了';
        if ($pct >= 50) return '不渴';
        if ($pct >= 20) return '有些渴';
        if ($pct > 0)   return '口渴难耐';
        return '极度口渴';
    }
}
