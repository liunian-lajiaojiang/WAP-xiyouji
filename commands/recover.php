<?php
/**
 * 恢复命令 (recover)
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 功能：运功恢复气血和精神
 * 用法：recover
 */

function cmd_recover(int $charId, string $param = ''): array {
    $me = CharacterModel::find($charId);
    
    if (!$me) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 检查是否在战斗中
    if (CombatDaemon::isInCombat($charId)) {
        return ['success' => false, 'message' => '你在战斗中，无法运功恢复！'];
    }
    
    // is_busy() 检查
    if (is_player_busy($charId)) {
        return ['success' => false, 'message' => '你正忙着呢，没空恢复。'];
    }
    
    // 检查是否已启用内功（enable force）
    $mappedForce = SkillManager::querySkillMapped($charId, 'force');
    if (empty($mappedForce)) {
        return ['success' => false, 'message' => '你必须先用 enable 选择你要用的内功心法。'];
    }
    
    // 检查内力是否足够
    $currentForce = intval($me['force'] ?? 0);
    $forceCost = 20;
    if ($currentForce < $forceCost) {
        return ['success' => false, 'message' => '你的内力不足，无法运功恢复。'];
    }
    
    // 获取内功技能等级
    $forceSkillLevel = SkillManager::getSkillLevel($charId, $mappedForce);
    $con = intval($me['con'] ?? 10);
    
    // 恢复公式
    $recoverAmount = intval($forceSkillLevel / 5) + intval($con / 3);
    if ($recoverAmount < 5) {
        $recoverAmount = 5;
    }
    
    // 当前气血和精神
    $currentKee = intval($me['kee'] ?? 0);
    $maxKee = intval($me['max_kee'] ?? 100);
    $currentSen = intval($me['sen'] ?? 0);
    $maxSen = intval($me['max_sen'] ?? 100);
    
    // 计算实际恢复量（不超过上限）
    $newKee = min($currentKee + $recoverAmount, $maxKee);
    $newSen = min($currentSen + $recoverAmount, $maxSen);
    $actualKeeRecovered = $newKee - $currentKee;
    $actualSenRecovered = $newSen - $currentSen;
    $newForce = $currentForce - $forceCost;
    
    // 更新数据库
    Database::execute(
        "UPDATE characters SET kee = ?, sen = ?, `force` = ? WHERE id = ?",
        [$newKee, $newSen, $newForce, $charId]
    );
    
    // 构建输出消息
    $output = [];
    $output[] = '你坐下来运起神功心法...';
    if ($actualKeeRecovered > 0) {
        $output[] = "你的气血恢复了 {$actualKeeRecovered} 点。";
    }
    if ($actualSenRecovered > 0) {
        $output[] = "你的精神恢复了 {$actualSenRecovered} 点。";
    }
    $output[] = '你运功完毕长舒一口气，脸色好了不少。';
    
    return [
        'success' => true,
        'message' => implode("\n", $output),
        'skip_queue' => true,
        'kee_recovered' => $actualKeeRecovered,
        'sen_recovered' => $actualSenRecovered,
        'force_cost' => $forceCost
    ];
}

