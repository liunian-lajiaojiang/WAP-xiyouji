<?php
/**
 * 自然恢复系统助手类
 * 处理角色的自动恢复（精神、气血）
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../models/Character.php';

class AutoRecoverHelper {
    
    // 恢复间隔时间（秒）
    const RECOVER_INTERVAL = 60;
    
    /**
     * 检查并执行自然恢复
     * 
     * @param int $charId 角色ID
     * @return array 恢复结果
     */
    public static function checkAndRecover(int $charId): array {
        try {
            $char = CharacterModel::find($charId);
            if (!$char) {
                return ['success' => false, 'message' => '角色不存在'];
            }
            
            // 检查是否在战斗中
            if (!empty($char['combat_state']) && $char['combat_state'] == 1) {
                return ['success' => false, 'message' => '战斗中无法恢复'];
            }
            
            // 检查是否在睡觉
            if (!empty($char['sleep_state']) && $char['sleep_state'] == 1) {
                return ['success' => false, 'message' => '正在睡眠中'];
            }
            
            // 检查上次恢复时间
            $lastRecover = !empty($char['last_recover']) ? (int)$char['last_recover'] : 0;
            $now = time();
            
            if ($now - $lastRecover < self::RECOVER_INTERVAL) {
                return ['success' => false, 'message' => '尚未到恢复时间'];
            }
            
            // 执行恢复
            return self::performRecover($charId, $char);
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * 执行自然恢复
     */
    private static function performRecover(int $charId, $char): array {
        $now = time();
        
        // 检查是否有足够的食物和饮水
        if ($char['food'] <= 0 && $char['water'] <= 0) {
            // 更新上次恢复时间（防止频繁提示）
            Database::execute('UPDATE characters SET last_recover = ? WHERE id = ?', [$now, $charId]);
            return ['success' => false, 'message' => '你又饿又渴，无法自然恢复！'];
        }
        
        if ($char['food'] <= 0) {
            Database::execute('UPDATE characters SET last_recover = ? WHERE id = ?', [$now, $charId]);
            return ['success' => false, 'message' => '你太饿了，无法自然恢复！'];
        }
        
        if ($char['water'] <= 0) {
            Database::execute('UPDATE characters SET last_recover = ? WHERE id = ?', [$now, $charId]);
            return ['success' => false, 'message' => '你太渴了，无法自然恢复！'];
        }
        
        // 计算恢复量（基于体质和等级）
        $conBonus = (int)($char['con'] ?? 10) * 2;
        $levelBonus = (int)($char['level'] ?? 1) * 3;
        $baseRecover = 5;
        
        $senRecover = $baseRecover + (int)($conBonus / 3);
        $keeRecover = $baseRecover + $conBonus + (int)($levelBonus / 2);
        
        // 内力恢复（休息调息）: 恢复 10% max_force，最低 15 点
        $maxForce = (int)($char['max_force'] ?? 0);
        $curForce = (int)($char['force'] ?? 0);
        $forceRecover = 0;
        if ($maxForce > 0 && $curForce < $maxForce) {
            $forceRecover = max(15, (int)($maxForce * 0.10));
            $forceRecover = min($forceRecover, $maxForce - $curForce);
        }
        
        // 计算恢复后的值
        $newSen = min($char['max_sen'], $char['sen'] + $senRecover);
        $newKee = min($char['max_kee'], $char['kee'] + $keeRecover);
        $newForce = $curForce + $forceRecover;
        
        // 检查是否需要恢复
        $actualSen = $newSen - $char['sen'];
        $actualKee = $newKee - $char['kee'];
        $actualForce = $forceRecover;
        
        if ($actualSen <= 0 && $actualKee <= 0 && $actualForce <= 0) {
            Database::execute('UPDATE characters SET last_recover = ? WHERE id = ?', [$now, $charId]);
            return ['success' => false, 'message' => '你已经处于最佳状态，无需恢复'];
        }
        
        // 消耗食物和饮水（恢复内力时额外消耗）
        $foodCost = 1;
        $waterCost = 1;
        if ($actualForce > 0) {
            $foodCost += 1;
            $waterCost += 1;
        }
        
        // 更新数据库（恢复气血时清除濒死标记）
        $sql = 'UPDATE characters SET
                sen = ?, kee = ?,
                near_death_time = 0,'
            . ($actualForce > 0 ? ' `force` = ?,' : '')
            . ' food = food - ?, water = water - ?,
                last_recover = ?
             WHERE id = ?';
        $params = [$newSen, $newKee];
        if ($actualForce > 0) {
            $params[] = $newForce;
        }
        $params = array_merge($params, [$foodCost, $waterCost, $now, $charId]);
        Database::execute($sql, $params);
        
        $messages = [];
        if ($actualSen > 0) $messages[] = "精神 +{$actualSen}";
        if ($actualKee > 0) $messages[] = "气血 +{$actualKee}";
        if ($actualForce > 0) $messages[] = "内力 +{$actualForce}";
        $messages[] = "消耗食物 {$foodCost}，饮水 {$waterCost}";
        
        return [
            'success' => true,
            'message' => "你休息了片刻，恢复了一些体力。\n" . implode('，', $messages),
            'sen' => $newSen,
            'kee' => $newKee
        ];
    }
    
    /**
     * 强制恢复（用于特殊情况，如完成任务后）
     */
    public static function forceRecover(int $charId, int $senAmount = 0, int $keeAmount = 0): array {
        try {
            $char = CharacterModel::find($charId);
            if (!$char) {
                return ['success' => false, 'message' => '角色不存在'];
            }
            
            $messages = [];
            
            if ($senAmount > 0) {
                $newSen = min($char['max_sen'], $char['sen'] + $senAmount);
                $actualSen = $newSen - $char['sen'];
                Database::execute('UPDATE characters SET sen = ? WHERE id = ?', [$newSen, $charId]);
                $messages[] = "精神 +{$actualSen}";
            }
            
            if ($keeAmount > 0) {
                $newKee = min($char['max_kee'], $char['kee'] + $keeAmount);
                $actualKee = $newKee - $char['kee'];
                Database::execute('UPDATE characters SET kee = ? WHERE id = ?', [$newKee, $charId]);
                $messages[] = "气血 +{$actualKee}";
            }
            
            if (empty($messages)) {
                return ['success' => false, 'message' => '没有进行任何恢复'];
            }
            
            return [
                'success' => true,
                'message' => '恢复成功！\n' . implode('，', $messages)
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
?>