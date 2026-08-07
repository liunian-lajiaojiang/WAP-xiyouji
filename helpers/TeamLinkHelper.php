<?php
namespace XYJ\Helpers;

use XYJ\Database;
use XYJ\Helpers\Character;

/**
 * 神识共鸣组队增益系统
 * 基于原始LPC feature/link.c 实现
 */
class TeamLinkHelper {
    /**
     * 计算团队精神共振强度
     * @param array $teamMembers 团队成员数据
     * @return float 共振强度值
     */
    public static function calculateSpiritualLink(array $teamMembers): float {
        if (empty($teamMembers)) return 0;
        
        $totalCultivation = array_sum(array_map(
            fn($m) => $m['cultivation'] ?? 0, 
            $teamMembers
        ));
        $compatibility = array_sum(array_map(
            fn($m) => $m['compatibility'] ?? 100, 
            $teamMembers
        )) / count($teamMembers);
        
        return round($totalCultivation / count($teamMembers) * ($compatibility / 100), 2);
    }
    
    /**
     * 触发团队增益效果
     * @param float $linkStrength 共振强度
     * @return array 增益效果列表
     */
    public static function triggerLinkBuff(float $linkStrength): array {
        return [
            'recovery_boost' => min(25, $linkStrength * 0.03),   // 灵力恢复加速
            'damage_reduction' => min(15, $linkStrength * 0.015), // 伤害减免
            'exp_bonus' => min(20, $linkStrength * 0.025)         // 经验加成
        ];
    }
}
